<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_layout\ComponentTransform\HostTransformInterface;

/**
 * Base for host transforms that sweep a host's whole style map.
 *
 * A sweep rides daedalus_layout's transform spine once per host, after the section
 * walk: the walkers run it over every persisted host revision and every
 * template display, and the presave / load-time safety net keeps stale
 * tempstore sessions converging. The base owns the ride (read the host's
 * style map wherever the host keeps it, skip hosts with none, write the
 * swept map back and report whether anything changed); each sweep
 * supplies only its rewrite through sweep(). Sweeps are registered
 * permanently, so a converged map costs one no-op pass, and their order is
 * the daedalus_layout.host_transform tag priority.
 */
abstract class StyleMapSweepBase implements HostTransformInterface {

  /**
   * {@inheritdoc}
   */
  public function transformHost(HostInterface $host): bool {
    if (!$host->hasStyleStore()) {
      return FALSE;
    }
    $map = $host->styleMap();
    if ($map === []) {
      return FALSE;
    }
    $lines = [];
    $context = $host->entityTypeId() . ':' . $host->id();
    $swept = $this->sweep($map, $host, $context, $lines);
    if ($swept === NULL) {
      return FALSE;
    }
    $host->setStyleMap($swept);
    return TRUE;
  }

  /**
   * Rewrites one host's style map.
   *
   * @param array $map
   *   The host's non-empty style map.
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
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
  abstract protected function sweep(array $map, HostInterface $host, string $context, array &$lines): ?array;

}
