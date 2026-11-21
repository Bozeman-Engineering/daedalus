<?php

declare(strict_types=1);

namespace Drupal\daedalus_tempstore\History;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\TempStore\SharedTempStoreFactory;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Host\HostResolver;
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
 * A timeline belongs to a host: the manager keys it by the host's entity
 * through the tempstore strategies (the same key every mutation and
 * save/discard already uses), and a restore answers the hosts whose staged
 * state moved, resolved from the restored envelopes' storages. The staged
 * state is one per host, shared by its editors, and so is the timeline:
 * undo is "undo the last change to this page", whoever made it, and each
 * entry names who did. A restore puts the whole staged state back as the
 * entry recorded it. Who may restore is the caller's question (the HTTP
 * route asks what Save and Discard ask).
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
 * the returned hosts (the cache-invalidation contract lives with them).
 */
class HistoryManager {

  public function __construct(
    protected HistoryStore $store,
    protected HistoryRecorder $recorder,
    protected StrategySelector $strategySelector,
    protected SharedTempStoreFactory $tempStoreFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected HostResolver $hostResolver,
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
   * Resolves the timeline ID for a host.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host whose staged state the timeline tracks.
   *
   * @return string
   *   The timeline ID: "{collection}:{key}".
   */
  public function timelineFor(HostInterface $host): string {
    $subject = $host->entity();
    $strategy = $this->strategySelector->getStrategy($subject);
    return $strategy->getCollection($subject) . ':' . $strategy->getKey($subject);
  }

  /**
   * The timeline metadata for a host, UI-shaped.
   *
   * @return array
   *   ['pointer' => int, 'can_undo' => bool, 'can_redo' => bool,
   *   'entries' => list sorted by seq ascending]. Entries are metadata only
   *   (seq, label, uid, author, timestamp, targets, bytes, baseline) —
   *   snapshot payloads never leave the store for display. 'author' is the
   *   display name of the account that made the change, NULL on the
   *   baseline and for an account that no longer exists.
   */
  public function metadata(HostInterface $host): array {
    $timeline = $this->timelineFor($host);
    $index = $this->store->loadIndex($timeline);
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
    $entries = $this->withAuthors($entries);
    $sequence_numbers = array_column($entries, 'seq');
    return [
      'pointer' => $index['pointer'],
      'can_undo' => $index['pointer'] > min($sequence_numbers),
      'can_redo' => $index['pointer'] < max($sequence_numbers),
      'entries' => $entries,
    ];
  }

  /**
   * Names the author of each entry's change.
   *
   * @param array $entries
   *   Entry metadata, each with the 'uid' the recorder kept.
   *
   * @return array
   *   The same entries, each with 'author': the display name of the account
   *   that made the change, NULL on the baseline and for an account that no
   *   longer exists.
   */
  protected function withAuthors(array $entries): array {
    $uids = array_unique(array_filter(array_column($entries, 'uid'), 'is_int'));
    $accounts = $uids === [] ? [] : $this->entityTypeManager->getStorage('user')->loadMultiple($uids);
    foreach ($entries as &$entry) {
      $account = is_int($entry['uid'] ?? NULL) ? ($accounts[$entry['uid']] ?? NULL) : NULL;
      $entry['author'] = $account === NULL ? NULL : (string) $account->getDisplayName();
    }
    return $entries;
  }

  /**
   * Undoes one step: restores the entry before the pointer.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host whose timeline to move.
   *
   * @return array|null
   *   The restore result (see restoreTo()), or NULL if there is nothing
   *   to undo.
   */
  public function undo(HostInterface $host): ?array {
    return $this->restore($host, fn(array $index): ?int => $this->adjacentSeq($index, -1));
  }

  /**
   * Redoes one step: restores the entry after the pointer.
   *
   * @return array|null
   *   The restore result, or NULL if there is nothing to redo.
   */
  public function redo(HostInterface $host): ?array {
    return $this->restore($host, fn(array $index): ?int => $this->adjacentSeq($index, 1));
  }

  /**
   * Restores the staged state to a specific history entry.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host whose timeline to move.
   * @param int $seq
   *   The target entry's sequence number.
   *
   * @return array|null
   *   On success: [
   *     'pointer' => the new pointer (= $seq),
   *     'direction' => 'undo' when the pointer moved back, 'redo' when it
   *       moved forward,
   *     'changes' => the entries whose changes the move undid or redid,
   *       in the order it took them (an undo's newest first), each
   *       ['seq', 'label', 'uid', 'author'] as metadata() names them. An
   *       undo's changes are the entries it left, not the one it landed
   *       on,
   *     'hosts' => the hosts whose staged state changed (for the
   *       caller's persist-hook firing),
   *     'targets' => the union of the traversed entries' targets (the
   *       subtree that changed between the two states, for re-render),
   *   ]. NULL when the entry does not exist, has expired, or is already
   *   current.
   */
  public function restoreTo(HostInterface $host, int $seq): ?array {
    return $this->restore($host, fn(array $index): int => $seq);
  }

  /**
   * Moves a host's timeline to the entry a chooser names.
   *
   * The index is read, the entry chosen, the staged state written and the
   * pointer saved inside the store's lock: the timeline is shared, so the
   * step "before the pointer" must be chosen from the index the move then
   * updates, not from one another editor's request has since changed.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host whose timeline to move.
   * @param callable $choose
   *   Handed the index, answers the target entry's sequence number or NULL.
   *
   * @return array|null
   *   The restore result (see restoreTo()), or NULL.
   */
  protected function restore(HostInterface $host, callable $choose): ?array {
    $timeline = $this->timelineFor($host);
    $restored = $this->store->locked($timeline, function () use ($timeline, $choose): ?array {
      $index = $this->store->loadIndex($timeline);
      if ($index === NULL) {
        return NULL;
      }
      $seq = $choose($index);
      return $seq === NULL ? NULL : $this->restoreEntry($timeline, $index, $seq);
    });
    if ($restored !== NULL) {
      // Outside the lock: naming loads accounts, and moves nothing.
      $restored['changes'] = $this->withAuthors($restored['changes']);
    }
    return $restored;
  }

  /**
   * Writes one entry's snapshot back and moves the pointer to it.
   *
   * @return array|null
   *   The restore result (see restoreTo()) with its changes not yet named,
   *   or NULL when the entry does not exist, has expired, or is already
   *   current.
   */
  protected function restoreEntry(string $timeline, array $index, int $seq): ?array {
    if (!isset($index['entries'][$seq]) || $index['pointer'] === $seq) {
      return NULL;
    }
    $entry = $this->store->loadEntry($timeline, $seq);
    if ($entry === NULL) {
      return NULL;
    }

    $hosts = [];
    foreach ($entry['writes'] as $write) {
      $restored = !empty($write['absent'])
        ? $this->restoreAbsent($write)
        : $this->restoreEnvelope($write);
      if ($restored !== NULL) {
        $hosts[] = $restored;
      }
    }

    // Everything between the two states changed, in whichever direction.
    // The entries between them are the changes the move takes back or puts
    // back.
    $targets = [];
    $changes = [];
    $direction = $seq < $index['pointer'] ? 'undo' : 'redo';
    $from = min($index['pointer'], $seq);
    $to = max($index['pointer'], $seq);
    foreach ($index['entries'] as $entry_seq => $metadata) {
      if ($entry_seq > $from && $entry_seq <= $to) {
        $targets = array_merge($targets, $metadata['targets'] ?? []);
        $changes[] = [
          'seq' => $metadata['seq'],
          'label' => $metadata['label'],
          'uid' => $metadata['uid'] ?? NULL,
        ];
      }
    }
    usort($changes, fn(array $a, array $b) => $direction === 'undo' ? $b['seq'] <=> $a['seq'] : $a['seq'] <=> $b['seq']);

    $index['pointer'] = $seq;
    $this->store->saveIndex($timeline, $index);

    return [
      'pointer' => $seq,
      'direction' => $direction,
      'changes' => $changes,
      'hosts' => $hosts,
      'targets' => $targets,
    ];
  }

  /**
   * Finds the entry adjacent to an index's pointer in the given direction.
   */
  protected function adjacentSeq(array $index, int $direction): ?int {
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
   * @return \Drupal\daedalus_blueprint\Host\HostInterface|null
   *   The host whose staged state changed, when one can be identified.
   */
  protected function restoreEnvelope(array $write): ?HostInterface {
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
    return $this->hostOf($subject);
  }

  /**
   * Restores an ABSENT baseline: deletes the key's tempstore entry.
   *
   * The snapshot has no subject to delete BY, so the subject is derived
   * from the state currently in tempstore — the state being replaced.
   *
   * @return \Drupal\daedalus_blueprint\Host\HostInterface|null
   *   The host whose staged state was removed, when one can be identified.
   */
  protected function restoreAbsent(array $write): ?HostInterface {
    $current = $this->tempStoreFactory->get($write['collection'])->get($write['key']);
    $subject = $this->subjectFromEnvelope(is_array($current) ? $current : NULL);
    if ($subject === NULL) {
      // Nothing staged: absent is already the current state.
      return NULL;
    }
    $this->recorder->suppress(
      fn() => $this->strategySelector->getStrategy($subject)->delete($subject),
    );
    return $this->hostOf($subject);
  }

  /**
   * Extracts the storable subject from a tempstore envelope.
   */
  protected function subjectFromEnvelope(?array $envelope): mixed {
    return $envelope['section_storage'] ?? $envelope['entity'] ?? NULL;
  }

  /**
   * The host a tempstore subject stages, when it has one.
   *
   * A section storage's host is the owner of the storage (the entity in an
   * overrides storage's context, the display in a defaults storage's); an
   * entity envelope's host is the entity's own, when it is hostable.
   */
  protected function hostOf(mixed $subject): ?HostInterface {
    if ($subject instanceof SectionStorageInterface) {
      return $this->hostResolver->fromSectionStorage($subject);
    }
    return $subject instanceof EntityInterface && $this->hostResolver->hosts($subject)
      ? $this->hostResolver->fromEntity($subject)
      : NULL;
  }

}
