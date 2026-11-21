<?php

declare(strict_types=1);

namespace Drupal\daedalus_tempstore\History;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Lock\LockBackendInterface;

/**
 * Persists edit-history timelines in expirable key/value storage.
 *
 * A timeline is the edit history of ONE tempstore key, shared by everyone
 * who edits it, as the staged state it describes is. Because tempstore keys
 * already encode entity type, id, langcode, and workspace, a timeline is
 * automatically per-entity, per-language, and workspace-aware. Who made a
 * change is a fact of the entry (its 'uid'), never of the key.
 *
 * Two item shapes live in the collection:
 * - The INDEX ("{hash}.index"): small, rewritten on every capture.
 *   ['timeline', 'pointer', 'next_seq', 'entries' => [seq => metadata]]
 *   where metadata is ['seq', 'label', 'uid', 'timestamp', 'targets',
 *   'bytes', 'baseline'] ('uid' is the account that made the change, NULL
 *   on the baseline). Everything a history UI lists comes from here;
 *   snapshot payloads are never read for display. The index is read,
 *   changed and written back, so every such update runs inside locked().
 * - ENTRIES ("{hash}.{seq}"): written once, read only on restore.
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

  /**
   * Seconds an index update waits for another request's update to finish.
   */
  protected const LOCK_WAIT = 10;

  public function __construct(
    protected KeyValueExpirableFactoryInterface $keyValueFactory,
    protected TimeInterface $time,
    protected LockBackendInterface $lock,
  ) {}

  /**
   * Runs an update of one timeline while no other request updates it.
   *
   * A timeline is shared, so two editors' requests can read the same index
   * and each write back its own change; the lock makes the read, the change
   * and the write one step. Callers do not nest it for one timeline.
   *
   * @param string $timeline
   *   The timeline ID ("{collection}:{key}" of the tempstore subject).
   * @param callable $update
   *   The update; it reads the index itself, inside the lock.
   *
   * @return mixed
   *   The callable's return value.
   *
   * @throws \RuntimeException
   *   When the timeline stays locked by another request.
   */
  public function locked(string $timeline, callable $update): mixed {
    $name = self::COLLECTION . ':' . sha1($timeline);
    if (!$this->lock->acquire($name)) {
      $this->lock->wait($name, self::LOCK_WAIT);
      if (!$this->lock->acquire($name)) {
        throw new \RuntimeException(sprintf('The edit history of "%s" is being updated by another request.', $timeline));
      }
    }
    try {
      return $update();
    }
    finally {
      $this->lock->release($name);
    }
  }

  /**
   * Loads a timeline's index.
   *
   * @param string $timeline
   *   The timeline ID ("{collection}:{key}" of the tempstore subject).
   *
   * @return array|null
   *   The index, or NULL if the timeline has no history.
   */
  public function loadIndex(string $timeline): ?array {
    return $this->storage()->get($this->itemKey($timeline, 'index'));
  }

  /**
   * Saves a timeline's index.
   */
  public function saveIndex(string $timeline, array $index): void {
    $this->storage()->setWithExpire($this->itemKey($timeline, 'index'), $index, self::EXPIRE);
  }

  /**
   * Loads one history entry (the snapshot payload).
   *
   * @return array|null
   *   The entry, or NULL if it expired or was trimmed.
   */
  public function loadEntry(string $timeline, int $seq): ?array {
    return $this->storage()->get($this->itemKey($timeline, (string) $seq));
  }

  /**
   * Saves one history entry.
   */
  public function saveEntry(string $timeline, int $seq, array $entry): void {
    $this->storage()->setWithExpire($this->itemKey($timeline, (string) $seq), $entry, self::EXPIRE);
  }

  /**
   * Deletes one history entry.
   */
  public function deleteEntry(string $timeline, int $seq): void {
    $this->storage()->delete($this->itemKey($timeline, (string) $seq));
  }

  /**
   * Deletes a timeline entirely: its index and every entry it lists.
   *
   * Called when the staged state the timeline describes stops existing —
   * save and discard both land here (via the strategy delete() tee). The
   * timeline is the page's one history, so it ends for every editor.
   */
  public function clearTimeline(string $timeline): void {
    $this->locked($timeline, function () use ($timeline): void {
      $index = $this->loadIndex($timeline);
      if ($index !== NULL) {
        foreach (array_keys($index['entries']) as $seq) {
          $this->deleteEntry($timeline, (int) $seq);
        }
      }
      $this->storage()->delete($this->itemKey($timeline, 'index'));
    });
  }

  /**
   * Builds a storage item key.
   *
   * The timeline string embeds a tempstore key (entity/langcode/workspace)
   * and can exceed the key/value name column; hash it to a fixed width.
   */
  protected function itemKey(string $timeline, string $suffix): string {
    return sha1($timeline) . '.' . $suffix;
  }

  /**
   * The key/value store backing history.
   */
  protected function storage(): KeyValueStoreExpirableInterface {
    return $this->keyValueFactory->get(self::COLLECTION);
  }

}
