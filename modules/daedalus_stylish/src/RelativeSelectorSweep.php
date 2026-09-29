<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Sweeps a host's style map onto the relative selector grammar.
 *
 * Rides daedalus_layout's transform spine (StyleMapSweepBase) once per
 * host, after every other sweep (tag priority): the container sweeps
 * converge truly stale data onto the container model first (the part
 * hoist still writes a children entry), and only then does the spelling
 * of every "::" suffix converge to the grammar's one canonical form
 * (RelativeSelectorConvergence). Registered permanently, so stale
 * tempstore sessions carrying the pseudo-part spelling keep converging
 * on load and save after no parser reads it.
 */
class RelativeSelectorSweep extends StyleMapSweepBase {

  public function __construct(
    protected RelativeSelectorConvergence $convergence,
  ) {}

  /**
   * {@inheritdoc}
   */
  protected function sweep(array $map, ContentEntityInterface $host, string $context, array &$lines): ?array {
    return $this->convergence->convergeStyleMap($map, $context, $lines);
  }

}
