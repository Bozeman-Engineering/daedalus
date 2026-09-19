<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\LayoutBuilder;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Layout\LayoutPluginManagerInterface;
use Drupal\daedalus_blueprint\Access\BlueprintAccessChecker;
use Drupal\daedalus_blueprint\FieldHandler\FieldHandlerManager;
use Drupal\daedalus_blueprint\ManagedKeysRegistryInterface;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_blueprint\Utility\FormOnlyStub;
use Drupal\daedalus_blueprint\Utility\HtmlEntityDecoder;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;

/**
 * Deserializes layout JSON into Section objects and inline blocks.
 *
 * Handles UUID matching between current and incoming layout state for
 * component lifecycle (create/update/delete). Uses OpaqueDataMerger for
 * managed/non-managed key preservation across all opaque data scopes.
 *
 * Sections nest natively: a region entry carrying layout_id is a nested
 * section node (same shape as a top-level section, recursive) and builds a
 * container component embedding the Section value: storage shape == sugar
 * shape. Blocks never carry nested layouts. A container-layout section node
 * (see ContainerLayout) lists its components in one ordered `children`
 * list, which maps onto the layout's vestigial storage region.
 *
 * Components in the previous layout whose UUIDs are absent from the incoming
 * layout are dropped. Core's InlineBlockEntityOperations handles orphaned
 * block_content cleanup during entity preSave.
 *
 * Block_content entities are NOT saved directly. They are serialized into
 * block_serialized using PHP serialize(), matching InlineBlock::blockSubmit()
 * behavior. Core's InlineBlockEntityOperations::handlePreSave() handles
 * persistence, usage tracking, and revision management.
 */
class LayoutDeserializer {

  /**
   * Configuration keys rebuilt structurally by the deserializer.
   *
   * These keys are set explicitly during deserialization and excluded from
   * OpaqueDataMerger processing. Non-structural keys (e.g., a block
   * plugin's own configuration) flow through the managed keys merge
   * instead. The embedded `section`
   * value of a container component is structural: it is rebuilt from the
   * freshly deserialized section node, and letting the opaque merge preserve
   * it would resurrect the stale pre-write subtree over the new structure.
   *
   * @var array<string, true>
   */
  protected const STRUCTURAL_CONFIG_KEYS = [
    'id' => TRUE,
    'provider' => TRUE,
    'label' => TRUE,
    'label_display' => TRUE,
    'view_mode' => TRUE,
    'block_serialized' => TRUE,
    'block_revision_id' => TRUE,
    'section' => TRUE,
  ];

  /**
   * Constructs a LayoutDeserializer.
   *
   * @param \Drupal\daedalus_blueprint\FieldHandler\FieldHandlerManager $fieldHandlerManager
   *   The field handler manager.
   * @param \Drupal\daedalus_blueprint\ManagedKeysRegistryInterface $managedKeysRegistry
   *   The managed keys registry.
   * @param \Drupal\daedalus_blueprint\LayoutBuilder\OpaqueDataMerger $opaqueDataMerger
   *   The opaque data merger.
   * @param \Drupal\daedalus_blueprint\LayoutBuilder\DaedalusLayoutAdapter $daedalusLayoutAdapter
   *   The daedalus_layout adapter.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\daedalus_blueprint\Access\BlueprintAccessChecker $accessChecker
   *   The access checker, used to gate inline block fields on edit access.
   * @param \Drupal\daedalus_blueprint\LayoutBuilder\PlacementRestrictionsInterface|null $placementRestrictions
   *   The placement-restriction spine (NULL for no restriction enforcement,
   *   e.g. in test isolation).
   * @param \Drupal\Core\Layout\LayoutPluginManagerInterface|null $layoutPluginManager
   *   The layout plugin manager, used to resolve container layouts for the
   *   children-list section shape (NULL when layout discovery is absent —
   *   children nodes are then refused rather than guessed into a region).
   */
  public function __construct(
    protected FieldHandlerManager $fieldHandlerManager,
    protected ManagedKeysRegistryInterface $managedKeysRegistry,
    protected OpaqueDataMerger $opaqueDataMerger,
    protected DaedalusLayoutAdapter $daedalusLayoutAdapter,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected BlueprintAccessChecker $accessChecker,
    protected ?PlacementRestrictionsInterface $placementRestrictions = NULL,
    protected ?LayoutPluginManagerInterface $layoutPluginManager = NULL,
  ) {}

