<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Materials;

/**
 * The materials this request changed, as the delta every response carries.
 *
 * The mid-session half of the delivery contract (MaterialsDelivery is the
 * page-load half): a material write anywhere in the request (an op batch
 * saving a stencil, a gesture route minting a swatch, an AI tool creating
 * a variant, an importer, an admin form) is recorded here by the owning
 * module's entity hooks, and every response builder appends the delta so
 * the client store learns the change the moment it lands, whichever lane
 * made it. Request scoped: the container is built per request, so the
 * set lives exactly as long as the request that filled it.
 *
 * Hooks record IDENTITY only (kind + id); the delta resolves against the
 * fresh roster at response time, so one rule covers every change: a
 * touched id the roster carries is an upsert (minted, updated, renamed,
 * republished, ownership moved), a touched id the roster lacks is a
 * remove (deleted, unpublished, or otherwise out of the published set).
 * A hook never has to know which of those its write was, and a gesture
 * that mints then deletes in one request settles to the truth.
 *
 * Delta shape: {upsert: [MaterialRecord::toArray()...], remove: [{kind,
 * id}...]}, kinds in MaterialKind order, ids in touch order.
 */
final class MaterialsChangeSet {

  /**
   * Touched ids per kind value, in touch order.
   *
   * @var array<string, array<string, true>>
   */
  private array $touched = [];

  public function __construct(
    private readonly MaterialsRosterBuilder $rosterBuilder,
  ) {}

  /**
   * The empty delta: the shape a response carries when nothing changed.
   */
  public static function none(): array {
    return ['upsert' => [], 'remove' => []];
  }

  /**
   * Records that materials of a kind changed in this request.
   *
   * @param \Drupal\daedalus_blueprint\Materials\MaterialKind $kind
   *   The kind.
   * @param string ...$ids
   *   The kind-local ids (MaterialRecord::$id) the write touched.
   */
  public function touch(MaterialKind $kind, string ...$ids): void {
    foreach ($ids as $id) {
      if ($id === '') {
        throw new \InvalidArgumentException(sprintf('A touched %s needs an id.', $kind->noun()));
      }
      $this->touched[$kind->value][$id] = TRUE;
    }
  }

  /**
   * Whether anything was touched since the last flush.
   */
  public function isEmpty(): bool {
    return $this->touched === [];
  }

  /**
   * The delta so far, resolved against the current roster; keeps the set.
   *
   * @return array{upsert: array[], remove: array[]}
   *   The delta.
   */
  public function delta(): array {
    $delta = self::none();
    if ($this->touched === []) {
      return $delta;
    }
    $roster = $this->rosterBuilder->build();
    foreach (MaterialKind::cases() as $kind) {
      foreach (array_keys($this->touched[$kind->value] ?? []) as $id) {
        $id = (string) $id;
        $record = $roster->get($kind, $id);
        if ($record !== NULL) {
          $delta['upsert'][] = $record->toArray();
        }
        else {
          $delta['remove'][] = ['kind' => $kind->value, 'id' => $id];
        }
      }
    }
    return $delta;
  }

  /**
   * The delta for a removal a response has staged but not yet performed.
   *
   * The batched delete lanes (a referenced swatch or variant: the route
   * stages a rewrite-then-delete core Batch the client drives, and the
   * entity goes in the batch's last request, long after this response is
   * built) cannot resolve the removal at flush: the record is still in
   * the roster. The response states the outcome the batch is committed
   * to instead, and the client applies it once the batch has run. A
   * staged removal is never merged into the touched set: it describes a
   * future write, and the flush describes this request's.
   *
   * @return array{upsert: array[], remove: array[]}
   *   The delta: no upserts, one remove per id.
   */
  public function stagedRemoval(MaterialKind $kind, string ...$ids): array {
    $delta = self::none();
    foreach ($ids as $id) {
      if ($id === '') {
        throw new \InvalidArgumentException(sprintf('A staged %s removal needs an id.', $kind->noun()));
      }
      $delta['remove'][] = ['kind' => $kind->value, 'id' => $id];
    }
    return $delta;
  }

  /**
   * The delta so far, and the set cleared: one response carries it once.
   *
   * @return array{upsert: array[], remove: array[]}
   *   The delta.
   */
  public function flush(): array {
    $delta = $this->delta();
    $this->touched = [];
    return $delta;
  }

}
