<?php

/**
 * @file
 * Post update functions for Daedalus Blueprint.
 */

declare(strict_types=1);

use Drupal\daedalus_blueprint\Materials\MaterialsBudget;

/**
 * Seeds the AI context budget keys on existing sites.
 *
 * Sites installed before the budget carry neither key; the settings
 * object is fully validatable, so a form save or a recipe action on it
 * would be refused until both exist. The shipped install defaults land
 * here (MaterialsBudget reads them on every gesture that shares).
 */
function daedalus_blueprint_post_update_ai_context_budget(): void {
  $settings = \Drupal::configFactory()->getEditable('daedalus_blueprint.settings');
  $changed = FALSE;
  foreach (MaterialsBudget::INSTALL_DEFAULTS as $key => $default) {
    if ($settings->get($key) === NULL) {
      $settings->set($key, $default);
      $changed = TRUE;
    }
  }
  if ($changed) {
    $settings->save();
  }
}
