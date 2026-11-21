<?php

declare(strict_types=1);

namespace Drupal\daedalus_tempstore\History;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Render\PlainTextOutput;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TempStore\SharedTempStoreFactory;

/**
 * The history tee: turns tempstore writes into undo history entries.
 *
 * Every mutation path in the page builder — the blueprint endpoint, the
 * form-swap submit, AI tools, core Layout Builder forms — funnels through a
 * tempstore strategy's set()/delete(). The strategies call this recorder at
 * that exact write site, where the snapshot unit (the envelope about to be
 * stored) is already in hand, so capture costs no extra reads and no
 * per-controller instrumentation.
 *
 * A timeline is the page's, not the editor's: every editor's writes to one
 * tempstore key extend the same timeline, and each entry records the
 * account that made it.
 *
 * Capture is request-scoped and coalesced: all writes to one timeline within
 * one request become ONE history entry (a batch is one entry; repeated
 * same-key writes keep the last state). Nothing persists until flush() —
 * the blueprint endpoint flushes explicitly so its response can carry fresh
 * timeline metadata; HistoryResponseSubscriber flushes as a fallback for
 * every other path (form-swap, AI). flush() with nothing pending is a no-op,
 * so double-flushing is safe.
 *
 * The first write to a timeline with no history snapshots the PRE-write
 * value as a baseline entry (seq 0) — the state "before any staged change"
 * that undoing the first mutation returns to. A key with no tempstore entry
 * at that moment gets an ABSENT baseline: restoring it deletes the key,
 * falling back to the saved entity.
 *
 * Restore itself writes through the same strategies; suppress() keeps those
 * writes out of history, and nests (a suppressed callable may suppress).
 * Suppression also covers render-time staging writes (core's PrepareLayout
 * tempstore seed, daedalus_layout's tree-index re-save) —
 * bookkeeping that stages state without a user mutation must never become
 * an entry. A strategy delete() outside suppression means the staged state
 * is gone (save or discard) and clears the timeline, for every editor.
 */
class HistoryRecorder {

  use StringTranslationTrait;

  /**
   * Maximum entries per timeline, including the baseline.
   *
   * A full snapshot rides every entry, so trimming old entries never breaks
   * newer ones; the baseline is kept so "undo everything" always works.
   */
  public const MAX_ENTRIES = 50;

  /**
   * The longest label an entry carries, in characters.
   *
   * A label can come from the client (the gesture's name); a longer one is
   * cut here, the one place every label passes.
   */
  public const MAX_LABEL_LENGTH = 255;

  /**
   * The largest snapshot an entry may hold, in compressed bytes.
   *
   * A write whose snapshot is larger is refused before it is staged
   * (HistoryEntryRefusedException), so a timeline of MAX_ENTRIES entries
   * has a bounded size and the staged state never moves without its entry.
   */
  public const MAX_ENTRY_BYTES = 4194304;

  /**
   * Pending writes for this request, keyed by timeline.
   *
   * Each value: ['collection', 'key', 'envelope' (gz), 'bytes'].
   */
  protected array $pending = [];

  /**
   * Pre-write snapshots (gz or NULL for absent), keyed by timeline.
   *
   * Only populated for timelines that had no history at first write; used
   * to create the baseline entry at flush.
   */
  protected array $preState = [];

  /**
   * The label the mutation path annotated for this request's entry.
   */
  protected ?string $label = NULL;

  /**
   * The targets (BlueprintResult vocabulary) annotated for this entry.
   */
  protected array $targets = [];

  /**
   * How many suppress() calls are running, one inside another.
   *
   * Capture is off while it is above zero.
   */
  protected int $suppressed = 0;

  public function __construct(
    protected HistoryStore $store,
    protected SharedTempStoreFactory $tempStoreFactory,
    protected AccountProxyInterface $currentUser,
    protected TimeInterface $time,
    TranslationInterface $string_translation,
  ) {
    $this->setStringTranslation($string_translation);
  }

