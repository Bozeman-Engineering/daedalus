<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\EventSubscriber;

use Drupal\daedalus_blueprint\Materials\MaterialsRosterBuilder;
use Drupal\daedalus_stylish\FontRepository;
use Drupal\daedalus_stylish\FontStylesheet;
use Drupal\daedalus_stylish\FontUsage;

/**
 * Reacts to a workspace publish for fonts: recompile, resync usage.
 *
 * The font instance of StylesheetWorkspaceSubscriberBase (which owns the
 * rationale): staged font revisions land in the compiled fonts file, and
 * the published sources' family references resync into the usage table.
 */
final class FontWorkspaceSubscriber extends StylesheetWorkspaceSubscriberBase {

  public function __construct(
    FontStylesheet $fontStylesheet,
    protected FontUsage $fontUsage,
    protected FontRepository $fontRepository,
    MaterialsRosterBuilder $rosterBuilder,
  ) {
    parent::__construct($fontStylesheet, $rosterBuilder);
  }

  /**
   * {@inheritdoc}
   */
  protected function entityTypeId(): string {
    return 'daedalus_font';
  }

  /**
   * {@inheritdoc}
   */
  protected function resetRepository(): void {
    $this->fontRepository->reset();
  }

  /**
   * {@inheritdoc}
   */
  protected function resyncUsage(array $published): void {
    $this->fontUsage->resyncPublished($published);
  }

}
