<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\StyleMapItem;

/**
 * The daedalus_variant_usage table: which host entities wear which variants.
 *
 * The inline_block_usage pattern: instead of parsing content on demand (the
 * retired LIKE scan plus load-everything walk in VariantManager::usages()),
 * a dedicated table mirrors the variant references in every saved style map,
 * maintained at the map field's one chokepoint, the host entity save (every
 * map write, whether from the op spine, a blueprint write, or a programmatic
 * save, ends in one). All usage consumers read it: push impact, the
 * card's per-open usage read, wearer lists, delete gating.
 *
 * Rows are (variant, entity_type, entity_id): one row per distinct reference
 * string per host entity. The variant column stores the reference VERBATIM
 * as the map carries it (machine name or uuid), never resolved at write
 * time, so the table's content is a pure function of the saved maps and an
 * incremental save produces exactly what rebuild() produces. A variant is
 * looked up by both of its reference forms, and wearer counts collapse the
 * two forms per entity via DISTINCT.
 *
 * Tracked against DEFAULT revisions only, matching what a push affects: the
 * compiled stylesheet live pages load. Workspace publish promotes staged
 * revisions without firing per-entity update hooks, so
 * VariantWorkspaceSubscriber resyncs the published hosts (resyncHosts()) —
 * the delete batch's work list must be exact. One accepted staleness edge
 * remains, repaired by rebuild() (drush daedalus_stylish:variant-usage:rebuild):
 * writes that bypass the entity save API are invisible to any hook.
 */
class VariantUsage {

  /**
   * The usage table name.
   */
  public const TABLE = 'daedalus_variant_usage';

  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * Syncs a saved map host's rows with its style map (the chokepoint).
   *
   * Called from daedalus_stylish_entity_insert()/daedalus_stylish_entity_update() for every
   * entity carrying the map field. Non-default revision saves (workspace
   * staging, pending revisions) are skipped: the table mirrors the default
   * revision the compiled stylesheet serves. Saves that neither carry nor
   * previously carried references never touch the database, so the common
   * unstyled save costs nothing.
   */
  public function track(FieldableEntityInterface $entity): void {
    if ($entity instanceof RevisionableInterface && !$entity->isDefaultRevision()) {
      return;
    }
    $references = $this->mapReferences($entity);
    $original = $entity->getOriginal();
    $previous = $original instanceof FieldableEntityInterface ? $this->mapReferences($original) : [];
    if ($references === $previous) {
      return;
    }
    $this->database->delete(self::TABLE)
      ->condition('entity_type', $entity->getEntityTypeId())
      ->condition('entity_id', (string) $entity->id())
      ->execute();
    $this->insertReferences($entity->getEntityTypeId(), (string) $entity->id(), $references);
  }

  /**
   * Drops a deleted map host's rows.
   *
   * Gated on the deleted entity's own map carrying references: track()'s
   * invariant is that rows exist exactly for reference-carrying default
   * revisions, so a reference-free map has nothing to drop. An entity whose
   * table rows drifted from its map (the accepted staleness edges in the
   * class docblock) is rebuild()'s job either way.
   */
  public function untrack(FieldableEntityInterface $entity): void {
    if ($this->mapReferences($entity) === []) {
      return;
    }
    $this->database->delete(self::TABLE)
      ->condition('entity_type', $entity->getEntityTypeId())
      ->condition('entity_id', (string) $entity->id())
      ->execute();
  }

  /**
   * Re-mirrors ONE host entity's rows from its saved map, unconditionally.
   *
   * The single-host repair: unlike track(), no original-comparison gate —
   * this is for hosts whose default revision changed WITHOUT a save
   * (workspace publish promotes staged revisions), so there is no original
   * to compare against and the rows must simply match the map now.
   */
  public function resync(FieldableEntityInterface $entity): void {
    if ($entity instanceof RevisionableInterface && !$entity->isDefaultRevision()) {
      return;
    }
    $this->database->delete(self::TABLE)
      ->condition('entity_type', $entity->getEntityTypeId())
      ->condition('entity_id', (string) $entity->id())
      ->execute();
    $this->insertReferences($entity->getEntityTypeId(), (string) $entity->id(), $this->mapReferences($entity));
  }

  /**
   * Resyncs the map hosts a workspace publish just promoted.
   *
   * Closes the FIRST staleness edge the class docblock names: publish
   * fires no per-entity update hooks, so without this the just-published
   * wearers would be invisible to every usage consumer — and the delete
   * batch's work list must be exact, because a wearer it misses keeps a
   * reference that can no longer materialize. Called from
   * VariantWorkspaceSubscriber with the publish event's payload; only
   * entity types carrying the managed map field are touched.
   *
   * @param array $published_revision_ids
   *   Entity ids keyed by revision id, keyed by entity type id (the
   *   WorkspacePostPublishEvent payload shape).
   */
  public function resyncHosts(array $published_revision_ids): void {
    $field_map = $this->entityFieldManager->getFieldMapByFieldType('daedalus_stylish_style_map');
    foreach ($published_revision_ids as $entity_type_id => $entity_ids) {
      if (!isset($field_map[$entity_type_id][DaedalusStylishFieldManager::FIELD_NAME])) {
        continue;
      }
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      foreach (array_chunk(array_unique($entity_ids), 50) as $chunk) {
        foreach ($storage->loadMultiple($chunk) as $entity) {
          if ($entity instanceof FieldableEntityInterface) {
            $this->resync($entity);
          }
        }
        $storage->resetCache($chunk);
      }
    }
  }

