<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish_layouts;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\daedalus_stylish\StyleMapSweepBase;

/**
 * Sweeps a host's whole style map to the container grammar.
 *
 * Runs once per host AFTER the section walk (the host-transform contract),
 * so the section transform has already moved region entries onto minted
 * children and renamed its own sections' keys. What remains is map-wide
 * grammar: stray section:{uuid} keys (non-daedalus_stylish or already-container
 * sections styled under the old spelling), region keys with no element
 * left to address (dropped loudly), retired mobile/tablet conditions on
 * EVERY entry (blocks and entities included; the frozen map folds both
 * into narrow, and WidthBandSweep afterward folds wide into base for the
 * current narrow/medium vocabulary), and fragment references still
 * spelling region()/section() slot segments.
 */
class ContainerGrammarSweep extends StyleMapSweepBase {

  public function __construct(
    protected ContainerMigration $migration,
  ) {}

  /**
   * {@inheritdoc}
   */
  protected function sweep(array $map, ContentEntityInterface $host, string $context, array &$lines): ?array {
    return $this->migration->migrateStyleMap($map, $context, $lines);
  }

}
