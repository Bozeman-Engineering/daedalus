<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\LayoutBuilder;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\daedalus_blueprint\Access\BlueprintAccessChecker;
use Drupal\daedalus_blueprint\FieldHandler\FieldHandlerManager;
use Drupal\daedalus_blueprint\ManagedKeysRegistryInterface;
use Drupal\daedalus_blueprint\Utility\FormOnlyStub;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;

/**
 * Serializes Layout Builder section objects to JSON arrays.
 *
 * Consults ManagedKeysRegistry to filter opaque data: managed keys are
 * included in output (AI can see and modify them), non-managed keys are
 * excluded (preserved silently during deserialization).
 *
 * Sections nest natively: a nested section (a container component embedding
 * one Section value) serializes as a section node (layout_id, uuid, label,
 * layout_settings, regions) inside its parent region's component list, with
 * the exact same shape as a top-level section, recursively. The container
 * component is invisible plumbing and never appears as a block node.
 *
 * Sections of a CONTAINER layout (see ContainerLayout) carry one ordered
 * `children` list in place of the `regions` map: their sole region is a
 * storage vestige with no addressable identity, so the node shape follows
 * the data's real shape — an ordered flow of blocks and nested containers.
 */
class LayoutSerializer {

  /**
   * The additional-data key carrying a placement's stencil provenance.
   *
   * WRITTEN by daedalus_blueprint_stencil: its instantiate_stencil operation
   * stamps the source stencil ID onto the placed root component's additional
   * data through the add_element operation's additional passthrough
   * (StencilOperationHandler::PROVENANCE_KEY aliases this constant; the
   * submodule depends on base, never the reverse, which is why the key name
   * lives here). Additional data is otherwise opaque and never serialized,
   * but provenance is a per-component FACT consumers need (the canvas verb
   * UI offers "Update stencil" over "Save as stencil" from it), so the one
   * serialization spine surfaces it as the component's read-only `stencil`
   * key: every delivered blueprint (boot payload, mutation response, AI
   * serialization) carries it without a bespoke channel. Read-only because
   * deserialization ignores unknown component keys and preserves additional
   * data from the previous state, so echoing a blueprint back cannot forge
   * or erase a stamp.
   */
  public const STENCIL_PROVENANCE_KEY = 'daedalus_blueprint_stencil';

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
   */
  public function __construct(
    protected FieldHandlerManager $fieldHandlerManager,
    protected ManagedKeysRegistryInterface $managedKeysRegistry,
    protected DaedalusLayoutAdapter $daedalusLayoutAdapter,
    protected BlueprintAccessChecker $accessChecker,
  ) {}

  /**
   * Serializes an entity's Layout Builder sections to JSON.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity with a layout_builder__layout field.
   *
   * @return array
   *   The serialized layout sections array.
   */
  public function serialize(FieldableEntityInterface $entity): array {
    $layout_sections = $this->getSections($entity);
    if (empty($layout_sections)) {
      return [];
    }

    $entity_type = $entity->getEntityTypeId();
    $bundle = $entity->bundle();
    $managed_keys = $this->managedKeysRegistry->getManagedKeys($entity_type, $bundle);

    $sections = [];
    foreach ($layout_sections as $index => $section) {
      $serialized = $this->serializeSection($section, $managed_keys);
      $serialized['section_index'] = $index;
      $sections[] = $serialized;
    }

    return $sections;
  }

