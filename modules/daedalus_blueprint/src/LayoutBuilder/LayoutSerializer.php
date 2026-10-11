<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\LayoutBuilder;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\daedalus_blueprint\Access\BlueprintAccessChecker;
use Drupal\daedalus_blueprint\FieldHandler\FieldHandlerManager;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\ManagedKeysRegistryInterface;
use Drupal\daedalus_blueprint\NodeTree\NodeTreeBuilder;
use Drupal\daedalus_blueprint\Plugin\Block\LinkedBlueprintBlock;
use Drupal\daedalus_blueprint\Plugin\Block\SlotBlock;
use Drupal\daedalus_blueprint\Utility\FormOnlyStub;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;

/**
 * Serializes a host's Layout Builder sections as the lean node tree.
 *
 * The ONE producer of the page document's one shape: the tree the
 * blueprint's `layout` carries, the instantiator writes, the dialect
 * prints and every client reader walks. Nothing else converts sections
 * into nodes.
 *
 * Node shapes:
 * - Container (every section, top-level or nested): {kind: 'container',
 *   layout_id, uuid?, label?, blueprint?, slot?, layout_settings?,
 *   children: [...], styles?}. A container LAYOUT's ordered flow (see ContainerLayout: its
 *   sole region is a storage vestige with no addressable identity) is its
 *   `children` directly; a multi-region layout's genuinely named slots
 *   appear as region nodes, empty ones included.
 * - Region (multi-region layouts only): {kind: 'region', region: name,
 *   children: [...]}. Regions are not addressable elements (no uuid, no
 *   styles) but their grouping is real structure, so it is a node, never
 *   a map: the tree has ONE child axis at every depth.
 * - Block: {kind: 'block', uuid, blueprint?, block_type|plugin_id|field,
 *   label?, label_rendered?, view_mode?, block_fields?, configuration?,
 *   styles?}. An inline block carries its bundle, its fields and its
 *   managed configuration keys (the registry's, each key's value or NULL);
 *   a plugin block with config schema of its own carries the label core
 *   gives every block and its own settings keys under configuration
 *   (PluginBlockConfiguration); a schema-less plugin is its plugin id
 *   alone, since it takes no properties and an echo of the read back
 *   through the write grammar writes nothing. A field block names the
 *   FIELD it renders (`field`), never core's per-bundle derivative
 *   (FieldBlock): the host's subject supplies the derivative on write, so
 *   the same node lands on any host that carries the field; its
 *   configuration is the formatter's own keys like any plugin block's.
 * - Linked: {kind: 'linked', uuid, blueprint, styles?}. A blueprint shown
 *   by reference (the daedalus_blueprint_linked:{machine_name} block,
 *   LinkedBlueprintBlock): `blueprint` is the machine name of the blueprint
 *   whose document renders live inside the node, its one fact; the node
 *   holds no children of its own (the blueprint's elements are the
 *   blueprint host's, never this document's) and no plugin id (the kind
 *   says it). Its identity is the block component's (block:{uuid}), so
 *   its styles are the placement's own box.
 *
 * The tree is sparse: a key is present when it carries a fact. `label`
 * when the configured label is non-empty; `label_rendered` (TRUE) when
 * that label renders as page content (NodeTreeBuilder::labelRendered, the
 * one rule, which replaces core's 'visible'/'0' `label_display` storage
 * spelling); `view_mode` when it is not the default 'full'; `blueprint` when
 * the placement carries provenance; `slot` when a TOP-LEVEL section is a
 * slot section (the section's third-party settings carry the slot id it
 * fills, see SLOT_PROVIDER; a slot is a fact of the document's root flow,
 * so a nested container never prints one); `styles` when the node owns
 * entries.
 * Storage residue (`section_index`, `block_revision_id`, per-component
 * region names and weights) never appears: order IS the array order, and
 * revision plumbing is nobody's to read.
 *
 * Sections nest natively: a nested section (a container component
 * embedding one Section value) is a container node inside its parent's
 * children, with the exact same shape as a top-level one, recursively.
 * The container component is invisible plumbing and never a block node.
 *
 * Styles live ON the node: the flat style map (selectable keys to
 * condition-keyed entries, the host's own or the map a caller hands in)
 * is distributed onto the nodes its keys address as the tree is built. A
 * node's `styles` is keyed by the LOCAL remainder of the selectable key
 * after the node's own identity (`block:{uuid}` / `container:{uuid}`) is
 * stripped: '' the element's own entry, ':{field_name}' a block field's,
 * '::{selector}' a relative selector under the element, and the compounds
 * of those exactly as the map spells them. Entries ride verbatim, so the
 * flat map is exactly reconstructible from the tree plus its node-less
 * remainder (NodeTreeBuilder::styleMapOf(), the one canonical order).
 *
 * The summary (serializeSummary()) answers the same nodes pared: every
 * container, region and block in place with its identity, words and
 * styles, no block_fields, no configuration, no layout_settings.
 */
