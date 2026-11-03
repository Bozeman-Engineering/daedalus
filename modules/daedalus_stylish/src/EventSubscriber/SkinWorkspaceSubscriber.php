<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\EventSubscriber;

use Drupal\daedalus_blueprint\Materials\MaterialsRosterBuilder;
use Drupal\daedalus_stylish\SkinStylesheet;
use Drupal\daedalus_stylish\SkinMaterialUsage;
use Drupal\daedalus_stylish\VariantStylesheet;

/**
 * Reacts to a workspace publish for the skin: recompile both, resync usage.
 *
 * The skin instance of StylesheetWorkspaceSubscriberBase (which owns the
 * rationale): a staged skin revision lands in the compiled defaults file
 * (the skin's tiers of it; the declared tier is code and stages nothing)
 * and, a skin publish being a law change (a token it minted flips the
 * verdict on every variant referencing it), in the compiled variants
 * file after it. The token walk and the verdict read the roster, so the
 * base's roster reset before the compiles is what makes the publish
 * visible. Skins keep no identity map; their tokens' usage table (the
 * published sources' var() references to custom properties) resyncs
 * here, the animation subscriber's twin.
 */
final class SkinWorkspaceSubscriber extends StylesheetWorkspaceSubscriberBase {

  public function __construct(
    SkinStylesheet $defaultsStylesheet,
    VariantStylesheet $variantStylesheet,
    MaterialsRosterBuilder $rosterBuilder,
    protected SkinMaterialUsage $skinMaterialUsage,
  ) {
    parent::__construct($rosterBuilder, $defaultsStylesheet, $variantStylesheet);
  }

  /**
   * {@inheritdoc}
   */
  protected function entityTypeId(): string {
    return 'daedalus_skin';
  }

  /**
   * {@inheritdoc}
   */
  protected function resyncUsage(array $published): void {
    $this->skinMaterialUsage->resyncPublished($published);
  }

}
