<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Sweeps a host's whole style map to the base-at-top width bands.
 *
 * The width-band migration's ride on daedalus_layout's transform spine: the
 * walkers run it over every persisted host revision, and the presave /
 * load-time safety net keeps stale tempstore sessions converging after
 * the "wide" condition is gone (a staged map still spelling wide would
 * otherwise write it back verbatim on save). Registered permanently, the
 * ContainerGrammarSweep posture: the fold rule is frozen history, and a
 * converged map costs one no-op sweep. Runs after the container-model
 * sweeps (tag priority) so a truly stale map speaks the narrow/wide
 * container grammar before wide folds into base.
 */
class WidthBandSweep extends StyleMapSweepBase {

  public function __construct(
    protected WidthBandMigration $migration,
  ) {}

  /**
   * {@inheritdoc}
   */
  protected function sweep(array $map, ContentEntityInterface $host, string $context, array &$lines): ?array {
    return $this->migration->migrateStyleMap($map, $context, $lines);
  }

}