  /**
   * Records a tempstore write. Called by strategies just before they set().
   *
   * The envelope is serialized immediately so the snapshot freezes the state
   * at write time — the live objects keep mutating with the request.
   *
   * @param string $collection
   *   The shared tempstore collection being written.
   * @param string $key
   *   The tempstore key being written.
   * @param array $envelope
   *   The exact envelope the strategy is about to store.
   *
   * @throws \Drupal\daedalus_tempstore\History\HistoryEntryRefusedException
   *   When the snapshot is over MAX_ENTRY_BYTES; the strategy must not
   *   store the envelope.
   */
  public function recordWrite(string $collection, string $key, array $envelope): void {
    if ($this->suppressed > 0) {
      return;
    }
    $timeline = $collection . ':' . $key;

    $payload = gzcompress(serialize($envelope));
    if (strlen($payload) > self::MAX_ENTRY_BYTES) {
      throw HistoryEntryRefusedException::tooLarge(strlen($payload), self::MAX_ENTRY_BYTES);
    }

    // First write to this timeline in this request: if the timeline has no
    // history yet, snapshot the pre-write state now (it is about to be
    // overwritten) for the baseline entry.
    if (!array_key_exists($timeline, $this->preState) && !isset($this->pending[$timeline])
      && $this->store->loadIndex($timeline) === NULL) {
      $previous = $this->tempStoreFactory->get($collection)->get($key);
      $this->preState[$timeline] = is_array($previous) ? gzcompress(serialize($previous)) : NULL;
    }

    $this->pending[$timeline] = [
      'collection' => $collection,
      'key' => $key,
      'envelope' => $payload,
      'bytes' => strlen($payload),
    ];
  }

  /**
   * Records a tempstore delete. Called by strategies in delete().
   *
   * Outside suppression a delete means the staged state stopped existing —
   * save and discard both flow through here — so the timeline's history is
   * cleared with it, whoever made its entries.
   */
  public function recordDelete(string $collection, string $key): void {
    if ($this->suppressed > 0) {
      return;
    }
    $timeline = $collection . ':' . $key;
    unset($this->pending[$timeline], $this->preState[$timeline]);
    $this->store->clearTimeline($timeline);
  }

  /**
   * Annotates the entry this request's writes will produce.
   *
   * Mutation paths that know the gesture call this before persisting: the
   * blueprint endpoint passes the client's label and the result's targets.
   * Without an annotation the label is derived from the targets.
   *
   * @param string|null $label
   *   The human-readable entry label (e.g. "Moved Heading"), as plain
   *   text: whoever shows it escapes it. Kept to MAX_LABEL_LENGTH
   *   characters.
   * @param array $targets
   *   Targets in BlueprintResult vocabulary ({uuid, field_name} lists);
   *   drives sidebar click-to-scroll and the undo re-render subtree.
   */
  public function annotate(?string $label, array $targets = []): void {
    if ($label !== NULL && $label !== '') {
      $this->label = Unicode::truncate($label, self::MAX_LABEL_LENGTH, FALSE, TRUE);
    }
    if ($targets !== []) {
      $this->targets = $targets;
    }
  }

  /**
   * Runs a callable with history capture off.
   *
   * Used by the restore path and by render-time staging writes (tempstore
   * seeding, tree-index re-saves) that stage state without a user mutation.
   * Calls nest: capture comes back on when the outermost one returns.
   *
   * @return mixed
   *   The callable's return value.
   */
  public function suppress(callable $callable): mixed {
    $this->suppressed++;
    try {
      return $callable();
    }
    finally {
      $this->suppressed--;
    }
  }

  /**
   * Persists the pending writes as history entries.
   *
   * One entry per touched timeline. Appending truncates any redo tail (a
   * new mutation after undo makes the undone future unreachable — linear
   * history) and trims the ring to MAX_ENTRIES, keeping the baseline. Each
   * timeline's update runs inside the store's lock, since another editor's
   * request may be extending the same timeline.
   *
   * @return array
   *   The updated index per touched timeline, keyed by timeline ID; empty
   *   when nothing was pending.
   */
  public function flush(): array {
    if ($this->pending === []) {
      $this->label = NULL;
      $this->targets = [];
      return [];
    }

    $uid = (int) $this->currentUser->id();
    $now = $this->time->getRequestTime();
    $flushed = [];

    foreach ($this->pending as $timeline => $write) {
      $flushed[$timeline] = $this->store->locked($timeline, fn(): array => $this->append($timeline, $write, $uid, $now));
    }

    $this->pending = [];
    $this->preState = [];
    $this->label = NULL;
    $this->targets = [];
    return $flushed;
  }

