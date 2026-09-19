<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint;

use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * Serializes Drupal entities to blueprint JSON format.
 */
interface BlueprintSerializerInterface {

  /**
   * Serializes an entity to a full blueprint array.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to serialize.
   * @param string|null $langcode
   *   The language code for translation output. NULL for default language.
   *
   * @return array
   *   The blueprint array with entity_type, bundle, fields, and optionally
   *   _root_entity and layout_builder__layout.
   */
  public function serialize(FieldableEntityInterface $entity, ?string $langcode = NULL): array;

  /**
   * Serializes an entity to a structural summary.
   *
   * Shows the full tree hierarchy at all depths but never includes field
   * values. Used as a "table of contents" for AI navigation.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to summarize.
   * @param string|null $langcode
   *   The language code for translation output. NULL for default language.
   *
   * @return array
   *   The summary blueprint array.
   */
  public function serializeSummary(FieldableEntityInterface $entity, ?string $langcode = NULL): array;

  /**
   * Serializes a single element by UUID.
   *
   * Walks the entity's full structure to find the element with the given
   * UUID. Returns the element's immediate field values in full, with any
   * nested content returned as summary data.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity containing the element.
   * @param string $uuid
   *   The UUID of the element to serialize.
   * @param string|null $langcode
   *   The language code for translation output. NULL for default language.
   *
   * @return array|null
   *   The element array, or NULL if the UUID was not found.
   */
  public function serializeElement(FieldableEntityInterface $entity, string $uuid, ?string $langcode = NULL): ?array;

}
