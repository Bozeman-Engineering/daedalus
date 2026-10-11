<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Dispatch;

use Drupal\Core\Entity\EntityInterface;

/**
 * Resolves entities and entity types to the appropriate blueprint backend.
 *
 * Uses the service_collector pattern to aggregate backends. Backends are
 * checked in priority order (highest first); the first backend that claims
 * the entity (supportsEntity()) or the entity type (supports()) wins. An
 * entity resolves by what it is, not by its type id alone: a Layout
 * Builder display is a page document (the content backend's, once the
 * display host lands) while every other config entity stays with the
 * config backend, and only the entity can tell the two apart.
 */
class BlueprintBackendResolver {

  /**
   * Registered backends, sorted by priority.
   *
   * @var \Drupal\daedalus_blueprint\Dispatch\BlueprintBackendInterface[]
   */
  protected array $backends = [];

  /**
   * Adds a backend to the registry.
   *
   * @param \Drupal\daedalus_blueprint\Dispatch\BlueprintBackendInterface $backend
   *   The backend to register.
   */
  public function addBackend(BlueprintBackendInterface $backend): void {
    $this->backends[] = $backend;
  }

  /**
   * Resolves the backend for an entity type.
   *
   * The type-level door, for asks with no entity in hand (a create, a
   * schema read). With an entity, ask resolveFor().
   *
   * @param string $entity_type_id
   *   The entity type ID.
   *
   * @return \Drupal\daedalus_blueprint\Dispatch\BlueprintBackendInterface|null
   *   The matching backend, or NULL if no backend supports the type.
   */
  public function resolve(string $entity_type_id): ?BlueprintBackendInterface {
    foreach ($this->backends as $backend) {
      if ($backend->supports($entity_type_id)) {
        return $backend;
      }
    }
    return NULL;
  }

  /**
   * Resolves the backend for an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return \Drupal\daedalus_blueprint\Dispatch\BlueprintBackendInterface|null
   *   The matching backend, or NULL if no backend claims the entity.
   */
  public function resolveFor(EntityInterface $entity): ?BlueprintBackendInterface {
    foreach ($this->backends as $backend) {
      if ($backend->supportsEntity($entity)) {
        return $backend;
      }
    }
    return NULL;
  }

  /**
   * Checks whether any registered backend supports the entity type.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   *
   * @return bool
   *   TRUE if a backend supports the type.
   */
  public function hasBackend(string $entity_type_id): bool {
    return $this->resolve($entity_type_id) !== NULL;
  }

}
