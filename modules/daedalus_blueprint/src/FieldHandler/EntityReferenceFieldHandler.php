<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\FieldHandler;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\daedalus_blueprint\Access\BlueprintAccessChecker;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Psr\Log\LoggerInterface;

/**
 * Handler for entity_reference field types.
 *
 * Serializes with target_id + _label. Deserializes with resolution priority:
 * target_id (direct load), uuid (property load) then _label (label query).
 */
class EntityReferenceFieldHandler extends FieldHandlerBase {

  use ReferenceResolverTrait;

  /**
   * Constructs an EntityReferenceFieldHandler.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\daedalus_blueprint\Access\BlueprintAccessChecker $accessChecker
   *   The access checker, used to drop references the user cannot view.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerInterface $logger,
    protected BlueprintAccessChecker $accessChecker,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(FieldDefinitionInterface $definition): bool {
    return $definition->getType() === 'entity_reference';
  }

  /**
   * {@inheritdoc}
   */
  public function getGuidance(): ?string {
    return 'Accepts {target_id: N}, {uuid: "..."}, {_label: "Title"}, a bare string (label lookup), or a bare integer (target_id). An unmatched label returns an error; reference an existing entity by target_id or uuid, or create the entity first and then reference it.';
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
      // Drop references to entities the current user cannot view.
      if (!$this->accessChecker->entityAccess($entity, 'view')) {
        continue;
      }
      $values[] = [
        'target_id' => is_numeric($item->target_id) ? (int) $item->target_id : $item->target_id,
        '_label' => (string) $entity->label(),
      ];
    }

    return $values;
  }

  /**
   * {@inheritdoc}
   */
  protected function doDeserialize(mixed $value, FieldDefinitionInterface $definition, array $options = []): FieldHandlerResult {
    $reference = $this->referenceSettings($definition->getSettings());
    $target_type = $reference['target_type'];
    $target_bundles = $reference['target_bundles'] ?? NULL;
    $cardinality = $definition->getFieldStorageDefinition()->getCardinality();

    // LLMs commonly send [{...}] instead of {...} for single-value fields.
    if ($cardinality === 1 && is_array($value) && array_is_list($value) && count($value) === 1) {
      $value = $value[0];
    }

    [$items, $is_single_value] = static::normalizeItems($value, $definition->getName(), $cardinality);

    $results = [];
    $errors = [];
    $warnings = [];

    foreach ($items as $path => $item) {
      $resolved = $this->resolveItem(
        $item, $target_type, $target_bundles, $path, $warnings,
      );

      if ($resolved instanceof BlueprintError) {
        $errors[] = $resolved;
      }
      else {
        $results[] = $resolved;
      }
    }

    if (!empty($errors)) {
      return new FieldHandlerResult(NULL, $errors, $warnings);
    }

    if ($is_single_value) {
      return new FieldHandlerResult($results[0] ?? NULL, [], $warnings);
    }

    return new FieldHandlerResult($results, [], $warnings);
  }

  /**
   * Resolves a single reference item to a target_id array.
   *
   * @param mixed $item
   *   The input item (scalar, string, or array with target_id/_label).
   * @param string $target_type
   *   The target entity type.
   * @param array|null $target_bundles
   *   Allowed bundles, or NULL for any.
   * @param string $path
   *   The error path.
   * @param \Drupal\daedalus_blueprint\Result\BlueprintWarning[] $warnings
   *   Collected warnings (passed by reference).
   *
   * @return array|\Drupal\daedalus_blueprint\Result\BlueprintError
   *   The resolved ['target_id' => N] or an error.
   */
  protected function resolveItem(
    mixed $item,
    string $target_type,
    ?array $target_bundles,
    string $path,
    array &$warnings = [],
  ): array|BlueprintError {
    // Normalize scalar inputs.
    if (is_int($item)) {
      $item = ['target_id' => $item];
    }
    elseif (is_string($item) && is_numeric($item)) {
      $item = ['target_id' => (int) $item];
    }
    elseif (is_string($item)) {
      $item = ['_label' => $item];
    }

    if (!is_array($item)) {
      return new BlueprintError(
        path: $path,
        code: 'INVALID_REFERENCE_FORMAT',
        message: 'Expected object with target_id, uuid or _label, or a scalar value.',
        hint: 'Provide {target_id: N}, {uuid: "..."} or {_label: "Name"} or a plain string/int.',
      );
    }

    // target_id takes priority.
    if (isset($item['target_id'])) {
      $provided_label = isset($item['_label']) ? (string) $item['_label'] : NULL;
      return $this->resolveByTargetId(
        (int) $item['target_id'], $target_type, $target_bundles, $path, $provided_label, $warnings,
      );
    }

    if (isset($item['uuid'])) {
      return $this->resolveByUuid((string) $item['uuid'], $target_type, $target_bundles, $path);
    }

    if (isset($item['_label'])) {
      return $this->resolveByLabel(
        (string) $item['_label'], $target_type, $target_bundles, $path,
      );
    }

    return new BlueprintError(
      path: $path,
      code: 'INVALID_REFERENCE_FORMAT',
      message: 'Reference must include target_id, uuid or _label.',
      hint: 'Provide {target_id: N}, {uuid: "..."} or {_label: "Name"}.',
    );
  }

}
