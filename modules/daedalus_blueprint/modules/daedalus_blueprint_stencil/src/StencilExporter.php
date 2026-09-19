<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_stencil;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Layout\LayoutPluginManagerInterface;
use Drupal\daedalus_blueprint\ElementLocator;
use Drupal\daedalus_blueprint\LayoutBuilder\ContainerLayout;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\daedalus_blueprint\LayoutBuilder\DaedalusLayoutAdapter;
use Drupal\daedalus_blueprint\ManagedKeysRegistryInterface;
use Drupal\daedalus_blueprint_stencil\Exception\StencilExportException;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;

/**
 * Exports a placed element subtree as a stencil definition.
 *
 * The inverse walk of instantiate: where the operation handler expands a
 * stencil tree into add_section (born as the node's layout) / add_element
 * / style operations, this walks the placed Layout Builder structures back
 * into the file grammar. Both live in this module so the grammar's two ends
 * stay in one place.
 *
 * The capture root may be a SECTION (top-level band or nested, by its
 * section uuid) or an inline-block component (by its element uuid); the
 * subtree is captured over section VALUES — a nested section is an embedded
 * Section value in its container component's config, and it exports as a
 * container node among the parent's children exactly as the spine's lean
 * node tree presents it.
 *
 * The exported grammar IS the lean node tree (NodeTreeBuilder's
 * kind-discriminated container / region / block nodes with ONE `children`
 * axis) minus per-placement facts: no uuids, no provenance, no derived
 * keys, and absent children/regions mean empty. A container layout's flow
 * exports as direct children; a multi-region layout's named slots export
 * as region nodes in the layout definition's region order (empty regions
 * are omitted). A stencil is stored as this tree, never as dialect text,
 * so saved stencils are independent of the model-facing serialization's
 * version.
 *
 * What is pinned and what is pruned (the export policy):
 * - block_fields carries the flat property bag add_element accepts: the
 *   handler-serialized field values, the component's display label (when it
 *   differs from the block type label add_element would default to), and
 *   managed inline_block_configuration keys. That IS the spine's addressable
 *   vocabulary for an element; configuration outside it (id, provider,
 *   label_display, view_mode, block_serialized, block_revision_id) is
 *   site-local or has no grammar slot and is pruned.
 * - Reference fields (entity_reference, entity_reference_revisions, image,
 *   file) are PRESERVED by default: a stencil saved on a site keeps its
 *   media and entity references, a firm capture requirement. Their targets
 *   are site-local IDs, so a stencil destined for DISK shipping declares
 *   the placeholder strategy instead: export with 'strip_references' (the
 *   ship lane always does) and the absent fields sample-populate at
 *   placement.
 * - Section labels ship as the node's top-level `label` key (they are the
 *   composed artifact's own names, e.g. a "Hero" band); layout_settings
 *   keep every key that deviates from the layout plugin's own defaults
 *   (opaque-but-structural data like column_widths MUST survive), minus
 *   label and context_mapping (label rides the top-level key,
 *   context_mapping wires site-local contexts). Default-equal keys are
 *   pruned because add_section patches layout_settings over the plugin
 *   defaults at instantiate anyway.
 * - UUIDs, regions/weights (implicit in tree order), section third-party
 *   settings, additional data, and empty regions never ship. The
 *   provenance exclusions are load-bearing: instantiate_stencil stamps its
 *   provenance into the root's additional data (block roots) or the
 *   section's third-party settings (section roots), and a captured stencil
 *   must not inherit the stamp of the placement it was captured from (a
 *   stencil is not a placement).
 * - Styles are chased on the HOST entity's daedalus_stylish_styles map (the map-host
 *   rule: every element's styles live on the root entity) and relativized
 *   onto the nodes they style, inverting the instantiate handler's key
 *   translation — the full layered entries travel: variant REFERENCES and
 *   condition-keyed overrides alike, for every section and block key in
 *   the subtree, keyed by the tree's local remainder ('' own, ':{field}'
 *   a block field's entry, '::.class' a part, and compounds). Structured
 *   {variant, fragment} references normalize into the grammar's string form
 *   first (see normalizeEntryVariants()): the stencil grammar speaks
 *   authored root wearings only, and what a composition distributed (its
 *   fragment references and the nested wears it stamped "via" itself) is
 *   derived data the composition root's own apply_variant re-creates at
 *   instantiate.
 */
