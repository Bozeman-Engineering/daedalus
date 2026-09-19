<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_stencil;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control for content stencils.
 *
 * One permission, "save stencils", governs every mutation: saving a new
 * stencil from the canvas, pushing an update into an existing one, and the
 * admin listing's edit/delete. It is also the entity type's admin
 * permission, so holders pass before these checks run.
 *
 * PLACING a stencil needs no permission here: it is an ordinary edit to a
 * host entity the user can already edit, exactly like applying a style
 * variant. That is also why published stencils are viewable by everyone —
 * every editor's palette lists them; the labels and element subtrees are
 * not secrets. Unpublished stencils (workspace drafts, retired entries)
 * are visible only to stencil savers.
 */
class StencilAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    assert($entity instanceof StencilInterface);
    return match ($operation) {
      'view' => $entity->isPublished()
        ? AccessResult::allowed()->addCacheableDependency($entity)
        : AccessResult::allowedIfHasPermission($account, 'save stencils')->addCacheableDependency($entity),
      'update', 'delete' => AccessResult::allowedIfHasPermission($account, 'save stencils'),
      default => AccessResult::neutral(),
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermission($account, 'save stencils');
  }

}