  /**
   * Deserializes layout JSON into Section objects on the entity.
   *
   * @param array $new_layout
   *   The incoming layout sections array from the blueprint JSON.
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity being deserialized. Its layout field will be updated.
   * @param string $view_mode
   *   The view mode for layout_builder_restrictions enforcement.
   * @param array $options
   *   Options forwarded to field handlers (e.g. decode_html_entities).
   *
   * @return \Drupal\daedalus_blueprint\LayoutBuilder\LayoutDeserializerResult
   *   The result containing errors (empty on success) and built sections.
   */
  public function deserialize(array $new_layout, FieldableEntityInterface $entity, string $view_mode = 'full', array $options = []): LayoutDeserializerResult {
    $entity_type = $entity->getEntityTypeId();
    $bundle = $entity->bundle();
    $managed_keys = $this->managedKeysRegistry->getManagedKeys($entity_type, $bundle);

    // Restriction answers for a whole-layout write are display-level (the
    // incoming sections do not exist in storage yet, so per-region providers
    // cannot be consulted; targeted ops enforce those at op time). The
    // defaults storage carries the governing display for the view mode.
    $restriction_storage = $this->placementRestrictions?->defaultsStorage($entity_type, $bundle, $view_mode);

    // Build maps from the entity's actual layout (full opaque data), at
    // every depth: nested sections and their components match by UUID.
    $previous_state = $this->buildPreviousLayoutState($entity);

    $errors = [];
    $warnings = [];
    $sections = [];
    // One identity namespace for the whole payload: section uuids and
    // component uuids share it (containers bridge the two — component uuid
    // == section uuid). A uuid already on the entity is a legitimate match
    // (update/relocate), but the same uuid on two nodes of one payload is a
    // repeat and refused (see buildSection / processComponent /
    // relocateElement).
    $seen_uuids = [];

    foreach ($new_layout as $section_index => $section_data) {
      $result = $this->buildSection(
        $section_data,
        "layout_builder__layout[{$section_index}]",
        $section_index,
        $previous_state,
        $managed_keys,
        $restriction_storage,
        $seen_uuids,
        $options,
      );
      $sections[] = $result['section'];
      $errors = array_merge($errors, $result['errors']);
      $warnings = array_merge($warnings, $result['warnings']);
    }

    // Replace entity's layout with new sections.
    $this->setSectionsOnEntity($entity, $sections);

    return new LayoutDeserializerResult($errors, $sections, $warnings);
  }

