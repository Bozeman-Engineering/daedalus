<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_config\PropertyHandler;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;

/**
 * Defines the interface for config property handlers.
 *
 * Each handler knows how to serialize and deserialize a specific config schema
 * data type (scalar, mapping, sequence, plugin collection).
 */
interface PropertyHandlerInterface {

  /**
   * Determines whether this handler applies to the given schema type.
   *
   * @param string $schema_type
   *   The typed config schema type (e.g., 'string', 'mapping', 'sequence').
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $definition
   *   The data definition, if available.
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface|null $entity
   *   The entity context, if available.
   * @param string|null $property_name
   *   The property name being handled.
   *
   * @return bool
   *   TRUE if this handler can process the type.
   */
  public function applies(string $schema_type, ?DataDefinitionInterface $definition = NULL, ?ConfigEntityInterface $entity = NULL, ?string $property_name = NULL): bool;

  /**
   * Serializes a config property value for blueprint output.
   *
   * @param mixed $value
   *   The raw config value.
   * @param string $property_name
   *   The property name.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $definition
   *   The data definition for the property.
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface|null $entity
   *   The entity context.
   *
   * @return \Drupal\daedalus_blueprint_config\PropertyHandler\PropertyHandlerResult
   *   The serialization result.
   */
  public function serialize(mixed $value, string $property_name, ?DataDefinitionInterface $definition = NULL, ?ConfigEntityInterface $entity = NULL): PropertyHandlerResult;

  /**
   * Deserializes a blueprint value into config property format.
   *
   * @param mixed $value
   *   The blueprint value.
   * @param string $property_name
   *   The property name.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $definition
   *   The data definition for the property.
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface|null $entity
   *   The entity context.
   *
   * @return \Drupal\daedalus_blueprint_config\PropertyHandler\PropertyHandlerResult
   *   The deserialization result.
   */
  public function deserialize(mixed $value, string $property_name, ?DataDefinitionInterface $definition = NULL, ?ConfigEntityInterface $entity = NULL): PropertyHandlerResult;

  /**
   * Returns the schema representation for this property type.
   *
   * @param string $property_name
   *   The property name.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $definition
   *   The data definition.
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface|null $entity
   *   The entity context.
   *
   * @return array
   *   Schema array describing the property.
   */
  public function getSchema(string $property_name, ?DataDefinitionInterface $definition = NULL, ?ConfigEntityInterface $entity = NULL): array;

  /**
   * Returns the handler's priority.
   *
   * Higher priority handlers are checked first. Specialized handlers (e.g.,
   * PluginCollection) should have higher priority than generic ones (Mapping).
   *
   * @return int
   *   The priority.
   */
  public function getPriority(): int;

}
