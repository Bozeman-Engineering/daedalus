<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Validator;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Layout\LayoutPluginManagerInterface;
use Drupal\daedalus_blueprint\FieldHandler\FieldHandlerBase;
use Drupal\daedalus_blueprint\FieldHandler\FieldHandlerManager;
use Drupal\daedalus_blueprint\LayoutBuilder\AvailableLayouts;
use Drupal\daedalus_blueprint\LayoutBuilder\ContainerLayout;
use Drupal\daedalus_blueprint\LayoutBuilder\PlacementRestrictionsInterface;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_blueprint\Utility\FormOnlyStub;

/**
 * Phase A structural validator for entity blueprints.
 *
 * Validates blueprint JSON structure against Drupal's entity/field type
 * system. Catches schema-level problems (nonexistent types, unknown fields,
 * wrong value formats) before the more expensive semantic validation phase.
 *
 * The blueprint grammar reserves the underscore prefix for its own
 * vocabulary, which a Drupal field or property name never carries:
 * "_root_entity" at the root, form-only stubs at the field level, and on
 * field items the keys a handler speaks beyond the storage properties
 * (the reference handlers' "_label", the link handler's read-only
 * "_displayable"). Phase A admits that vocabulary wherever it appears and
 * leaves its meaning to the owner (the deserializer for the root marker,
 * the handler for item keys in Phase B), so the serializer's own output
 * always passes this phase.
 */
class StructuralValidator {

  /**
   * Constructs a StructuralValidator.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $bundleInfo
   *   The bundle info service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   * @param \Drupal\daedalus_blueprint\FieldHandler\FieldHandlerManager $fieldHandlerManager
   *   The field handler manager.
   * @param \Drupal\Core\Layout\LayoutPluginManagerInterface|null $layoutPluginManager
   *   The layout plugin manager (NULL when layout_discovery is not installed).
   * @param \Drupal\daedalus_blueprint\LayoutBuilder\PlacementRestrictionsInterface|null $placementRestrictions
   *   The placement-restriction spine (NULL for no restriction enforcement,
   *   e.g. in test isolation).
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected FieldHandlerManager $fieldHandlerManager,
    protected ?LayoutPluginManagerInterface $layoutPluginManager = NULL,
    protected ?PlacementRestrictionsInterface $placementRestrictions = NULL,
  ) {}

  /**
   * Validates the structural integrity of a blueprint data array.
   *
   * Returns early if entity type or bundle are invalid since field
   * validation depends on them.
   *
   * @param array $data
   *   The blueprint data with keys: entity_type, bundle, fields.
   * @param string $view_mode
   *   The view mode for layout_builder_restrictions enforcement.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Structural errors. Empty if valid.
   */
  public function validate(array $data, string $view_mode = 'full'): array {
    $entity_type = $data['entity_type'] ?? NULL;
    $errors = $this->validateEntityType($entity_type);
    if (!empty($errors)) {
      return $errors;
    }

    $bundle = $data['bundle'] ?? NULL;
    $errors = $this->validateBundle($entity_type, $bundle);
    if (!empty($errors)) {
      return $errors;
    }

    $all_errors = [];

    // Reject unknown top-level keys. Entity field values must live inside the
    // "fields" object; a misplaced field name here (e.g. "title") would
    // otherwise be silently dropped and surface later as a confusing
    // "value should not be null" constraint violation instead of a clear
    // placement error.
    foreach (array_diff(array_keys($data), static::BLUEPRINT_ROOT_KEYS) as $unknown_key) {
      $all_errors[] = new BlueprintError(
        path: (string) $unknown_key,
        code: 'UNKNOWN_KEY',
        message: "Unknown top-level key '$unknown_key'.",
        context: ['expected' => static::BLUEPRINT_ROOT_KEYS],
        hint: "Entity field values belong inside the \"fields\" object, e.g. {\"fields\": {\"$unknown_key\": ...}}. Only \"layout_builder__layout\" sits at the top level.",
      );
    }

    $fields = $data['fields'] ?? [];
    if (is_array($fields)) {
      $all_errors = array_merge($all_errors, $this->validateFieldSet(
        $entity_type, $bundle, $fields, 'fields', "$entity_type:$bundle",
      ));
    }

    // Validate layout_builder__layout if present. Restriction answers are
    // display-level here (whole-layout writes stage sections that do not
    // exist in storage yet, so per-region providers cannot be consulted;
    // targeted ops enforce those at op time).
    $layout = $data['layout_builder__layout'] ?? NULL;
    if ($layout !== NULL && is_array($layout)) {
      $storage = $this->placementRestrictions?->defaultsStorage($entity_type, $bundle, $view_mode);
      $all_errors = array_merge($all_errors, $this->validateLayout($layout, $storage));
    }

    return $all_errors;
  }