  /**
   * Appends one pending write to its timeline. Runs inside the store lock.
   *
   * @param string $timeline
   *   The timeline ID.
   * @param array $write
   *   The pending write: ['collection', 'key', 'envelope', 'bytes'].
   * @param int $uid
   *   The account making the change.
   * @param int $now
   *   The request time.
   *
   * @return array
   *   The updated index.
   */
  protected function append(string $timeline, array $write, int $uid, int $now): array {
    $index = $this->store->loadIndex($timeline) ?? [
      'timeline' => $timeline,
      'pointer' => 0,
      'next_seq' => 1,
      'entries' => [],
    ];

    // Baseline: the pre-first-mutation state, captured at recordWrite.
    if ($index['entries'] === []) {
      $pre = $this->preState[$timeline] ?? NULL;
      $this->store->saveEntry($timeline, 0, [
        'seq' => 0,
        'writes' => [
          [
            'collection' => $write['collection'],
            'key' => $write['key'],
            'envelope' => $pre,
            'absent' => $pre === NULL,
          ],
        ],
      ]);
      $index['entries'][0] = [
        'seq' => 0,
        'label' => (string) $this->t('Initial state'),
        // The state before anyone's change is nobody's change.
        'uid' => NULL,
        'timestamp' => $now,
        'targets' => [],
        'bytes' => $pre === NULL ? 0 : strlen($pre),
        'baseline' => TRUE,
      ];
      $index['pointer'] = 0;
    }

    // A mutation while undone truncates the redo tail.
    foreach (array_keys($index['entries']) as $seq) {
      if ($seq > $index['pointer']) {
        $this->store->deleteEntry($timeline, (int) $seq);
        unset($index['entries'][$seq]);
      }
    }

    $seq = $index['next_seq']++;
    $this->store->saveEntry($timeline, $seq, [
      'seq' => $seq,
      'writes' => [
        [
          'collection' => $write['collection'],
          'key' => $write['key'],
          'envelope' => $write['envelope'],
          'absent' => FALSE,
        ],
      ],
    ]);
    $index['entries'][$seq] = [
      'seq' => $seq,
      'label' => $this->label ?? $this->deriveLabel($this->targets),
      'uid' => $uid,
      'timestamp' => $now,
      'targets' => $this->targets,
      'bytes' => $write['bytes'],
      'baseline' => FALSE,
    ];
    $index['pointer'] = $seq;

    // Ring trim: drop the oldest non-baseline entries. Every entry is a
    // full snapshot, so surviving entries stay restorable.
    $over = count($index['entries']) - self::MAX_ENTRIES;
    if ($over > 0) {
      foreach (array_keys($index['entries']) as $old_seq) {
        if ($over === 0) {
          break;
        }
        if (!empty($index['entries'][$old_seq]['baseline'])) {
          continue;
        }
        $this->store->deleteEntry($timeline, (int) $old_seq);
        unset($index['entries'][$old_seq]);
        $over--;
      }
    }

    $this->store->saveIndex($timeline, $index);
    return $index;
  }

  /**
   * Derives an entry label from its targets when no path annotated one.
   */
  protected function deriveLabel(array $targets): string {
    $fields = [];
    foreach ($targets as $target) {
      $field = $target['field_name'] ?? NULL;
      switch ($field) {
        case '_added':
          return (string) $this->t('Added content');

        case '_removed':
          return (string) $this->t('Removed content');

        case '_reordered':
          return (string) $this->t('Moved content');

        case '_restructured':
          return (string) $this->t('Changed layout');

        case NULL:
          break;

        default:
          $fields[$field] = $field;
      }
    }
    if (count($fields) === 1) {
      // A label is plain text: the panel and the undo cue each escape it
      // once where they show it.
      return PlainTextOutput::renderFromHtml($this->t('Edited @field', ['@field' => reset($fields)]));
    }
    return (string) $this->t('Edited content');
  }

}
