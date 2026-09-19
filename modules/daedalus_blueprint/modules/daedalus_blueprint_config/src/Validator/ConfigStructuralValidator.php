<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_config\Validator;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityWithPluginCollectionInterface;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_blueprint_config\ConfigEntityMetadataTrait;
use Drupal\daedalus_blueprint_config\PropertyHandler\PluginCollectionPropertyHandler;

/**
 * Structural validator for config entity blueprints.
 *
 * Phase A: validates property names, types, plugin IDs, and immutable
 * properties without touching the database or checking permissions.
 */
class ConfigStructuralValidator {

  use ConfigEntityMetadataTrait;

  /**
   * Constructs a ConfigStructuralValidator.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\daedalus_blueprint_config\PropertyHandler\PluginCollectionPropertyHandler $pluginCollectionHandler
   *   The plugin collection handler for plugin ID validation.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected PluginCollectionPropertyHandler $pluginCollectionHandler,
  ) {}

  /**
   * Validates a config entity blueprint structurally.
   *
   * @param array $blueprint
   *   The blueprint data.
   * @param bool $is_update
   *   Whether this is an update operation (id becomes immutable).
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Errors found, or empty array if valid.
   */
  public function validate(array $blueprint, bool $is_update = FALSE): array {
    $errors = [];

    if (empty($blueprint['entity_type'])) {
      $errors[] = new BlueprintError(
        path: 'entity_type',
        code: 'missing_entity_type',
        message: "The 'entity_type' key is required.",
      );
      return $errors;
    }

    $entity_type_id = $blueprint['entity_type'];
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id, FALSE);

    if ($entity_type === NULL) {
      $errors[] = new BlueprintError(
        path: 'entity_type',
        code: 'invalid_entity_type',
        message: "Entity type '$entity_type_id' does not exist.",
      );
      return $errors;
    }

    if (!$entity_type->entityClassImplements(ConfigEntityInterface::class)) {
      $errors[] = new BlueprintError(
        path: 'entity_type',
        code: 'not_config_entity',
        message: "Entity type '$entity_type_id' is not a config entity.",
      );
      return $errors;
    }

    $errors = array_merge($errors, $this->validateProperties($blueprint, $entity_type_id, $is_update));
    $errors = array_merge($errors, $this->validatePluginCollections($blueprint, $entity_type_id));

    return $errors;
  }

  /**
   * Validates properties in the blueprint.
   *
   * @param array $blueprint
   *   The blueprint data.
   * @param string $entity_type_id
   *   The entity type ID.
   * @param bool $is_update
   *   Whether this is an update.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Errors found.
   */
  protected function validateProperties(array $blueprint, string $entity_type_id, bool $is_update): array {
    $errors = [];
    $properties = $blueprint['properties'] ?? [];

    if (!is_array($properties)) {
      $errors[] = new BlueprintError(
        path: 'properties',
        code: 'invalid_type',
        message: "The 'properties' key must be an object.",
      );
      return $errors;
    }

    $immutable = $this->getImmutableProperties($entity_type_id);

    foreach ($properties as $key => $value) {
      if (in_array($key, static::$systemProperties, TRUE)) {
        $errors[] = new BlueprintError(
          path: "properties.$key",
          code: 'system_property',
          message: "Cannot set system property '$key'.",
        );
        continue;
      }

      if ($is_update && in_array($key, $immutable, TRUE)) {
        $errors[] = new BlueprintError(
          path: "properties.$key",
          code: 'immutable_property',
          message: "Cannot modify immutable property '$key' during update.",
        );
      }
    }

    return $errors;
  }

  /**
   * Validates plugin collections in the blueprint.
   *
   * @param array $blueprint
   *   The blueprint data.
   * @param string $entity_type_id
   *   The entity type ID.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Errors found.
   */
  protected function validatePluginCollections(array $blueprint, string $entity_type_id): array {
    $errors = [];
    $collections = $blueprint['plugin_collections'] ?? [];

    if (empty($collections)) {
      return $errors;
    }

    if (!is_array($collections)) {
      $errors[] = new BlueprintError(
        path: 'plugin_collections',
        code: 'invalid_type',
        message: "The 'plugin_collections' key must be an object.",
      );
      return $errors;
    }

    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
    $class = $entity_type->getClass();

    if (!is_subclass_of($class, EntityWithPluginCollectionInterface::class)) {
      $errors[] = new BlueprintError(
        path: 'plugin_collections',
        code: 'no_plugin_collections',
        message: "Entity type '$entity_type_id' does not support plugin collections.",
      );
      return $errors;
    }

    // Create a temporary entity to validate plugin IDs.
    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    $entity = $storage->create([]);

    if (!$entity instanceof EntityWithPluginCollectionInterface) {
      return $errors;
    }

    $available_collections = $entity->getPluginCollections();

    foreach ($collections as $collection_name => $items) {
      if (!isset($available_collections[$collection_name])) {
        $errors[] = new BlueprintError(
          path: "plugin_collections.$collection_name",
          code: 'unknown_collection',
          message: "Plugin collection '$collection_name' does not exist on '$entity_type_id'.",
        );
        continue;
      }

      if (!is_array($items)) {
        $errors[] = new BlueprintError(
          path: "plugin_collections.$collection_name",
          code: 'invalid_type',
          message: "Plugin collection '$collection_name' must be an array.",
        );
        continue;
      }

      $manager = $this->pluginCollectionHandler->getPluginManager($available_collections[$collection_name]);
      if ($manager === NULL) {
        continue;
      }

      foreach ($items as $index => $item) {
        if (!is_array($item) || !isset($item['id'])) {
          $errors[] = new BlueprintError(
            path: "plugin_collections.$collection_name.$index",
            code: 'missing_plugin_id',
            message: "Each plugin entry must have an 'id' key.",
          );
          continue;
        }

        if (!$manager->hasDefinition($item['id'])) {
          $errors[] = new BlueprintError(
            path: "plugin_collections.$collection_name.$index.id",
            code: 'invalid_plugin_id',
            message: "Plugin '{$item['id']}' does not exist in collection '$collection_name'.",
            hint: 'Use eb_config_read_schema to see available plugins.',
          );
        }
      }
    }

    return $errors;
  }

}
