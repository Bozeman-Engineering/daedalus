<?php

declare(strict_types=1);

namespace Drupal\daedalus_tempstore\History;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;

/**
 * Persists edit-history timelines in expirable key/value storage.
 *
 * A timeline is the edit history of ONE tempstore key for ONE user. Because
 * tempstore keys already encode entity type, id, langcode, and workspace, a
 * timeline is automatically per-entity, per-language, and workspace-aware;
 * this store only adds the per-user axis (the uid prefix).
 *
 * Two item shapes live in the collection:
 * - The INDEX ("{uid}.{hash}.index"): small, rewritten on every capture.
 *   ['timeline', 'pointer', 'next_seq', 'entries' => [seq => metadata]]
 *   where metadata is ['seq', 'label', 'uid', 'timestamp', 'targets',
 *   'bytes', 'baseline']. Everything a history UI lists comes from here;
 *   snapshot payloads are never read for display.
 * - ENTRIES ("{uid}.{hash}.{seq}"): written once, read only on restore.
 *   ['seq', 'writes' => [['collection', 'key', 'envelope', 'absent']]]
 *   where 'envelope' is the gzcompress()ed serialize()d tempstore envelope
 *   snapshot, and 'absent' marks a baseline for a key that had no tempstore
 *   entry (restoring it means deleting the key).
 *
 * Entries expire on the same clock as the tempstore payloads they snapshot,
 * so history never outlives the staged state it can restore.
 */
class HistoryStore {

  /**
   * The key/value collection name.
   */
  public const COLLECTION = 'daedalus_tempstore.history';

  /**
   * Item lifetime in seconds; matches core SharedTempStoreFactory's default.
   */
  public const EXPIRE = 604800;

  public function __construct(
    protected KeyValueExpirableFactoryInterface $keyValueFactory,
    protected TimeInterface $time,
  ) {}

  /**
   * Loads a timeline's index.
   *
   * @param int|string $uid
   *   The user ID the timeline belongs to.
   * @param string $timeline
   *   The timeline ID ("{collection}:{key}" of the tempstore subject).
   *
   * @return array|null
   *   The index, or NULL if the timeline has no history.
   */
  public function loadIndex(int|string $uid, string $timeline): ?array {
    return $this->storage()->get($this->itemKey($uid, $timeline, 'index'));
  }

  /**
   * Saves a timeline's index.
   */
  public function saveIndex(int|string $uid, string $timeline, array $index): void {
    $this->storage()->setWithExpire($this->itemKey($uid, $timeline, 'index'), $index, self::EXPIRE);
  }

  /**
   * Loads one history entry (the snapshot payload).
   *
   * @return array|null
   *   The entry, or NULL if it expired or was trimmed.
   */
  public function loadEntry(int|string $uid, string $timeline, int $seq): ?array {
    return $this->storage()->get($this->itemKey($uid, $timeline, (string) $seq));
  }

  /**
   * Saves one history entry.
   */
  public function saveEntry(int|string $uid, string $timeline, int $seq, array $entry): void {
    $this->storage()->setWithExpire($this->itemKey($uid, $timeline, (string) $seq), $entry, self::EXPIRE);
  }

  /**
   * Deletes one history entry.
   */
  public function deleteEntry(int|string $uid, string $timeline, int $seq): void {
    $this->storage()->delete($this->itemKey($uid, $timeline, (string) $seq));
  }

  /**
   * Deletes a timeline entirely: its index and every entry it lists.
   *
   * Called when the staged state the timeline describes stops existing —
   * save and discard both land here (via the strategy delete() tee).
   */
  public function clearTimeline(int|string $uid, string $timeline): void {
    $index = $this->loadIndex($uid, $timeline);
    if ($index !== NULL) {
      foreach (array_keys($index['entries']) as $seq) {
        $this->deleteEntry($uid, $timeline, (int) $seq);
      }
    }
    $this->storage()->delete($this->itemKey($uid, $timeline, 'index'));
  }

  /**
   * Builds a storage item key.
   *
   * The timeline string embeds a tempstore key (entity/langcode/workspace)
   * and can exceed the key/value name column; hash it to a fixed width.
   */
  protected function itemKey(int|string $uid, string $timeline, string $suffix): string {
    return $uid . '.' . sha1($timeline) . '.' . $suffix;
  }

  /**
   * The key/value store backing history.
   */
  protected function storage(): KeyValueStoreExpirableInterface {
    return $this->keyValueFactory->get(self::COLLECTION);
  }

}
