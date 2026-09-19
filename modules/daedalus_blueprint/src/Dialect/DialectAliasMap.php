<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Dialect;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\TempStore\SharedTempStoreFactory;

/**
 * The dialect's alias layer: author-chosen ids over minted uuids.
 *
 * An author writes `id="hero"`; the op layer mints the RFC 4122 uuid (the
 * uuid identity model is untouched) and remembers the pairing here,
 * per entity per conversation, so `ref: "hero"` keeps resolving on later
 * turns and the printer can echo `data-alias="hero"` beside the uuid id.
 *
 * Storage is shared tempstore (collection
 * 'daedalus_blueprint.dialect_aliases'), keyed by conversation + entity:
 * aliases are working-session state, not content — they expire with the
 * tempstore and are never part of the saved entity.
 *
 * Writes land on an in-memory overlay first and only persist() flushes
 * them to tempstore; discard() drops the overlay. BlueprintOperations
 * calls persist()/discard() when the OUTERMOST batch settles, so a rolled
 * back batch never leaves aliases pointing at identity that was never
 * born, while ops later in one batch see aliases minted by earlier ones.
 */
final class DialectAliasMap {

  private const COLLECTION = 'daedalus_blueprint.dialect_aliases';

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
   * The alias => uuid map for an entity in a conversation.
   *
   * Persisted pairings with the batch overlay applied (recordings win,
   * removals filter).
   */
  public function aliases(FieldableEntityInterface $entity, string $conversation): array {
    $key = $this->storeKey($entity, $conversation);
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
   * Resolves an alias to its uuid, or NULL when unknown.
   */
  public function resolve(FieldableEntityInterface $entity, string $conversation, string $alias): ?string {
    return $this->aliases($entity, $conversation)[$alias] ?? NULL;
  }

  /**
   * The uuid => alias map for printing (`data-alias`).
   *
   * When several aliases point at one uuid, the most recently recorded
   * pairing wins (array order: stored first, overlay last).
   */
  public function printAliases(FieldableEntityInterface $entity, string $conversation): array {
    $by_uuid = [];
    foreach ($this->aliases($entity, $conversation) as $alias => $uuid) {
      $by_uuid[$uuid] = $alias;
    }
    return $by_uuid;
  }

  /**
   * Records an alias => uuid pairing on the overlay.
   */
  public function record(FieldableEntityInterface $entity, string $conversation, string $alias, string $uuid): void {
    $key = $this->storeKey($entity, $conversation);
    $this->overlay[$key][$alias] = $uuid;
    unset($this->removals[$key][$uuid]);
  }

  /**
   * Forgets every alias pointing at a uuid (the element was removed).
   */
  public function forget(FieldableEntityInterface $entity, string $conversation, string $uuid): void {
    $key = $this->storeKey($entity, $conversation);
    $this->removals[$key][$uuid] = TRUE;
    $this->overlay[$key] = array_filter(
      $this->overlay[$key] ?? [],
      static fn (string $mapped): bool => $mapped !== $uuid,
    );
  }

  /**
   * Flushes the overlay to tempstore (the outermost batch succeeded).
   */
  public function persist(): void {
    $keys = array_unique(array_merge(array_keys($this->overlay), array_keys($this->removals)));
    foreach ($keys as $key) {
      $map = $this->stored($key);
      foreach ($this->overlay[$key] ?? [] as $alias => $uuid) {
        $map[$alias] = $uuid;
      }
      if (!empty($this->removals[$key])) {
        $map = array_filter($map, fn (string $uuid): bool => !isset($this->removals[$key][$uuid]));
      }
      $this->tempstoreFactory->get(self::COLLECTION)->set($key, $map);
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
   * Reads the persisted map for a store key.
   */
  private function stored(string $key): array {
    $stored = $this->tempstoreFactory->get(self::COLLECTION)->get($key);
    return is_array($stored) ? $stored : [];
  }

  /**
   * The tempstore key: conversation + entity identity.
   */
  private function storeKey(FieldableEntityInterface $entity, string $conversation): string {
    $identity = $entity->uuid() ?: (string) $entity->id();
    return $conversation . '|' . $entity->getEntityTypeId() . '|' . $identity;
  }

}
