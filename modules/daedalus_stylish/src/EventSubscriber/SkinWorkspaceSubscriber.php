<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\EventSubscriber;

use Drupal\daedalus_blueprint\Materials\MaterialsRosterBuilder;
use Drupal\daedalus_stylish\DefaultsStylesheet;
use Drupal\daedalus_stylish\TokenUsage;

/**
 * Reacts to a workspace publish for the skin: recompile, resync usage.
 *
 * The skin instance of StylesheetWorkspaceSubscriberBase (which owns the
 * rationale): a staged skin revision lands in the compiled defaults file
 * (the skin's tiers of it; the declared tier is code and stages nothing).
 * The token walk reads the roster, so the base's roster reset before the
 * compile is what makes the publish visible. Skins keep no identity map;
 * their tokens' usage table (the published sources' var() references to
 * custom properties) resyncs here, the swatch subscriber's twin.
 */
final class SkinWorkspaceSubscriber extends StylesheetWorkspaceSubscriberBase {

  public function __construct(
    DefaultsStylesheet $defaultsStylesheet,
    MaterialsRosterBuilder $rosterBuilder,
    protected TokenUsage $tokenUsage,
  ) {
    parent::__construct($defaultsStylesheet, $rosterBuilder);
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
    $this->tokenUsage->resyncPublished($published);
  }

}
