<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control for animations: the swatch governance model, motion-scoped.
 *
 * Per-gesture permissions, each matching a lifecycle gesture:
 * - "create animations": minting. On strict-guide sites this IS the
 *   vocabulary-extension gate: a site whose motion must stay curated
 *   simply does not grant it.
 * - "update animations": step edits, rename, and revision restore, the
 *   site-wide-blast-radius gestures (editing an animation's steps
 *   restyles every element wearing it).
 * - "delete animations" / "delete animations in use": the two delete
 *   grades. Entity access answers the OR of both; the contextual gate (a
 *   WORN animation needs the in-use grade, because deleting it rewrites
 *   every reference to `none`) lives in the delete gesture itself, where
 *   the usage table is readable. Cached access results cannot depend on
 *   reference counts.
 * - "administer animations": everything.
 *
 * WEARING an animation needs no permission here: it is an ordinary edit to
 * a host entity the user can already edit, exactly like applying a
 * variant. Published animations are viewable by everyone: their keyframes
 * compile into a public stylesheet and every editor's Materials section
 * lists them. Unpublished animations (workspace drafts) are
 * administer-only.
 */
class DaedalusStylishAnimationAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    return match ($operation) {
      'view' => $entity->isPublished()
        ? AccessResult::allowed()->addCacheableDependency($entity)
        : AccessResult::allowedIfHasPermission($account, 'administer animations')->addCacheableDependency($entity),
      'update' => AccessResult::allowedIfHasPermissions($account, ['update animations', 'administer animations'], 'OR'),
      'delete' => AccessResult::allowedIfHasPermissions($account, [
        'delete animations',
        'delete animations in use',
        'administer animations',
      ], 'OR'),
      default => AccessResult::neutral(),
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermissions($account, ['create animations', 'administer animations'], 'OR');
  }

}
