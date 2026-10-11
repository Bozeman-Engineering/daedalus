<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_library;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control for content blueprints: per type.
 *
 * Every mutation of a blueprint is judged by its TYPE's permissions
 * (BlueprintPermissions: `create {type} blueprint` for capturing one from
 * the canvas or composing one from scratch, `edit any {type} blueprint`
 * for updating, composing on its canvas, editing its details and curating
 * its variants, `delete any {type} blueprint` for deleting it). The
 * document on the blueprint's canvas is governed as on any page besides:
 * core's per-bundle override permission on the type's display (through
 * the blueprint section storage's access) and the styling module's
 * per-bundle style permission, on top of update access here.
 *
 * PLACING a blueprint needs no permission here: it is an ordinary edit to
 * a host the user can already edit, exactly like applying a style variant.
 * That is also why published blueprints are viewable by everyone: every
 * editor's palette lists them, and the labels and documents are not
 * secrets. Unpublished blueprints (workspace drafts, retired entries) are
 * visible only to those who may edit blueprints of the type.
 */
class BlueprintAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    assert($entity instanceof BlueprintInterface);
    $type = $entity->getType();
    return match ($operation) {
      'view' => $entity->isPublished()
        ? AccessResult::allowed()->addCacheableDependency($entity)
        : AccessResult::allowedIfHasPermission($account, BlueprintPermissions::permission($type, 'update'))->addCacheableDependency($entity),
      'update' => AccessResult::allowedIfHasPermission($account, BlueprintPermissions::permission($type, 'update')),
      'delete' => AccessResult::allowedIfHasPermission($account, BlueprintPermissions::permission($type, 'delete')),
      default => AccessResult::neutral(),
    };
  }

  /**
   * {@inheritdoc}
   *
   * A blueprint is created AS a type; a create question that names none
   * has no permission to ask and is neutral (the any-type question is
   * BlueprintTypeAccess's).
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    if ($entity_bundle === NULL || $entity_bundle === '') {
      return AccessResult::neutral('A blueprint is created as a type; name the type.');
    }
    return AccessResult::allowedIfHasPermission($account, BlueprintPermissions::permission((string) $entity_bundle, 'create'));
  }

}