  /**
   * Validates the entity type exists and is fieldable.
   *
   * @param mixed $entity_type
   *   The entity type ID from the blueprint.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Errors if invalid.
   */
  protected function validateEntityType(mixed $entity_type): array {
    if ($entity_type === NULL || $entity_type === '') {
      return [
        new BlueprintError(
          path: 'entity_type',
          code: 'INVALID_ENTITY_TYPE',
          message: 'Entity type is required.',
          context: ['available' => $this->getFieldableEntityTypes()],
          hint: 'Provide a valid entity type.',
        ),
      ];
    }

    if (!$this->entityTypeManager->hasDefinition($entity_type)) {
      return [
        new BlueprintError(
          path: 'entity_type',
          code: 'INVALID_ENTITY_TYPE',
          message: "Entity type '$entity_type' does not exist.",
          context: ['available' => $this->getFieldableEntityTypes()],
          hint: 'Check spelling or use one of the available entity types.',
        ),
      ];
    }

    $definition = $this->entityTypeManager->getDefinition($entity_type);
    if (!$definition->entityClassImplements(FieldableEntityInterface::class)) {
      return [
        new BlueprintError(
          path: 'entity_type',
          code: 'INVALID_ENTITY_TYPE',
          message: "Entity type '$entity_type' is not fieldable.",
          context: ['available' => $this->getFieldableEntityTypes()],
          hint: 'Only fieldable entity types can be used with blueprints.',
        ),
      ];
    }

    return [];
  }

  /**
   * Validates the bundle exists for the entity type.
   *
   * @param string $entity_type
   *   The validated entity type ID.
   * @param mixed $bundle
   *   The bundle from the blueprint.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Errors if invalid.
   */
  protected function validateBundle(string $entity_type, mixed $bundle): array {
    $bundles = $this->bundleInfo->getBundleInfo($entity_type);
    $available_bundles = array_keys($bundles);

    if ($bundle === NULL || $bundle === '') {
      return [
        new BlueprintError(
          path: 'bundle',
          code: 'INVALID_BUNDLE',
          message: "Bundle is required for entity type '$entity_type'.",
          context: ['available' => $available_bundles],
          hint: 'Provide a valid bundle.',
        ),
      ];
    }

    if (!isset($bundles[$bundle])) {
      return [
        new BlueprintError(
          path: 'bundle',
          code: 'INVALID_BUNDLE',
          message: "Bundle '$bundle' does not exist on entity type '$entity_type'.",
          context: ['available' => $available_bundles],
          hint: 'Check spelling or use one of the available bundles.',
        ),
      ];
    }

    return [];
  }

  /**
   * Field types that use inline entity format.
   *
   * These fields serialize/deserialize full entity structures instead of
   * property-based values, so they need specialized format validation.
   *
   * @var string[]
   */
  protected const INLINE_ENTITY_FIELD_TYPES = [
    'entity_reference_revisions',
  ];

  /**
   * Valid keys in an inline entity item's JSON structure.
   *
   * @var string[]
   */
  protected const INLINE_ENTITY_KEYS = [
    'target_id',
    'uuid',
    'type',
    'fields',
  ];