class StencilExporter implements StencilExporterInterface {

  /**
   * The style map field auto-managed by daedalus_stylish (DaedalusStylishFieldManager).
   *
   * Named locally because daedalus_stylish is an optional module: an unstyled site
   * simply has no such field and exports no styles.
   */
  protected const STYLE_MAP_FIELD = 'daedalus_stylish_styles';

  /**
   * Field types pruned from block_fields under 'strip_references'.
   *
   * The disk-shipping strategy: reference targets are site-local IDs that
   * cannot ship in a file, and an absent field sample-populates at
   * placement. NOT applied to ordinary captures — media and entity
   * references are preserved on the site that saved the stencil.
   */
  protected const REFERENCE_FIELD_TYPES = [
    'entity_reference',
    'entity_reference_revisions',
    'image',
    'file',
  ];

  /**
   * Layout settings keys that never ship inside layout_settings.
   *
   * The label ships as the section node's top-level `label` key instead;
   * context_mapping wires site-local contexts; layout_plugin is daedalus_layout's
   * default-section artifact (the display's default_section setting doubles
   * as the born section's layout settings), fully redundant with the node's
   * layout_id. Everything else in layout_settings is treated as
   * opaque-but-structural and survives when it deviates from the plugin
   * defaults.
   */
  protected const PRUNED_LAYOUT_SETTINGS = ['label', 'context_mapping', 'layout_plugin'];

  /**
   * The UUID pattern of selectable style keys.
   *
   * Mirrors daedalus_stylish's StyleMapValidator::parseKey() (daedalus_stylish is optional, so
   * the validator cannot be injected here); the instantiate handler's
   * expandStyles() builds these same keys in the other direction.
   */
  protected const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

