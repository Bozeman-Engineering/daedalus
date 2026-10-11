<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Materials;

use Drupal\daedalus_blueprint\Host\HostInterface;

/**
 * The materials this request changed, as the delta every response carries.
 *
 * The mid-session half of the delivery contract (MaterialsDelivery is the
 * page-load half): a material write anywhere in the request (an op batch
 * saving a blueprint, a gesture route minting a swatch, an AI tool creating
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
 * The delta is FOR a canvas when the response has one: resolved with the
 * canvas host, a touched blueprint the roster carries but the canvas does
 * not offer at block scope (CanvasOffer asked for the Add panel's scope:
 * page scope, no root, or a fit the write just ended) is a remove too, so
 * the client store on that page sheds it the
 * moment the write lands, the same judgement the page-load payload
 * applied. The canvas is the page the client is on, which is not always
 * the host the write touched (an AI turn may write another host). A
 * flush with no canvas (a gesture route over no page, the inspector
 * pop-out) is unfiltered, as the payload is with no host.
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
    private readonly CanvasOffer $offer,
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
   * @param \Drupal\daedalus_blueprint\Host\HostInterface|null $canvas
   *   The canvas host the response is for: a touched record it does not
   *   offer is a remove; NULL for a response over no canvas, unfiltered.
   *
   * @return array{upsert: array[], remove: array[]}
   *   The delta.
   */
  public function delta(?HostInterface $canvas = NULL): array {
    $delta = self::none();
    if ($this->touched === []) {
      return $delta;
    }
    $roster = $this->rosterBuilder->build();
    foreach (MaterialKind::cases() as $kind) {
      foreach (array_keys($this->touched[$kind->value] ?? []) as $id) {
        $id = (string) $id;
        $record = $roster->get($kind, $id);
        if ($record !== NULL && ($canvas === NULL || $this->offer->offered($record, $canvas, MaterialRecord::SCOPE_BLOCK))) {
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
   * The delta for an upsert a response has staged but not yet performed.
   *
   * The twin of stagedRemoval() for a batched gesture that ENDS in a
   * record (a referenced token's rename: the rewrite-then-rename batch
   * moves the record in its last request): the response states the
   * record as it will be, and the client applies it once the batch has
   * run. Never merged into the touched set, for the same reason.
   *
   * @return array{upsert: array[], remove: array[]}
   *   The delta: one upsert per record, no removes.
   */
  public function stagedUpsert(MaterialRecord ...$records): array {
    $delta = self::none();
    foreach ($records as $record) {
      $delta['upsert'][] = $record->toArray();
    }
    return $delta;
  }

  /**
   * The delta so far, and the set cleared: one response carries it once.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface|null $canvas
   *   The canvas host, as delta() takes it.
   *
   * @return array{upsert: array[], remove: array[]}
   *   The delta.
   */
  public function flush(?HostInterface $canvas = NULL): array {
    $delta = $this->delta($canvas);
    $this->touched = [];
    return $delta;
  }

}