  /**
   * Valid top-level keys in a blueprint's JSON structure.
   *
   * Entity field values belong inside the "fields" object. The only field
   * hoisted to the top level is "layout_builder__layout" (handled by the
   * layout deserializer, not a field handler). "entity_type"/"bundle"/
   * "langcode" are addressing metadata; "_root_entity" is an internal marker
   * set when building a sub-entity blueprint for an operation.
   *
   * @var string[]
   */
  protected const BLUEPRINT_ROOT_KEYS = [
    'entity_type',
    'bundle',
    'langcode',
    'fields',
    'layout_builder__layout',
    '_root_entity',
  ];

  /**
   * Validates the format of a field value against its definition.
   *
   * For multi-value fields with a sequential array, validates each item.
   * For single values, validates the item directly.
   *
   * @param string $field_name
   *   The field machine name.
   * @param mixed $value
   *   The submitted value.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Format errors.
   */
  protected function validateFieldFormat(string $field_name, mixed $value, FieldDefinitionInterface $definition): array {
    if ($value === NULL) {
      return [];
    }

    $info = $this->fieldHandlerManager->inspectFieldDefinition($definition);
    $cardinality = $info['cardinality'];

    // Inline entity fields use specialized validation.
    if (in_array($info['type'], static::INLINE_ENTITY_FIELD_TYPES, TRUE)) {
      return $this->validateInlineEntityField($field_name, $value, $info, $cardinality);
    }

    // Multi-value field with sequential array: validate each item.
    if ($cardinality !== 1 && is_array($value) && !empty($value) && array_is_list($value)) {
      $errors = [];
      foreach ($value as $index => $item) {
        $item_errors = $this->validateFieldItemFormat(
          "fields.{$field_name}[$index]",
          $item,
          $info,
        );
        $errors = array_merge($errors, $item_errors);
      }
      return $errors;
    }

    return $this->validateFieldItemFormat("fields.$field_name", $value, $info);
  }

  /**
   * Validates a single field item's format against field properties.
   *
   * Checks that associative array keys match the field's known properties.
   * Underscore-prefixed keys are handler vocabulary, not storage properties
   * (see the class docblock): they are admitted here and owned by the
   * handler in Phase B, which resolves the write-accepted ones ("_label")
   * and strips the read-only ones ("_displayable"), so a read payload
   * spread back as a write passes this check.
   *
   * @param string $path
   *   The error path.
   * @param mixed $value
   *   The item value.
   * @param array $info
   *   Field introspection info from inspectFieldDefinition().
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Format errors.
   */
  protected function validateFieldItemFormat(string $path, mixed $value, array $info): array {
    if ($value === NULL || is_scalar($value)) {
      return [];
    }

    if (!is_array($value) || empty($value)) {
      return [];
    }

    // Associative array: validate property names for compound fields.
    if (!array_is_list($value)) {
      $properties = $info['properties'] ?? [];
      if (!empty($properties)) {
        $keys = array_filter(
          array_keys($value),
          static fn ($key) => !is_string($key) || !str_starts_with($key, '_'),
        );
        $unknown_keys = array_diff($keys, $properties);
        if (!empty($unknown_keys)) {
          return [
            new BlueprintError(
              path: $path,
              code: 'INVALID_FIELD_FORMAT',
              message: "Unknown properties: " . implode(', ', $unknown_keys) . ".",
              context: [
                'unknown' => array_values($unknown_keys),
                'expected' => $properties,
              ],
              hint: 'Use only valid property names for this field type.',
            ),
          ];
        }
      }
    }

    return [];
  }

  /**
   * Validates an inline entity field's value format.
   *
   * Handles both single-value and multi-value inline entity fields.
   * Validates bundle existence and nested field names recursively.
   *
   * @param string $field_name
   *   The field machine name.
   * @param mixed $value
   *   The submitted value.
   * @param array $info
   *   Field introspection info from inspectFieldDefinition().
   * @param int $cardinality
   *   The field cardinality.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Format errors.
   */
  protected function validateInlineEntityField(string $field_name, mixed $value, array $info, int $cardinality): array {
    [$items] = FieldHandlerBase::normalizeItems($value, $field_name, $cardinality);

    $target_type = $info['settings']['target_type'] ?? NULL;
    $target_bundles = $info['settings']['target_bundles'] ?? NULL;

    $errors = [];
    foreach ($items as $path => $item) {
      $item_errors = $this->validateInlineEntityItem($path, $item, $target_type, $target_bundles);
      $errors = array_merge($errors, $item_errors);
    }

    return $errors;
  }

