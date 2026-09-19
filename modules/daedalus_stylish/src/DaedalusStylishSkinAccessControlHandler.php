<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control for skins.
 *
 * One permission ("administer skins", the admin_permission) governs every
 * write and the admin UI: editing a skin restyles the whole site, the same
 * blast radius as theme administration, so there is no create/update split
 * like the variant gestures have — there are no per-element skin gestures
 * to hang finer permissions on.
 *
 * Published skins are viewable by everyone, exactly like published
 * variants: the active skin's tokens compile into a public stylesheet, so
 * the names and values are not secrets. Unpublished skins (drafts, retired
 * looks) are administer-only.
 */
class DaedalusStylishSkinAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    return match ($operation) {
      'view' => $entity->isPublished()
        ? AccessResult::allowed()->addCacheableDependency($entity)
        : AccessResult::allowedIfHasPermission($account, 'administer skins')->addCacheableDependency($entity),
      'update', 'delete' => AccessResult::allowedIfHasPermission($account, 'administer skins'),
      default => AccessResult::neutral(),
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermission($account, 'administer skins');
  }

}
