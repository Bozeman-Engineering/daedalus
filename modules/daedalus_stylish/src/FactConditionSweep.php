<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\daedalus_blueprint\Host\HostInterface;

/**
 * Sweeps a host's style map from position conditions to position qualifiers.
 *
 * Rides daedalus_layout's transform spine (StyleMapSweepBase) once per
 * host, after the relative selector sweep (tag priority): every key is
 * in the grammar's canonical spelling before a fact's block moves onto
 * its qualified key (FactConditionConvergence). Registered permanently,
 * so stale tempstore sessions carrying a fact condition keep converging
 * on load and save after no reader knows the condition.
 */
class FactConditionSweep extends StyleMapSweepBase {

  public function __construct(
    protected FactConditionConvergence $convergence,
  ) {}

  /**
   * {@inheritdoc}
   */
  protected function sweep(array $map, HostInterface $host, string $context, array &$lines): ?array {
    return $this->convergence->convergeStyleMap($map, $context, $lines);
  }

}
