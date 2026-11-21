<?php

/**
 * @file
 * Post update functions for Daedalus Blueprint Library.
 */

declare(strict_types=1);

use Drupal\daedalus\RoleGrant;
use Drupal\daedalus_blueprint_library\BlueprintPermissions;
use Drupal\daedalus_blueprint_library\BlueprintTypeInterface;

/**
 * Grants variant update to the roles that could already curate a blueprint.
 *
 * Adopting variants when a blueprint is saved or updated, and releasing
 * or transferring a variant a blueprint owns, now also ask "update
 * variants". Before, the blueprint permission alone carried it, so every
 * role that is no administrator and holds "create {type} blueprint" or
 * "edit any {type} blueprint" for a blueprint type of the site gains it.
 * The permission reaches every variant of the site, not only the ones a
 * blueprint owns. Nothing happens when daedalus_stylish, which defines
 * the permission, is not installed.
 */
function daedalus_blueprint_library_post_update_grant_variant_update() {
  if (!\Drupal::moduleHandler()->moduleExists('daedalus_stylish')) {
    return NULL;
  }
  $older = [];
  $types = \Drupal::entityTypeManager()->getStorage(BlueprintTypeInterface::ENTITY_TYPE_ID)->loadMultiple();
  foreach (array_keys($types) as $type) {
    $older[] = BlueprintPermissions::permission((string) $type, 'create');
    $older[] = BlueprintPermissions::permission((string) $type, 'update');
  }
  $lines = RoleGrant::grant(
    static fn (array $held): bool => array_intersect($older, $held) !== [],
    ['update variants'],
  );
  if ($lines === []) {
    return t('No role needed the variant update grant.');
  }
  $logger = \Drupal::logger('daedalus_blueprint_library');
  foreach ($lines as $line) {
    $logger->notice('Variant update grant: @line', ['@line' => $line]);
  }
  return t('Saving a blueprint with its style variants now asks for update on variants; the roles that could already save or edit a blueprint gained it, for every variant of the site. @lines', ['@lines' => implode(' ', $lines)]);
}
