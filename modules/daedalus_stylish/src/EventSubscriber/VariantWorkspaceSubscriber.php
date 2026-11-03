<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\EventSubscriber;

use Drupal\daedalus_blueprint\Materials\MaterialsRosterBuilder;
use Drupal\daedalus_stylish\VariantRepository;
use Drupal\daedalus_stylish\VariantStylesheet;
use Drupal\daedalus_stylish\VariantUsage;

/**
 * Reacts to a workspace publish for variants: recompile, resync wearers.
 *
 * The variant instance of StylesheetWorkspaceSubscriberBase (which owns
 * the rationale): staged variant revisions land in the compiled variants
 * file, and the published hosts' variant references resync into the usage
 * table (VariantUsage::resyncHosts()). The conformance verdict's request
 * memo is not reset here: the base's roster reset cascades to the
 * providers, and daedalus_stylish's forgets the verdict there.
 */
final class VariantWorkspaceSubscriber extends StylesheetWorkspaceSubscriberBase {

  public function __construct(
    VariantStylesheet $variantStylesheet,
    protected VariantUsage $variantUsage,
    protected VariantRepository $variantRepository,
    MaterialsRosterBuilder $rosterBuilder,
  ) {
    parent::__construct($rosterBuilder, $variantStylesheet);
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
  }

  /**
   * {@inheritdoc}
   */
  protected function resyncUsage(array $published): void {
    $this->variantUsage->resyncHosts($published);
  }

}
