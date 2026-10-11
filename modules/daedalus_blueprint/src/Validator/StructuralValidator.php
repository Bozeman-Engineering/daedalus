<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Validator;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\daedalus_blueprint\FieldHandler\FieldHandlerBase;
use Drupal\daedalus_blueprint\FieldHandler\FieldHandlerManager;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_blueprint\Utility\FormOnlyStub;
use Drupal\daedalus_blueprint\Utility\SampleRequest;

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
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected FieldHandlerManager $fieldHandlerManager,
  ) {}

  /**
   * Validates the structural integrity of a blueprint data array.
   *
   * Returns early if entity type or bundle are invalid since field
   * validation depends on them.
   *
   * @param array $data
   *   The blueprint data with keys: entity_type, bundle, fields.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Structural errors. Empty if valid.
   */
  public function validate(array $data): array {
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
        hint: "Entity field values belong inside the \"fields\" object, e.g. {\"fields\": {\"$unknown_key\": ...}}. Only the document keys \"layout\" and \"styles\" sit at the top level.",
      );
    }

    $fields = $data['fields'] ?? [];
    if (is_array($fields)) {
      $all_errors = array_merge($all_errors, $this->validateFieldSet(
        $entity_type, $bundle, $fields, 'fields', "$entity_type:$bundle",
      ));
    }

    // The layout's shape: a list of nodes, the lean node tree. Its grammar
    // is the instantiator's and everything it names (layouts, block types,
    // placement restrictions, fields) is the op door's, judged when the
    // tree expands in Phase B; Phase A refuses only what no tree could be.
    if (array_key_exists('layout', $data)) {
      $layout = $data['layout'];
      if (!is_array($layout) || !array_is_list($layout)) {
        $all_errors[] = new BlueprintError(
          path: 'layout',
          code: 'INVALID_LAYOUT',
          message: 'layout is a list of container nodes.',
          hint: 'Provide the document as a list of tree nodes, e.g. {"layout": [{"kind": "container", "layout_id": "...", "children": [...]}]}.',
        );
      }
    }

    // The style map's shape: an object of selectable keys. Its grammar is
    // the style store's handler's (Phase B); Phase A refuses only what no
    // map could be.
    if (array_key_exists('styles', $data)) {
      $styles = $data['styles'];
      if (!is_array($styles) || (array_is_list($styles) && $styles !== [])) {
        $all_errors[] = new BlueprintError(
          path: 'styles',
          code: 'INVALID_STYLE_MAP',
          message: 'styles is an object keyed by selectable key.',
          hint: 'Provide an object mapping selectable keys to style entries, e.g. {"styles": {"block:UUID": {"default": {"padding": "2rem"}}}}.',
        );
      }
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
   * Entity field values belong inside the "fields" object. The document
   * rides beside it at the top level for every host: "layout" (the section
   * tree, the layout deserializer's) and "styles" (the style map, the host's
   * style store's). "entity_type"/"bundle"/"langcode" are addressing
   * metadata; "_root_entity" is an internal marker set when building a
   * sub-entity blueprint for an operation; "_verdict" is the read's
   * capability stamp (VerdictStamper), delivery-only and ignored on echo,
   * so a read deserializes as written.
   *
   * @var string[]
   */
  protected const BLUEPRINT_ROOT_KEYS = [
    'entity_type',
    'bundle',
    'langcode',
    'fields',
    'layout',
    'styles',
    '_root_entity',
    '_verdict',
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
        // A document field (the layout, the style map) spelled as a field
        // would be dropped on the floor by the write path; refuse it with
        // the road it belongs on.
        if ($this->fieldHandlerManager->isSkippedFieldType($field_definitions[$field_name]->getType())) {
          $errors[] = new BlueprintError(
            path: $field_path,
            code: 'DOCUMENT_KEY_IN_FIELDS',
            message: "'$field_name' is the document, not a field.",
            hint: 'The layout and the style map ride the top-level "layout" and "styles" keys of the blueprint, never "fields".',
          );
        }
        continue;
      }

      // Form-only stubs are valid transport (FULL-mode structure
      // completeness), never data: write paths ignore them, so their shape
      // is not held to the field's value format.
      if (FormOnlyStub::isStub($value)) {
        continue;
      }

      // A sample request is an op-time word (update_element resolves it
      // into a value); a blueprint carries values, and no owner on this
      // road would resolve it, so Phase A refuses it rather than admit it
      // as handler vocabulary and let a handler misread the marker.
      if (SampleRequest::isRequest($value)) {
        $errors[] = new BlueprintError(
          path: $field_path,
          code: 'SAMPLE_REQUEST_NOT_A_VALUE',
          message: "Field '$field_name' asks for a sample, but a blueprint carries values.",
          hint: 'Write the entity with values (or omit the field), then fill it with update_element {"' . SampleRequest::KEY . '": true} on the field.',
        );
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

}