  /**
   * Serializes a single Layout Builder section.
   *
   * @param \Drupal\layout_builder\Section $section
   *   The section to serialize.
   * @param array $managed_keys
   *   The managed keys for this entity type + bundle.
   * @param bool $summarize_blocks
   *   When TRUE, blocks are serialized as summaries (uuid, block_type,
   *   label) instead of fully expanded with field values.
   *
   * @return array
   *   The serialized section data.
   */
  public function serializeSection(Section $section, array $managed_keys, bool $summarize_blocks = FALSE): array {
    $result = [
      'layout_id' => $section->getLayoutId(),
    ];

    // Include section UUID from daedalus_layout if available.
    $section_uuid = $this->daedalusLayoutAdapter->getSectionUuid($section);
    if ($section_uuid !== NULL) {
      $result['uuid'] = $section_uuid;
    }

    // The section's user-facing name lives in layout_settings.label
    // (core-native, uniform at every depth). Emitted as a top-level key like
    // the inline-block label so consumers need no layout-settings knowledge.
    $label = $section->getLayoutSettings()['label'] ?? '';
    if (is_string($label) && $label !== '') {
      $result['label'] = $label;
    }

    // Section-rooted stencil provenance: instantiate_stencil stamps the
    // source stencil ID into the SECTION's third-party settings (a top-level
    // band has no wrapper component whose additional data could carry it, so
    // the section value is the one uniform slot at every depth). Surfaced as
    // the same read-only `stencil` key block nodes get from their
    // additional-data stamp (see STENCIL_PROVENANCE_KEY).
    $stencil = $section->getThirdPartySetting(self::STENCIL_PROVENANCE_KEY, 'stencil');
    if (is_string($stencil) && $stencil !== '') {
      $result['stencil'] = $stencil;
    }

    // Filter layout_settings: only managed keys for this layout plugin.
    $layout_id = $section->getLayoutId();
    $layout_settings = $section->getLayoutSettings();
    $managed_layout_settings = $managed_keys['section_layout_settings'][$layout_id] ?? [];
    $filtered_layout_settings = $this->filterByManagedKeys($layout_settings, $managed_layout_settings);
    if (!empty($filtered_layout_settings)) {
      $result['layout_settings'] = $filtered_layout_settings;
    }

    // The non-summarized branch is the FULL serialization path (the panel's
    // read side): inline-block fields include form-only stub nodes there.
    $by_region = $summarize_blocks
      ? $this->buildRegions($section, function (SectionComponent $component) {
          return $this->serializeSummaryComponent($component);
      })
      : $this->buildRegions($section, function (SectionComponent $component) use ($managed_keys) {
          return $this->serializeElement($component, $managed_keys);
      });

    // A container section's sole region is a storage vestige (see
    // ContainerLayout), so its components serialize as ONE ordered
    // `children` list — the shape an ordered flow of blocks and nested
    // containers actually has, and the shape an LLM expects. Multi-region
    // sections keep the regions map until migrated.
    $definition = $section->getLayout()->getPluginDefinition();
    if (ContainerLayout::isContainer($definition)) {
      $result['children'] = $by_region[ContainerLayout::storageRegion($definition)] ?? [];
    }
    else {
      $result['regions'] = $by_region;
    }

    return $result;
  }

  /**
   * Builds the regions array for a section.
   *
   * Iterates all region names from the layout plugin definition so that
   * empty regions are included in the output. Components within each region
   * are sorted by weight.
   *
   * @param \Drupal\layout_builder\Section $section
   *   The section.
   * @param callable $componentSerializer
   *   A callable that receives a SectionComponent and returns its array.
   *
   * @return array
   *   Regions keyed by name, each containing serialized components.
   */
  protected function buildRegions(Section $section, callable $componentSerializer): array {
    $by_region = [];
    foreach ($section->getComponents() as $component) {
      $region = $component->getRegion();
      $by_region[$region][] = [
        'weight' => $component->getWeight(),
        'data' => $componentSerializer($component),
      ];
    }

    $regions = [];
    foreach ($section->getLayout()->getPluginDefinition()->getRegionNames() as $region_name) {
      $items = $by_region[$region_name] ?? [];
      usort($items, fn(array $a, array $b) => $a['weight'] <=> $b['weight']);
      $regions[$region_name] = array_column($items, 'data');
    }

    return $regions;
  }

