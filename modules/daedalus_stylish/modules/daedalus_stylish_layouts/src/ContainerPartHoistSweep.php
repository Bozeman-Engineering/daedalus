<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish_layouts;

use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_stylish\StyleMapSweepBase;

/**
 * Sweeps a host's style map to the one-box container grammar.
 *
 * Runs once per host AFTER the section walk, the grammar sweep, AND the
 * row-arrangement sweep (tag priority): those converge truly stale data
 * onto the two-box container grammar first - the row sweep in particular
 * still READS the part key to parse stepper tracks - and only then do the
 * surviving `::.daedalus-stylish-layout-container` (and `::>`) entries hoist onto
 * the root key, the one element a container is now.
 *
 * Registered permanently (the ContainerGrammarSweep posture): the part key
 * is frozen history, and stale tempstore sessions carrying it keep
 * converging on load and save after the part is gone from the declaration.
 */
class ContainerPartHoistSweep extends StyleMapSweepBase {

  public function __construct(
    protected ContainerPartHoist $hoist,
  ) {}

  /**
   * {@inheritdoc}
   */
  protected function sweep(array $map, HostInterface $host, string $context, array &$lines): ?array {
    return $this->hoist->hoistStyleMap($map, $context, $lines);
  }

}