  /**
   * Builds one Section from a section node, recursing into nested sections.
   *
   * The one section-building path for every depth: top-level nodes become
   * layout field rows, nested nodes are wrapped into container components by
   * the caller. Both carry the identical shape.
   *
   * @param array $section_data
   *   The section node (layout_id, uuid, label, layout_settings, regions).
   * @param string $path
   *   The error path prefix for this section.
   * @param int|null $section_index
   *   The positional index for previous-section matching (top-level only;
   *   NULL for nested sections, which match by UUID).
   * @param array $previous_state
   *   The recursive previous layout state from buildPreviousLayoutState().
   * @param array $managed_keys
   *   The managed keys for the root entity type + bundle.
   * @param object|null $restriction_storage
   *   The defaults section storage governing restriction answers, or NULL.
   * @param array $seen_uuids
   *   The payload-wide set of uuids already claimed by earlier nodes of this
   *   walk (uuid => TRUE), modified by reference. A repeat is refused with
   *   UUID_IN_USE.
   * @param array $options
   *   Options forwarded to field handlers.
   *
   * @return array{section: \Drupal\layout_builder\Section, errors: \Drupal\daedalus_blueprint\Result\BlueprintError[], warnings: \Drupal\daedalus_blueprint\Result\BlueprintWarning[]}
   *   The built section and collected errors/warnings.
   */
  protected function buildSection(array $section_data, string $path, ?int $section_index, array $previous_state, array $managed_keys, ?object $restriction_storage, array &$seen_uuids, array $options = []): array {
    $errors = [];
    $warnings = [];

    // Restriction enforcement at the spine: the display's allowed layouts
    // govern section nodes at every depth (restructure_layout reaches this
    // path without the structural validator, so the check lives here too).
    $node_layout_id = $section_data['layout_id'] ?? '';
    if (is_string($node_layout_id) && $node_layout_id !== ''
        && $this->placementRestrictions !== NULL
        && !$this->placementRestrictions->layoutAllowed($node_layout_id, $restriction_storage)) {
      $errors[] = new BlueprintError(
        path: "{$path}.layout_id",
        code: 'RESTRICTED_LAYOUT',
        message: "Layout '$node_layout_id' is not allowed for this entity display.",
        context: ['allowed_layouts' => array_keys($this->placementRestrictions->allowedLayoutDefinitions($restriction_storage))],
        hint: 'Pick an allowed layout, or update the display\'s layout restrictions.',
      );
    }

    // Match to previous section for opaque data preservation.
    $previous_section = $this->matchPreviousSection(
      $section_data,
      $section_index,
      $previous_state,
    );

    // Merge section-level opaque data.
    // Layout settings managed keys are nested by layout_id (each layout
    // plugin declares its own set of managed settings), so select the
    // sub-array for this section's layout plugin, matching the
    // inline_block_configuration pattern which nests by block_type.
    // Managed keys are patched: an omitted key (or the whole scope)
    // preserves the previous value; an explicit empty value clears it.
    $layout_id = $section_data['layout_id'];
    $previous_section_array = $previous_section ? $previous_section->toArray() : [];
    $layout_settings_managed = $managed_keys['section_layout_settings'][$layout_id] ?? [];
    $merged_layout_settings = $this->opaqueDataMerger->mergeOptionalScope(
      $previous_section_array['layout_settings'] ?? [],
      $section_data['layout_settings'] ?? NULL,
      $layout_settings_managed,
    );

    // The section's user-facing name lives in layout_settings.label
    // (core-native, uniform at every depth), surfaced as a top-level node
    // key. Omitted preserves the previous label; explicit empty clears it.
    if (array_key_exists('label', $section_data)) {
      $label = $section_data['label'];
      if (is_string($label) && !empty($options['decode_html_entities'])) {
        $label = HtmlEntityDecoder::decode($label);
      }
      if (is_string($label) && $label !== '') {
        $merged_layout_settings['label'] = $label;
      }
      else {
        unset($merged_layout_settings['label']);
      }
    }

    // Preserve third_party_settings from previous section (fully opaque).
    $previous_tps = $previous_section_array['third_party_settings'] ?? [];

    // Build the section.
    $section = new Section(
      $section_data['layout_id'],
      $merged_layout_settings,
      [],
      $previous_tps,
    );

    // Set section UUID: use provided value or auto-generate for daedalus_layout
    // compatibility. daedalus_layout expects every section to have a UUID in
    // third_party_settings; without one, the section is inconsistent
    // until daedalus_layout rebuilds the TreeIndex at render time.
    if (isset($section_data['uuid'])) {
      if (!is_string($section_data['uuid']) || !Uuid::isValid($section_data['uuid'])) {
        $errors[] = BlueprintError::invalidUuid("{$path}.uuid", $section_data['uuid']);
      }
      elseif (isset($seen_uuids[$section_data['uuid']])) {
        // Within-payload repeat: an earlier node of this walk already
        // claimed the uuid. (Matching a section that exists on the entity
        // is legitimate — relocation/round-trip — so only the payload-local
        // set is consulted.)
        $errors[] = BlueprintError::uuidInUse("{$path}.uuid", $section_data['uuid']);
      }
      else {
        $seen_uuids[$section_data['uuid']] = TRUE;
        $this->daedalusLayoutAdapter->setSectionUuid($section, $section_data['uuid']);
      }
    }
    elseif ($this->daedalusLayoutAdapter->isDaedalusLayoutAvailable()) {
      $generated_uuid = (new PhpUuid())->generate();
      $seen_uuids[$generated_uuid] = TRUE;
      $this->daedalusLayoutAdapter->setSectionUuid($section, $generated_uuid);
    }

    // Process components grouped by region. A container section node (see
    // ContainerLayout) carries one ordered `children` list instead: its sole
    // region is a storage vestige, so the list maps onto it. The stored
    // `regions` spelling stays accepted for containers (storage shape,
    // stencils, old payloads); `children` on a multi-region layout is
    // refused rather than guessed into a region, and a node carrying both
    // shapes is ambiguous — refused loudly.
    $component_lists = [];
    foreach ($section_data['regions'] ?? [] as $region_name => $region_components) {
      $component_lists[] = [$region_name, $region_components, "{$path}.regions.{$region_name}"];
    }
    if (array_key_exists('children', $section_data)) {
      $definition = $this->layoutPluginManager?->getDefinition($section_data['layout_id'], FALSE);
      if (!ContainerLayout::isContainer($definition)) {
        $errors[] = new BlueprintError(
          path: "{$path}.children",
          code: 'INVALID_CHILDREN',
          message: "Layout '{$section_data['layout_id']}' is not a container layout, so its components are keyed by region.",
          hint: 'Use regions: {REGION_NAME: [...]}; the ordered children list is the container-section shape.',
        );
      }
      elseif (!empty($section_data['regions'])) {
        $errors[] = new BlueprintError(
          path: "{$path}.children",
          code: 'INVALID_CHILDREN',
          message: 'A section node carries children or regions, never both.',
          hint: 'A container section lists its components in children; drop the regions key.',
        );
      }
      elseif (!is_array($section_data['children']) || !array_is_list($section_data['children'])) {
        $errors[] = new BlueprintError(
          path: "{$path}.children",
          code: 'INVALID_CHILDREN',
          message: 'children is an ordered array of components and nested sections.',
          hint: 'Provide a list, not a map: container sections have no addressable regions.',
        );
      }
      else {
        $component_lists[] = [
          ContainerLayout::storageRegion($definition),
          $section_data['children'],
          "{$path}.children",
        ];
      }
    }
    foreach ($component_lists as [$region_name, $region_components, $list_path]) {
      foreach ($region_components as $comp_index => $comp_data) {
        $comp_path = "{$list_path}[{$comp_index}]";

        // Bare UUID string: relocate an existing component without changes
        // (containers included; the embedded subtree rides in the config).
        if (is_string($comp_data)) {
          $result = $this->relocateElement(
            $comp_data,
            $comp_path,
            $region_name,
            $comp_index,
            $previous_state['componentMap'],
            $seen_uuids,
          );
        }
        elseif (isset($comp_data['layout_id'])) {
          // A region entry carrying layout_id is a nested section node.
          $result = $this->processNestedSection(
            $comp_data,
            $comp_path,
            $region_name,
            $comp_index,
            $previous_state,
            $managed_keys,
            $restriction_storage,
            $seen_uuids,
            $options,
          );
        }
        else {
          $result = $this->processComponent(
            $comp_data,
            $comp_path,
            $region_name,
            $comp_index,
            $previous_state['componentMap'],
            $managed_keys,
            $restriction_storage,
            $seen_uuids,
            $options,
          );
        }
        if ($result['component'] !== NULL) {
          $section->appendComponent($result['component']);
        }
        $errors = array_merge($errors, $result['errors']);
        $warnings = array_merge($warnings, $result['warnings']);
      }
    }

    return [
      'section' => $section,
      'errors' => $errors,
      'warnings' => $warnings,
    ];
  }

