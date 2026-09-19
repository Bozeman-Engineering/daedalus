<?php

declare(strict_types=1);

namespace Drupal\daedalus_tempstore\History;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\TempStore\SharedTempStoreFactory;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\daedalus_tempstore\StrategySelector;

/**
 * Multi-step undo/redo over tempstore payload snapshots.
 *
 * The model (see HistoryRecorder for capture): a timeline is a bounded list
 * of full tempstore-envelope snapshots, one per mutation, plus a baseline;
 * a pointer marks the entry whose snapshot equals the current staged state.
 * Undo/redo/jump all reduce to "move the pointer and write that entry's
 * snapshot back" — no inverse operations, no patch replay.
 *
 * Restore writes through the tempstore strategies (the same machinery every
 * mutation and save/discard already trusts), with recording suppressed so
 * the restore itself never becomes history. Going through the strategies —
 * not raw key/value writes — also keeps strategy-level state coherent
 * (LayoutTempstoreStrategy's static cache is populated by the entity param
 * converter's upcast get() before a restore request reaches us).
 *
 * This service moves state only; it fires no hooks and renders nothing.
 * Callers that expose restore over HTTP own firing the persist hook with
 * the returned entities (the cache-invalidation contract lives with them).
 */
class HistoryManager {

  public function __construct(
    protected HistoryStore $store,
    protected HistoryRecorder $recorder,
    protected StrategySelector $strategySelector,
    protected SharedTempStoreFactory $tempStoreFactory,
    protected AccountProxyInterface $currentUser,
  ) {}

  /**
   * Runs a callable with history capture off.
   *
   * For callers whose work around a restore — persist hooks, response
   * rendering — may itself write tempstore (e.g. core's PrepareLayout
   * subscriber re-seeds a key a baseline restore just deleted). Nothing
   * that happens while answering a restore may append to or truncate the
   * timeline it moved.
   *
   * @return mixed
   *   The callable's return value.
   */
  public function suppress(callable $callable): mixed {
    return $this->recorder->suppress($callable);
  }

  /**
   * Resolves the timeline ID for a tempstore subject.
   *
   * @param mixed $subject
   *   Anything a tempstore strategy supports (an entity, section storage).
   *
   * @return string
   *   The timeline ID: "{collection}:{key}".
   */
  public function timelineFor(mixed $subject): string {
    $strategy = $this->strategySelector->getStrategy($subject);
    return $strategy->getCollection($subject) . ':' . $strategy->getKey($subject);
  }

  /**
   * The current user's timeline metadata for a subject, UI-shaped.
   *
   * @return array
   *   ['pointer' => int, 'can_undo' => bool, 'can_redo' => bool,
   *   'entries' => list sorted by seq ascending]. Entries are metadata only
   *   (seq, label, uid, timestamp, targets, bytes, baseline) — snapshot
   *   payloads never leave the store for display.
   */
  public function metadata(mixed $subject): array {
    $timeline = $this->timelineFor($subject);
    $index = $this->store->loadIndex($this->currentUser->id(), $timeline);
    if ($index === NULL) {
      return [
        'pointer' => NULL,
        'can_undo' => FALSE,
        'can_redo' => FALSE,
        'entries' => [],
      ];
    }
    $entries = array_values($index['entries']);
    usort($entries, fn(array $a, array $b) => $a['seq'] <=> $b['seq']);
    $sequence_numbers = array_column($entries, 'seq');
    return [
      'pointer' => $index['pointer'],
      'can_undo' => $index['pointer'] > min($sequence_numbers),
      'can_redo' => $index['pointer'] < max($sequence_numbers),
      'entries' => $entries,
    ];
  }

  /**
   * Undoes one step: restores the entry before the pointer.
   *
   * @param mixed $subject
   *   The tempstore subject whose timeline to move.
   *
   * @return array|null
   *   The restore result (see restoreTo()), or NULL if there is nothing
   *   to undo.
   */
  public function undo(mixed $subject): ?array {
    $target = $this->adjacentSeq($subject, -1);
    return $target === NULL ? NULL : $this->restoreTo($subject, $target);
  }

  /**
   * Redoes one step: restores the entry after the pointer.
   *
   * @return array|null
   *   The restore result, or NULL if there is nothing to redo.
   */
  public function redo(mixed $subject): ?array {
    $target = $this->adjacentSeq($subject, 1);
    return $target === NULL ? NULL : $this->restoreTo($subject, $target);
  }

