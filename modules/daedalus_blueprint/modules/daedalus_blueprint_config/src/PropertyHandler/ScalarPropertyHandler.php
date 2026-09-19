<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_config\PropertyHandler;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\daedalus_blueprint\Result\BlueprintError;

/**
 * Handles scalar config properties (string, integer, boolean, float, label).
 */
class ScalarPropertyHandler extends PropertyHandlerBase {

  /**
   * Schema types this handler supports.
   */
  protected const SCALAR_TYPES = [
    'string',
    'integer',
    'float',
    'boolean',
    'label',
    'text',
    'machine_name',
    'path',
    'uri',
    'email',
    'color_hex',
    'langcode',
    'uuid',
  ];

  /**
   * {@inheritdoc}
   */
  public function applies(string $schema_type, ?DataDefinitionInterface $definition = NULL, ?ConfigEntityInterface $entity = NULL, ?string $property_name = NULL): bool {
    return in_array($schema_type, self::SCALAR_TYPES, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function deserialize(mixed $value, string $property_name, ?DataDefinitionInterface $definition = NULL, ?ConfigEntityInterface $entity = NULL): PropertyHandlerResult {
    if ($value === NULL) {
      return PropertyHandlerResult::success(NULL);
    }

    $schema_type = $definition?->getDataType() ?? 'string';

    if ($schema_type === 'boolean') {
      if (!is_bool($value) && !is_int($value)) {
        return PropertyHandlerResult::failure([
          new BlueprintError(
            path: "properties.$property_name",
            code: 'invalid_type',
            message: "Expected boolean for '$property_name', got " . gettype($value) . '.',
          ),
        ]);
      }
      return PropertyHandlerResult::success((bool) $value);
    }

    if ($schema_type === 'integer') {
      if (!is_int($value) && !is_numeric($value)) {
        return PropertyHandlerResult::failure([
          new BlueprintError(
            path: "properties.$property_name",
            code: 'invalid_type',
            message: "Expected integer for '$property_name', got " . gettype($value) . '.',
          ),
        ]);
      }
      return PropertyHandlerResult::success((int) $value);
    }

    if ($schema_type === 'float') {
      if (!is_numeric($value)) {
        return PropertyHandlerResult::failure([
          new BlueprintError(
            path: "properties.$property_name",
            code: 'invalid_type',
            message: "Expected number for '$property_name', got " . gettype($value) . '.',
          ),
        ]);
      }
      return PropertyHandlerResult::success((float) $value);
    }

    if (!is_scalar($value)) {
      return PropertyHandlerResult::failure([
        new BlueprintError(
          path: "properties.$property_name",
          code: 'invalid_type',
          message: "Expected scalar for '$property_name', got " . gettype($value) . '.',
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
    $schema['type'] = $definition?->getDataType() ?? 'string';
    return $schema;
  }

  /**
   * {@inheritdoc}
   */
  public function getPriority(): int {
    return -10;
  }

}
