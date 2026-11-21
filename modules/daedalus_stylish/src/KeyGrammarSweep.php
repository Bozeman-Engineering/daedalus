<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\daedalus_blueprint\Host\HostInterface;

/**
 * Sweeps the keys the style map key grammar rejects out of a host's map.
 *
 * Rides daedalus_layout's transform spine (StyleMapSweepBase) once per
 * host, LAST among the sweeps (tag priority): the sweeps before it
 * rewrite every retired spelling they know into the grammar, and what is
 * still outside it after them prints nothing and is dropped
 * (KeyGrammarConvergence). Registered permanently, so an unsaved copy
 * carrying such a key sheds it when it loads and a host never saves one.
 */
class KeyGrammarSweep extends StyleMapSweepBase {

  public function __construct(
    protected KeyGrammarConvergence $convergence,
  ) {}

  /**
   * {@inheritdoc}
   */
  protected function sweep(array $map, HostInterface $host, string $context, array &$lines): ?array {
    return $this->convergence->convergeStyleMap($map, $context, $lines);
  }

}
