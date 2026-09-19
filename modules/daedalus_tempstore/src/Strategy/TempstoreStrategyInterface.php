<?php

namespace Drupal\daedalus_tempstore\Strategy;

/**
 * Interface for tempstore operation strategies.
 *
 * Strategies encapsulate different approaches to storing, retrieving, and
 * managing tempstore data. This allows for clean separation of concerns
 * between different storage types (e.g., Layout Builder, Page Manager,
 * Non-LB Content Entities) without deep inheritance chains.
 */
interface TempstoreStrategyInterface {

  /**
   * Determines if this strategy can handle the given subject.
   *
   * @param mixed $subject
   *   The subject to check (SectionStorageInterface, EntityInterface, etc.).
   *
   * @return bool
   *   TRUE if this strategy supports the subject, FALSE otherwise.
   */
  public function supports($subject): bool;

  /**
   * Retrieves an item from tempstore.
   *
   * @param mixed $subject
   *   The subject to retrieve (SectionStorageInterface, EntityInterface, etc.).
   *
   * @return mixed|null
   *   The stored item, or NULL if not found.
   */
  public function get($subject);

  /**
   * Stores an item in tempstore.
   *
   * @param mixed $subject
   *   The subject to store (SectionStorageInterface, EntityInterface, etc.).
   */
  public function set($subject): void;

  /**
   * Checks if an item exists in tempstore.
   *
   * @param mixed $subject
   *   The subject to check (SectionStorageInterface, EntityInterface, etc.).
   *
   * @return bool
   *   TRUE if the item exists in tempstore, FALSE otherwise.
   */
  public function has($subject): bool;

  /**
   * Deletes an item from tempstore.
   *
   * @param mixed $subject
   *   The subject to delete (SectionStorageInterface, EntityInterface, etc.).
   */
  public function delete($subject): void;

  /**
   * Generates the tempstore key for the given subject.
   *
   * @param mixed $subject
   *   The subject to generate a key for.
   *
   * @return string
   *   The tempstore key.
   */
  public function getKey($subject): string;

  /**
   * Gets the tempstore collection name for the given subject.
   *
   * @param mixed $subject
   *   The subject to get the collection for.
   *
   * @return string
   *   The tempstore collection name.
   */
  public function getCollection($subject): string;

}
