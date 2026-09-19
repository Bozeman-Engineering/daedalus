<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_config\PropertyHandler;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;

/**
 * Resolves config schema types to the appropriate property handler.
 *
 * Uses a service_collector pattern. Handlers are sorted by priority (highest
 * first) and checked via applies() for each property.
 */
class PropertyHandlerManager {

  /**
   * Registered handlers.
   *
   * @var \Drupal\daedalus_blueprint_config\PropertyHandler\PropertyHandlerInterface[]
   */
  protected array $handlers = [];

  /**
   * Whether handlers have been sorted by priority.
   *
   * @var bool
   */
  protected bool $sorted = FALSE;

  /**
   * Adds a handler to the manager.
   *
   * @param \Drupal\daedalus_blueprint_config\PropertyHandler\PropertyHandlerInterface $handler
   *   The handler to register.
   */
  public function addHandler(PropertyHandlerInterface $handler): void {
    $this->handlers[] = $handler;
    $this->sorted = FALSE;
  }

  /**
   * Resolves the handler for a given schema type and context.
   *
   * @param string $schema_type
   *   The typed config schema type.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $definition
   *   The data definition.
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface|null $entity
   *   The entity context.
   * @param string|null $property_name
   *   The property name.
   *
   * @return \Drupal\daedalus_blueprint_config\PropertyHandler\PropertyHandlerInterface|null
   *   The matching handler, or NULL if none applies.
   */
  public function getHandler(string $schema_type, ?DataDefinitionInterface $definition = NULL, ?ConfigEntityInterface $entity = NULL, ?string $property_name = NULL): ?PropertyHandlerInterface {
    $this->sortHandlers();
    foreach ($this->handlers as $handler) {
      if ($handler->applies($schema_type, $definition, $entity, $property_name)) {
        return $handler;
      }
    }
    return NULL;
  }

  /**
   * Sorts handlers by priority (highest first).
   */
  protected function sortHandlers(): void {
    if ($this->sorted) {
      return;
    }
    usort($this->handlers, fn(PropertyHandlerInterface $a, PropertyHandlerInterface $b) => $b->getPriority() <=> $a->getPriority());
    $this->sorted = TRUE;
  }

}
