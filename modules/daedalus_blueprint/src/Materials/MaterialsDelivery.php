<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Materials;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\daedalus_blueprint\Host\HostInterface;

/**
 * The roster as the client boots it: the page-load half of delivery.
 *
 * The delivery contract has two halves and one record shape:
 * - Page load: {records: [MaterialRecord::toArray()...]} rides
 *   drupalSettings as a JSON string on the same render as the blueprint
 *   mirror (daedalus_ui's BlueprintMirror attaches it), so the client
 *   materials store boots consistent with the canvas and every widget
 *   reads its stops, offers and labels from it without a fetch. A string
 *   like the mirror envelope: core's AJAX settings merge deep-extends
 *   arrays element-wise, and a refreshed roster would mis-merge with
 *   the stale one; a string replaces atomically.
 * - Mid-session: {upsert, remove} on every mutation response and gesture
 *   route (MaterialsChangeSet), applied to the same store.
 *
 * The payload is FOR a canvas: handed the canvas host, it keeps only what
 * the canvas offers at block scope (CanvasOffer asked for the Add panel's
 * scope: a blueprint that is a container placed into a page, has a root
 * and fits the host; every other kind whole), so the Add panel offers
 * what can land on this page and nothing that would refuse. The same
 * judgement filters the mid-session delta (MaterialsChangeSet), so an
 * upsert never undoes it. Without a host (daedalus_stylish's inspector
 * pop-out, a window over no canvas, which reads blueprints only to name a
 * variant's owner) nothing is filtered. The roster the prompt and the
 * schema read is never this payload: the AI's list is the site's.
 *
 * The roster's cache metadata rides with the envelope so the render that
 * carries it is invalidated by any material write; a filtered payload adds
 * what the offer depends on.
 */
final class MaterialsDelivery {

  public function __construct(
    private readonly MaterialsRosterBuilder $rosterBuilder,
    private readonly CanvasOffer $offer,
  ) {}

  /**
   * The page-load payload: every record of every kind, in roster order.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface|null $host
   *   The canvas host the payload boots, which keeps only the records it
   *   offers; NULL for a delivery over no canvas, unfiltered.
   *
   * @return array{records: array[]}
   *   The payload.
   */
  public function payload(?HostInterface $host = NULL): array {
    $records = $this->rosterBuilder->build()->all();
    if ($host !== NULL) {
      $records = array_filter(
        $records,
        fn (MaterialRecord $record): bool => $this->offer->offered($record, $host, MaterialRecord::SCOPE_BLOCK),
      );
    }
    return [
      'records' => array_values(array_map(
        static fn (MaterialRecord $record): array => $record->toArray(),
        $records,
      )),
    ];
  }

  /**
   * The payload as the JSON string drupalSettings carries.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface|null $host
   *   The canvas host, as payload() takes it.
   */
  public function envelope(?HostInterface $host = NULL): string {
    return Json::encode($this->payload($host));
  }

  /**
   * What the envelope derives from, for the render that attaches it.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface|null $host
   *   The canvas host, as payload() takes it: a filtered envelope also
   *   depends on what the offer judged.
   */
  public function cacheMetadata(?HostInterface $host = NULL): CacheableMetadata {
    $metadata = $this->rosterBuilder->build()->getCacheableMetadata();
    return $host === NULL ? $metadata : (new CacheableMetadata())->merge($metadata)->merge($this->offer->cacheMetadata());
  }

}