  /**
   * Processes a nested section node into a container component.
   *
   * Builds the Section recursively through the same buildSection() path as
   * top-level nodes, then wraps it in a nested-section container component
   * (component uuid == section uuid). Opaque configuration and additional
   * data from the previous container are preserved; the embedded section is
   * structural and always rebuilt.
   *
   * @param array $comp_data
   *   The nested section node.
   * @param string $comp_path
   *   The error path prefix.
   * @param string $region
   *   The parent region.
   * @param int $weight
   *   The component weight (array position within region).
   * @param array $previous_state
   *   The recursive previous layout state.
   * @param array $managed_keys
   *   The managed keys.
   * @param object|null $restriction_storage
   *   The defaults section storage governing restriction answers, or NULL.
   * @param array $seen_uuids
   *   The payload-wide seen-uuid set (see buildSection), modified by
   *   reference. The nested node's uuid is claimed by the recursive
   *   buildSection call; the container component reuses that same identity,
   *   so it is not registered twice.
   * @param array $options
   *   Options forwarded to field handlers.
   *
   * @return array{component: ?\Drupal\layout_builder\SectionComponent, errors: \Drupal\daedalus_blueprint\Result\BlueprintError[], warnings: \Drupal\daedalus_blueprint\Result\BlueprintWarning[]}
   *   The container component and collected errors/warnings.
   */
  protected function processNestedSection(array $comp_data, string $comp_path, string $region, int $weight, array $previous_state, array $managed_keys, ?object $restriction_storage, array &$seen_uuids, array $options = []): array {
    if (!$this->daedalusLayoutAdapter->isDaedalusLayoutAvailable()) {
      return [
        'component' => NULL,
        'errors' => [
          new BlueprintError(
            path: $comp_path,
            code: 'NESTING_UNAVAILABLE',
            message: 'Nested sections require the daedalus_layout module.',
            hint: 'Place the section at the top level, or install daedalus_layout.',
          ),
        ],
        'warnings' => [],
      ];
    }

    $result = $this->buildSection(
      $comp_data,
      $comp_path,
      NULL,
      $previous_state,
      $managed_keys,
      $restriction_storage,
      $seen_uuids,
      $options,
    );
    $section = $result['section'];

    // Preserve additional data and opaque (non-structural, non-managed)
    // configuration from the previous container, matched by section uuid ==
    // component uuid.
    $section_uuid = $this->daedalusLayoutAdapter->getSectionUuid($section);
    $previous_component = $section_uuid !== NULL
      ? ($previous_state['componentMap'][$section_uuid] ?? NULL)
      : NULL;
    $previous_additional = [];
    $opaque_config = [];
    if ($previous_component !== NULL) {
      $previous_additional = $previous_component->toArray()['additional'] ?? [];
      $opaque_config = array_diff_key(
        $previous_component->get('configuration'),
        static::STRUCTURAL_CONFIG_KEYS,
      );
    }

    $component = $this->daedalusLayoutAdapter->createSectionComponent($section, $region, $weight, $previous_additional);
    if (!empty($opaque_config)) {
      // Structural keys (fresh) win over preserved opaque keys.
      $component->setConfiguration(array_merge($opaque_config, $component->get('configuration')));
    }

    return [
      'component' => $component,
      'errors' => $result['errors'],
      'warnings' => $result['warnings'],
    ];
  }

  /**
   * Builds the previous layout state from the entity's current layout.
   *
   * Walks the layout recursively: nested sections embedded in container
   * components are indexed by UUID alongside top-level sections, and their
   * components join the component map, so incoming nodes at any depth match
   * their previous state.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity.
   *
   * @return array{componentMap: array<string, \Drupal\layout_builder\SectionComponent>, sections: \Drupal\layout_builder\Section[], sectionsByUuid: array<string, \Drupal\layout_builder\Section>}
   *   The previous layout state.
   */
  protected function buildPreviousLayoutState(FieldableEntityInterface $entity): array {
    $state = ['componentMap' => [], 'sections' => [], 'sectionsByUuid' => []];

    if ($entity->hasField('layout_builder__layout')) {
      $field = $entity->get('layout_builder__layout');
      if (!$field->isEmpty()) {
        foreach ($field as $item) {
          $state['sections'][] = $item->section;
          $this->indexPreviousSection($item->section, $state);
        }
      }
    }

    return $state;
  }

