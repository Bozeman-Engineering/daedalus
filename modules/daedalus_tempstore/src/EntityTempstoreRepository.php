<?php

namespace Drupal\daedalus_tempstore;

use Drupal\Core\Entity\EntityInterface;

/**
 * Entity tempstore repository.
 *
 * This provides entity-based temporary storage using the strategy pattern.
 * It delegates to appropriate strategies based on entity type.
 */
class EntityTempstoreRepository extends TempstoreRepository {

  /**
   * Gets an entity from tempstore.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to retrieve from tempstore.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The entity from tempstore, or the original entity if not found.
   */
  public function get(EntityInterface $entity) {
    return $this->getStrategy($entity)->get($entity);
  }

  /**
   * Stores an entity in tempstore.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to store in tempstore.
   */
  public function set(EntityInterface $entity): void {
    $this->getStrategy($entity)->set($entity);
  }

  /**
   * Checks if an entity exists in tempstore.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to check.
   *
   * @return bool
   *   TRUE if the entity exists in tempstore, FALSE otherwise.
   */
  public function has(EntityInterface $entity): bool {
    return $this->getStrategy($entity)->has($entity);
  }

  /**
   * Deletes an entity from tempstore.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to delete from tempstore.
   */
  public function delete(EntityInterface $entity): void {
    $this->getStrategy($entity)->delete($entity);
  }

}
