<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_config\PropertyHandler;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityWithPluginCollectionInterface;
use Drupal\Core\Plugin\DefaultLazyPluginCollection;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\daedalus_blueprint\Result\BlueprintError;

/**
 * Handles plugin collection properties on config entities.
 *
 * Detects EntityWithPluginCollectionInterface entities, introspects their
 * plugin managers via reflection, and serializes/deserializes plugin
 * instances with their configuration.
 */
class PluginCollectionPropertyHandler extends PropertyHandlerBase {

  /**
   * {@inheritdoc}
   */
  public function applies(string $schema_type, ?DataDefinitionInterface $definition = NULL, ?ConfigEntityInterface $entity = NULL, ?string $property_name = NULL): bool {
    if ($entity === NULL || $property_name === NULL) {
      return FALSE;
    }

    if (!$entity instanceof EntityWithPluginCollectionInterface) {
      return FALSE;
    }

    $collections = $entity->getPluginCollections();
    return isset($collections[$property_name]);
  }

  /**
   * {@inheritdoc}
   */
  public function serialize(mixed $value, string $property_name, ?DataDefinitionInterface $definition = NULL, ?ConfigEntityInterface $entity = NULL): PropertyHandlerResult {
    if (!$entity instanceof EntityWithPluginCollectionInterface) {
      return PropertyHandlerResult::success($value);
    }

    $collections = $entity->getPluginCollections();
    if (!isset($collections[$property_name])) {
      return PropertyHandlerResult::success($value);
    }

    $collection = $collections[$property_name];
    $items = [];
    foreach ($collection as $instance_id => $plugin) {
      $item = [
        'id' => $plugin->getPluginId(),
        'instance_id' => $instance_id,
      ];
      $configuration = $plugin->getConfiguration();
      unset($configuration['id']);
      if (!empty($configuration)) {
        $item['configuration'] = $configuration;
      }
      $items[] = $item;
    }

    return PropertyHandlerResult::success($items);
  }

  /**
   * {@inheritdoc}
   */
  public function deserialize(mixed $value, string $property_name, ?DataDefinitionInterface $definition = NULL, ?ConfigEntityInterface $entity = NULL): PropertyHandlerResult {
    if ($value === NULL) {
      return PropertyHandlerResult::success([]);
    }

    if (!is_array($value)) {
      return PropertyHandlerResult::failure([
        new BlueprintError(
          path: "plugin_collections.$property_name",
          code: 'invalid_type',
          message: "Expected array for plugin collection '$property_name', got " . gettype($value) . '.',
        ),
      ]);
    }

    $errors = [];
    foreach ($value as $index => $item) {
      if (!is_array($item) || !isset($item['id'])) {
        $errors[] = new BlueprintError(
          path: "plugin_collections.$property_name.$index",
          code: 'missing_plugin_id',
          message: "Each plugin entry must have an 'id' key.",
        );
      }
    }

    if (!empty($errors)) {
      return PropertyHandlerResult::failure($errors);
    }

    return PropertyHandlerResult::success($value);
  }

  /**
   * {@inheritdoc}
   */
  public function getSchema(string $property_name, ?DataDefinitionInterface $definition = NULL, ?ConfigEntityInterface $entity = NULL): array {
    $schema = [
      'name' => $property_name,
      'label' => $property_name,
      'type' => 'plugin_collection',
    ];

    if (!$entity instanceof EntityWithPluginCollectionInterface) {
      return $schema;
    }

    $collections = $entity->getPluginCollections();
    if (!isset($collections[$property_name])) {
      return $schema;
    }

    $manager = $this->getPluginManager($collections[$property_name]);
    if ($manager !== NULL) {
      $available = [];
      foreach ($manager->getDefinitions() as $plugin_id => $plugin_definition) {
        $available[$plugin_id] = [
          'id' => $plugin_id,
          'label' => (string) ($plugin_definition['label'] ?? $plugin_definition['title'] ?? $plugin_id),
        ];
      }
      if (!empty($available)) {
        $schema['available_plugins'] = $available;
      }
    }

    return $schema;
  }

  /**
   * {@inheritdoc}
   */
  public function getPriority(): int {
    return 10;
  }

  /**
   * Extracts the plugin manager from a plugin collection via reflection.
   *
   * @param object $collection
   *   The plugin collection instance.
   *
   * @return \Drupal\Component\Plugin\PluginManagerInterface|null
   *   The plugin manager, or NULL if extraction fails.
   */
  public function getPluginManager(object $collection): ?PluginManagerInterface {
    if (!$collection instanceof DefaultLazyPluginCollection) {
      return NULL;
    }

    try {
      $reflection = new \ReflectionProperty(DefaultLazyPluginCollection::class, 'manager');
      $manager = $reflection->getValue($collection);
      if ($manager instanceof PluginManagerInterface) {
        return $manager;
      }
    }
    catch (\ReflectionException) {
      // Property does not exist or is inaccessible.
    }

    return NULL;
  }

}
