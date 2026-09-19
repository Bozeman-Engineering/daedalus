<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_config\PropertyHandler;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\daedalus_blueprint\Result\BlueprintError;

/**
 * Handles mapping (associative array/object) config properties.
 */
class MappingPropertyHandler extends PropertyHandlerBase {

  /**
   * {@inheritdoc}
   */
  public function applies(string $schema_type, ?DataDefinitionInterface $definition = NULL, ?ConfigEntityInterface $entity = NULL, ?string $property_name = NULL): bool {
    return $schema_type === 'mapping';
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
          path: "properties.$property_name",
          code: 'invalid_type',
          message: "Expected object/mapping for '$property_name', got " . gettype($value) . '.',
        ),
      ]);
    }

    return PropertyHandlerResult::success($value);
  }

  /**
   * {@inheritdoc}
   */
  public function getSchema(string $property_name, ?DataDefinitionInterface $definition = NULL, ?ConfigEntityInterface $entity = NULL): array {
    $schema = parent::getSchema($property_name, $definition, $entity);
    $schema['type'] = 'mapping';
    return $schema;
  }

  /**
   * {@inheritdoc}
   */
  public function getPriority(): int {
    return -20;
  }

}
