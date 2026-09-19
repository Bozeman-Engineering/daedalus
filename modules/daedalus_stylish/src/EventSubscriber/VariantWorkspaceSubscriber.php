<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\EventSubscriber;

use Drupal\daedalus_blueprint\Materials\MaterialsRosterBuilder;
use Drupal\daedalus_stylish\VariantConformance;
use Drupal\daedalus_stylish\VariantRepository;
use Drupal\daedalus_stylish\VariantStylesheet;
use Drupal\daedalus_stylish\VariantUsage;

/**
 * Reacts to a workspace publish for variants: recompile, resync wearers.
 *
 * The variant instance of StylesheetWorkspaceSubscriberBase (which owns
 * the rationale): staged variant revisions land in the compiled variants
 * file, and the published hosts' variant references resync into the usage
 * table (VariantUsage::resyncHosts()).
 */
final class VariantWorkspaceSubscriber extends StylesheetWorkspaceSubscriberBase {

  public function __construct(
    VariantStylesheet $variantStylesheet,
    protected VariantUsage $variantUsage,
    protected VariantRepository $variantRepository,
    MaterialsRosterBuilder $rosterBuilder,
    protected VariantConformance $conformance,
  ) {
    parent::__construct($variantStylesheet, $rosterBuilder);
  }

  /**
   * {@inheritdoc}
   */
  protected function entityTypeId(): string {
    return 'daedalus_variant';
  }

  /**
   * {@inheritdoc}
   */
  protected function resetRepository(): void {
    $this->variantRepository->reset();
    // The verdict memoizes with the repository: the published revisions
    // are judged afresh under the current law.
    $this->conformance->reset();
  }

  /**
   * {@inheritdoc}
   */
  protected function resyncUsage(array $published): void {
    $this->variantUsage->resyncHosts($published);
  }

}