class LayoutSerializer {

  /**
   * The additional-data key carrying a placement's blueprint provenance.
   *
   * WRITTEN by daedalus_blueprint_library: its place_blueprint operation
   * stamps the source blueprint ID onto the placed root component's additional
   * data through the add_element operation's additional passthrough
   * (BlueprintOperationHandler::PROVENANCE_KEY aliases this constant; the
   * submodule depends on base, never the reverse, which is why the key name
   * lives here). Additional data is otherwise opaque and never serialized,
   * but provenance is a per-component FACT consumers need (the canvas verb
   * UI offers "Update blueprint" over "Save as blueprint" from it), so the one
   * serialization spine surfaces it as the node's read-only `blueprint`
   * key: every delivered blueprint (boot payload, mutation response, AI
   * serialization) carries it without a bespoke channel. Read-only because
   * the write grammar ignores it below the root, so echoing a blueprint
   * back cannot forge or erase a stamp.
   */
  public const BLUEPRINT_PROVENANCE_KEY = 'daedalus_blueprint_library';

  /**
   * The third-party provider and setting carrying a section's slot id.
   *
   * A slot section is a page's own top-level section filling a slot of
   * the same id that the document around it (a locked bundle's template,
   * later the site frame) placed as a slot block; a template's section
   * fills a frame slot the same way. The id is a birth fact written by
   * add_section's `slot` parameter (the op door validates the id and that
   * it appears at most once in the host's own document) and surfaced here
   * as the top-level container node's read-only `slot` key, the one
   * reader every consumer shares (slotOf()). Written as a section setting
   * rather than a wrapper because a top-level section has no wrapping
   * component, the same reason provenance lives there.
   */
  public const SLOT_PROVIDER = 'daedalus_blueprint';
  public const SLOT_SETTING = 'slot';

  /**
   * The shape of a slot id: a machine name, one namespace per site.
   */
  public const SLOT_ID_PATTERN = '/^[a-z0-9_]+$/';

  /**
   * The slot id a section fills, or NULL for an ordinary section.
   *
   * @param \Drupal\layout_builder\Section $section
   *   The section.
   *
   * @return string|null
   *   The slot id, or NULL.
   */
  public static function slotOf(Section $section): ?string {
    $slot = $section->getThirdPartySetting(self::SLOT_PROVIDER, self::SLOT_SETTING);
    return is_string($slot) && $slot !== '' ? $slot : NULL;
  }