  /**
   * Indexes a previous section (and its embedded subtree) into the state.
   *
   * @param \Drupal\layout_builder\Section $section
   *   The section to index.
   * @param array $state
   *   The state array, modified by reference.
   */
  protected function indexPreviousSection(Section $section, array &$state): void {
    $uuid = $this->daedalusLayoutAdapter->getSectionUuid($section);
    if ($uuid !== NULL) {
      $state['sectionsByUuid'][$uuid] = $section;
    }
    foreach ($section->getComponents() as $component) {
      $state['componentMap'][$component->getUuid()] = $component;
      $embedded = $this->daedalusLayoutAdapter->embeddedSection($component);
      if ($embedded !== NULL) {
        $this->indexPreviousSection($embedded, $state);
      }
    }
  }

  /**
   * Matches an incoming section node to a previous section.
   *
   * Matches by section UUID (from daedalus_layout third-party settings) at any
   * depth. Falls back to positional matching with layout_id confirmation for
   * top-level nodes.
   *
   * @param array $section_data
   *   The incoming section data.
   * @param int|null $section_index
   *   The positional index in the incoming layout (top-level only).
   * @param array $previous_state
   *   The previous layout state.
   *
   * @return \Drupal\layout_builder\Section|null
   *   The matched previous section, or NULL if no match.
   */
  protected function matchPreviousSection(array $section_data, ?int $section_index, array $previous_state): ?Section {
    // Match by UUID first (daedalus_layout feature).
    if (isset($section_data['uuid']) && is_string($section_data['uuid'])) {
      $match = $previous_state['sectionsByUuid'][$section_data['uuid']] ?? NULL;
      if ($match !== NULL) {
        return $match;
      }
    }

    // Fall back to positional + layout_id confirmation.
    if ($section_index !== NULL && isset($previous_state['sections'][$section_index])) {
      $previous_layout_id = $previous_state['sections'][$section_index]->getLayoutId();
      if ($previous_layout_id === ($section_data['layout_id'] ?? '')) {
        return $previous_state['sections'][$section_index];
      }
    }

    return NULL;
  }

  /**
   * Relocates an existing component to a new region and weight.
   *
   * Preserves all configuration and additional data from the previous
   * component. Used when a region entry is a bare UUID string rather
   * than a component object.
   *
   * @param string $uuid
   *   The UUID of the existing component.
   * @param string $comp_path
   *   The error path prefix for this component.
   * @param string $region
   *   The target region.
   * @param int $weight
   *   The component weight (array position within region).
   * @param array $previous_component_map
   *   Map of UUID to previous SectionComponent objects.
   * @param array $seen_uuids
   *   The payload-wide seen-uuid set (see buildSection), modified by
   *   reference. The same existing component relocated twice in one payload
   *   is a repeat, refused with UUID_IN_USE.
   *
   * @return array{component: ?\Drupal\layout_builder\SectionComponent, errors: \Drupal\daedalus_blueprint\Result\BlueprintError[], warnings: \Drupal\daedalus_blueprint\Result\BlueprintWarning[]}
   *   The relocated component, or errors if the UUID was not found.
   */
  protected function relocateElement(string $uuid, string $comp_path, string $region, int $weight, array $previous_component_map, array &$seen_uuids): array {
    if (isset($seen_uuids[$uuid])) {
      return [
        'component' => NULL,
        'errors' => [BlueprintError::uuidInUse($comp_path, $uuid)],
        'warnings' => [],
      ];
    }
    if (!isset($previous_component_map[$uuid])) {
      return [
        'component' => NULL,
        'errors' => [
          new BlueprintError(
            path: $comp_path,
            code: 'ELEMENT_NOT_FOUND',
            message: "Element UUID '$uuid' does not exist in the current layout.",
            hint: 'Check the UUID against the entity summary.',
          ),
        ],
        'warnings' => [],
      ];
    }

    $seen_uuids[$uuid] = TRUE;

    $previous = $previous_component_map[$uuid];
    $config = $previous->get('configuration');
    $previous_array = $previous->toArray();
    $additional = $previous_array['additional'] ?? [];

    $component = new SectionComponent($uuid, $region, $config, $additional);
    $component->setWeight($weight);

    return ['component' => $component, 'errors' => [], 'warnings' => []];
  }

