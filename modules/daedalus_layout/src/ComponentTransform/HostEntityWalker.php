<?php

namespace Drupal\daedalus_layout\ComponentTransform;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;

/**
 * Migration spine store walker 1: content entity layout hosts.
 *
 * Walks every content entity revision holding layout sections and applies
 * the registered component transforms (ComponentTransformRegistry) to every
 * component, re-saving only the revisions a transform actually rewrote.
 *
 * Coverage:
 * - Every entity type carrying a layout_section field: layout_builder__layout
 *   on overridable hosts (nodes, layout block block_content, ...), and any
 *   other layout_section field a content entity declares.
 * - Every revision, including old and pending (non-default) revisions.
 *   Workspace revisions are ordinary non-default revisions in the entity's
 *   revision table, so the same allRevisions() walk covers them.
 * - Every translation when the layout field is translatable; an
 *   untranslatable layout field is processed once on the default translation
 *   because its values are shared across translations. The translation
 *   object is what transforms receive as the host, so per-language hosts
 *   (labels, style fields) resolve correctly.
 *
 * Dirty revisions are re-saved in place (no new revisions) through the
 * normal save path, not marked as syncing, so layout_builder's inline block
 * presave machinery and usage tracking stay correct.
 *
 * Designed for a batched hook_post_update_NAME():
 * @code
 * function example_post_update_migrate_blocks(&$sandbox) {
 *   \Drupal::service('daedalus_layout.host_entity_walker')->walk($sandbox);
 * }
 * @endcode
 */
class HostEntityWalker {

  /**
   * The key under which walk state is kept in the caller's sandbox.
   */
  public const SANDBOX_KEY = 'daedalus_layout_host_entity_walker';

  /**
   * Constructs a HostEntityWalker.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   * @param \Drupal\daedalus_layout\ComponentTransform\ComponentTransformRegistry $transforms
   *   The component transform registry.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected ComponentTransformRegistry $transforms,
  ) {}

  /**
   * Processes one batch of host entity revisions.
   *
   * @param array $sandbox
   *   The batch sandbox (a post_update hook's $sandbox). The walker owns the
   *   self::SANDBOX_KEY entry and sets $sandbox['#finished'] (1 when the
   *   walk is complete).
   * @param int $batch_size
   *   Maximum revisions to load and examine in this call.
   *
   * @return int
   *   The number of revisions rewritten and re-saved during this call.
   */
  public function walk(array &$sandbox, int $batch_size = 50): int {
    if (!$this->transforms->hasTransforms()) {
      $sandbox['#finished'] = 1;
      return 0;
    }
    if (!isset($sandbox[self::SANDBOX_KEY])) {
      $sandbox[self::SANDBOX_KEY] = $this->initializeState();
    }
    $state = &$sandbox[self::SANDBOX_KEY];

    $saved = 0;
    $remaining = $batch_size;
    while ($remaining > 0 && !empty($state['queue'])) {
      $store = $state['queue'][0];
      $ids = $this->claimIds($store, $remaining);
      if (empty($ids)) {
        array_shift($state['queue']);
        continue;
      }
      // Claimed IDs are committed before processing so a failing batch does
      // not reprocess them forever on retry.
      $state['queue'][0]['last_id'] = end($ids);
      $saved += $this->processHosts($store, $ids);
      $state['processed'] += count($ids);
      $remaining -= count($ids);
    }

    $state['saved'] += $saved;
    if (empty($state['queue'])) {
      $sandbox['#finished'] = 1;
    }
    else {
      $sandbox['#finished'] = $state['total'] > 0 ? min($state['processed'] / $state['total'], 0.99) : 0.99;
    }
    return $saved;
  }

  /**
   * Builds the initial walk state.
   *
   * One queue entry per entity type carrying a layout_section field, with a
   * total revision count for progress reporting. Entity types without any
   * entities are skipped up front.
   *
   * @return array
   *   The state array: queue, total, processed, saved.
   */
  protected function initializeState(): array {
    $queue = [];
    $total = 0;
    foreach ($this->entityFieldManager->getFieldMapByFieldType('layout_section') as $entity_type_id => $fields) {
      $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      $revisionable = $entity_type->isRevisionable() && $storage instanceof RevisionableStorageInterface;

      $count_query = $storage->getQuery()->accessCheck(FALSE)->count();
      if ($revisionable) {
        $count_query->allRevisions();
      }
      $count = (int) $count_query->execute();
      if ($count === 0) {
        continue;
      }

      $total += $count;
      $queue[] = [
        'entity_type_id' => $entity_type_id,
        'field_names' => array_keys($fields),
        'revisionable' => $revisionable,
        // Batches page by ascending revision ID (or entity ID for
        // non-revisionable hosts), resuming after the last claimed one.
        'id_key' => $revisionable ? $entity_type->getKey('revision') : $entity_type->getKey('id'),
        'last_id' => 0,
      ];
    }
    return [
      'queue' => $queue,
      'total' => $total,
      'processed' => 0,
      'saved' => 0,
    ];
  }

  /**
   * Claims the next chunk of revision (or entity) IDs for a store.
   *
   * @param array $store
   *   A queue entry from the walk state.
   * @param int $limit
   *   Maximum IDs to claim.
   *
   * @return array
   *   Revision IDs for revisionable hosts, entity IDs otherwise, ascending.
   */
  protected function claimIds(array $store, int $limit): array {
    $query = $this->entityTypeManager->getStorage($store['entity_type_id'])->getQuery()
      ->accessCheck(FALSE)
      ->condition($store['id_key'], $store['last_id'], '>')
      ->sort($store['id_key'])
      ->range(0, $limit);
    if ($store['revisionable']) {
      $query->allRevisions();
      // allRevisions() results are keyed by revision ID.
      return array_keys($query->execute());
    }
    return array_values($query->execute());
  }

  /**
   * Loads a chunk of hosts, transforms them, and saves the dirty ones.
   *
   * @param array $store
   *   A queue entry from the walk state.
   * @param array $ids
   *   The claimed revision or entity IDs.
   *
   * @return int
   *   The number of hosts that were rewritten and re-saved.
   */
  protected function processHosts(array $store, array $ids): int {
    $storage = $this->entityTypeManager->getStorage($store['entity_type_id']);
    /** @var \Drupal\Core\Entity\ContentEntityInterface[] $hosts */
    $hosts = $store['revisionable'] && $storage instanceof RevisionableStorageInterface
      ? $storage->loadMultipleRevisions($ids)
      : $storage->loadMultiple($ids);

    $saved = 0;
    foreach ($hosts as $host) {
      if ($this->transforms->transformLayoutFields($host, $store['field_names'])) {
        if ($store['revisionable']) {
          // Rewrite the loaded revision in place; migrating a deprecated
          // component is not an editorial change worth a new revision, and
          // old revisions must keep their identity.
          $host->setNewRevision(FALSE);
        }
        $host->save();
        $saved++;
      }
    }
    // Keep the static entity cache from growing across a long walk.
    $storage->resetCache();
    return $saved;
  }

}
