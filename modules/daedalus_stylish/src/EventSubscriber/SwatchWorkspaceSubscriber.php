<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\EventSubscriber;

use Drupal\daedalus_blueprint\Materials\MaterialsRosterBuilder;
use Drupal\daedalus_stylish\SwatchRepository;
use Drupal\daedalus_stylish\SwatchStylesheet;
use Drupal\daedalus_stylish\SwatchUsage;

/**
 * Reacts to a workspace publish for the palette: recompile, resync usage.
 *
 * The swatch instance of StylesheetWorkspaceSubscriberBase (which owns the
 * rationale): staged swatch revisions land in the compiled swatch file,
 * and the published sources' var(--swatch-*) references resync into the
 * usage table.
 */
final class SwatchWorkspaceSubscriber extends StylesheetWorkspaceSubscriberBase {

  public function __construct(
    SwatchStylesheet $swatchStylesheet,
    protected SwatchUsage $swatchUsage,
    protected SwatchRepository $swatchRepository,
    MaterialsRosterBuilder $rosterBuilder,
  ) {
    parent::__construct($swatchStylesheet, $rosterBuilder);
  }

  /**
   * {@inheritdoc}
   */
  protected function entityTypeId(): string {
    return 'daedalus_swatch';
  }

  /**
   * {@inheritdoc}
   */
  protected function resetRepository(): void {
    $this->swatchRepository->reset();
  }

  /**
   * {@inheritdoc}
   */
  protected function resyncUsage(array $published): void {
    $this->swatchUsage->resyncPublished($published);
  }

}
