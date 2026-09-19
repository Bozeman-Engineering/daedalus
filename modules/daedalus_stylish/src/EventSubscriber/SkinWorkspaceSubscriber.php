<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\EventSubscriber;

/**
 * Recompiles the defaults stylesheet when a workspace publishes.
 *
 * The skin instance of StylesheetWorkspaceSubscriberBase (which owns the
 * rationale): a staged skin revision lands in the compiled defaults file
 * (the skin's tiers of it; the declared tier is code and stages nothing). The
 * token walk reads the roster, so the base's roster reset before the
 * compile is what makes the publish visible; skins keep no identity map
 * and no usage table, so the other two hooks stay at their no-op defaults.
 */
final class SkinWorkspaceSubscriber extends StylesheetWorkspaceSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function entityTypeId(): string {
    return 'daedalus_skin';
  }

}
