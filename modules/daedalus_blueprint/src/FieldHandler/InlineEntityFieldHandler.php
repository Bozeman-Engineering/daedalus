<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\FieldHandler;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;
use Drupal\daedalus_blueprint\Access\BlueprintAccessChecker;
use Drupal\daedalus_blueprint\Result\BlueprintError;

/**
 * Handler for entity_reference_revisions field types (inline/owned entities).
 *
 * Serializes referenced entities inline with full field content. Deserializes
 * with lifecycle detection: a target_id the written field already holds =
 * update, anything else = create, missing from array = delete (handled by
 * Drupal's field item lifecycle).
 *
 * An inline entity is owned: it belongs to the one field of the one entity
 * that holds it. So a target_id is read only against the items the written
 * field holds on the entity being written (the `entity` option), never
 * against storage: an id from anywhere else names nothing here, and its item
 * creates a new entity of its declared type, as an item with no id does.
 *
 * This is the counterpart to EntityReferenceFieldHandler which handles shared
 * references (lookup by target_id or _label). Inline entities are owned by
 * the parent and their content is serialized/deserialized in place.
 */
class InlineEntityFieldHandler extends FieldHandlerBase {

  /**
   * Constructs an InlineEntityFieldHandler.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $bundleInfo
   *   The bundle info service.
   * @param \Drupal\daedalus_blueprint\FieldHandler\FieldHandlerManager $fieldHandlerManager
   *   The field handler manager.
   * @param \Drupal\daedalus_blueprint\Access\BlueprintAccessChecker $accessChecker
   *   The access checker, used to filter fields the user cannot view.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected FieldHandlerManager $fieldHandlerManager,
    protected BlueprintAccessChecker $accessChecker,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(FieldDefinitionInterface $definition): bool {
    return $definition->getType() === 'entity_reference_revisions';
  }

  /**
   * {@inheritdoc}
   */
  public function getGuidance(): ?string {
    return 'Create: {type: "bundle_name", fields: {...}}. Update: {target_id: N, fields: {...}}, where N is an item this field already holds (an id from anywhere else creates a new item and needs type). Omitting an item from the array removes it.';
  }

  /**
   * {@inheritdoc}
   */
  public function getRelevantSettings(array $settings): array {
    return $this->referenceSettings($settings);
  }

  /**
   * {@inheritdoc}
   */
  protected function doSerialize(FieldItemListInterface $items, FieldDefinitionInterface $definition): array {
    $values = [];

    foreach ($items as $item) {
      $entity = $item->entity;
      if ($entity === NULL) {
        continue;
      }

      $serialized = [
        'target_id' => is_numeric($entity->id()) ? (int) $entity->id() : $entity->id(),
        'uuid' => $entity->uuid(),
        'type' => $entity->bundle(),
      ];

      $fields = $this->serializeEntityFields($entity);
      if (!empty($fields)) {
        $serialized['fields'] = $fields;
      }

      $values[] = $serialized;
    }

    return $values;
  }

