<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Dispatch;

use Drupal\Core\Entity\EntityInterface;
use Drupal\daedalus_blueprint\Result\BlueprintResultInterface;

/**
 * Defines the contract for an entity blueprint backend.
 *
 * Each backend handles a family of entity types (content entities, config
 * entities, etc.) and provides serialization, deserialization, validation,
 * and persistence for those types.
 */
interface BlueprintBackendInterface {

  /**
   * Determines whether this backend supports the given entity type.
   *
   * @param string $entity_type_id
   *   The entity type ID to check.
   *
   * @return bool
   *   TRUE if this backend can handle the entity type.
   */
  public function supports(string $entity_type_id): bool;

  /**
   * Determines whether this backend hosts the given entity.
   *
   * The entity-level door: what an entity IS decides its backend (a
   * Layout Builder display is a page document, another config entity is
   * config), which a type id alone cannot tell.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to check.
   *
   * @return bool
   *   TRUE if this backend can handle the entity.
   */
  public function supportsEntity(EntityInterface $entity): bool;

  /**
   * Serializes an entity to a full blueprint array.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to serialize.
   * @param string|null $langcode
   *   The language code for translation output (content entities). NULL for
   *   the default language. Ignored by backends that do not support
   *   translations.
   *
   * @return array
   *   The blueprint array.
   */
  public function serialize(EntityInterface $entity, ?string $langcode = NULL): array;

  /**
   * Serializes an entity to a structural summary.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to summarize.
   * @param string|null $langcode
   *   The language code for translation output (content entities). NULL for
   *   the default language. Ignored by backends that do not support
   *   translations.
   *
   * @return array
   *   The summary array.
   */
  public function serializeSummary(EntityInterface $entity, ?string $langcode = NULL): array;

  /**
   * Gets the descriptive schema for an entity type and optional bundle.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string|null $bundle
   *   The bundle machine name, or NULL for entity types without bundles.
   * @param string|null $view_mode
   *   The Layout Builder view mode to filter available block types by
   *   restrictions (content entities only). NULL to skip filtering.
   *
   * @return array
   *   The schema array.
   */
  public function getSchema(string $entity_type_id, ?string $bundle = NULL, ?string $view_mode = NULL): array;

  /**
   * Creates an entity from a blueprint array.
   *
   * @param array $blueprint
   *   The full blueprint data array.
   * @param array $options
   *   Deserialization options forwarded to field handlers (e.g.
   *   decode_html_entities for the AI plain-text entity-escape defense).
   *   Backends without field handlers may ignore this.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResultInterface
   *   The result with the unsaved entity on success.
   */
  public function create(array $blueprint, array $options = []): BlueprintResultInterface;

  /**
   * Updates an entity with the given data.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to update.
   * @param array $data
   *   The update data (field values for content, properties for config).
   * @param array $options
   *   Deserialization options forwarded to field handlers (e.g.
   *   decode_html_entities for the AI plain-text entity-escape defense).
   *   Backends without field handlers may ignore this.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResultInterface
   *   The result with the modified entity on success.
   */
  public function update(EntityInterface $entity, array $data, array $options = []): BlueprintResultInterface;

  /**
   * Validates a blueprint without creating or modifying an entity.
   *
   * @param array $blueprint
   *   The blueprint data to validate.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResultInterface
   *   The validation result.
   */
  public function validate(array $blueprint): BlueprintResultInterface;

  /**
   * Applies multiple operations atomically to a single entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to modify.
   * @param array $operations
   *   Array of operation definitions.
   * @param array $options
   *   Deserialization options forwarded to field handlers (e.g.
   *   decode_html_entities for the AI plain-text entity-escape defense).
   *   Backends without field handlers may ignore this.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResultInterface
   *   The result with the modified entity on success.
   */
  public function batchOperations(EntityInterface $entity, array $operations, array $options = []): BlueprintResultInterface;

  /**
   * Persists an entity to the appropriate storage backend.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to persist.
   * @param bool $is_new
   *   TRUE if this is a newly created entity.
   */
  public function persist(EntityInterface $entity, bool $is_new = FALSE): void;

  /**
   * Loads an entity by type and ID.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param int|string $entity_id
   *   The entity ID.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The loaded entity, or NULL if not found.
   */
  public function load(string $entity_type_id, int|string $entity_id): ?EntityInterface;

  /**
   * Returns whether this backend requires an active workspace for writes.
   *
   * @return bool
   *   TRUE if write operations require an active workspace and none is active.
   */
  public function requiresWorkspace(): bool;

}