  /**
   * Restores the staged state to a specific history entry.
   *
   * @param mixed $subject
   *   The tempstore subject whose timeline to move.
   * @param int $seq
   *   The target entry's sequence number.
   *
   * @return array|null
   *   On success: [
   *     'pointer' => the new pointer (= $seq),
   *     'label' => the restored entry's label,
   *     'entities' => the entities whose staged state changed (for the
   *       caller's persist-hook firing),
   *     'targets' => the union of the traversed entries' targets (the
   *       subtree that changed between the two states, for re-render),
   *   ]. NULL when the entry does not exist, has expired, or is already
   *   current.
   */
  public function restoreTo(mixed $subject, int $seq): ?array {
    $uid = $this->currentUser->id();
    $timeline = $this->timelineFor($subject);
    $index = $this->store->loadIndex($uid, $timeline);
    if ($index === NULL || !isset($index['entries'][$seq]) || $index['pointer'] === $seq) {
      return NULL;
    }
    $entry = $this->store->loadEntry($uid, $timeline, $seq);
    if ($entry === NULL) {
      return NULL;
    }

    $entities = [];
    foreach ($entry['writes'] as $write) {
      $restored = !empty($write['absent'])
        ? $this->restoreAbsent($write)
        : $this->restoreEnvelope($write);
      if ($restored instanceof EntityInterface) {
        $entities[] = $restored;
      }
    }

    // Everything between the two states changed, in whichever direction.
    $targets = [];
    $from = min($index['pointer'], $seq);
    $to = max($index['pointer'], $seq);
    foreach ($index['entries'] as $entry_seq => $metadata) {
      if ($entry_seq > $from && $entry_seq <= $to) {
        $targets = array_merge($targets, $metadata['targets'] ?? []);
      }
    }

    $index['pointer'] = $seq;
    $this->store->saveIndex($uid, $timeline, $index);

    return [
      'pointer' => $seq,
      'label' => $index['entries'][$seq]['label'],
      'entities' => $entities,
      'targets' => $targets,
    ];
  }

  /**
   * Finds the entry adjacent to the pointer in the given direction.
   */
  protected function adjacentSeq(mixed $subject, int $direction): ?int {
    $timeline = $this->timelineFor($subject);
    $index = $this->store->loadIndex($this->currentUser->id(), $timeline);
    if ($index === NULL) {
      return NULL;
    }
    $candidates = array_filter(
      array_keys($index['entries']),
      fn(int|string $seq) => $direction < 0 ? $seq < $index['pointer'] : $seq > $index['pointer'],
    );
    if ($candidates === []) {
      return NULL;
    }
    return (int) ($direction < 0 ? max($candidates) : min($candidates));
  }

  /**
   * Restores a snapshot envelope by writing its subject through a strategy.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The entity whose staged state changed, when one can be identified.
   */
  protected function restoreEnvelope(array $write): ?EntityInterface {
    // The envelope is this module's own gzcompress()ed serialize() of a
    // tempstore envelope (HistoryRecorder), read back from the key-value
    // store; it carries section storage objects, so the classes cannot be
    // limited without breaking restore, and no user input reaches it.
    // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
    $envelope = unserialize(gzuncompress($write['envelope']));
    $subject = $this->subjectFromEnvelope(is_array($envelope) ? $envelope : NULL);
    if ($subject === NULL) {
      return NULL;
    }
    $this->recorder->suppress(
      fn() => $this->strategySelector->getStrategy($subject)->set($subject),
    );
    return $this->entityFromSubject($subject);
  }

  /**
   * Restores an ABSENT baseline: deletes the key's tempstore entry.
   *
   * The snapshot has no subject to delete BY, so the subject is derived
   * from the state currently in tempstore — the state being replaced.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The entity whose staged state was removed, when one can be identified.
   */
  protected function restoreAbsent(array $write): ?EntityInterface {
    $current = $this->tempStoreFactory->get($write['collection'])->get($write['key']);
    $subject = $this->subjectFromEnvelope(is_array($current) ? $current : NULL);
    if ($subject === NULL) {
      // Nothing staged: absent is already the current state.
      return NULL;
    }
    $this->recorder->suppress(
      fn() => $this->strategySelector->getStrategy($subject)->delete($subject),
    );
    return $this->entityFromSubject($subject);
  }

  /**
   * Extracts the storable subject from a tempstore envelope.
   */
  protected function subjectFromEnvelope(?array $envelope): mixed {
    return $envelope['section_storage'] ?? $envelope['entity'] ?? NULL;
  }

  /**
   * Extracts the entity a tempstore subject stages, when there is one.
   */
  protected function entityFromSubject(mixed $subject): ?EntityInterface {
    if ($subject instanceof SectionStorageInterface) {
      try {
        $entity = $subject->getContextValue('entity');
        return $entity instanceof EntityInterface ? $entity : NULL;
      }
      catch (\Exception) {
        return NULL;
      }
    }
    return $subject instanceof EntityInterface ? $subject : NULL;
  }

}
