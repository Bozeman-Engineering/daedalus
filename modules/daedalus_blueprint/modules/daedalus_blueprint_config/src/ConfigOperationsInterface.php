<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_config;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\daedalus_blueprint_config\Result\ConfigBlueprintResult;

/**
 * Targeted operations for config entity manipulation.
 *
 * Each method returns a ConfigBlueprintResult with the unsaved entity — the
 * caller decides when to save.
 */
interface ConfigOperationsInterface {

  /**
   * Creates a config entity from a blueprint.
   *
   * @param array $blueprint
   *   The blueprint data.
   *
   * @return \Drupal\daedalus_blueprint_config\Result\ConfigBlueprintResult
   *   The result with the unsaved entity on success.
   */
  public function createEntity(array $blueprint): ConfigBlueprintResult;

  /**
   * Returns the full serialized blueprint for a config entity.
   *
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface $entity
   *   The entity to serialize.
   *
   * @return array
   *   The blueprint array.
   */
  public function getBlueprint(ConfigEntityInterface $entity): array;

  /**
   * Returns a structural summary of a config entity.
   *
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface $entity
   *   The entity to summarize.
   *
   * @return array
   *   The summary array.
   */
  public function getBlueprintSummary(ConfigEntityInterface $entity): array;

  /**
   * Gets the schema for a config entity type.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   *
   * @return array
   *   The schema array.
   */
  public function getSchema(string $entity_type_id): array;

  /**
   * Updates properties on a config entity.
   *
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface $entity
   *   The entity to update.
   * @param array $properties
   *   Map of property name to value.
   *
   * @return \Drupal\daedalus_blueprint_config\Result\ConfigBlueprintResult
   *   The result with the modified entity on success.
   */
  public function updateProperties(ConfigEntityInterface $entity, array $properties): ConfigBlueprintResult;

  /**
   * Adds a plugin to a collection.
   *
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface $entity
   *   The entity.
   * @param string $collection
   *   The plugin collection name.
   * @param array $plugin_data
   *   Plugin data with 'id' and optionally 'configuration'.
   *
   * @return \Drupal\daedalus_blueprint_config\Result\ConfigBlueprintResult
   *   The result with the modified entity on success.
   */
  public function addPlugin(ConfigEntityInterface $entity, string $collection, array $plugin_data): ConfigBlueprintResult;

  /**
   * Updates a plugin's configuration in a collection.
   *
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface $entity
   *   The entity.
   * @param string $collection
   *   The plugin collection name.
   * @param string $instance_id
   *   The plugin instance ID.
   * @param array $configuration
   *   The new configuration values.
   *
   * @return \Drupal\daedalus_blueprint_config\Result\ConfigBlueprintResult
   *   The result with the modified entity on success.
   */
  public function updatePlugin(ConfigEntityInterface $entity, string $collection, string $instance_id, array $configuration): ConfigBlueprintResult;

  /**
   * Removes a plugin from a collection.
   *
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface $entity
   *   The entity.
   * @param string $collection
   *   The plugin collection name.
   * @param string $instance_id
   *   The plugin instance ID to remove.
   *
   * @return \Drupal\daedalus_blueprint_config\Result\ConfigBlueprintResult
   *   The result with the modified entity on success.
   */
  public function removePlugin(ConfigEntityInterface $entity, string $collection, string $instance_id): ConfigBlueprintResult;

  /**
   * Reorders plugins within a collection.
   *
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface $entity
   *   The entity.
   * @param string $collection
   *   The plugin collection name.
   * @param array $order
   *   Instance IDs in the desired order.
   *
   * @return \Drupal\daedalus_blueprint_config\Result\ConfigBlueprintResult
   *   The result with the modified entity on success.
   */
  public function reorderPlugins(ConfigEntityInterface $entity, string $collection, array $order): ConfigBlueprintResult;

  /**
   * Applies multiple operations atomically.
   *
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface $entity
   *   The entity.
   * @param array $operations
   *   Array of operation definitions.
   *
   * @return \Drupal\daedalus_blueprint_config\Result\ConfigBlueprintResult
   *   The result with the modified entity on success.
   */
  public function batchOperations(ConfigEntityInterface $entity, array $operations): ConfigBlueprintResult;

}
