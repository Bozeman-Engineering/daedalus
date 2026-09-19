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
use Drupal\Component\Uuid\UuidInterface;

/**
 * Deserializes blueprint JSON into config entities.
 *
 * Performs two-phase validation (structural then semantic) and returns a
 * result containing the hydrated config entity or collected errors.
 */
class ConfigDeserializer {

  /**
   * Constructs a ConfigDeserializer.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\daedalus_blueprint_config\Validator\ConfigStructuralValidator $structuralValidator
   *   The structural validator.
   * @param \Drupal\daedalus_blueprint_config\Validator\ConfigSemanticValidator $semanticValidator
   *   The semantic validator.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid
   *   The UUID generator.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigStructuralValidator $structuralValidator,
    protected ConfigSemanticValidator $semanticValidator,
    protected UuidInterface $uuid,
  ) {}

  /**
   * Deserializes a blueprint array into a config entity.
   *
   * @param array $blueprint
   *   The blueprint data with entity_type, properties, and optionally
   *   plugin_collections.
   * @param array $options
   *   Options:
   *   - validate_only (bool): Structural validation only. Default FALSE.
   *   - is_update (bool): Treat as update (immutable checks). Default FALSE.
   *
   * @return \Drupal\daedalus_blueprint_config\Result\ConfigBlueprintResult
   *   The result with the unsaved entity on success, or errors on failure.
   */
  public function deserialize(array $blueprint, array $options = []): ConfigBlueprintResult {
    $validate_only = !empty($options['validate_only']);
    $is_update = !empty($options['is_update']);

    // Phase A: structural validation.
    $structural_errors = $this->structuralValidator->validate($blueprint, $is_update);
    if (!empty($structural_errors)) {
      return ConfigBlueprintResult::structuralFailure($structural_errors);
    }

    if ($validate_only) {
      return ConfigBlueprintResult::validated();
    }

    // Hydrate entity.
    $entity = $this->hydrateEntity($blueprint, $is_update);
    if ($entity === NULL) {
      return ConfigBlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'entity_type',
          code: 'hydration_failed',
          message: 'Could not create or load entity from blueprint.',
        ),
      ]);
    }

    // Phase B: semantic validation.
    $semantic_result = $this->semanticValidator->validate($entity, !$is_update);
    if (!empty($semantic_result['errors'])) {
      return ConfigBlueprintResult::semanticFailure($semantic_result['errors'], $semantic_result['warnings']);
    }

    return ConfigBlueprintResult::success($entity, $semantic_result['warnings']);
  }

  /**
   * Hydrates a config entity from blueprint data.
   *
   * @param array $blueprint
   *   The blueprint data.
   * @param bool $is_update
   *   Whether this is an update to an existing entity.
   *
   * @return \Drupal\Core\Config\Entity\ConfigEntityInterface|null
   *   The hydrated entity, or NULL on failure.
   */
  protected function hydrateEntity(array $blueprint, bool $is_update): ?ConfigEntityInterface {
    $entity_type_id = $blueprint['entity_type'];
    $storage = $this->entityTypeManager->getStorage($entity_type_id);

    if ($is_update) {
      $entity_id = $blueprint['id'] ?? $blueprint['properties']['id'] ?? NULL;
      if ($entity_id === NULL) {
        return NULL;
      }
      $entity = $storage->load($entity_id);
      if (!$entity instanceof ConfigEntityInterface) {
        return NULL;
      }
    }
    else {
      $values = $this->buildCreateValues($blueprint);
      $entity = $storage->create($values);
      if (!$entity instanceof ConfigEntityInterface) {
        return NULL;
      }
    }

    // Determine plugin collection keys so we can route them correctly.
    $collection_keys = [];
    if ($entity instanceof EntityWithPluginCollectionInterface) {
      $collection_keys = array_keys($entity->getPluginCollections());
      // Clear any plugin collection data that was set as raw properties during
      // entity creation — it will be re-applied properly via the collection
      // API.
      foreach ($collection_keys as $key) {
        $entity->set($key, []);
      }
    }

    // Apply properties, intercepting plugin collection data.
    $properties = $blueprint['properties'] ?? [];
    $collections_from_properties = [];
    foreach ($properties as $key => $value) {
      if ($is_update && $key === 'id') {
        continue;
      }
      if (in_array($key, $collection_keys, TRUE) && is_array($value)) {
        $collections_from_properties[$key] = $value;
        continue;
      }
      $entity->set($key, $value);
    }

    // Merge explicit plugin_collections with any found in properties.
    $collections_data = $blueprint['plugin_collections'] ?? [];
    foreach ($collections_from_properties as $key => $items) {
      if (!isset($collections_data[$key])) {
        $collections_data[$key] = $items;
      }
    }

    // Apply plugin collections.
    $this->applyPluginCollections($entity, $collections_data);

    return $entity;
  }

  /**
   * Builds initial values array for entity creation.
   *
   * @param array $blueprint
   *   The blueprint data.
   *
   * @return array
   *   Values for storage->create().
   */
  protected function buildCreateValues(array $blueprint): array {
    $values = [];

    if (isset($blueprint['id'])) {
      $values['id'] = $blueprint['id'];
    }

    if (isset($blueprint['label'])) {
      $entity_type = $this->entityTypeManager->getDefinition($blueprint['entity_type']);
      $label_key = $entity_type->getKey('label');
      if ($label_key) {
        $values[$label_key] = $blueprint['label'];
      }
    }

    $properties = $blueprint['properties'] ?? [];
    foreach ($properties as $key => $value) {
      $values[$key] = $value;
    }

    return $values;
  }

  /**
   * Applies plugin collections to an entity.
   *
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface $entity
   *   The entity.
   * @param array $collections_data
   *   Blueprint plugin_collections data.
   */
  protected function applyPluginCollections(ConfigEntityInterface $entity, array $collections_data): void {
    if (empty($collections_data)) {
      return;
    }

    if (!$entity instanceof EntityWithPluginCollectionInterface) {
      return;
    }

    $collections = $entity->getPluginCollections();

    foreach ($collections_data as $collection_name => $items) {
      if (!isset($collections[$collection_name])) {
        continue;
      }

      $collection = $collections[$collection_name];

      // Remove existing instances.
      $existing_ids = [];
      foreach ($collection as $instance_id => $plugin) {
        $existing_ids[] = $instance_id;
      }
      foreach ($existing_ids as $instance_id) {
        $collection->removeInstanceId($instance_id);
      }

      // Add new instances.
      foreach ($items as $item) {
        $plugin_id = $item['id'];
        $configuration = $this->normalizePluginConfiguration($item);
        $configuration['id'] = $plugin_id;

        $instance_id = $item['instance_id'] ?? $item['uuid'] ?? $configuration['uuid'] ?? NULL;
        if ($instance_id === NULL) {
          $instance_id = $this->uuid->generate();
          $configuration['uuid'] = $instance_id;
        }
        $collection->addInstanceId($instance_id, $configuration);
      }
    }
  }

  /**
   * Normalizes a plugin item into configuration format.
   *
   * Accepts both the canonical format ({id, configuration: {...}}) and the
   * shorthand format ({id, data, weight, ...}) that mirrors raw config.
   *
   * @param array $item
   *   The plugin item from the blueprint.
   *
   * @return array
   *   The normalized configuration array.
   */
  protected function normalizePluginConfiguration(array $item): array {
    if (isset($item['configuration'])) {
      return $item['configuration'];
    }

    $configuration = $item;
    unset($configuration['id'], $configuration['instance_id']);
    return $configuration;
  }

}