  /**
   * Validates a single inline entity item.
   *
   * Checks structure (must be an object), bundle validity, and nested field
   * names. Recursively validates nested inline entity fields.
   *
   * @param string $path
   *   The error path.
   * @param mixed $item
   *   The item value.
   * @param string|null $target_type
   *   The target entity type.
   * @param array|null $target_bundles
   *   Allowed bundles, or NULL for any.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Errors for this item.
   */
  protected function validateInlineEntityItem(string $path, mixed $item, ?string $target_type, ?array $target_bundles): array {
    if (!is_array($item) || array_is_list($item)) {
      return [
        new BlueprintError(
          path: $path,
          code: 'INVALID_FIELD_FORMAT',
          message: 'Inline entity must be an object with type and fields.',
          hint: 'Provide {type: "bundle", fields: {...}} for new or {target_id: N, fields: {...}} for update.',
        ),
      ];
    }

    // Check for unknown keys.
    $unknown_keys = array_diff(array_keys($item), static::INLINE_ENTITY_KEYS);
    if (!empty($unknown_keys)) {
      return [
        new BlueprintError(
          path: $path,
          code: 'INVALID_FIELD_FORMAT',
          message: "Unknown keys: " . implode(', ', $unknown_keys) . ".",
          context: [
            'unknown' => array_values($unknown_keys),
            'expected' => static::INLINE_ENTITY_KEYS,
          ],
          hint: 'Inline entities accept: target_id, uuid, type, fields.',
        ),
      ];
    }

    // Must have either target_id (update) or type (create).
    $has_target_id = isset($item['target_id']);
    $has_type = isset($item['type']);

    if (!$has_target_id && !$has_type) {
      return [
        new BlueprintError(
          path: $path,
          code: 'INVALID_FIELD_FORMAT',
          message: 'Inline entity must include target_id (update) or type (create).',
          hint: 'Provide {type: "bundle", fields: {...}} for new or {target_id: N, fields: {...}} for update.',
        ),
      ];
    }

    // Validate bundle if present.
    if ($has_type && $target_type) {
      $bundle = $item['type'];
      $errors = $this->validateInlineBundle($path, $bundle, $target_type, $target_bundles);
      if (!empty($errors)) {
        return $errors;
      }

      // Validate nested fields if present.
      if (!empty($item['fields']) && is_array($item['fields'])) {
        return $this->validateFieldSet(
          $target_type, $bundle, $item['fields'], "$path.fields", "$target_type:$bundle",
        );
      }
    }

    // For updates (target_id present, no type), we can't validate fields
    // structurally without loading the entity to determine bundle. This is
    // deferred to Phase B (semantic validation).
    return [];
  }

  /**
   * Validates the bundle in an inline entity item.
   *
   * @param string $path
   *   The error path.
   * @param mixed $bundle
   *   The bundle value from the item.
   * @param string $target_type
   *   The target entity type.
   * @param array|null $target_bundles
   *   Allowed bundles, or NULL for any.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Bundle validation errors.
   */
  protected function validateInlineBundle(string $path, mixed $bundle, string $target_type, ?array $target_bundles): array {
    if (!is_string($bundle) || $bundle === '') {
      return [
        new BlueprintError(
          path: $path . '.type',
          code: 'INVALID_INLINE_BUNDLE',
          message: 'Inline entity type must be a non-empty string.',
          context: $target_bundles ? ['available_bundles' => $target_bundles] : [],
          hint: 'Provide a valid bundle machine name.',
        ),
      ];
    }

    // Check against allowed bundles if restricted.
    if ($target_bundles && !in_array($bundle, $target_bundles, TRUE)) {
      return [
        new BlueprintError(
          path: $path . '.type',
          code: 'INVALID_INLINE_BUNDLE',
          message: "Bundle '$bundle' is not allowed for this field.",
          context: ['available_bundles' => $target_bundles],
          hint: 'Use one of the allowed bundle types.',
        ),
      ];
    }

    // Check bundle exists on entity type.
    $all_bundles = $this->bundleInfo->getBundleInfo($target_type);
    if (!isset($all_bundles[$bundle])) {
      return [
        new BlueprintError(
          path: $path . '.type',
          code: 'INVALID_INLINE_BUNDLE',
          message: "Bundle '$bundle' does not exist on entity type '$target_type'.",
          context: ['available_bundles' => $target_bundles ?: array_keys($all_bundles)],
          hint: 'Check the bundle machine name.',
        ),
      ];
    }

    return [];
  }