  /**
   * Processes a single component from the incoming layout.
   *
   * Determines lifecycle (create/update) based on UUID matching with
   * the previous layout state.
   *
   * @param array $comp_data
   *   The component data from the incoming JSON.
   * @param string $comp_path
   *   The error path prefix for this component.
   * @param string $region
   *   The region this component belongs to.
   * @param int $weight
   *   The component weight (array position within region).
   * @param array $previous_component_map
   *   Map of UUID to previous SectionComponent objects.
   * @param array $managed_keys
   *   The managed keys for this entity type and bundle.
   * @param object|null $restriction_storage
   *   The defaults section storage governing restriction answers, or NULL.
   * @param array $seen_uuids
   *   The payload-wide seen-uuid set (see buildSection), modified by
   *   reference.
   * @param array $options
   *   Options forwarded to field handlers.
   *
   * @return array{component: ?\Drupal\layout_builder\SectionComponent, errors: \Drupal\daedalus_blueprint\Result\BlueprintError[], warnings: \Drupal\daedalus_blueprint\Result\BlueprintWarning[]}
   *   The processed component, errors, and warnings.
   */
  protected function processComponent(array $comp_data, string $comp_path, string $region, int $weight, array $previous_component_map, array $managed_keys, ?object $restriction_storage, array &$seen_uuids, array $options = []): array {
    if (isset($comp_data['uuid']) && (!is_string($comp_data['uuid']) || !Uuid::isValid($comp_data['uuid']))) {
      return [
        'component' => NULL,
        'errors' => [BlueprintError::invalidUuid("$comp_path.uuid", $comp_data['uuid'])],
        'warnings' => [],
      ];
    }
    // Within-payload repeat: format precedence preserved (checked above),
    // then the payload-local claim. Matching a component that exists on the
    // entity is the legitimate update path and stays untouched.
    if (isset($comp_data['uuid']) && isset($seen_uuids[$comp_data['uuid']])) {
      return [
        'component' => NULL,
        'errors' => [BlueprintError::uuidInUse("$comp_path.uuid", $comp_data['uuid'])],
        'warnings' => [],
      ];
    }
    $uuid = $comp_data['uuid'] ?? (new PhpUuid())->generate();
    $seen_uuids[$uuid] = TRUE;
    $block_type = $comp_data['block_type'] ?? NULL;
    $plugin_id = $comp_data['plugin_id'] ?? NULL;

    if ($block_type !== NULL) {
      return $this->processInlineBlock(
        $comp_data, $uuid, $region, $weight, $block_type,
        $comp_path, $previous_component_map, $managed_keys,
        $restriction_storage, $options,
      );
    }

    if ($plugin_id !== NULL) {
      return $this->processNonInlineComponent(
        $comp_data, $uuid, $region, $weight, $plugin_id,
        $comp_path, $previous_component_map, $managed_keys,
        $restriction_storage,
      );
    }

    // Neither block_type nor plugin_id — skip.
    return ['component' => NULL, 'errors' => [], 'warnings' => []];
  }

