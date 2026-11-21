<?php

/**
 * @file
 * Post update functions for Daedalus UI.
 */

declare(strict_types=1);

use Drupal\daedalus\RoleGrant;
use Drupal\media\Entity\MediaType;
use Drupal\user\Entity\Role;

/**
 * Grants image media creation to the roles that can enter Edit Mode.
 *
 * Dropping a file on the page to make or replace media now asks "use
 * daedalus ui edit mode" and core's create access on the media type.
 * Before, update access on the page alone carried it, so no older
 * permission names the roles; the drop surface only exists in Edit Mode,
 * so every role that is no administrator, holds "use daedalus ui edit
 * mode" and cannot create media of every type already gains "create
 * {type} media" for each media type whose source is core's image plugin.
 *
 * Every other media type that keeps its source in a file (a vector image
 * type among them) is not granted: which roles may upload such a file is
 * the site's decision. The message names, per such type, the Edit Mode
 * roles that cannot create it and the permission that restores the drop.
 * Nothing happens when media is not installed.
 */
function daedalus_ui_post_update_grant_image_media_creation() {
  if (!\Drupal::moduleHandler()->moduleExists('media')) {
    return NULL;
  }
  $file_lane = \Drupal::service('daedalus_ui.file_lane');
  $granted = [];
  $reported = [];
  $types = MediaType::loadMultiple();
  ksort($types);
  foreach ($types as $id => $type) {
    if ($type->getSource()->getPluginId() === 'image') {
      $granted[] = "create $id media";
    }
    elseif ($file_lane->hasFileSource($type)) {
      $reported[] = "create $id media";
    }
  }
  // Core's create access on media: either of these reaches every type.
  $any_type = ['administer media', 'create media'];
  $lacks_create = static fn (array $held): bool => in_array('use daedalus ui edit mode', $held, TRUE)
    && array_intersect($any_type, $held) === [];

  $lines = RoleGrant::grant($lacks_create, $granted);

  $roles = Role::loadMultiple();
  ksort($roles);
  $left = [];
  foreach ($reported as $permission) {
    $lacking = [];
    foreach ($roles as $role) {
      if (!$role->isAdmin() && $lacks_create($role->getPermissions()) && !$role->hasPermission($permission)) {
        $lacking[] = sprintf('the "%s" role (%s)', $role->label(), $role->id());
      }
    }
    if ($lacking !== []) {
      $left[] = sprintf('"%s" is not granted; without it a file dropped for that media type is refused to: %s.', $permission, implode(', ', $lacking));
    }
  }

  $logger = \Drupal::logger('daedalus_ui');
  foreach ($lines as $line) {
    $logger->notice('Image media creation grant: @line', ['@line' => $line]);
  }
  foreach ($left as $line) {
    $logger->warning('Image media creation grant: @line', ['@line' => $line]);
  }
  if ($lines === [] && $left === []) {
    return t('No role needed the image media creation grant.');
  }
  return t('Dropping a file on the page now asks for Edit Mode and for create on the media type; create on the image media types, and on no other, is granted to the roles that can enter Edit Mode. @lines', ['@lines' => implode(' ', array_merge($lines, $left))]);
}
