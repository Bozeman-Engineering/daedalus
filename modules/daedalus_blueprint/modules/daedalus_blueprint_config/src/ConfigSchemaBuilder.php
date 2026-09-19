<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_config;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityWithPluginCollectionInterface;
use Drupal\daedalus_blueprint_config\PropertyHandler\PropertyHandlerManager;

/**
 * Builds descriptive schemas for config entity types.
 *
 * Uses TypedConfigManager to introspect config schema definitions and
 * PropertyHandlerManager for plugin collection schemas.
 */
class ConfigSchemaBuilder {

  use ConfigEntityMetadataTrait;

  /**
   * Constructs a ConfigSchemaBuilder.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfigManager
   *   The typed config manager.
   * @param \Drupal\daedalus_blueprint_config\PropertyHandler\PropertyHandlerManager $propertyHandlerManager
   *   The property handler manager.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TypedConfigManagerInterface $typedConfigManager,
    protected PropertyHandlerManager $propertyHandlerManager,
  ) {}

  /**
   * Gets the descriptive schema for a config entity type.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   *
   * @return array
   *   The schema array with entity_type, label, properties, and
   *   plugin_collections keys.
   *
   * @throws \InvalidArgumentException
   *   If the entity type does not exist or is not a config entity.
   */
  public function getSchema(string $entity_type_id): array {
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id, FALSE);
    if ($entity_type === NULL) {
      throw new \InvalidArgumentException("Entity type '$entity_type_id' does not exist.");
    }

    if (!$entity_type->entityClassImplements(ConfigEntityInterface::class)) {
      throw new \InvalidArgumentException("Entity type '$entity_type_id' is not a config entity.");
    }

    $schema = [
      'entity_type' => $entity_type_id,
      'label' => (string) $entity_type->getLabel(),
    ];

    $config_prefix = $entity_type->getConfigPrefix();
    $typed_definition = $this->resolveTypedDefinition($config_prefix);

    $properties = [];
    $plugin_collection_keys = $this->getPluginCollectionKeys($entity_type_id);

    if ($typed_definition !== NULL && isset($typed_definition['mapping'])) {
      foreach ($typed_definition['mapping'] as $key => $property_schema) {
        if (in_array($key, static::$systemProperties, TRUE)) {
          continue;
        }

        if (in_array($key, $plugin_collection_keys, TRUE)) {
          continue;
        }

        $property_info = [
          'name' => $key,
          'type' => $property_schema['type'] ?? 'string',
        ];
        if (isset($property_schema['label'])) {
          $property_info['label'] = (string) $property_schema['label'];
        }
        $properties[$key] = $property_info;
      }
    }

    if (!empty($properties)) {
      $schema['properties'] = $properties;
    }

    if (!empty($plugin_collection_keys)) {
      $plugin_schemas = $this->buildPluginCollectionSchemas($entity_type_id, $plugin_collection_keys);
      if (!empty($plugin_schemas)) {
        $schema['plugin_collections'] = $plugin_schemas;
      }
    }

    $immutable = $this->getImmutableProperties($entity_type_id);
    if (!empty($immutable)) {
      $schema['immutable_properties'] = $immutable;
    }

    return $schema;
  }

  /**
   * Resolves the typed config definition for a config prefix.
   *
   * Config entity IDs can have varying numbers of dot-separated segments
   * (e.g., field.storage.node.field_body has 2 parts after the prefix).
   * This method tries progressively more wildcards until a definition with
   * a mapping is found.
   *
   * @param string $config_prefix
   *   The config prefix (e.g., 'field.storage').
   *
   * @return array|null
   *   The typed config definition, or NULL if not found.
   */
  protected function resolveTypedDefinition(string $config_prefix): ?array {
    for ($wildcards = 1; $wildcards <= 4; $wildcards++) {
      $schema_name = $config_prefix . str_repeat('.*', $wildcards);
      $definition = $this->typedConfigManager->getDefinition($schema_name, FALSE);
      if ($definition !== NULL && isset($definition['mapping'])) {
        return $definition;
      }
    }
    return NULL;
  }

  /**
   * Gets plugin collection keys for an entity type.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   *
   * @return string[]
   *   Plugin collection property names.
   */
  protected function getPluginCollectionKeys(string $entity_type_id): array {
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
    $class = $entity_type->getClass();

    if (!is_subclass_of($class, EntityWithPluginCollectionInterface::class)) {
      return [];
    }

    $entity = $this->createOrLoadEntity($entity_type_id);
    if ($entity instanceof EntityWithPluginCollectionInterface) {
      return array_keys($entity->getPluginCollections());
    }

    return [];
  }

  /**
   * Builds schemas for plugin collections.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string[] $collection_keys
   *   The plugin collection property names.
   *
   * @return array
   *   Schema arrays keyed by collection name.
   */
  protected function buildPluginCollectionSchemas(string $entity_type_id, array $collection_keys): array {
    $schemas = [];

    $entity = $this->createOrLoadEntity($entity_type_id);

    if (!$entity instanceof EntityWithPluginCollectionInterface) {
      return $schemas;
    }

    $collections = $entity->getPluginCollections();

    foreach ($collection_keys as $key) {
      if (!isset($collections[$key])) {
        continue;
      }
      $handler = $this->propertyHandlerManager->getHandler(
        'plugin_collection',
        NULL,
        $entity,
        $key,
      );
      if ($handler !== NULL) {
        $schemas[$key] = $handler->getSchema($key, NULL, $entity);
      }
      else {
        $schemas[$key] = [
          'name' => $key,
          'type' => 'plugin_collection',
        ];
      }
    }

    return $schemas;
  }

  /**
   * Creates or loads an entity instance for introspection.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   *
   * @return \Drupal\Core\Config\Entity\ConfigEntityInterface|null
   *   An entity instance, or NULL if none could be obtained.
   */
  protected function createOrLoadEntity(string $entity_type_id): ?ConfigEntityInterface {
    $storage = $this->entityTypeManager->getStorage($entity_type_id);

    try {
      return $storage->create([]);
    }
    catch (\InvalidArgumentException) {
      $entities = $storage->loadMultiple();
      return reset($entities) ?: NULL;
    }
  }

}