  /**
   * {@inheritdoc}
   *
   * Inline entity payloads mix field types; only the per-field child handler
   * knows whether a value is rich text, so entity decoding happens there.
   */
  protected function decodesHtmlEntities(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  protected function doDeserialize(mixed $value, FieldDefinitionInterface $definition, array $options = []): FieldHandlerResult {
    $reference = $this->referenceSettings($definition->getSettings());
    $target_type = $reference['target_type'];
    $target_bundles = $reference['target_bundles'] ?? NULL;
    $cardinality = $definition->getFieldStorageDefinition()->getCardinality();

    [$items, $is_single_value] = static::normalizeItems($value, $definition->getName(), $cardinality);
    $held = $this->heldEntities($definition, $options);

    $results = [];
    $errors = [];
    $warnings = [];

    foreach ($items as $path => $item) {
      $processed = $this->processItem($item, $target_type, $target_bundles, $path, $options, $held);

      if (!empty($processed['errors'])) {
        $errors = array_merge($errors, $processed['errors']);
      }
      else {
        $results[] = $processed['value'];
      }

      if (!empty($processed['warnings'])) {
        $warnings = array_merge($warnings, $processed['warnings']);
      }
    }

    if (!empty($errors)) {
      return new FieldHandlerResult(NULL, $errors, $warnings);
    }

    $result_value = $is_single_value ? ($results[0] ?? NULL) : $results;
    return new FieldHandlerResult($result_value, [], $warnings);
  }

  /**
   * Serializes a fieldable entity's fields to an associative array.
   *
   * Uses the same skip logic and handler delegation as BlueprintSerializer
   * but operates on any fieldable entity (paragraphs, block_content, etc.).
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to serialize.
   *
   * @return array
   *   The serialized fields.
   */
  protected function serializeEntityFields(FieldableEntityInterface $entity): array {
    $processable_fields = $this->fieldHandlerManager->getProcessableFieldDefinitions($entity);
    $fields = [];

    foreach ($processable_fields as $field_name => $definition) {
      // Skip fields the current user cannot view.
      if (!$this->accessChecker->fieldVisible($entity->get($field_name))) {
        continue;
      }

      $handler = $this->fieldHandlerManager->getHandler($definition);
      $field_value = $handler->serialize($entity->get($field_name), $definition);

      if ($field_value !== NULL) {
        $fields[$field_name] = $field_value;
      }
    }

    return $fields;
  }

  /**
   * The inline entities the written field already holds, by id.
   *
   * The whole of what a target_id may name: the items of this field on the
   * entity being written (the unsaved copy when one is staged, so a staged
   * item is the one updated). A caller that hands no entity, a new entity
   * and an entity without the field hold nothing.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field being written.
   * @param array $options
   *   The deserialization options; `entity` is the entity written to.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface[]
   *   The held entities, keyed by entity id.
   */
  protected function heldEntities(FieldDefinitionInterface $definition, array $options): array {
    $owner = $options['entity'] ?? NULL;
    if (!$owner instanceof FieldableEntityInterface || !$owner->hasField($definition->getName())) {
      return [];
    }
    $held = [];
    foreach ($owner->get($definition->getName()) as $item) {
      assert($item instanceof EntityReferenceItem);
      $entity = $item->entity;
      if ($entity instanceof FieldableEntityInterface && !$entity->isNew()) {
        $held[$entity->id()] = $entity;
      }
    }
    return $held;
  }

  /**
   * Processes a single inline entity item for deserialization.
   *
   * Updates the held entity a target_id names and creates otherwise, then
   * applies field values via the FieldHandler system.
   *
   * @param mixed $item
   *   The input item data.
   * @param string $target_type
   *   The target entity type.
   * @param array|null $target_bundles
   *   Allowed bundles, or NULL for any.
   * @param string $path
   *   The error path prefix.
   * @param array $options
   *   Options forwarded to child field handlers.
   * @param \Drupal\Core\Entity\FieldableEntityInterface[] $held
   *   The entities the written field already holds, keyed by entity id.
   *
   * @return array
   *   Array with keys 'value', 'errors', 'warnings'.
   */
  protected function processItem(mixed $item, string $target_type, ?array $target_bundles, string $path, array $options = [], array $held = []): array {
    $errors = [];
    $warnings = [];

    if (!is_array($item) || array_is_list($item)) {
      $errors[] = new BlueprintError(
        path: $path,
        code: 'INVALID_FIELD_FORMAT',
        message: 'Inline entity must be an object with type and fields.',
        hint: 'Provide {type: "bundle", fields: {...}} for new or {target_id: N, fields: {...}} for update.',
      );
      return ['value' => NULL, 'errors' => $errors, 'warnings' => $warnings];
    }

    $target_id = $item['target_id'] ?? NULL;
    $bundle = $item['type'] ?? NULL;
    $fields = $item['fields'] ?? [];

    // Determine create vs update: an id the field holds updates that item,
    // anything else creates. An id the field does not hold is never loaded.
    $entity = is_scalar($target_id) ? ($held[$target_id] ?? NULL) : NULL;
    if ($entity !== NULL) {
      $bundle = $entity->bundle();
    }
    elseif ($target_id !== NULL && ($bundle === NULL || $bundle === '')) {
      return [
        'value' => NULL,
        'errors' => [
          new BlueprintError(
            path: $path,
            code: 'REFERENCE_NOT_FOUND',
            message: sprintf('This field holds no %s item with ID %s.', $target_type, is_scalar($target_id) ? $target_id : '(not an id)'),
            context: ['held_ids' => array_keys($held)] + ($target_bundles ? ['available_bundles' => $target_bundles] : []),
            hint: 'target_id names an item this field already holds. Use one of its ids to update, or provide {type: "bundle_name", fields: {...}} to create a new item.',
          ),
        ],
        'warnings' => $warnings,
      ];
    }
    else {
      $bundle_error = $this->validateBundle($bundle, $target_type, $target_bundles, $path);
      if ($bundle_error !== NULL) {
        return ['value' => NULL, 'errors' => [$bundle_error], 'warnings' => $warnings];
      }

      $entity = $this->createNewEntity($target_type, $bundle);
    }

    // Apply field values.
    if (!empty($fields) && is_array($fields)) {
      $field_result = $this->deserializeEntityFields($entity, $fields, $path, $options);
      $errors = array_merge($errors, $field_result['errors']);
      $warnings = array_merge($warnings, $field_result['warnings']);
    }

    if (!empty($errors)) {
      return ['value' => NULL, 'errors' => $errors, 'warnings' => $warnings];
    }

    return [
      'value' => ['entity' => $entity],
      'errors' => [],
      'warnings' => $warnings,
    ];
  }

  /**
   * Validates a bundle for inline entity creation.
   *
   * Checks that the bundle is provided, allowed by the field config, and
   * exists on the target entity type.
   *
   * @param string|null $bundle
   *   The bundle machine name.
   * @param string $target_type
   *   The target entity type.
   * @param array|null $target_bundles
   *   Allowed bundles, or NULL for any.
   * @param string $path
   *   The error path.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError|null
   *   An error if validation fails, or NULL on success.
   */
  protected function validateBundle(?string $bundle, string $target_type, ?array $target_bundles, string $path): ?BlueprintError {
    if ($bundle === NULL || $bundle === '') {
      return new BlueprintError(
        path: $path,
        code: 'INVALID_FIELD_FORMAT',
        message: 'New inline entity must include a type (bundle).',
        context: $target_bundles ? ['available_bundles' => $target_bundles] : [],
        hint: 'Provide {type: "bundle_name", fields: {...}}.',
      );
    }

    if ($target_bundles && !in_array($bundle, $target_bundles, TRUE)) {
      return new BlueprintError(
        path: $path . '.type',
        code: 'INVALID_INLINE_BUNDLE',
        message: "Bundle '$bundle' is not allowed for this field.",
        context: ['available_bundles' => $target_bundles],
        hint: 'Use one of the allowed bundle types.',
      );
    }

    $bundle_info = $this->bundleInfo->getBundleInfo($target_type);
    if (!isset($bundle_info[$bundle])) {
      return new BlueprintError(
        path: $path . '.type',
        code: 'INVALID_INLINE_BUNDLE',
        message: "Bundle '$bundle' does not exist on entity type '$target_type'.",
        context: ['available_bundles' => array_keys($bundle_info)],
        hint: 'Check the bundle machine name.',
      );
    }

    return NULL;
  }

  /**
   * Creates a new entity of the given type and bundle.
   *
   * @param string $target_type
   *   The entity type.
   * @param string $bundle
   *   The bundle.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface
   *   The new unsaved entity.
   */
  protected function createNewEntity(string $target_type, string $bundle): FieldableEntityInterface {
    $storage = $this->entityTypeManager->getStorage($target_type);
    $bundle_key = $this->entityTypeManager->getDefinition($target_type)->getKey('bundle');

    $values = [];
    if ($bundle_key) {
      $values[$bundle_key] = $bundle;
    }

    return $storage->create($values);
  }

  /**
   * Applies field values to an inline entity.
   *
   * Iterates over submitted fields, resolves handlers, and applies values.
   * Collects errors from field handlers without stopping on first error.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to apply fields to.
   * @param array $fields
   *   The field data from the blueprint.
   * @param string $base_path
   *   The base error path (e.g., "fields.field_paragraphs[0]").
   * @param array $options
   *   Deserialization options forwarded to the field handlers.
   *
   * @return array
   *   Array with keys 'errors' and 'warnings'.
   */
  protected function deserializeEntityFields(FieldableEntityInterface $entity, array $fields, string $base_path, array $options = []): array {
    $errors = [];
    $warnings = [];
    $processable_fields = $this->fieldHandlerManager->getProcessableFieldDefinitions($entity);

    foreach ($fields as $field_name => $value) {
      $definition = $entity->getFieldDefinition($field_name);

      if ($definition === NULL) {
        $errors[] = new BlueprintError(
          path: "{$base_path}.fields.{$field_name}",
          code: 'UNKNOWN_FIELD',
          message: "Field '$field_name' does not exist on {$entity->getEntityTypeId()}:{$entity->bundle()}.",
          context: ['available' => array_keys($processable_fields)],
          hint: 'Check the field machine name spelling.',
        );
        continue;
      }

      if (!isset($processable_fields[$field_name])) {
        continue;
      }

      // Per-field edit access on the child entity's own field, mirroring the
      // root deserialize path. Owned children have no independent entity-level
      // access, so field access is the enforcement surface here. Denial errors
      // rather than silently dropping the field. The system posture names
      // no actor to judge.
      if (empty($options['system']) && !$this->accessChecker->fieldEditable($entity->get($field_name))) {
        $errors[] = new BlueprintError(
          path: "{$base_path}.fields.{$field_name}",
          code: 'ACCESS_DENIED_FIELD',
          message: "Access denied: cannot edit field '$field_name'.",
          hint: 'The current user lacks permission to modify this field.',
        );
        continue;
      }

      $handler = $this->fieldHandlerManager->getHandler($definition);
      $handler_result = $handler->deserialize($value, $definition, [
        'entity' => $entity,
        'element_uuid' => $entity->uuid(),
      ] + $options);

      if ($handler_result->hasErrors()) {
        foreach ($handler_result->getErrors() as $error) {
          $original_path = $error->getPath();
          if (str_starts_with($original_path, 'fields.')) {
            $rewritten_path = $base_path . '.' . $original_path;
          }
          else {
            $rewritten_path = "{$base_path}.fields.{$field_name}";
          }
          $errors[] = $error->withPath($rewritten_path);
        }
      }
      else {
        $entity->set($field_name, $handler_result->getValue());
      }

      foreach ($handler_result->getWarnings() as $warning) {
        $warnings[] = $warning;
      }
    }

    return ['errors' => $errors, 'warnings' => $warnings];
  }

}
