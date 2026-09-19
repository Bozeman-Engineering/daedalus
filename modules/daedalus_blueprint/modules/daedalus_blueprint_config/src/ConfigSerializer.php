<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_config;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityWithPluginCollectionInterface;
use Drupal\daedalus_blueprint_config\PropertyHandler\PropertyHandlerManager;

/**
 * Serializes config entities to blueprint JSON format.
 *
 * Uses $entity->toArray() as the base representation, enriches with metadata,
 * and delegates plugin collection handling to the property handler system.
 */
class ConfigSerializer {

  use ConfigEntityMetadataTrait;

  /**
   * Constructs a ConfigSerializer.
   *
   * @param \Drupal\daedalus_blueprint_config\PropertyHandler\PropertyHandlerManager $propertyHandlerManager
   *   The property handler manager.
   */
  public function __construct(
    protected PropertyHandlerManager $propertyHandlerManager,
  ) {}

  /**
   * Serializes a config entity to a full blueprint array.
   *
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface $entity
   *   The config entity to serialize.
   *
   * @return array
   *   The blueprint array.
   */
  public function serialize(ConfigEntityInterface $entity): array {
    $values = $entity->toArray();

    $blueprint = [
      'entity_type' => $entity->getEntityTypeId(),
    ];

    if ($entity->id()) {
      $blueprint['id'] = $entity->id();
    }
    if ($entity->label()) {
      $blueprint['label'] = (string) $entity->label();
    }

    $properties = [];
    $plugin_collections = [];

    $collection_keys = $this->getPluginCollectionKeys($entity);

    foreach ($values as $key => $value) {
      if (in_array($key, static::$systemProperties, TRUE)) {
        continue;
      }

      if (in_array($key, $collection_keys, TRUE)) {
        $handler = $this->propertyHandlerManager->getHandler(
          'plugin_collection',
          NULL,
          $entity,
          $key,
        );
        if ($handler !== NULL) {
          $result = $handler->serialize($value, $key, NULL, $entity);
          if ($result->isSuccess()) {
            $plugin_collections[$key] = $result->getValue();
          }
        }
        continue;
      }

      $properties[$key] = $value;
    }

    if (!empty($properties)) {
      $blueprint['properties'] = $properties;
    }
    if (!empty($plugin_collections)) {
      $blueprint['plugin_collections'] = $plugin_collections;
    }

    return $blueprint;
  }

  /**
   * Serializes a config entity to a structural summary.
   *
   * Shows entity type, ID, label, and plugin collection names without full
   * configuration details.
   *
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface $entity
   *   The config entity to summarize.
   *
   * @return array
   *   The summary array.
   */
  public function serializeSummary(ConfigEntityInterface $entity): array {
    $summary = [
      'entity_type' => $entity->getEntityTypeId(),
    ];

    if ($entity->id()) {
      $summary['id'] = $entity->id();
    }
    if ($entity->label()) {
      $summary['label'] = (string) $entity->label();
    }

    $collection_keys = $this->getPluginCollectionKeys($entity);
    if (!empty($collection_keys)) {
      $summary['plugin_collections'] = $collection_keys;
    }

    $values = $entity->toArray();
    $property_keys = array_diff(
      array_keys($values),
      static::$systemProperties,
      $collection_keys,
    );
    if (!empty($property_keys)) {
      $summary['properties'] = array_values($property_keys);
    }

    return $summary;
  }

  /**
   * Gets the config export keys that correspond to plugin collections.
   *
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface $entity
   *   The entity.
   *
   * @return string[]
   *   Plugin collection property names.
   */
  protected function getPluginCollectionKeys(ConfigEntityInterface $entity): array {
    if (!$entity instanceof EntityWithPluginCollectionInterface) {
      return [];
    }
    return array_keys($entity->getPluginCollections());
  }

}
