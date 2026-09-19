<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\FieldHandler;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * Passthrough handler for simple scalars and compound field types.
 *
 * Used as the fallback for any field type without a registered handler.
 * Introspects FieldStorageDefinition::getPropertyDefinitions() to
 * automatically serialize/deserialize all properties for compound field types.
 */
class DefaultFieldHandler extends FieldHandlerBase {

  /**
   * Constructs a new DefaultFieldHandler.
   *
   * @param \Drupal\daedalus_blueprint\FieldHandler\FieldHandlerManager $manager
   *   The field handler manager for field introspection.
   */
  public function __construct(
    protected readonly FieldHandlerManager $manager,
  ) {}

  /**
   * {@inheritdoc}
   */
  protected function doSerialize(FieldItemListInterface $items, FieldDefinitionInterface $definition): array {
    $storage_definition = $definition->getFieldStorageDefinition();
    $main_property = $storage_definition->getMainPropertyName();
    $properties = $this->manager->getNonComputedProperties($storage_definition);

    $values = [];
    foreach ($items as $item) {
      $values[] = $this->serializeItem($item->getValue(), $main_property, $properties);
    }

    return $values;
  }

  /**
   * {@inheritdoc}
   */
  protected function doDeserialize(mixed $value, FieldDefinitionInterface $definition, array $options = []): FieldHandlerResult {
    return new FieldHandlerResult($value);
  }

  /**
   * Serializes a single field item.
   *
   * @param array $item_value
   *   The raw item value array.
   * @param string|null $main_property
   *   The main property name, or NULL.
   * @param string[] $properties
   *   Non-computed property names.
   *
   * @return mixed
   *   A scalar for single-property fields, or an associative array.
   */
  protected function serializeItem(array $item_value, ?string $main_property, array $properties): mixed {
    // Single-property fields: return just the scalar value.
    if (count($properties) === 1 && $main_property !== NULL) {
      return $item_value[$main_property] ?? NULL;
    }

    // Multi-property fields: return object with non-computed properties.
    $result = [];
    foreach ($properties as $property) {
      if (array_key_exists($property, $item_value)) {
        $result[$property] = $item_value[$property];
      }
    }

    return $result;
  }

}
