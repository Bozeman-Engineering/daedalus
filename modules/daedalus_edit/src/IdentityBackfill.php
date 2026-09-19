<?php

declare(strict_types=1);

namespace Drupal\daedalus_edit;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * One-time backfill of identity classes onto existing content.
 *
 * Content written before eager identity landed carries no s-* classes, and
 * the presave backstop only reaches entities that get saved again. This
 * service walks existing content in id-ordered chunks and re-saves exactly
 * the entities minting changes, so cold-loaded legacy pages become
 * addressable without waiting for an organic edit. Exposed through the
 * daedalus_edit:mint-identities drush command and the
 * daedalus_edit_post_update_mint_identity_classes() batch.
 *
 * Scope: current default revisions only. Older revisions mint on their next
 * save (revert, workflow transition) via the presave backstop; rewriting
 * revision history for identity would churn every table for pages nobody
 * renders. Saving here updates 'changed' timestamps, the honest signal that
 * stored values changed.
 */
final class IdentityBackfill {

  /**
   * Core's formatted text field types, used to find candidate entity types.
   *
   * Custom field types exposing a filter_format property are still minted by
   * the presave backstop on their next save; the field map can only be
   * queried by concrete type, so the backfill sweep covers the core three.
   */
  private const FORMATTED_TEXT_TYPES = ['text', 'text_long', 'text_with_summary'];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly EntityIdentityMinting $entityIdentityMinting,
  ) {}

  /**
   * Entity type ids that carry formatted text fields.
   *
   * @return string[]
   *   Sorted entity type ids.
   */
  public function entityTypeIds(): array {
    $ids = [];
    foreach (self::FORMATTED_TEXT_TYPES as $field_type) {
      foreach ($this->entityFieldManager->getFieldMapByFieldType($field_type) as $entity_type_id => $fields) {
        $ids[$entity_type_id] = TRUE;
      }
    }
    $ids = array_keys($ids);
    sort($ids);
    return $ids;
  }

  /**
   * Mints one id-ordered chunk of an entity type's content.
   *
   * Minting runs here (not just in the save's presave hook) so untouched
   * entities are detected without saving them; the save then re-mints as a
   * no-op.
   *
   * @param string $entity_type_id
   *   The entity type to process.
   * @param int|string|null $after_id
   *   Process entities with ids greater than this; NULL starts from the
   *   beginning.
   * @param int $limit
   *   Maximum entities to load in this chunk.
   *
   * @return array{last_id: int|string|null, processed: int, saved: int}
   *   The last processed id (feed it back as $after_id; NULL when the chunk
   *   was empty), how many entities were checked, and how many needed
   *   minting and were saved.
   */
  public function processChunk(string $entity_type_id, int|string|null $after_id = NULL, int $limit = 50): array {
    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    $id_key = $this->entityTypeManager->getDefinition($entity_type_id)->getKey('id');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->sort($id_key)
      ->range(0, $limit);
    if ($after_id !== NULL) {
      $query->condition($id_key, $after_id, '>');
    }
    $ids = array_values($query->execute());

    $saved = 0;
    foreach ($storage->loadMultiple($ids) as $entity) {
      if ($entity instanceof FieldableEntityInterface && $this->entityIdentityMinting->mintEntity($entity)) {
        $entity->save();
        $saved++;
      }
    }

    return [
      'last_id' => $ids === [] ? NULL : end($ids),
      'processed' => count($ids),
      'saved' => $saved,
    ];
  }

}