  /**
   * Processes an inline block component (create or update).
   *
   * For updates (UUID matches previous): loads existing block_content,
   * applies field changes, serializes back. For creates (no match):
   * creates new block_content, applies fields, serializes.
   *
   * @param array $comp_data
   *   The component data from incoming JSON.
   * @param string $uuid
   *   The component UUID.
   * @param string $region
   *   The layout region.
   * @param int $weight
   *   The component weight.
   * @param string $block_type
   *   The block content type.
   * @param string $comp_path
   *   The error path prefix.
   * @param array $previous_component_map
   *   Map of UUID to previous SectionComponent objects.
   * @param array $managed_keys
   *   The managed keys.
   * @param object|null $restriction_storage
   *   The defaults section storage governing restriction answers, or NULL.
   * @param array $options
   *   Options forwarded to field handlers.
   *
   * @return array{component: ?\Drupal\layout_builder\SectionComponent, errors: \Drupal\daedalus_blueprint\Result\BlueprintError[], warnings: \Drupal\daedalus_blueprint\Result\BlueprintWarning[]}
   *   The processed component, errors, and warnings.
   */
  protected function processInlineBlock(array $comp_data, string $uuid, string $region, int $weight, string $block_type, string $comp_path, array $previous_component_map, array $managed_keys, ?object $restriction_storage, array $options = []): array {
    // Validate block type exists.
    $block_type_entity = $this->entityTypeManager->getStorage('block_content_type')->load($block_type);
    if ($block_type_entity === NULL) {
      return [
        'component' => NULL,
        'errors' => [
          new BlueprintError(
            path: $comp_path . '.block_type',
            code: 'INVALID_BLOCK_TYPE',
            message: "Block type '$block_type' does not exist.",
            hint: 'Check the schema for available block types.',
          ),
        ],
        'warnings' => [],
      ];
    }

    // Restriction enforcement at the spine.
    if ($this->placementRestrictions !== NULL
        && !$this->placementRestrictions->blockAllowed('inline_block:' . $block_type, $restriction_storage)) {
      return [
        'component' => NULL,
        'errors' => [
          new BlueprintError(
            path: $comp_path . '.block_type',
            code: 'RESTRICTED_BLOCK_TYPE',
            message: "Block type '$block_type' is not allowed for this entity display.",
            context: ['allowed_block_types' => $this->placementRestrictions->allowedInlineBlockBundles($restriction_storage)],
            hint: 'Check the schema for available block types, or update the display\'s block restrictions.',
          ),
        ],
        'warnings' => [],
      ];
    }

    $errors = [];
    $warnings = [];
    $previous_component = $previous_component_map[$uuid] ?? NULL;

    if ($previous_component !== NULL) {
      // Update: load existing block_content from previous component.
      $block = $this->daedalusLayoutAdapter->extractBlockContent($previous_component);
      if ($block === NULL) {
        $errors[] = new BlueprintError(
          path: $comp_path,
          code: 'BLOCK_CONTENT_NOT_FOUND',
          message: "Could not load block_content for component '$uuid'.",
          hint: 'The previous inline block entity could not be extracted.',
        );
        return ['component' => NULL, 'errors' => $errors, 'warnings' => $warnings];
      }
    }
    else {
      // Create: new block_content entity.
      $storage = $this->entityTypeManager->getStorage('block_content');
      $block = $storage->create([
        'type' => $block_type,
        'reusable' => FALSE,
      ]);
    }

    // Blocks never carry nested layouts: sections nest inside sections
    // (a nested section is a section node in a region), and the old
    // layout-capable-block shape is gone. Refuse loudly rather than
    // silently dropping the subtree.
    if (isset($comp_data['layout_builder__layout'])) {
      return [
        'component' => NULL,
        'errors' => [
          new BlueprintError(
            path: $comp_path . '.layout_builder__layout',
            code: 'NO_LAYOUT',
            message: 'Blocks do not hold nested layouts.',
            hint: 'Sections nest inside sections: place a section node (layout_id + regions) directly in the parent region instead of wrapping it in a block.',
          ),
        ],
        'warnings' => [],
      ];
    }

    // Apply block_fields via FieldHandlers.
    $block_fields = $comp_data['block_fields'] ?? [];
    $field_result = $this->applyBlockFields($block, $block_fields, $comp_path, [
      'entity' => $block,
      'element_uuid' => $uuid,
    ] + $options);
    $errors = array_merge($errors, $field_result['errors']);
    $warnings = array_merge($warnings, $field_result['warnings']);

    // The component display label (`configuration['label']`) is layout config —
    // for many block types (e.g. headings) it is the rendered text. It is NOT
    // the block_content `info` field, which is written only through the gated
    // field pipeline (applyBlockFields, above). See RF2 decisions in
    // notes/entity-access.md.
    $label = $comp_data['label'] ?? '';
    // The label is plain text (for heading-style blocks, the rendered text),
    // so it gets the same LLM entity-escape defense as plain-text fields.
    if (!empty($options['decode_html_entities']) && is_string($label)) {
      $label = HtmlEntityDecoder::decode($label);
    }

    // `info` is required (NOT NULL). On create the block is fresh, so seed it
    // if the field pipeline did not. On update the block was loaded with its
    // existing `info`, so this preserves it and `label` never overwrites it.
    if ($block->get('info')->isEmpty()) {
      $block->setInfo($label !== '' ? $label : $block_type);
    }

    // Build InlineBlock plugin configuration.
    // The block is serialized AFTER nested layout processing, so the
    // serialized form includes the updated nested layout sections.
    $block_view_mode = $comp_data['view_mode'] ?? 'full';
    $configuration = [
      'id' => 'inline_block:' . $block_type,
      'provider' => 'layout_builder',
      'label' => $label,
      'label_display' => '0',
      'view_mode' => $block_view_mode,
      'block_serialized' => serialize($block),
    ];

    // Merge managed inline block configuration.
    // Managed keys are patched: an omitted key (or the whole scope) preserves
    // the previous value — relocating or re-declaring a block structurally
    // never wipes its styling. An explicit empty value clears the key.
    $inline_managed = $managed_keys['inline_block_configuration'][$block_type] ?? [];
    $previous_config = $previous_component
      ? array_diff_key($previous_component->get('configuration'), static::STRUCTURAL_CONFIG_KEYS)
      : [];
    $incoming_config = $comp_data['configuration'] ?? NULL;
    $merged = $this->opaqueDataMerger->mergeOptionalScope($previous_config, $incoming_config, $inline_managed);
    $configuration = array_merge($configuration, $merged);

    // Preserve additional data from previous component (fully opaque).
    $previous_additional = [];
    if ($previous_component !== NULL) {
      $previous_component_array = $previous_component->toArray();
      $previous_additional = $previous_component_array['additional'] ?? [];
    }

    $component = new SectionComponent($uuid, $region, $configuration, $previous_additional);
    $component->setWeight($weight);

    return ['component' => $component, 'errors' => $errors, 'warnings' => $warnings];
  }

