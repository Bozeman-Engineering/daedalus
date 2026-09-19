<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Materials;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\CacheableMetadata;

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
 * The roster's cache metadata rides with the envelope so the render that
 * carries it is invalidated by any material write.
 */
final class MaterialsDelivery {

  public function __construct(
    private readonly MaterialsRosterBuilder $rosterBuilder,
  ) {}

  /**
   * The page-load payload: every record of every kind, in roster order.
   *
   * @return array{records: array[]}
   *   The payload.
   */
  public function payload(): array {
    return [
      'records' => array_map(
        static fn (MaterialRecord $record): array => $record->toArray(),
        $this->rosterBuilder->build()->all(),
      ),
    ];
  }

  /**
   * The payload as the JSON string drupalSettings carries.
   */
  public function envelope(): string {
    return Json::encode($this->payload());
  }

  /**
   * What the envelope derives from, for the render that attaches it.
   */
  public function cacheMetadata(): CacheableMetadata {
    return $this->rosterBuilder->build()->getCacheableMetadata();
  }

}