  public function __construct(
    protected ElementLocator $elementLocator,
    protected DaedalusLayoutAdapter $daedalusLayoutAdapter,
    protected LayoutSerializer $layoutSerializer,
    protected ManagedKeysRegistryInterface $managedKeysRegistry,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ?LayoutPluginManagerInterface $layoutManager = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function export(FieldableEntityInterface $entity, string $uuid, array $options = []): StencilExport {
    $context = $this->elementLocator->locate($entity, $uuid);
    if ($context === NULL || (!$context->isLbComponent() && !$context->isSection())) {
      throw new StencilExportException(sprintf(
        'No Layout Builder element "%s" exists on %s %s.',
        $uuid,
        $entity->getEntityTypeId(),
        $entity->id() ?? $entity->uuid(),
      ));
    }

    $styles_index = $this->indexStyleMap($entity);
    $managed_config = $this->managedKeysRegistry->getManagedKeys(
      $entity->getEntityTypeId(),
      $entity->bundle(),
    )['inline_block_configuration'] ?? [];
    $strip_values = !empty($options['strip_values']);
    $strip_references = !empty($options['strip_references']);

    $warnings = [];
    if ($context->isSection()) {
      $section = $context->target;
      assert($section instanceof Section);
      $node = $this->exportSection($section, $managed_config, $styles_index, $strip_values, $strip_references, $warnings, 'element');
      $display_label = $section->getLayoutSettings()['label'] ?? NULL;
      if (!is_string($display_label) || $display_label === '') {
        $display_label = $this->layoutLabel($section->getLayoutId());
      }
    }
    else {
      $component = $context->target;
      assert($component instanceof SectionComponent);
      // A container component and its embedded section are one element (one
      // identity): capturing the container IS capturing the section.
      $embedded = $this->daedalusLayoutAdapter->embeddedSection($component);
      if ($embedded !== NULL) {
        $node = $this->exportSection($embedded, $managed_config, $styles_index, $strip_values, $strip_references, $warnings, 'element');
        $display_label = $embedded->getLayoutSettings()['label'] ?? NULL;
        if (!is_string($display_label) || $display_label === '') {
          $display_label = $this->layoutLabel($embedded->getLayoutId());
        }
      }
      else {
        $node = $this->exportElement($component, $managed_config, $styles_index, $strip_values, $strip_references, $warnings, 'element');
        $configuration = $component->get('configuration');
        $display_label = $configuration['label'] ?? NULL;
        if (!is_string($display_label) || $display_label === '') {
          $display_label = $this->blockTypeLabel($node['block_type']);
        }
      }
    }

    $definition = [
      'label' => $options['label'] ?? $display_label,
      'description' => $options['description'] ?? sprintf(
        'Exported from %s %s. Describe this stencil before shipping it.',
        $entity->getEntityTypeId(),
        $entity->id() ?? $entity->uuid(),
      ),
      'element' => $node,
    ];

    return new StencilExport($definition, $warnings);
  }

  /**
   * {@inheritdoc}
   */
  public function referencedVariants(array $element): array {
    $references = [];
    $walk = function (array $node) use (&$walk, &$references): void {
      foreach ((array) ($node['styles'] ?? []) as $entry) {
        if (!is_array($entry)) {
          continue;
        }
        foreach ((array) ($entry['variants'] ?? []) as $reference) {
          if (is_string($reference) && $reference !== '') {
            $references[$reference] = TRUE;
          }
          elseif (is_array($reference) && is_string($reference['variant'] ?? NULL) && $reference['variant'] !== '') {
            $references[$reference['variant']] = TRUE;
          }
        }
      }
      foreach ((array) ($node['children'] ?? []) as $child) {
        if (is_array($child)) {
          $walk($child);
        }
      }
      foreach ((array) ($node['regions'] ?? []) as $nodes) {
        foreach ((array) $nodes as $child) {
          if (is_array($child)) {
            $walk($child);
          }
        }
      }
    };
    if ($element !== []) {
      $walk($element);
    }
    return array_keys($references);
  }

  /**
   * Exports one placed element as a stencil node.
   *
   * A container component exports as a SECTION node (its embedded section);
   * an inline block exports as a block node. Plugin blocks have no grammar
   * slot and fail the capture loudly.
   *
   * @param \Drupal\layout_builder\SectionComponent $component
   *   The placed component.
   * @param array $managed_config
   *   The host's inline_block_configuration managed keys, by block type.
   * @param array $styles_index
   *   The host style map indexed by element UUID (see indexStyleMap()).
   * @param bool $strip_values
   *   Whether to skeletonize (omit all pinned block_fields).
   * @param bool $strip_references
   *   Whether to prune reference fields (the disk-shipping strategy).
   * @param array $warnings
   *   Collected warnings, appended.
   * @param string $path
   *   The node's path within the stencil file, for error coordinates.
   *
   * @return array
   *   The stencil node.
   */
  protected function exportElement(SectionComponent $component, array $managed_config, array $styles_index, bool $strip_values, bool $strip_references, array &$warnings, string $path): array {
    $embedded = $this->daedalusLayoutAdapter->embeddedSection($component);
    if ($embedded !== NULL) {
      return $this->exportSection($embedded, $managed_config, $styles_index, $strip_values, $strip_references, $warnings, $path);
    }

    if (!$this->daedalusLayoutAdapter->isInlineBlock($component)) {
      throw new StencilExportException(sprintf(
        'The component at %s is the "%s" plugin block; the stencil grammar holds sections and inline blocks only. Remove it from the design or replace it with an inline block.',
        $path,
        $component->getPluginId(),
      ));
    }
    $block_type = $this->daedalusLayoutAdapter->getInlineBlockType($component);
    $block = $this->daedalusLayoutAdapter->extractBlockContent($component);
    if ($block_type === NULL || $block === NULL) {
      throw new StencilExportException(sprintf(
        'The inline block content behind the component at %s could not be loaded.',
        $path,
      ));
    }

    $node = ['kind' => 'block', 'block_type' => $block_type];

    if (!$strip_values) {
      $block_fields = $this->exportBlockFields($block, $component, $block_type, $managed_config, $strip_references);
      if ($block_fields !== []) {
        $node['block_fields'] = $block_fields;
      }
    }

    $styles = $styles_index[$component->getUuid()]['block'] ?? [];
    if ($styles !== []) {
      $node['styles'] = $styles;
    }

    return $node;
  }

  /**
   * Exports one placed section as a stencil container node.
   *
   * Uniform at every depth: the capture root, a nested section among a
   * parent's children, and a nested section's own nested sections all
   * export through here (blocks and nested sections are both children).
   *
   * @param \Drupal\layout_builder\Section $section
   *   The placed section (a field row's Section or an embedded value).
   * @param array $managed_config
   *   The host's inline_block_configuration managed keys, by block type.
   * @param array $styles_index
   *   The host style map indexed by element UUID.
   * @param bool $strip_values
   *   Whether to skeletonize.
   * @param bool $strip_references
   *   Whether to prune reference fields (the disk-shipping strategy).
   * @param array $warnings
   *   Collected warnings, appended.
   * @param string $path
   *   The node's path within the stencil file.
   *
   * @return array
   *   The stencil section node.
   */
  protected function exportSection(Section $section, array $managed_config, array $styles_index, bool $strip_values, bool $strip_references, array &$warnings, string $path): array {
    $layout_id = $section->getLayoutId();
    $node = ['kind' => 'container', 'layout_id' => $layout_id];

    $label = $section->getLayoutSettings()['label'] ?? '';
    if (is_string($label) && $label !== '') {
      $node['label'] = $label;
    }

    $layout_settings = $this->pruneLayoutSettings($layout_id, $section->getLayoutSettings());
    if ($layout_settings !== []) {
      $node['layout_settings'] = $layout_settings;
    }

    $section_uuid = $this->daedalusLayoutAdapter->getSectionUuid($section);
    if ($section_uuid !== NULL) {
      $styles = $this->collectSectionStyles($section_uuid, $styles_index);
      if ($styles !== []) {
        $node['styles'] = $styles;
      }
    }

    // Group by region in the layout definition's region order (empty
    // regions are omitted: the stencil grammar treats an absent region as
    // empty), components sorted by weight so tree order carries the
    // placement order. A container layout's sole region is a storage
    // vestige (the tree's rule): its flow exports as direct children; any
    // other layout's named slots export as region nodes.
    $by_region = [];
    foreach ($section->getComponents() as $component) {
      $by_region[$component->getRegion()][] = $component;
    }
    $definition = $this->layoutManager?->getDefinition($layout_id, FALSE);
    if ($definition !== NULL) {
      $ordered = array_intersect_key(
        array_replace(array_fill_keys($definition->getRegionNames(), []), $by_region),
        $by_region,
      );
    }
    else {
      $ordered = $by_region;
    }

    $is_container = ContainerLayout::isContainer($definition);
    $children = [];
    foreach ($ordered as $region => $components) {
      usort($components, static fn (SectionComponent $a, SectionComponent $b) => $a->getWeight() <=> $b->getWeight());
      if ($is_container) {
        foreach ($components as $index => $component) {
          $children[] = $this->exportElement($component, $managed_config, $styles_index, $strip_values, $strip_references, $warnings, "$path.children[$index]");
        }
        continue;
      }
      $region_children = [];
      $region_path = "$path.children[" . count($children) . ']';
      foreach ($components as $index => $component) {
        $region_children[] = $this->exportElement($component, $managed_config, $styles_index, $strip_values, $strip_references, $warnings, "$region_path.children[$index]");
      }
      $children[] = [
        'kind' => 'region',
        'region' => (string) $region,
        'children' => $region_children,
      ];
    }
    if ($children !== []) {
      $node['children'] = $children;
    }

    return $node;
  }

  /**
   * Builds a component's block_fields: the flat property bag.
   *
   * The bag add_element consumes: serialized field values, plus the
   * component's display label and managed configuration keys (which
   * sortElementProperties() routes back apart at instantiate).
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $block
   *   The inline block content.
   * @param \Drupal\layout_builder\SectionComponent $component
   *   The placed component carrying the configuration.
   * @param string $block_type
   *   The inline block type.
   * @param array $managed_config
   *   The host's inline_block_configuration managed keys, by block type.
   * @param bool $strip_references
   *   Whether to prune reference fields (the disk-shipping strategy).
   *
   * @return array
   *   The block_fields map.
   */
  protected function exportBlockFields(FieldableEntityInterface $block, SectionComponent $component, string $block_type, array $managed_config, bool $strip_references): array {
    $fields = $this->layoutSerializer->serializeBlockFields($block);
    if ($strip_references) {
      foreach (array_keys($fields) as $field_name) {
        $field_definition = $block->getFieldDefinition($field_name);
        if ($field_definition !== NULL && in_array($field_definition->getType(), self::REFERENCE_FIELD_TYPES, TRUE)) {
          unset($fields[$field_name]);
        }
      }
    }

    $configuration = $component->get('configuration');
    $managed = $managed_config[$block_type] ?? [];
    foreach (array_keys($managed) as $key) {
      if (array_key_exists($key, $configuration) && $configuration[$key] !== NULL) {
        $fields[$key] = $configuration[$key];
      }
    }
    // An unmanaged display label is still pinned when the author changed it
    // from the block type label add_element would default to (for
    // heading-style blocks the label IS the rendered text).
    if (!isset($managed['label'])) {
      $label = $configuration['label'] ?? '';
      if (is_string($label) && $label !== '' && $label !== $this->blockTypeLabel($block_type)) {
        $fields['label'] = $label;
      }
    }

    return $fields;
  }

  /**
   * Prunes a section's layout settings down to what ships.
   *
   * @param string $layout_id
   *   The section's layout plugin ID.
   * @param array $settings
   *   The placed section's layout settings.
   *
   * @return array
   *   The settings that deviate from the plugin defaults, minus the
   *   never-ship keys. Empty when the section runs on defaults.
   */
  protected function pruneLayoutSettings(string $layout_id, array $settings): array {
    $defaults = [];
    if ($this->layoutManager !== NULL && $this->layoutManager->getDefinition($layout_id, FALSE) !== NULL) {
      $defaults = $this->layoutManager->createInstance($layout_id)->getConfiguration();
    }
    foreach (self::PRUNED_LAYOUT_SETTINGS as $key) {
      unset($settings[$key]);
    }
    foreach ($settings as $key => $value) {
      if (array_key_exists($key, $defaults) && $defaults[$key] === $value) {
        unset($settings[$key]);
      }
    }
    return $settings;
  }

  /**
   * Indexes the host entity's style map by the element UUID each key names.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The host entity (the map host).
   *
   * @return array
   *   Per-UUID buckets of already-relativized entries, keyed by the tree's
   *   local remainder ('' own, ':{field}' a block field, '::.class' a
   *   part, and compounds, exactly as the map spells them):
   *   - block: { local => entry } for block:{uuid}... keys.
   *   - section: { local => entry } for container:{uuid}... keys.
   */
  protected function indexStyleMap(FieldableEntityInterface $entity): array {
    if (!$entity->hasField(self::STYLE_MAP_FIELD)) {
      return [];
    }
    $item = $entity->get(self::STYLE_MAP_FIELD)->first();
    // Duck-typed: daedalus_stylish's StyleMapItem is not a dependency of this module.
    $map = is_object($item) && method_exists($item, 'getStyleMap') ? $item->getStyleMap() : [];

    $uuid = self::UUID;
    $index = [];
    foreach ($map as $key => $entry) {
      $key = (string) $key;
      $entry = $this->normalizeEntryVariants($entry);
      if ($entry === NULL) {
        continue;
      }
      if (preg_match("/^block:($uuid)(.*)\$/", $key, $matches)) {
        $index[$matches[1]]['block'][$matches[2]] = $entry;
      }
      elseif (preg_match("/^container:($uuid)(.*)\$/", $key, $matches)) {
        $index[$matches[1]]['section'][$matches[2]] = $entry;
      }
      // entity:{uuid} and field:{uuid}:{name} keys style the root entity's
      // own surface, never an element subtree; they are not export
      // candidates.
    }
    return $index;
  }

  /**
   * Normalizes an entry's variant references into the stencil grammar.
   *
   * The map stores one reference shape (daedalus_stylish's StyleMapValidator):
   * structured {"variant": ..., "fragment": ...} objects. The stencil
   * grammar speaks variant name strings — a stencil's styles expand into
   * apply_variant ops at instantiate, and applying a composition variant
   * distributes {variant, fragment} references over the live subtree
   * itself — so stored references normalize instead of shipping literally:
   * - A '' fragment with no origin is an ordinary root wearing (the
   *   editor's own, or the stencil's) and flattens to its variant string.
   * - A non-empty fragment is DERIVED data (written onto the child by its
   *   composition root's apply, re-created by the same apply at
   *   instantiate) and drops without a warning: nothing inexpressible is
   *   lost. A fragment reference whose composition root is OUTSIDE the
   *   exported subtree (it rode a moved element in) drops too — a stencil
   *   captures the subtree's own treatment, not looks followed from
   *   elsewhere.
   * - A '' fragment carrying "via" (the map's provenance key: the
   *   composition whose apply distributed this wear onto the child) is
   *   the same derived data in its other shape and drops by the same
   *   rule. Shipping it as a bare name would make instantiate's
   *   tree-order apply_variant re-place it on the child as an authored
   *   wear with no origin, AFTER the root's own apply stamped it, so a
   *   later switch of the root's composition could no longer reap it: the
   *   placed card would keep its "row" wrapper on Card Left to Stacked.
   *   Dropping it keeps the wear derived across the trip (the root's
   *   apply re-distributes it, origin included).
   * Anything else in the list is malformed and drops with the same
   * posture as daedalus_stylish's own rewrites.
   *
   * @return array|null
   *   The normalized entry, or NULL when nothing exportable remains.
   */
  protected function normalizeEntryVariants(mixed $entry): mixed {
    if (!is_array($entry) || !isset($entry['variants']) || !is_array($entry['variants'])) {
      return $entry;
    }
    $normalized = [];
    foreach ($entry['variants'] as $reference) {
      if (!is_array($reference) || !is_string($reference['variant'] ?? NULL) || ($reference['fragment'] ?? NULL) !== '') {
        continue;
      }
      // The map lane's provenance key is a non-empty string when present
      // (StyleMapValidator's gate): an origin-bearing wear is derived.
      if (is_string($reference['via'] ?? NULL) && $reference['via'] !== '') {
        continue;
      }
      $normalized[] = $reference['variant'];
    }
    if ($normalized === []) {
      unset($entry['variants']);
      return $entry === [] ? NULL : $entry;
    }
    $entry['variants'] = $normalized;
    return $entry;
  }

  /**
   * Collects a section node's co-located styles.
   */
  protected function collectSectionStyles(string $uuid, array $styles_index): array {
    return $styles_index[$uuid]['section'] ?? [];
  }

  /**
   * The label of an inline block type, for label-pinning decisions.
   */
  protected function blockTypeLabel(string $block_type): string {
    $type = $this->entityTypeManager->getStorage('block_content_type')->load($block_type);
    return $type !== NULL ? (string) $type->label() : $block_type;
  }

  /**
   * The human label of a layout plugin, for section display labels.
   */
  protected function layoutLabel(string $layout_id): string {
    $definition = $this->layoutManager?->getDefinition($layout_id, FALSE);
    return $definition !== NULL ? (string) $definition->getLabel() : $layout_id;
  }

}
