<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Entity;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control for the site frame.
 *
 * Every visitor sees the frame rendered around every route, so viewing it
 * is open; everything else (editing its document and styles, deleting it)
 * is the site builder's `configure the site frame`, the entity type's
 * admin permission.
 */
class FrameAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    if ($operation === 'view') {
      return AccessResult::allowed();
    }
    return parent::checkAccess($entity, $operation, $account);
  }

}
