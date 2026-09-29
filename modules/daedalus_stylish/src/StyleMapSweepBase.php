<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\daedalus_layout\ComponentTransform\HostTransformInterface;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\StyleMapItem;

/**
 * Base for host transforms that sweep a host's whole style map.
 *
 * A sweep rides daedalus_layout's transform spine once per host, after the section
 * walk: the walkers run it over every persisted host revision, and the
 * presave / load-time safety net keeps stale tempstore sessions converging.
 * The base owns the ride (find the host's style map, skip hosts with none,
 * write the swept map back and report whether anything changed); each
 * sweep supplies only its rewrite through sweep(). Sweeps are registered
 * permanently, so a converged map costs one no-op pass, and their order is
 * the daedalus_layout.host_transform tag priority.
 */
abstract class StyleMapSweepBase implements HostTransformInterface {

  /**
   * {@inheritdoc}
   */
  public function transformHost(ContentEntityInterface $host): bool {
    if (!$host->hasField('daedalus_stylish_styles')) {
      return FALSE;
    }
    $item = $host->get('daedalus_stylish_styles')->first();
    if (!$item instanceof StyleMapItem) {
      return FALSE;
    }
    $map = $item->getStyleMap();
    if ($map === []) {
      return FALSE;
    }
    $lines = [];
    $context = $host->getEntityTypeId() . ':' . $host->id();
    $swept = $this->sweep($map, $host, $context, $lines);
    if ($swept === NULL) {
      return FALSE;
    }
    $item->setStyleMap($swept);
    return TRUE;
  }

  /**
   * Rewrites one host's style map.
   *
   * @param array $map
   *   The host's non-empty style map.
   * @param \Drupal\Core\Entity\ContentEntityInterface $host
   *   The host, for sweeps that need more than the map (the row-arrangement sweep reads
   *   the layout tree for each container's ordered children).
   * @param string $context
   *   The "entity_type:id" label the migrations prefix their report lines
   *   with.
   * @param string[] $lines
   *   Report lines the migration appends to, by reference.
   *
   * @return array|null
   *   The rewritten map, or NULL when nothing changed.
   */
  abstract protected function sweep(array $map, ContentEntityInterface $host, string $context, array &$lines): ?array;

}