  /**
   * Counts a variant's wearers: host entities whose saved map references it.
   *
   * DISTINCT over (entity_type, entity_id), so an entity referencing the
   * variant by machine name in one entry and by uuid in another counts once.
   */
  public function countWearers(DaedalusStylishVariantInterface $variant): int {
    $references = $this->variantReferences($variant);
    if ($references === []) {
      return 0;
    }
    $query = $this->database->select(self::TABLE, 'u')
      ->fields('u', ['entity_type', 'entity_id'])
      ->distinct()
      ->condition('u.variant', $references, 'IN');
    return (int) $query->countQuery()->execute()->fetchField();
  }

  /**
   * Lists a variant's wearers, the work list for batch gestures.
   *
   * @return array[]
   *   ['entity_type' => string, 'entity_id' => string] per wearing host
   *   entity, distinct, ordered for stable batches.
   */
  public function wearers(DaedalusStylishVariantInterface $variant): array {
    $references = $this->variantReferences($variant);
    if ($references === []) {
      return [];
    }
    return $this->database->select(self::TABLE, 'u')
      ->fields('u', ['entity_type', 'entity_id'])
      ->distinct()
      ->condition('u.variant', $references, 'IN')
      ->orderBy('u.entity_type')
      ->orderBy('u.entity_id')
      ->execute()
      ->fetchAll(FetchAs::Associative);
  }

  /**
   * Rebuilds the whole table from the saved default-revision style maps.
   *
   * The repair path (drush daedalus_stylish:variant-usage:rebuild) for the staleness
   * edges the class docblock names, and the seed for sites updating into
   * the table. Runs in a transaction so readers never see a half-built
   * table (delete, not truncate: truncate commits implicitly on MySQL).
   *
   * @return int
   *   The number of reference-carrying host entities tracked.
   */
  public function rebuild(): int {
    // Held until the method returns: the transaction commits when the object
    // is destroyed, so the rebuild lands whole.
    // phpcs:ignore DrupalPractice.CodeAnalysis.VariableAnalysis.UnusedVariable
    $transaction = $this->database->startTransaction();
    $this->database->delete(self::TABLE)->execute();
    $tracked = 0;
    foreach ($this->entityFieldManager->getFieldMapByFieldType('daedalus_stylish_style_map') as $entity_type_id => $fields) {
      if (!isset($fields[DaedalusStylishFieldManager::FIELD_NAME])) {
        // Tracking covers the auto-managed field only (mapReferences());
        // a foreign field of the map type is outside the module's
        // delivery and outside its counting.
        continue;
      }
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->exists(DaedalusStylishFieldManager::FIELD_NAME)
        ->execute();
      foreach (array_chunk($ids, 50) as $chunk) {
        foreach ($storage->loadMultiple($chunk) as $entity) {
          if (!$entity instanceof FieldableEntityInterface) {
            continue;
          }
          $references = $this->mapReferences($entity);
          if ($references !== []) {
            $this->insertReferences($entity_type_id, (string) $entity->id(), $references);
            $tracked++;
          }
        }
        $storage->resetCache($chunk);
      }
    }
    return $tracked;
  }

  /**
   * The distinct variant references in a host entity's style map, sorted.
   *
   * Reads the auto-managed map field only (DaedalusStylishFieldManager::FIELD_NAME,
   * the one map field the module manages and delivers), through the one
   * structured reference read (StyleMapValidator::entryVariants()), so a
   * variant name mentioned inside a css VALUE never counts. Sorted so
   * track()'s changed-or-not comparison is order-insensitive.
   *
   * @return string[]
   *   The reference strings, verbatim as the map stores them.
   */
  protected function mapReferences(FieldableEntityInterface $entity): array {
    if (!$entity->hasField(DaedalusStylishFieldManager::FIELD_NAME)) {
      return [];
    }
    $item = $entity->get(DaedalusStylishFieldManager::FIELD_NAME)->first();
    $map = $item instanceof StyleMapItem ? $item->getStyleMap() : [];
    $references = [];
    foreach ($map as $entry) {
      foreach (StyleMapValidator::entryVariants($entry) as $reference) {
        $references[$reference] = TRUE;
      }
    }
    $references = array_keys($references);
    sort($references);
    return $references;
  }

  /**
   * A variant's lookup forms: machine name and uuid, whichever exist.
   *
   * @return string[]
   *   The lookup forms.
   */
  protected function variantReferences(DaedalusStylishVariantInterface $variant): array {
    return array_values(array_filter([$variant->getMachineName(), (string) $variant->uuid()]));
  }

  /**
   * Inserts one row per reference for a host entity.
   *
   * @param string $entity_type_id
   *   The host entity's type id.
   * @param string $entity_id
   *   The host entity's id.
   * @param string[] $references
   *   The variant references as stored.
   */
  protected function insertReferences(string $entity_type_id, string $entity_id, array $references): void {
    if ($references === []) {
      return;
    }
    $insert = $this->database->insert(self::TABLE)->fields(['variant', 'entity_type', 'entity_id']);
    foreach ($references as $reference) {
      $insert->values([
        'variant' => $reference,
        'entity_type' => $entity_type_id,
        'entity_id' => $entity_id,
      ]);
    }
    $insert->execute();
  }

}
