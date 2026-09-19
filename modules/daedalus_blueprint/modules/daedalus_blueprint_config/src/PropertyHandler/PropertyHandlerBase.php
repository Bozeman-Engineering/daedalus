<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_config\PropertyHandler;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;

/**
 * Base class for property handlers.
 *
 * Provides common patterns: passthrough serialize/deserialize for simple types
 * and a default schema implementation.
 */
abstract class PropertyHandlerBase implements PropertyHandlerInterface {

  /**
   * {@inheritdoc}
   */
  public function serialize(mixed $value, string $property_name, ?DataDefinitionInterface $definition = NULL, ?ConfigEntityInterface $entity = NULL): PropertyHandlerResult {
    return PropertyHandlerResult::success($value);
  }

  /**
   * {@inheritdoc}
   */
  public function deserialize(mixed $value, string $property_name, ?DataDefinitionInterface $definition = NULL, ?ConfigEntityInterface $entity = NULL): PropertyHandlerResult {
    return PropertyHandlerResult::success($value);
  }

  /**
   * {@inheritdoc}
   */
  public function getSchema(string $property_name, ?DataDefinitionInterface $definition = NULL, ?ConfigEntityInterface $entity = NULL): array {
    return [
      'name' => $property_name,
      'label' => $definition?->getLabel() ?: $property_name,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getPriority(): int {
    return 0;
  }

}
