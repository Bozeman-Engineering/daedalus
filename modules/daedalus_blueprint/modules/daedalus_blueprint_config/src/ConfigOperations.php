<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_config;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityWithPluginCollectionInterface;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_blueprint_config\Result\ConfigBlueprintResult;
use Drupal\daedalus_blueprint_config\Validator\ConfigSemanticValidator;
use Drupal\daedalus_blueprint_config\Validator\ConfigStructuralValidator;

/**
 * Config entity operations implementation.
 */
class ConfigOperations implements ConfigOperationsInterface {

  /**
   * Constructs a ConfigOperations.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\daedalus_blueprint_config\ConfigSerializer $serializer
   *   The config serializer.
   * @param \Drupal\daedalus_blueprint_config\ConfigDeserializer $deserializer
   *   The config deserializer.
   * @param \Drupal\daedalus_blueprint_config\ConfigSchemaBuilder $schemaBuilder
   *   The schema builder.
   * @param \Drupal\daedalus_blueprint_config\Validator\ConfigStructuralValidator $structuralValidator
   *   The structural validator.
   * @param \Drupal\daedalus_blueprint_config\Validator\ConfigSemanticValidator $semanticValidator
   *   The semantic validator.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigSerializer $serializer,
    protected ConfigDeserializer $deserializer,
    protected ConfigSchemaBuilder $schemaBuilder,
    protected ConfigStructuralValidator $structuralValidator,
    protected ConfigSemanticValidator $semanticValidator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function createEntity(array $blueprint): ConfigBlueprintResult {
    return $this->deserializer->deserialize($blueprint);
  }

  /**
   * {@inheritdoc}
   */
  public function getBlueprint(ConfigEntityInterface $entity): array {
    return $this->serializer->serialize($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function getBlueprintSummary(ConfigEntityInterface $entity): array {
    return $this->serializer->serializeSummary($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function getSchema(string $entity_type_id): array {
    return $this->schemaBuilder->getSchema($entity_type_id);
  }

  /**
   * {@inheritdoc}
   */
  public function updateProperties(ConfigEntityInterface $entity, array $properties): ConfigBlueprintResult {
    $blueprint = [
      'entity_type' => $entity->getEntityTypeId(),
      'id' => $entity->id(),
      'properties' => $properties,
    ];

    $structural_errors = $this->structuralValidator->validate($blueprint, TRUE);
    if (!empty($structural_errors)) {
      return ConfigBlueprintResult::structuralFailure($structural_errors);
    }

    foreach ($properties as $key => $value) {
      $entity->set($key, $value);
    }

    $semantic_result = $this->semanticValidator->validate($entity, FALSE);
    if (!empty($semantic_result['errors'])) {
      return ConfigBlueprintResult::semanticFailure($semantic_result['errors'], $semantic_result['warnings']);
    }

    return ConfigBlueprintResult::success($entity, $semantic_result['warnings']);
  }

  /**
   * {@inheritdoc}
   */
  public function addPlugin(ConfigEntityInterface $entity, string $collection, array $plugin_data): ConfigBlueprintResult {
    if ($denied = $this->checkWriteAccess($entity)) {
      return $denied;
    }

    if (!$entity instanceof EntityWithPluginCollectionInterface) {
      return ConfigBlueprintResult::structuralFailure([
        new BlueprintError(
          path: "plugin_collections.$collection",
          code: 'no_plugin_collections',
          message: 'Entity does not support plugin collections.',
        ),
      ]);
    }

    $collections = $entity->getPluginCollections();
    if (!isset($collections[$collection])) {
      return ConfigBlueprintResult::structuralFailure([
        new BlueprintError(
          path: "plugin_collections.$collection",
          code: 'unknown_collection',
          message: "Plugin collection '$collection' does not exist.",
        ),
      ]);
    }

    if (!isset($plugin_data['id'])) {
      return ConfigBlueprintResult::structuralFailure([
        new BlueprintError(
          path: "plugin_collections.$collection",
          code: 'missing_plugin_id',
          message: "Plugin data must include an 'id' key.",
        ),
      ]);
    }

    $plugin_collection = $collections[$collection];
    $configuration = $plugin_data['configuration'] ?? [];
    $configuration['id'] = $plugin_data['id'];

    $instance_id = $plugin_data['instance_id'] ?? $plugin_data['uuid'] ?? $plugin_data['id'] . '_' . bin2hex(random_bytes(4));
    $plugin_collection->addInstanceId($instance_id, $configuration);

    return ConfigBlueprintResult::success($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function updatePlugin(ConfigEntityInterface $entity, string $collection, string $instance_id, array $configuration): ConfigBlueprintResult {
    if ($denied = $this->checkWriteAccess($entity)) {
      return $denied;
    }

    if (!$entity instanceof EntityWithPluginCollectionInterface) {
      return ConfigBlueprintResult::structuralFailure([
        new BlueprintError(
          path: "plugin_collections.$collection",
          code: 'no_plugin_collections',
          message: 'Entity does not support plugin collections.',
        ),
      ]);
    }

    $collections = $entity->getPluginCollections();
    if (!isset($collections[$collection])) {
      return ConfigBlueprintResult::structuralFailure([
        new BlueprintError(
          path: "plugin_collections.$collection",
          code: 'unknown_collection',
          message: "Plugin collection '$collection' does not exist.",
        ),
      ]);
    }

    $plugin_collection = $collections[$collection];
    if (!$plugin_collection->has($instance_id)) {
      return ConfigBlueprintResult::structuralFailure([
        new BlueprintError(
          path: "plugin_collections.$collection.$instance_id",
          code: 'plugin_not_found',
          message: "Plugin instance '$instance_id' not found in collection '$collection'.",
        ),
      ]);
    }

    $plugin = $plugin_collection->get($instance_id);
    $existing_config = $plugin->getConfiguration();
    $merged = array_merge($existing_config, $configuration);
    $plugin->setConfiguration($merged);

    return ConfigBlueprintResult::success($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function removePlugin(ConfigEntityInterface $entity, string $collection, string $instance_id): ConfigBlueprintResult {
    if ($denied = $this->checkWriteAccess($entity)) {
      return $denied;
    }

    if (!$entity instanceof EntityWithPluginCollectionInterface) {
      return ConfigBlueprintResult::structuralFailure([
        new BlueprintError(
          path: "plugin_collections.$collection",
          code: 'no_plugin_collections',
          message: 'Entity does not support plugin collections.',
        ),
      ]);
    }

    $collections = $entity->getPluginCollections();
    if (!isset($collections[$collection])) {
      return ConfigBlueprintResult::structuralFailure([
        new BlueprintError(
          path: "plugin_collections.$collection",
          code: 'unknown_collection',
          message: "Plugin collection '$collection' does not exist.",
        ),
      ]);
    }

    $plugin_collection = $collections[$collection];
    if (!$plugin_collection->has($instance_id)) {
      return ConfigBlueprintResult::structuralFailure([
        new BlueprintError(
          path: "plugin_collections.$collection.$instance_id",
          code: 'plugin_not_found',
          message: "Plugin instance '$instance_id' not found in collection '$collection'.",
        ),
      ]);
    }

    $plugin_collection->removeInstanceId($instance_id);

    return ConfigBlueprintResult::success($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function reorderPlugins(ConfigEntityInterface $entity, string $collection, array $order): ConfigBlueprintResult {
    if ($denied = $this->checkWriteAccess($entity)) {
      return $denied;
    }

    if (!$entity instanceof EntityWithPluginCollectionInterface) {
      return ConfigBlueprintResult::structuralFailure([
        new BlueprintError(
          path: "plugin_collections.$collection",
          code: 'no_plugin_collections',
          message: 'Entity does not support plugin collections.',
        ),
      ]);
    }

    $collections = $entity->getPluginCollections();
    if (!isset($collections[$collection])) {
      return ConfigBlueprintResult::structuralFailure([
        new BlueprintError(
          path: "plugin_collections.$collection",
          code: 'unknown_collection',
          message: "Plugin collection '$collection' does not exist.",
        ),
      ]);
    }

    $plugin_collection = $collections[$collection];
    $errors = [];

    $existing_ids = [];
    foreach ($plugin_collection as $id => $plugin) {
      $existing_ids[] = $id;
    }

    foreach ($order as $index => $instance_id) {
      if (!$plugin_collection->has($instance_id)) {
        $errors[] = new BlueprintError(
          path: "plugin_collections.$collection.order.$index",
          code: 'plugin_not_found',
          message: "Plugin instance '$instance_id' not found.",
        );
      }
    }

    $missing_from_order = array_diff($existing_ids, $order);
    if (!empty($missing_from_order)) {
      $errors[] = new BlueprintError(
        path: "plugin_collections.$collection.order",
        code: 'incomplete_order',
        message: 'The order must include all plugin instance IDs. Missing: ' . implode(', ', $missing_from_order) . '.',
        hint: 'Provide a complete permutation of all instance IDs in the collection.',
      );
    }

    if (!empty($errors)) {
      return ConfigBlueprintResult::structuralFailure($errors);
    }

    // Rebuild with specified order by extracting and re-adding.
    $configs = [];
    foreach ($order as $instance_id) {
      $plugin = $plugin_collection->get($instance_id);
      $configs[$instance_id] = $plugin->getConfiguration();
    }

    foreach ($existing_ids as $id) {
      $plugin_collection->removeInstanceId($id);
    }
    foreach ($configs as $id => $config) {
      $plugin_collection->addInstanceId($id, $config);
    }

    return ConfigBlueprintResult::success($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function batchOperations(ConfigEntityInterface $entity, array $operations): ConfigBlueprintResult {
    if (empty($operations)) {
      return ConfigBlueprintResult::success($entity);
    }

    foreach ($operations as $index => $operation) {
      $result = $this->applyOperation($entity, $operation, $index);
      if (!$result->isSuccess()) {
        return $result;
      }
      $entity = $result->getEntity();
    }

    return ConfigBlueprintResult::success($entity);
  }

  /**
   * Applies a single operation.
   *
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface $entity
   *   The entity.
   * @param array $operation
   *   The operation definition with 'op' key.
   * @param int $index
   *   The operation index in the batch.
   *
   * @return \Drupal\daedalus_blueprint_config\Result\ConfigBlueprintResult
   *   The result.
   */
  protected function applyOperation(ConfigEntityInterface $entity, array $operation, int $index): ConfigBlueprintResult {
    $op = $operation['op'] ?? '';

    return match ($op) {
      'update_properties' => $this->updateProperties($entity, $operation['properties'] ?? []),
      'add_plugin' => $this->addPlugin($entity, $operation['collection'] ?? '', $operation['plugin'] ?? $operation),
      'update_plugin' => $this->updatePlugin($entity, $operation['collection'] ?? '', $operation['instance_id'] ?? '', $operation['configuration'] ?? []),
      'remove_plugin' => $this->removePlugin($entity, $operation['collection'] ?? '', $operation['instance_id'] ?? ''),
      'reorder_plugins' => $this->reorderPlugins($entity, $operation['collection'] ?? '', $operation['order'] ?? []),
      default => ConfigBlueprintResult::structuralFailure([
        new BlueprintError(
          path: "operations.$index.op",
          code: 'unknown_operation',
          message: "Unknown operation '$op'.",
          hint: "Valid operations: update_properties, add_plugin, update_plugin, remove_plugin, reorder_plugins.",
        ),
      ]),
    };
  }

  /**
   * Gates a plugin-collection mutation on update access.
   *
   * Plugin operations are reachable directly through the service (and the AI
   * tool layer), so the access boundary lives here rather than relying on the
   * caller to have checked. Delegates to the fail-closed semantic validator.
   *
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface $entity
   *   The entity being mutated.
   *
   * @return \Drupal\daedalus_blueprint_config\Result\ConfigBlueprintResult|null
   *   A semantic-failure result when access is denied, or NULL when allowed.
   */
  protected function checkWriteAccess(ConfigEntityInterface $entity): ?ConfigBlueprintResult {
    $errors = $this->semanticValidator->validateAccess($entity, FALSE);
    return $errors === [] ? NULL : ConfigBlueprintResult::semanticFailure($errors);
  }

}