  /**
   * Processes a non-inline (plugin) component.
   *
   * Builds a SectionComponent from plugin_id. Configuration and additional
   * data are fully opaque: preserved from the previous component, or minimal
   * defaults for creates.
   *
   * @param array $comp_data
   *   The component data from incoming JSON.
   * @param string $uuid
   *   The component UUID.
   * @param string $region
   *   The layout region.
   * @param int $weight
   *   The component weight.
   * @param string $plugin_id
   *   The block plugin ID.
   * @param string $comp_path
   *   The error path prefix.
   * @param array $previous_component_map
   *   Map of UUID to previous SectionComponent objects.
   * @param array $managed_keys
   *   The managed keys.
   * @param object|null $restriction_storage
   *   The defaults section storage governing restriction answers, or NULL.
   *
   * @return array{component: ?\Drupal\layout_builder\SectionComponent, errors: array, warnings: array}
   *   The processed component and errors.
   */
  protected function processNonInlineComponent(array $comp_data, string $uuid, string $region, int $weight, string $plugin_id, string $comp_path, array $previous_component_map, array $managed_keys, ?object $restriction_storage): array {
    // Restriction enforcement at the spine, same answer as inline blocks
    // (display-level here — see deserialize()). Existence distinguishes
    // INVALID from RESTRICTED.
    if ($this->placementRestrictions !== NULL
        && !$this->placementRestrictions->blockAllowed($plugin_id, $restriction_storage)) {
      $exists = $this->placementRestrictions->blockExists($plugin_id);
      return [
        'component' => NULL,
        'errors' => [
          new BlueprintError(
            path: $comp_path . '.plugin_id',
            code: $exists ? 'RESTRICTED_PLUGIN_ID' : 'INVALID_PLUGIN_ID',
            message: $exists
              ? "Block plugin '$plugin_id' is not allowed for this entity display."
              : "Block plugin '$plugin_id' does not exist.",
            hint: $exists
              ? 'Pick an allowed block plugin, or update the display\'s block restrictions.'
              : 'Check the block plugin ID.',
          ),
        ],
        'warnings' => [],
      ];
    }

    $previous_component = $previous_component_map[$uuid] ?? NULL;

    // Non-inline component configuration is fully opaque — preserve previous
    // or use minimal config for creates.
    if ($previous_component !== NULL) {
      $configuration = $previous_component->get('configuration');
    }
    else {
      $configuration = ['id' => $plugin_id];
    }
    // Ensure plugin id is always correct.
    $configuration['id'] = $plugin_id;

    // Preserve additional data from previous component (fully opaque).
    $previous_additional = [];
    if ($previous_component !== NULL) {
      $previous_component_array = $previous_component->toArray();
      $previous_additional = $previous_component_array['additional'] ?? [];
    }

    $component = new SectionComponent($uuid, $region, $configuration, $previous_additional);
    $component->setWeight($weight);

    return ['component' => $component, 'errors' => [], 'warnings' => []];
  }

  /**
   * Applies block_fields to a block_content entity via FieldHandlers.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $block
   *   The block_content entity.
   * @param array $block_fields
   *   The field values to apply.
   * @param string $comp_path
   *   The error path prefix for the parent component.
   * @param array $options
   *   Options forwarded to field handlers.
   *
   * @return array{errors: \Drupal\daedalus_blueprint\Result\BlueprintError[], warnings: \Drupal\daedalus_blueprint\Result\BlueprintWarning[]}
   *   Errors and warnings collected during field processing.
   */
  protected function applyBlockFields(FieldableEntityInterface $block, array $block_fields, string $comp_path, array $options = []): array {
    $errors = [];
    $warnings = [];
    $processable_fields = $this->fieldHandlerManager->getProcessableFieldDefinitions($block);

    foreach ($block_fields as $field_name => $value) {
      if (!isset($processable_fields[$field_name])) {
        continue;
      }

      // A form-only stub (FULL-mode structure completeness) is identity,
      // not data: a FULL blueprint echoed back must never write the stub
      // into the field, the same PATCH semantics as an omitted key.
      if (FormOnlyStub::isStub($value)) {
        continue;
      }

      // Per-field edit access on the inline block's own field. Inline blocks
      // are owned by the layout and have no independent entity-level access, so
      // field access is the enforcement surface. Denial errors rather than
      // silently dropping the field.
      if (!$this->accessChecker->fieldEditable($block->get($field_name))) {
        $errors[] = new BlueprintError(
          path: "{$comp_path}.block_fields.{$field_name}",
          code: 'ACCESS_DENIED_FIELD',
          message: "Access denied: cannot edit field '$field_name'.",
          hint: 'The current user lacks permission to modify this field.',
        );
        continue;
      }

      $definition = $processable_fields[$field_name];
      $handler = $this->fieldHandlerManager->getHandler($definition);
      $handler_result = $handler->deserialize($value, $definition, $options);

      if ($handler_result->hasErrors()) {
        foreach ($handler_result->getErrors() as $error) {
          $original_path = $error->getPath();
          if (str_starts_with($original_path, 'fields.')) {
            $rewritten_path = $comp_path . '.block_fields.' . substr($original_path, 7);
          }
          else {
            $rewritten_path = $comp_path . '.block_fields.' . $field_name;
          }
          $errors[] = $error->withPath($rewritten_path);
        }
      }
      else {
        $block->set($field_name, $handler_result->getValue());
      }

      array_push($warnings, ...$handler_result->getWarnings());
    }

    return ['errors' => $errors, 'warnings' => $warnings];
  }

  /**
   * Replaces an entity's layout sections.
   *
   * Clears all existing sections and appends the new ones.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity.
   * @param \Drupal\layout_builder\Section[] $sections
   *   The new sections to set.
   */
  protected function setSectionsOnEntity(FieldableEntityInterface $entity, array $sections): void {
    // Clear existing layout.
    $entity->set('layout_builder__layout', []);

    // Append new sections.
    $field = $entity->get('layout_builder__layout');
    foreach ($sections as $section) {
      $field->appendSection($section);
    }
  }

}