  /**
   * Whether every section of a list fills a slot (an empty list does).
   *
   * A document made only of fills has no document of its own around them:
   * it is a locked page's own document (the slots it fills in its
   * template), the shape the lock and unlock merge keeps and seeds around
   * (DocumentInstantiator::narrowToSlots(), seed()). Core's blank marker
   * fills no slot, so a started-and-emptied page is not this shape.
   *
   * @param \Drupal\layout_builder\Section[] $sections
   *   A section list, as stored.
   *
   * @return bool
   *   TRUE when no section of the list is without a slot.
   */
  public static function allSlotSections(array $sections): bool {
    foreach ($sections as $section) {
      if (!$section instanceof Section || self::slotOf($section) === NULL) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Constructs a LayoutSerializer.
   *
   * @param \Drupal\daedalus_blueprint\FieldHandler\FieldHandlerManager $fieldHandlerManager
   *   The field handler manager.
   * @param \Drupal\daedalus_blueprint\ManagedKeysRegistryInterface $managedKeysRegistry
   *   The managed keys registry.
   * @param \Drupal\daedalus_blueprint\LayoutBuilder\DaedalusLayoutAdapter $daedalusLayoutAdapter
   *   The daedalus_layout adapter.
   * @param \Drupal\daedalus_blueprint\Access\BlueprintAccessChecker $accessChecker
   *   The access checker, used to filter fields the user cannot view.
   * @param \Drupal\daedalus_blueprint\LayoutBuilder\PluginBlockConfiguration $pluginBlockConfiguration
   *   A plugin block's property bag, schema and validation, from its config
   *   schema.
   */
  public function __construct(
    protected FieldHandlerManager $fieldHandlerManager,
    protected ManagedKeysRegistryInterface $managedKeysRegistry,
    protected DaedalusLayoutAdapter $daedalusLayoutAdapter,
    protected BlueprintAccessChecker $accessChecker,
    protected PluginBlockConfiguration $pluginBlockConfiguration,
  ) {}

  /**
   * Serializes a host's document as the lean node tree.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host (staged state included: the serializer reads whatever
   *   sections the host carries).
   * @param array|null $style_map
   *   The flat style map to distribute onto the nodes, or NULL for the
   *   host's own. A caller that delivers a layered or access-filtered map
   *   hands over the map it is about to deliver.
   * @param \Drupal\layout_builder\Section[]|null $sections
   *   The sections to serialize, or NULL for what the host's render shows
   *   (sections(): on a host rendered inside a foreign document, the
   *   owner's then its own). A reader of the host's OWN document alone
   *   (a capture of what a locked page owns) passes ownSections().
   *
   * @return array
   *   The ordered list of top-level container nodes; empty when the host
   *   carries no sections.
   */
  public function serialize(HostInterface $host, ?array $style_map = NULL, ?array $sections = NULL): array {
    return $this->tree($host, $style_map, FALSE, $sections);
  }

  /**
   * Serializes a host's document as the lean node tree, pared.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host.
   * @param array|null $style_map
   *   The flat style map to distribute onto the nodes, or NULL for the
   *   host's own.
   *
   * @return array
   *   The same nodes serialize() answers, without block_fields,
   *   configuration or layout_settings.
   */
  public function serializeSummary(HostInterface $host, ?array $style_map = NULL): array {
    return $this->tree($host, $style_map, TRUE);
  }

  /**
   * Builds the tree, full or pared.
   */
  protected function tree(HostInterface $host, ?array $style_map, bool $summarize, ?array $sections = NULL): array {
    $sections ??= $this->getSections($host);
    if (empty($sections)) {
      return [];
    }
    $managed_keys = $summarize ? [] : $this->managedKeysRegistry->getManagedKeys($host->entityTypeId(), $host->bundle());
    $styles = $this->indexStyles($style_map ?? $host->styleMap());
    $nodes = [];
    foreach ($sections as $section) {
      $nodes[] = $this->containerNode($section, $managed_keys, $styles, $summarize);
    }
    return $nodes;
  }

  /**
   * Builds the container node of one section.
   *
   * @param \Drupal\layout_builder\Section $section
   *   The section.
   * @param array $managed_keys
   *   The managed keys for the host's entity type and bundle ([] pares
   *   layout_settings and configuration away).
   * @param array $styles
   *   The style index (indexStyles()).
   * @param bool $summarize
   *   Whether blocks are pared.
   * @param \Drupal\layout_builder\SectionComponent|null $component
   *   The container component embedding the section when it is nested,
   *   whose additional data may carry the placement's blueprint provenance.
   *
   * @return array
   *   The container node.
   */
  protected function containerNode(Section $section, array $managed_keys, array $styles, bool $summarize, ?SectionComponent $component = NULL): array {
    $node = [
      'kind' => 'container',
      'layout_id' => $section->getLayoutId(),
    ];

    $uuid = $this->daedalusLayoutAdapter->getSectionUuid($section);
    if ($uuid !== NULL) {
      $node['uuid'] = $uuid;
    }

    // The section's user-facing name lives in layout_settings.label
    // (core-native, uniform at every depth). Emitted as a top-level key like
    // the inline-block label so consumers need no layout-settings knowledge.
    $label = $section->getLayoutSettings()['label'] ?? '';
    if (is_string($label) && $label !== '') {
      $node['label'] = $label;
    }

    // Section-rooted blueprint provenance: place_blueprint stamps the
    // source blueprint ID into the SECTION's third-party settings (a top-level
    // band has no wrapper component whose additional data could carry it, so
    // the section value is the one uniform slot at every depth). Surfaced as
    // the same read-only `blueprint` key block nodes get from their
    // additional-data stamp (see BLUEPRINT_PROVENANCE_KEY); a nested
    // section's container component may carry the stamp instead.
    $blueprint = $section->getThirdPartySetting(self::BLUEPRINT_PROVENANCE_KEY, 'blueprint');
    if (is_string($blueprint) && $blueprint !== '') {
      $node['blueprint'] = $blueprint;
    }
    if ($component !== NULL) {
      $this->addBlueprintProvenance($node, $component);
    }
    // A slot is a fact of the root flow: only a top-level section (no
    // wrapping component) fills one.
    elseif (($slot = static::slotOf($section)) !== NULL) {
      $node['slot'] = $slot;
    }

    // Filter layout_settings: only managed keys for this layout plugin.
    $managed_layout_settings = $managed_keys['section_layout_settings'][$section->getLayoutId()] ?? [];
    $filtered_layout_settings = $this->filterByManagedKeys($section->getLayoutSettings(), $managed_layout_settings);
    if (!empty($filtered_layout_settings)) {
      $node['layout_settings'] = $filtered_layout_settings;
    }

    // A container layout's sole region is a storage vestige (see
    // ContainerLayout), so its components are ONE ordered children list;
    // a multi-region layout's named slots are region nodes, every region
    // of the plugin definition, empty ones included (they are still drop
    // targets).
    $definition = $section->getLayout()->getPluginDefinition();
    $by_region = $this->childrenByRegion($section, $managed_keys, $styles, $summarize);
    if (ContainerLayout::isContainer($definition)) {
      $node['children'] = $by_region[ContainerLayout::storageRegion($definition)] ?? [];
    }
    else {
      $node['children'] = [];
      foreach ($definition->getRegionNames() as $region_name) {
        $node['children'][] = [
          'kind' => 'region',
          'region' => $region_name,
          'children' => $by_region[$region_name] ?? [],
        ];
      }
    }

    if ($uuid !== NULL) {
      $this->attachStyles($node, $styles['container'][$uuid] ?? []);
    }
    return $node;
  }

  /**
   * The child nodes of a section grouped by region, each list in weight order.
   *
   * @return array<string, array>
   *   Region name to its ordered child nodes.
   */
  protected function childrenByRegion(Section $section, array $managed_keys, array $styles, bool $summarize): array {
    $by_region = [];
    foreach ($section->getComponents() as $component) {
      $by_region[$component->getRegion()][] = [
        'weight' => $component->getWeight(),
        'node' => $this->childNode($component, $managed_keys, $styles, $summarize),
      ];
    }
    foreach ($by_region as $region => $items) {
      usort($items, static fn (array $a, array $b): int => $a['weight'] <=> $b['weight']);
      $by_region[$region] = array_column($items, 'node');
    }
    return $by_region;
  }

  /**
   * Builds the node of one component: a nested container, or a block.
   */
  protected function childNode(SectionComponent $component, array $managed_keys, array $styles, bool $summarize): array {
    // A nested section presents AS a section: storage shape == tree shape.
    // The container component is invisible plumbing, so its embedded
    // section is a container node with the exact same shape as a
    // top-level one, and recursion covers arbitrary depth.
    $embedded = $this->daedalusLayoutAdapter->embeddedSection($component);
    if ($embedded !== NULL) {
      return $this->containerNode($embedded, $managed_keys, $styles, $summarize, $component);
    }
    return $this->blockNode($component, $managed_keys, $styles, $summarize);
  }

  /**
   * Builds the block node of one component.
   */
  protected function blockNode(SectionComponent $component, array $managed_keys, array $styles, bool $summarize): array {
    $uuid = $component->getUuid();
    $configuration = $component->get('configuration');
    // A linked placement is its own kind: the blueprint it shows by
    // reference is its one fact, and the plugin id is the kind's spelling
    // in storage, never a node key (the write grammar derives it back).
    $linked = LinkedBlueprintBlock::blueprintOf($configuration['id'] ?? $component->getPluginId());
    if ($linked !== NULL) {
      $node = [
        'kind' => LinkedBlueprintBlock::KIND,
        'uuid' => $uuid,
        LinkedBlueprintBlock::BLUEPRINT_KEY => $linked,
      ];
      $this->attachStyles($node, $styles['block'][$uuid] ?? []);
      return $node;
    }
    $node = [
      'kind' => 'block',
      'uuid' => $uuid,
    ];
    $this->addBlueprintProvenance($node, $component);

    if ($this->daedalusLayoutAdapter->isInlineBlock($component)) {
      $block_type = $this->daedalusLayoutAdapter->getInlineBlockType($component);
      $node['block_type'] = $block_type;
      $this->addLabel($node, $configuration);
      if (!$summarize) {
        $view_mode = $configuration['view_mode'] ?? 'full';
        if (is_string($view_mode) && $view_mode !== '' && $view_mode !== 'full') {
          $node['view_mode'] = $view_mode;
        }
        // The FULL path: inline-block fields include form-only stub nodes.
        $block_content = $this->daedalusLayoutAdapter->extractBlockContent($component);
        $node['block_fields'] = $block_content
          ? $this->serializeBlockFields($block_content, include_stubs: TRUE)
          : [];
        $inline_managed = $managed_keys['inline_block_configuration'][$block_type] ?? [];
        if (!empty($inline_managed)) {
          $node['configuration'] = [];
          foreach (array_keys($inline_managed) as $key) {
            $node['configuration'][$key] = $configuration[$key] ?? NULL;
          }
        }
      }
    }
    else {
      $plugin_id = $configuration['id'] ?? $component->getPluginId();
      // A field block names its field, never core's per-bundle derivative:
      // the destination host's subject supplies the derivative when the
      // tree is written (DocumentInstantiator), so a tree fits any host
      // that carries the field.
      $field = FieldBlock::fieldOf($plugin_id);
      if ($field !== NULL) {
        $node['field'] = $field;
      }
      else {
        $node['plugin_id'] = $plugin_id;
        // A slot block declares the slot it is: the door a document
        // rendered inside this one fills (a placement anchored inside the
        // block lands in that document's slot section). The same key a
        // top-level container carries for the slot it FILLS.
        $slot = SlotBlock::slotIdOf($plugin_id);
        if ($slot !== NULL) {
          $node['slot'] = $slot;
        }
      }
      if ($this->pluginBlockConfiguration->hasSchema($plugin_id)) {
        $this->addLabel($node, $configuration);
        if (!$summarize) {
          $own = $this->pluginBlockConfiguration->ownValues($plugin_id, $configuration);
          if ($own !== []) {
            $node['configuration'] = $own;
          }
        }
      }
    }

    $this->attachStyles($node, $styles['block'][$uuid] ?? []);
    return $node;
  }

  /**
   * Adds a block's label facts: the configured label, and whether it shows.
   */
  protected function addLabel(array &$node, array $configuration): void {
    if (!empty($configuration['label'])) {
      $node['label'] = $configuration['label'];
    }
    if (NodeTreeBuilder::labelRendered($configuration)) {
      $node['label_rendered'] = TRUE;
    }
  }

  /**
   * Surfaces a component's blueprint provenance stamp onto its node.
   *
   * Blueprint provenance is the one additional-data fact the spine surfaces
   * (see BLUEPRINT_PROVENANCE_KEY): the stamped source blueprint ID, read-only.
   *
   * @param array &$node
   *   The node (modified by reference).
   * @param \Drupal\layout_builder\SectionComponent $component
   *   The component carrying the additional data.
   */
  protected function addBlueprintProvenance(array &$node, SectionComponent $component): void {
    $blueprint = $component->get(self::BLUEPRINT_PROVENANCE_KEY);
    if (is_string($blueprint) && $blueprint !== '') {
      $node['blueprint'] = $blueprint;
    }
  }

  /**
   * Attaches a node's style entries, keyed by local remainder.
   */
  protected function attachStyles(array &$node, array $entries): void {
    if (!empty($entries)) {
      $node['styles'] = $entries;
    }
  }

  /**
   * Indexes a flat style map by addressed node.
   *
   * @param array $style_map
   *   The flat map.
   *
   * @return array
   *   ['container' => [uuid => [local => entry]], 'block' => [...]] where
   *   local is the selectable key with the node identity stripped ('' for
   *   the element's own entry; every sub-address keeps its leading ':').
   *   Keys addressing no layout node (`entity:`, `field:`) are not indexed:
   *   they stay in the flat map's node-less remainder.
   */
  protected function indexStyles(array $style_map): array {
    $index = [];
    foreach ($style_map as $key => $entry) {
      if (!is_string($key)) {
        continue;
      }
      [$prefix, $rest] = array_pad(explode(':', $key, 2), 2, '');
      if (($prefix !== 'container' && $prefix !== 'block') || $rest === '') {
        continue;
      }
      $colon = strpos($rest, ':');
      $uuid = $colon === FALSE ? $rest : substr($rest, 0, $colon);
      $local = $colon === FALSE ? '' : substr($rest, $colon);
      if ($uuid !== '') {
        $index[$prefix][$uuid][$local] = $entry;
      }
    }
    return $index;
  }

  /**
   * Serializes a block_content or paragraph entity's fields.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity whose fields to serialize.
   * @param bool $summarize_err
   *   When TRUE, entity_reference_revisions fields are shown as summaries
   *   (uuid, target_id, type, label) instead of full serialization. Used
   *   by the paragraph element read, where nested content is summarized.
   * @param bool $include_stubs
   *   When TRUE (the FULL serialization path), fields without a serialized
   *   value get identity-only form-only stub nodes (structure
   *   completeness). The element and summary transports keep sparse output.
   *
   * @return array
   *   The serialized field values.
   */
  public function serializeBlockFields(FieldableEntityInterface $entity, bool $summarize_err = FALSE, bool $include_stubs = FALSE): array {
    $processable_fields = $this->fieldHandlerManager->getProcessableFieldDefinitions($entity);
    $fields = [];

    foreach ($processable_fields as $field_name => $definition) {
      // Fields the current user cannot view carry identity only, never a
      // value.
      if (!$this->accessChecker->fieldVisible($entity->get($field_name))) {
        if ($include_stubs) {
          $fields[$field_name] = FormOnlyStub::forDefinition($definition);
        }
        continue;
      }

      if ($summarize_err && $definition->getType() === 'entity_reference_revisions') {
        $summary_value = $this->serializeInlineEntitySummary(
          $entity->get($field_name),
          $definition,
        );
        if ($summary_value !== NULL) {
          $fields[$field_name] = $summary_value;
        }
        continue;
      }

      $handler = $this->fieldHandlerManager->getHandler($definition);
      $field_value = $handler->serialize($entity->get($field_name), $definition);

      if ($field_value !== NULL) {
        $fields[$field_name] = $field_value;
      }
      elseif ($include_stubs) {
        // The stub says whether the block field is empty (the panel's
        // "Fill with sample" verb reads it), as the root serializer's does.
        $fields[$field_name] = FormOnlyStub::forDefinition(
          $definition,
          empty: $entity->get($field_name)->isEmpty(),
        );
      }
    }

    return $fields;
  }

  /**
   * Filters data to include only managed keys.
   *
   * @param array $data
   *   The full data array.
   * @param array $managed_keys
   *   The managed keys definition for this scope.
   *
   * @return array
   *   Only the entries whose keys are in the managed keys.
   */
  protected function filterByManagedKeys(array $data, array $managed_keys): array {
    if (empty($managed_keys)) {
      return [];
    }

    return array_intersect_key($data, $managed_keys);
  }

  /**
   * Serializes inline entity items as structural summaries.
   *
   * Used by the summary read and the paragraph element read for
   * entity_reference_revisions fields. Returns UUID, target_id, type, and
   * label for each item. Nested paragraph fields are recursed via the
   * children key.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The entity_reference_revisions field items.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition.
   *
   * @return array|null
   *   Array of paragraph summaries, or NULL if empty.
   */
  public function serializeInlineEntitySummary(FieldItemListInterface $items, FieldDefinitionInterface $definition): ?array {
    if ($items->isEmpty()) {
      return NULL;
    }

    $cardinality = $definition->getFieldStorageDefinition()->getCardinality();
    $values = [];

    foreach ($items as $item) {
      $entity = $item->entity;
      if ($entity === NULL) {
        continue;
      }

      $summary = [
        'target_id' => is_numeric($entity->id()) ? (int) $entity->id() : $entity->id(),
        'uuid' => $entity->uuid(),
        'type' => $entity->bundle(),
        'label' => (string) $entity->label(),
      ];

      // Check for nested entity_reference_revisions fields (children).
      $children = $this->buildChildSummaries($entity);
      if (!empty($children)) {
        $summary['children'] = $children;
      }

      $values[] = $summary;
    }

    if (empty($values)) {
      return NULL;
    }

    if ($cardinality === 1) {
      return $values[0];
    }

    return $values;
  }

  /**
   * Builds child summaries for an entity's ERR fields.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to inspect.
   *
   * @return array
   *   Array of child field summaries, each with 'field' and 'items' keys.
   */
  protected function buildChildSummaries(FieldableEntityInterface $entity): array {
    $processable_fields = $this->fieldHandlerManager->getProcessableFieldDefinitions($entity);
    $children = [];

    foreach ($processable_fields as $field_name => $definition) {
      if ($definition->getType() !== 'entity_reference_revisions') {
        continue;
      }

      // Skip fields the current user cannot view.
      if (!$this->accessChecker->fieldVisible($entity->get($field_name))) {
        continue;
      }

      $child_items = $entity->get($field_name);
      if ($child_items->isEmpty()) {
        continue;
      }

      $items = [];
      foreach ($child_items as $child_item) {
        $child_entity = $child_item->entity;
        if ($child_entity === NULL) {
          continue;
        }

        $child_summary = [
          'target_id' => is_numeric($child_entity->id()) ? (int) $child_entity->id() : $child_entity->id(),
          'uuid' => $child_entity->uuid(),
          'type' => $child_entity->bundle(),
          'label' => (string) $child_entity->label(),
        ];

        $nested_children = $this->buildChildSummaries($child_entity);
        if (!empty($nested_children)) {
          $child_summary['children'] = $nested_children;
        }

        $items[] = $child_summary;
      }

      if (!empty($items)) {
        $children[] = [
          'field' => $field_name,
          'items' => $items,
        ];
      }
    }

    return $children;
  }

  /**
   * The layout sections a reader sees: the saved override or the defaults.
   *
   * The one lookup behind every read of "the layout as delivered": the
   * serializers here and the blueprint ownership walk read the same tree,
   * the host's sections() (its own storage's sections, else the display's,
   * resolved by the host's one display rule, without mutating anything). A
   * blank placeholder list, own or default, is no override and no default.
   * Readers of the own storage alone (locating a section by root delta,
   * snapshotting for rollback, diffing the previous state) deliberately do
   * not use it, since defaults are not the host's own.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The entity.
   *
   * @return \Drupal\layout_builder\Section[]
   *   The layout sections, or an empty array if LB is not enabled.
   */
  public function getSections(HostInterface $host): array {
    return $host->sections();
  }

}