  /**
   * Validates a set of field names and formats against an entity type/bundle.
   *
   * Handles unknown-field detection, format validation, and error path
   * rewriting.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param string $bundle
   *   The bundle.
   * @param array $fields
   *   The submitted field data keyed by field name.
   * @param string $scope
   *   The path prefix for errors (e.g. 'fields', 'path.fields',
   *   'path.block_fields').
   * @param string $context_label
   *   Human-readable label for error messages (e.g. 'node:article',
   *   "block type 'basic'").
   * @param string $unknown_code
   *   Error code for unknown fields.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Validation errors.
   */
  protected function validateFieldSet(string $entity_type, string $bundle, array $fields, string $scope, string $context_label, string $unknown_code = 'UNKNOWN_FIELD'): array {
    $errors = [];
    $field_definitions = $this->entityFieldManager->getFieldDefinitions($entity_type, $bundle);
    $entity_type_definition = $this->entityTypeManager->getDefinition($entity_type);
    $processable_fields = $this->fieldHandlerManager->filterFieldDefinitions($entity_type_definition, $field_definitions, $bundle);
    $available_fields = array_keys($processable_fields);

    foreach ($fields as $field_name => $value) {
      $field_path = "{$scope}.{$field_name}";

      if (!isset($field_definitions[$field_name])) {
        $errors[] = new BlueprintError(
          path: $field_path,
          code: $unknown_code,
          message: "Field '$field_name' does not exist on $context_label.",
          context: ['available' => $available_fields],
          hint: 'Check the field machine name.',
        );
        continue;
      }

      if (!isset($processable_fields[$field_name])) {
        continue;
      }

      // Form-only stubs are valid transport (FULL-mode structure
      // completeness), never data: write paths ignore them, so their shape
      // is not held to the field's value format.
      if (FormOnlyStub::isStub($value)) {
        continue;
      }

      $definition = $processable_fields[$field_name];
      $format_errors = $this->validateFieldFormat($field_name, $value, $definition);

      // Rewrite format error paths from "fields.X" to "{$scope}.X".
      foreach ($format_errors as $error) {
        $original_path = $error->getPath();
        if (str_starts_with($original_path, 'fields.')) {
          $errors[] = $error->withPath($scope . '.' . substr($original_path, 7));
        }
        else {
          $errors[] = $error->withPath($field_path);
        }
      }
    }

    return $errors;
  }

  /**
   * Gets fieldable entity type IDs.
   *
   * @return string[]
   *   Sorted machine names of fieldable entity types.
   */
  protected function getFieldableEntityTypes(): array {
    $types = [];
    foreach ($this->entityTypeManager->getDefinitions() as $id => $definition) {
      if ($definition->entityClassImplements(FieldableEntityInterface::class)) {
        $types[] = $id;
      }
    }
    sort($types);
    return $types;
  }

  /**
   * Validates the layout_builder__layout array.
   *
   * Checks layout plugin IDs, regions, block types, and block fields.
   *
   * @param array $layout
   *   The layout sections array from the blueprint.
   * @param object|null $storage
   *   The defaults section storage governing restriction answers, or NULL
   *   when none is resolvable.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Layout validation errors.
   */
  protected function validateLayout(array $layout, ?object $storage): array {
    if ($this->layoutPluginManager === NULL) {
      // Layout discovery not available — skip layout validation.
      return [];
    }

    $errors = [];

    foreach ($layout as $section_index => $section) {
      if (!is_array($section)) {
        continue;
      }
      $errors = array_merge($errors, $this->validateSectionNode(
        $section, "layout_builder__layout[{$section_index}]", $storage,
      ));
    }

    return $errors;
  }

