<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Dialect;

use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\Core\TempStore\SharedTempStoreFactory;

/**
 * The dialect's alias layer: author-chosen names over minted uuids.
 *
 * Two namespaces share one layer. The element namespace: an author writes
 * `id="hero"`; the op layer mints the RFC 4122 uuid (the uuid identity
 * model is untouched) and remembers the pairing here, per host per
 * conversation, so `ref: "hero"` keeps resolving on later turns and the
 * printer can echo `data-alias="hero"` beside the uuid id. The files
 * namespace: a file the conversation holds (an attachment, bytes the
 * model declared in a batch, a render shot) is addressed by its name
 * (`file="hero.svg"`, `url('file:hero.svg')`, `view_file("hero.svg")`)
 * and the pairing name => file uuid lives here per conversation, not per
 * entity, since a file is the conversation's, not a page's.
 *
 * Storage is shared tempstore (collection
 * 'daedalus_blueprint.dialect_aliases'), keyed by conversation + host
 * for elements and by conversation + 'file' for files: aliases are
 * working-session state, not content; they expire with the tempstore and
 * are never part of the saved entity.
 *
 * Writes land on an in-memory overlay first and only persist() flushes
 * them to tempstore; discard() drops the overlay. BlueprintOperations
 * calls persist()/discard() when the OUTERMOST batch settles, so a rolled
 * back batch never leaves aliases pointing at identity that was never
 * born (an element never inserted, a file whose declaration was refused),
 * while ops later in one batch see aliases minted by earlier ones. Both
 * namespaces ride the same overlay and the same settlement.
 */
final class DialectAliasMap {

  private const COLLECTION = 'daedalus_blueprint.dialect_aliases';

  /**
   * The files namespace's segment in the store key.
   */
  private const FILE_NAMESPACE = 'file';

  /**
   * Pending alias recordings: store key => [alias => uuid].
   */
  private array $overlay = [];

  /**
   * Pending removals: store key => [uuid => TRUE].
   */
  private array $removals = [];

  public function __construct(
    private readonly SharedTempStoreFactory $tempstoreFactory,
  ) {}

  /**
   * The alias => uuid map for a host in a conversation.
   *
   * Persisted pairings with the batch overlay applied (recordings win,
   * removals filter).
   */
  public function aliases(HostInterface $host, string $conversation): array {
    return $this->mapFor($this->storeKey($host, $conversation));
  }

  /**
   * Resolves an alias to its uuid, or NULL when unknown.
   */
  public function resolve(HostInterface $host, string $conversation, string $alias): ?string {
    return $this->aliases($host, $conversation)[$alias] ?? NULL;
  }

  /**
   * The uuid => alias map for printing (`data-alias`).
   *
   * When several aliases point at one uuid, the most recently recorded
   * pairing wins (array order: stored first, overlay last).
   */
  public function printAliases(HostInterface $host, string $conversation): array {
    $by_uuid = [];
    foreach ($this->aliases($host, $conversation) as $alias => $uuid) {
      $by_uuid[$uuid] = $alias;
    }
    return $by_uuid;
  }

  /**
   * Records an alias => uuid pairing on the overlay.
   */
  public function record(HostInterface $host, string $conversation, string $alias, string $uuid): void {
    $this->recordOn($this->storeKey($host, $conversation), $alias, $uuid);
  }

  /**
   * Forgets every alias pointing at a uuid (the element was removed).
   */
  public function forget(HostInterface $host, string $conversation, string $uuid): void {
    $this->forgetOn($this->storeKey($host, $conversation), $uuid);
  }

  /**
   * The name => file uuid map for a conversation.
   *
   * Persisted pairings with the batch overlay applied (recordings win,
   * removals filter).
   */
  public function fileAliases(string $conversation): array {
    return $this->mapFor($this->fileStoreKey($conversation));
  }

  /**
   * Resolves a file name to its file uuid, or NULL when unknown.
   */
  public function resolveFile(string $conversation, string $name): ?string {
    return $this->fileAliases($conversation)[$name] ?? NULL;
  }

  /**
   * Records a name => file uuid pairing on the overlay.
   */
  public function recordFile(string $conversation, string $name, string $uuid): void {
    $this->recordOn($this->fileStoreKey($conversation), $name, $uuid);
  }

  /**
   * Forgets every name pointing at a file uuid (the file was released).
   */
  public function forgetFile(string $conversation, string $uuid): void {
    $this->forgetOn($this->fileStoreKey($conversation), $uuid);
  }

  /**
   * Flushes the overlay to tempstore (the outermost batch succeeded).
   */
  public function persist(): void {
    $keys = array_unique(array_merge(array_keys($this->overlay), array_keys($this->removals)));
    foreach ($keys as $key) {
      $this->tempstoreFactory->get(self::COLLECTION)->set($key, $this->mapFor($key));
    }
    $this->discard();
  }

  /**
   * Drops the overlay (the outermost batch rolled back).
   */
  public function discard(): void {
    $this->overlay = [];
    $this->removals = [];
  }

  /**
   * The persisted map for a store key with the overlay applied.
   */
  private function mapFor(string $key): array {
    $map = $this->stored($key);
    foreach ($this->overlay[$key] ?? [] as $alias => $uuid) {
      $map[$alias] = $uuid;
    }
    if (!empty($this->removals[$key])) {
      $map = array_filter($map, fn (string $uuid): bool => !isset($this->removals[$key][$uuid]));
    }
    return $map;
  }

  /**
   * Records a pairing on a store key's overlay.
   */
  private function recordOn(string $key, string $alias, string $uuid): void {
    $this->overlay[$key][$alias] = $uuid;
    unset($this->removals[$key][$uuid]);
  }

  /**
   * Marks a uuid removed on a store key and drops its overlay pairings.
   */
  private function forgetOn(string $key, string $uuid): void {
    $this->removals[$key][$uuid] = TRUE;
    $this->overlay[$key] = array_filter(
      $this->overlay[$key] ?? [],
      static fn (string $mapped): bool => $mapped !== $uuid,
    );
  }

  /**
   * Reads the persisted map for a store key.
   */
  private function stored(string $key): array {
    $stored = $this->tempstoreFactory->get(self::COLLECTION)->get($key);
    return is_array($stored) ? $stored : [];
  }

  /**
   * The tempstore key for the element namespace: conversation + host.
   */
  private function storeKey(HostInterface $host, string $conversation): string {
    return $conversation . '|' . $host->entityTypeId() . '|' . $host->uuid();
  }

  /**
   * The tempstore key for the files namespace: conversation + 'file'.
   *
   * Two segments where an element key has three, so no entity key can
   * ever spell it.
   */
  private function fileStoreKey(string $conversation): string {
    return $conversation . '|' . self::FILE_NAMESPACE;
  }

}