  /**
   * Serializes a single section component.
   *
   * @param \Drupal\layout_builder\SectionComponent $component
   *   The component to serialize.
   * @param array $managed_keys
   *   The managed keys for this entity type + bundle.
   *
   * @return array
   *   The serialized component data.
   */
  protected function serializeElement(SectionComponent $component, array $managed_keys): array {
    // A nested section presents AS a section: storage shape == sugar shape.
    // The container component is invisible plumbing, so its embedded section
    // serializes with the exact same node shape as a top-level section and
    // recursion covers arbitrary depth.
    $embedded = $this->daedalusLayoutAdapter->embeddedSection($component);
    if ($embedded !== NULL) {
      $result = $this->serializeSection($embedded, $managed_keys);
      $this->addStencilProvenance($result, $component);
      return $result;
    }

    $result = [
      'uuid' => $component->getUuid(),
    ];
    $this->addStencilProvenance($result, $component);

    if ($this->daedalusLayoutAdapter->isInlineBlock($component)) {
      $this->serializeInlineBlockComponent($result, $component, $managed_keys, include_stubs: TRUE);
    }
    else {
      $this->serializeNonInlineComponent($result, $component);
    }

    return $result;
  }

  /**
   * Surfaces a component's stencil provenance stamp onto its node.
   *
   * Stencil provenance is the one additional-data fact the spine surfaces
   * (see STENCIL_PROVENANCE_KEY): the stamped source stencil ID, read-only.
   *
   * @param array &$result
   *   The serialized node (modified by reference).
   * @param \Drupal\layout_builder\SectionComponent $component
   *   The component carrying the additional data.
   */
  protected function addStencilProvenance(array &$result, SectionComponent $component): void {
    $stencil = $component->get(self::STENCIL_PROVENANCE_KEY);
    if (is_string($stencil) && $stencil !== '') {
      $result['stencil'] = $stencil;
    }
  }

  /**
   * Serializes inline block component details.
   *
   * @param array &$result
   *   The component result array (modified by reference).
   * @param \Drupal\layout_builder\SectionComponent $component
   *   The section component.
   * @param array $managed_keys
   *   The managed keys.
   * @param bool $rich_managed_keys
   *   When TRUE, managed key entries include value, description, and
   *   options. When FALSE, just the value.
   * @param bool $summarize_err
   *   When TRUE, ERR fields in block_fields are shown as summaries.
   * @param bool $include_stubs
   *   When TRUE (the FULL serialization path), block_fields include
   *   identity-only stub nodes for fields without a serialized value.
   */
  public function serializeInlineBlockComponent(array &$result, SectionComponent $component, array $managed_keys, bool $rich_managed_keys = FALSE, bool $summarize_err = FALSE, bool $include_stubs = FALSE): void {
    $block_type = $this->daedalusLayoutAdapter->getInlineBlockType($component);
    $result['block_type'] = $block_type;

    $configuration = $component->get('configuration');
    if (!empty($configuration['label'])) {
      $result['label'] = $configuration['label'];
    }
    // The raw stored value ('visible' or '0'), emitted uniformly so consumers
    // can tell whether the label renders as page content (heading-style
    // blocks) without it being declared a managed key for the block type.
    // An absent key falls back to 'visible' because that is core's block
    // plugin default (baseConfigurationDefaults) — a config without the key
    // renders the label.
    $result['label_display'] = $configuration['label_display'] ?? 'visible';
    $result['view_mode'] = $configuration['view_mode'] ?? 'full';

    if (!empty($configuration['block_revision_id'])) {
      $result['block_revision_id'] = (int) $configuration['block_revision_id'];
    }

    $block_content = $this->daedalusLayoutAdapter->extractBlockContent($component);
    $result['block_fields'] = $block_content
      ? $this->serializeBlockFields($block_content, $summarize_err, $include_stubs)
      : [];

    $inline_managed = $managed_keys['inline_block_configuration'][$block_type] ?? [];
    if (!empty($inline_managed)) {
      $config_output = [];
      if ($rich_managed_keys) {
        foreach ($inline_managed as $key => $definition) {
          $entry = [
            'value' => $configuration[$key] ?? NULL,
            'description' => $definition['description'] ?? $definition['label'] ?? $key,
          ];
          if (isset($definition['schema']['enum'])) {
            $entry['options'] = $definition['schema']['enum'];
          }
          $config_output[$key] = $entry;
        }
      }
      else {
        foreach (array_keys($inline_managed) as $key) {
          $config_output[$key] = $configuration[$key] ?? NULL;
        }
      }
      $result['configuration'] = $config_output;
    }
  }