  /**
   * Validates one section node, recursing into nested section nodes.
   *
   * The one section grammar at every depth: a region entry carrying
   * layout_id is a nested section node, identical in shape, validated
   * identically (same restriction answer — the root display governs all
   * depths).
   *
   * @param array $section
   *   The section node.
   * @param string $section_path
   *   The error path for this section.
   * @param object|null $storage
   *   The defaults section storage governing restriction answers, or NULL.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Section validation errors.
   */
  protected function validateSectionNode(array $section, string $section_path, ?object $storage): array {
    $errors = [];

    // Validate layout_id.
    $layout_id = $section['layout_id'] ?? NULL;
    $layout_errors = $this->validateLayoutId($section_path, $layout_id, $storage);
    if (!empty($layout_errors)) {
      // Can't validate regions without a valid layout.
      return $layout_errors;
    }

    // Validate regions.
    $regions = $section['regions'] ?? [];
    if (is_array($regions)) {
      foreach ($regions as $region_name => $region_components) {
        // Validate region name against layout plugin.
        $region_path = "{$section_path}.regions.{$region_name}";
        $region_errors = $this->validateRegion($region_path, $region_name, $layout_id);
        $errors = array_merge($errors, $region_errors);

        if (is_array($region_components)) {
          foreach ($region_components as $comp_index => $component) {
            $errors = array_merge($errors, $this->validateElementEntry(
              $component, "{$region_path}[{$comp_index}]", $layout_id, $storage,
            ));
          }
        }
      }
    }

    // Validate the ordered children list — the container-section shape (a
    // container layout's sole region is a storage vestige, see
    // ContainerLayout). Refused for multi-region layouts and never combined
    // with the regions map, mirroring the deserializer.
    if (array_key_exists('children', $section)) {
      $definition = $this->layoutPluginManager->getDefinition($layout_id, FALSE);
      if (!ContainerLayout::isContainer($definition)) {
        $errors[] = new BlueprintError(
          path: "{$section_path}.children",
          code: 'INVALID_CHILDREN',
          message: "Layout '$layout_id' is not a container layout, so its elements are keyed by region.",
          context: ['available' => $definition?->getRegionNames() ?? []],
          hint: 'Use regions: {REGION_NAME: [...]}; the ordered children list is the container-section shape.',
        );
      }
      elseif (!empty($regions)) {
        $errors[] = new BlueprintError(
          path: "{$section_path}.children",
          code: 'INVALID_CHILDREN',
          message: 'A section node carries children or regions, never both.',
          hint: 'A container section lists its elements in children; drop the regions key.',
        );
      }
      elseif (!is_array($section['children']) || !array_is_list($section['children'])) {
        $errors[] = new BlueprintError(
          path: "{$section_path}.children",
          code: 'INVALID_CHILDREN',
          message: 'children is an ordered array of blocks and nested sections.',
          hint: 'Provide a list, not a map: container sections have no addressable regions.',
        );
      }
      else {
        foreach ($section['children'] as $comp_index => $component) {
          $errors = array_merge($errors, $this->validateElementEntry(
            $component, "{$section_path}.children[{$comp_index}]", $layout_id, $storage,
          ));
        }
      }
    }

    return $errors;
  }

  /**
   * Validates one element-list entry (a block or a nested section).
   *
   * The shared rule for region lists and container children lists: an entry
   * carrying layout_id is a nested section node and recurses through the
   * one section grammar; anything else validates as a block entry.
   *
   * @param mixed $component
   *   The entry (non-arrays — e.g. bare relocation UUIDs — pass through).
   * @param string $comp_path
   *   The error path for the entry.
   * @param string $layout_id
   *   The parent section's layout plugin ID.
   * @param object|null $storage
   *   The defaults section storage governing restriction answers, or NULL.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Entry validation errors.
   */
  protected function validateElementEntry(mixed $component, string $comp_path, string $layout_id, ?object $storage): array {
    if (!is_array($component)) {
      return [];
    }
    if (isset($component['layout_id'])) {
      // An entry carrying layout_id is a nested section node.
      return $this->validateSectionNode($component, $comp_path, $storage);
    }
    return $this->validateLayoutElement($comp_path, $component, $layout_id, $storage);
  }

