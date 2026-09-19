<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control for style variants: the governance model.
 *
 * Per-gesture permissions, each matching a lifecycle gesture:
 * - "create variants": save-as-variant.
 * - "update variants": the push ("update variant"), rename, and revision
 *   restore — the site-wide-blast-radius edit gestures.
 * - "delete variants" / "delete variants in use": the two delete grades.
 *   Entity access answers the OR of both; the contextual gate — a WORN
 *   variant needs the in-use grade, because deleting it runs the
 *   detach-then-delete batch — lives in the delete gesture itself
 *   (VariantManager::deleteVariant()), where the usage table is readable.
 *   Cached access results cannot depend on wearer counts.
 * - "administer variants": everything, library management included.
 *
 * APPLYING a variant needs no permission here: it is an ordinary edit to a
 * host entity the user can already edit. That is also why published
 * variants are viewable by everyone — their CSS compiles into a public
 * stylesheet and every editor's library picker lists them; the names and
 * values are not secrets. Unpublished variants (workspace drafts, retired
 * sets) are administer-only.
 */
class DaedalusStylishVariantAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    return match ($operation) {
      'view' => $entity->isPublished()
        ? AccessResult::allowed()->addCacheableDependency($entity)
        : AccessResult::allowedIfHasPermission($account, 'administer variants')->addCacheableDependency($entity),
      'update' => AccessResult::allowedIfHasPermissions($account, ['update variants', 'administer variants'], 'OR'),
      'delete' => AccessResult::allowedIfHasPermissions($account, [
        'delete variants',
        'delete variants in use',
        'administer variants',
      ], 'OR'),
      default => AccessResult::neutral(),
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermissions($account, ['create variants', 'administer variants'], 'OR');
  }

}
