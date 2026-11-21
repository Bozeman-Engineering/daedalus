<?php

/**
 * @file
 * Post update functions for Daedalus Blueprint.
 */

declare(strict_types=1);

use Drupal\block_content\Entity\BlockContentType;
use Drupal\daedalus\RoleGrant;
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

/**
 * Grants block creation to the roles that could already place a block.
 *
 * Placing or copying an inline block now also asks core's "create {type}
 * block content". Before, the layout permission alone carried it, so
 * every role that is no administrator and holds "configure any layout",
 * a "configure all|editable {bundle} {entity type} layout overrides"
 * permission or "configure the site frame" gains create on every block
 * type of the site. Nothing happens when block_content is not installed.
 */
function daedalus_blueprint_post_update_grant_block_creation() {
  if (!\Drupal::moduleHandler()->moduleExists('block_content')) {
    return NULL;
  }
  $permissions = [];
  foreach (array_keys(BlockContentType::loadMultiple()) as $type) {
    $permissions[] = "create $type block content";
  }
  $lines = RoleGrant::grant(
    static function (array $held): bool {
      foreach ($held as $permission) {
        if ($permission === 'configure any layout'
          || $permission === 'configure the site frame'
          || preg_match('/^configure (all|editable) .+ layout overrides$/', $permission)) {
          return TRUE;
        }
      }
      return FALSE;
    },
    $permissions,
  );
  if ($lines === []) {
    return t('No role needed the block creation grant.');
  }
  $logger = \Drupal::logger('daedalus_blueprint');
  foreach ($lines as $line) {
    $logger->notice('Block creation grant: @line', ['@line' => $line]);
  }
  return t('Placing an inline block now asks for create on its block type; the roles that could already change a layout gained it. @lines', ['@lines' => implode(' ', $lines)]);
}