  /**
   * Validates a layout plugin ID.
   *
   * @param string $section_path
   *   The error path for the section.
   * @param mixed $layout_id
   *   The layout plugin ID.
   * @param object|null $storage
   *   The defaults section storage governing restriction answers, or NULL.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Errors if invalid.
   */
  protected function validateLayoutId(string $section_path, mixed $layout_id, ?object $storage = NULL): array {
    if ($layout_id === NULL || $layout_id === '') {
      return [
        new BlueprintError(
          path: "{$section_path}.layout_id",
          code: 'INVALID_LAYOUT',
          message: 'Layout plugin ID is required.',
          context: ['available' => AvailableLayouts::summarize($this->layoutPluginManager)],
          hint: 'Provide a valid layout plugin ID; each entry\'s columns is how many regions sit side by side.',
        ),
      ];
    }

    $available = AvailableLayouts::summarize($this->layoutPluginManager);
    if (!in_array($layout_id, array_column($available, 'id'), TRUE)) {
      return [
        new BlueprintError(
          path: "{$section_path}.layout_id",
          code: 'INVALID_LAYOUT',
          message: "Layout plugin '$layout_id' does not exist.",
          context: ['available' => $available],
          hint: 'Check the layout plugin ID spelling; each entry\'s columns is how many regions sit side by side.',
        ),
      ];
    }

    // Restriction enforcement at the spine: the display's allowed layouts.
    if ($this->placementRestrictions !== NULL
        && !$this->placementRestrictions->layoutAllowed($layout_id, $storage)) {
      return [
        new BlueprintError(
          path: "{$section_path}.layout_id",
          code: 'RESTRICTED_LAYOUT',
          message: "Layout '$layout_id' is not allowed for this entity display.",
          context: ['allowed_layouts' => array_keys($this->placementRestrictions->allowedLayoutDefinitions($storage))],
          hint: 'Pick an allowed layout, or update the display\'s layout restrictions.',
        ),
      ];
    }

    return [];
  }

  /**
   * Validates a layout block entry's structure.
   *
   * Validates block type (for inline blocks), block fields, and plugin_id
   * (for non-inline blocks). Region is validated at the container level.
   *
   * @param string $comp_path
   *   The error path for the component.
   * @param array $component
   *   The component data.
   * @param string $layout_id
   *   The parent section's layout plugin ID.
   * @param object|null $storage
   *   The defaults section storage governing restriction answers, or NULL.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Component validation errors.
   */
  protected function validateLayoutElement(string $comp_path, array $component, string $layout_id, ?object $storage): array {
    $errors = [];

    // Determine if inline block or non-inline.
    $block_type = $component['block_type'] ?? NULL;
    $plugin_id = $component['plugin_id'] ?? NULL;

    if ($block_type !== NULL) {
      // Inline block: validate block_type and block_fields.
      $block_type_errors = $this->validateBlockType($comp_path, $block_type, $storage);
      if (empty($block_type_errors)) {
        // Validate block_fields if present.
        $block_fields = $component['block_fields'] ?? [];
        if (is_array($block_fields) && !empty($block_fields)) {
          $field_errors = $this->validateFieldSet(
            'block_content', $block_type, $block_fields,
            "$comp_path.block_fields", "block type '$block_type'",
            'UNKNOWN_BLOCK_FIELD',
          );
          $errors = array_merge($errors, $field_errors);
        }
      }
      else {
        $errors = array_merge($errors, $block_type_errors);
      }
    }
    elseif ($plugin_id !== NULL && $this->placementRestrictions !== NULL) {
      // Plugin block: existence, then the same spine restriction answer as
      // inline blocks (display-level here — see validate()).
      if (!is_string($plugin_id) || $plugin_id === '' || !$this->placementRestrictions->blockExists($plugin_id)) {
        $errors[] = new BlueprintError(
          path: "{$comp_path}.plugin_id",
          code: 'INVALID_PLUGIN_ID',
          message: "Block plugin '" . (is_string($plugin_id) ? $plugin_id : gettype($plugin_id)) . "' does not exist.",
          hint: 'Check the block plugin ID.',
        );
      }
      elseif (!$this->placementRestrictions->blockAllowed($plugin_id, $storage)) {
        $errors[] = new BlueprintError(
          path: "{$comp_path}.plugin_id",
          code: 'RESTRICTED_PLUGIN_ID',
          message: "Block plugin '$plugin_id' is not allowed for this entity display.",
          hint: 'Pick an allowed block plugin, or update the display\'s block restrictions.',
        );
      }
    }

    return $errors;
  }