  /**
   * Serializes non-inline (system/plugin) component details.
   *
   * @param array &$result
   *   The component result array (modified by reference).
   * @param \Drupal\layout_builder\SectionComponent $component
   *   The section component.
   */
  protected function serializeNonInlineComponent(array &$result, SectionComponent $component): void {
    $configuration = $component->get('configuration');
    $result['plugin_id'] = $configuration['id'] ?? $component->getPluginId();
  }

  /**
   * Serializes a block_content or paragraph entity's fields.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity whose fields to serialize.
   * @param bool $summarize_err
   *   When TRUE, entity_reference_revisions fields are shown as summaries
   *   (uuid, target_id, type, label) instead of full serialization. Used
   *   by component-mode serialization where nested content is summarized.
   * @param bool $include_stubs
   *   When TRUE (the FULL serialization path), fields without a serialized
   *   value get identity-only form-only stub nodes (structure
   *   completeness). Component and summary transports keep sparse output.
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
   * Serializes an entity's Layout Builder sections as a structural summary.
   *
   * Shows sections with components grouped by region — UUIDs, block types,
   * and labels — but no block_fields or opaque data. Nested layouts are
   * summarized recursively with the same rules.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity with a layout_builder__layout field.
   *
   * @return array
   *   The summarized layout sections array.
   */
  public function serializeSummary(FieldableEntityInterface $entity): array {
    $layout_sections = $this->getSections($entity);
    if (empty($layout_sections)) {
      return [];
    }

    $sections = [];
    foreach ($layout_sections as $index => $section) {
      $serialized = $this->serializeSection($section, [], summarize_blocks: TRUE);
      $serialized['section_index'] = $index;
      $sections[] = $serialized;
    }

    return $sections;
  }

  /**
   * Serializes a single component as a structural summary.
   *
   * For inline blocks: UUID, block_type, label. If the block has a nested
   * layout, it is summarized recursively.
   *
   * For non-inline blocks: UUID, plugin_id.
   *
   * @param \Drupal\layout_builder\SectionComponent $component
   *   The SectionComponent to summarize.
   *
   * @return array
   *   The summarized component data.
   */
  protected function serializeSummaryComponent(SectionComponent $component): array {
    // Nested sections summarize with the same node shape as top-level
    // sections, recursively.
    $embedded = $this->daedalusLayoutAdapter->embeddedSection($component);
    if ($embedded !== NULL) {
      return $this->serializeSection($embedded, [], summarize_blocks: TRUE);
    }

    $result = [
      'uuid' => $component->getUuid(),
    ];

    if ($this->daedalusLayoutAdapter->isInlineBlock($component)) {
      $result['block_type'] = $this->daedalusLayoutAdapter->getInlineBlockType($component);

      $configuration = $component->get('configuration');
      if (!empty($configuration['label'])) {
        $result['label'] = $configuration['label'];
      }
    }
    else {
      $configuration = $component->get('configuration');
      $result['plugin_id'] = $configuration['id'] ?? $component->getPluginId();
    }

    return $result;
  }

  /**
   * Serializes inline entity items as structural summaries.
   *
   * Used by serializeSummary() for entity_reference_revisions fields.
   * Returns UUID, target_id, type, and label for each item. Nested
   * paragraph fields are recursed via the children key.
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
   * serializers here and the stencil ownership walk read the same tree, so
   * an entity without a saved override answers with the display defaults
   * (DaedalusLayoutAdapter::getDefaultSections()) without mutating the entity.
   * A blank placeholder list, on the field or on the display, is no
   * override and no default (DaedalusLayoutAdapter::isBlankSectionList()).
   * Readers of the saved field alone (locating a section by field delta,
   * snapshotting for rollback, diffing the previous state) deliberately do
   * not use it, since defaults are not on the entity.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity.
   *
   * @return \Drupal\layout_builder\Section[]
   *   The layout sections, or an empty array if LB is not enabled.
   */
  public function getSections(FieldableEntityInterface $entity): array {
    if (!$entity->hasField('layout_builder__layout')) {
      return [];
    }

    $sections = $this->daedalusLayoutAdapter->getFieldSections($entity);
    if ($sections !== []) {
      return $sections;
    }

    return $this->daedalusLayoutAdapter->getDefaultSections($entity);
  }

}