  /**
   * Validates a region name against the layout plugin.
   *
   * @param string $path
   *   The error path for the region.
   * @param mixed $region
   *   The region name.
   * @param string $layout_id
   *   The layout plugin ID.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Region validation errors.
   */
  protected function validateRegion(string $path, mixed $region, string $layout_id): array {
    if (!is_string($region) || $region === '') {
      return [];
    }

    $definition = $this->layoutPluginManager->getDefinition($layout_id, FALSE);
    if ($definition === NULL) {
      return [];
    }

    $valid_regions = $definition->getRegionNames();
    if (!in_array($region, $valid_regions, TRUE)) {
      return [
        new BlueprintError(
          path: $path,
          code: 'INVALID_REGION',
          message: "Region '$region' does not exist on layout '$layout_id'.",
          context: ['available' => $valid_regions],
          hint: 'Use one of the valid regions for this layout.',
        ),
      ];
    }

    return [];
  }

  /**
   * Validates an inline block type.
   *
   * @param string $comp_path
   *   The error path for the component.
   * @param mixed $block_type
   *   The block content type.
   * @param object|null $storage
   *   The defaults section storage governing restriction answers, or NULL.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Block type validation errors.
   */
  protected function validateBlockType(string $comp_path, mixed $block_type, ?object $storage): array {
    if (!is_string($block_type) || $block_type === '') {
      return [
        new BlueprintError(
          path: "{$comp_path}.block_type",
          code: 'INVALID_BLOCK_TYPE',
          message: 'Block type must be a non-empty string.',
          context: ['available' => $this->getAvailableBlockTypes()],
          hint: 'Provide a valid block content type.',
        ),
      ];
    }

    $bundles = $this->bundleInfo->getBundleInfo('block_content');
    if (!isset($bundles[$block_type])) {
      return [
        new BlueprintError(
          path: "{$comp_path}.block_type",
          code: 'INVALID_BLOCK_TYPE',
          message: "Block type '$block_type' does not exist.",
          context: ['available' => $this->getAvailableBlockTypes()],
          hint: 'Check the block type machine name.',
        ),
      ];
    }

    // Restriction enforcement at the spine.
    if ($this->placementRestrictions !== NULL
        && !$this->placementRestrictions->blockAllowed('inline_block:' . $block_type, $storage)) {
      return [
        new BlueprintError(
          path: "{$comp_path}.block_type",
          code: 'RESTRICTED_BLOCK_TYPE',
          message: "Block type '$block_type' is not allowed for this entity display.",
          context: ['allowed_block_types' => $this->placementRestrictions->allowedInlineBlockBundles($storage)],
          hint: 'Check the schema for available block types, or update the display\'s block restrictions.',
        ),
      ];
    }

    return [];
  }

  /**
   * Gets available block content type machine names.
   *
   * @return string[]
   *   Sorted block type machine names.
   */
  protected function getAvailableBlockTypes(): array {
    $bundles = $this->bundleInfo->getBundleInfo('block_content');
    $types = array_keys($bundles);
    sort($types);
    return $types;
  }

}
