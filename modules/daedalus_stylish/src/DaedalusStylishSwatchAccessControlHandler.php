<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control for swatches — the variant governance model, value-scoped.
 *
 * Per-gesture permissions, each matching a lifecycle gesture:
 * - "create swatches": minting. On strict-guide sites this IS the
 *   vocabulary-extension gate — a site whose palette must stay curated
 *   simply does not grant it.
 * - "update swatches": value edits, rename, and revision restore — the
 *   site-wide-blast-radius gestures (a swatch edit restyles every element
 *   referencing it, the skin-like half of the model).
 * - "delete swatches" / "delete swatches in use": the two delete grades.
 *   Entity access answers the OR of both; the contextual gate — a
 *   REFERENCED swatch needs the in-use grade, because deleting it runs the
 *   var()-to-literal rewrite batch — lives in the delete gesture itself,
 *   where the usage table is readable. Cached access results cannot depend
 *   on reference counts.
 * - "administer swatches": everything, palette management included.
 *
 * REFERENCING a swatch needs no permission here: it is an ordinary edit to
 * a host entity the user can already edit, exactly like applying a variant.
 * That is also why published swatches are viewable by everyone — their CSS
 * compiles into a public stylesheet and every editor's palette lists them;
 * the names and values are not secrets. Unpublished swatches (workspace
 * drafts, retired sets) are administer-only.
 */
class DaedalusStylishSwatchAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    return match ($operation) {
      'view' => $entity->isPublished()
        ? AccessResult::allowed()->addCacheableDependency($entity)
        : AccessResult::allowedIfHasPermission($account, 'administer swatches')->addCacheableDependency($entity),
      'update' => AccessResult::allowedIfHasPermissions($account, ['update swatches', 'administer swatches'], 'OR'),
      'delete' => AccessResult::allowedIfHasPermissions($account, [
        'delete swatches',
        'delete swatches in use',
        'administer swatches',
      ], 'OR'),
      default => AccessResult::neutral(),
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermissions($account, ['create swatches', 'administer swatches'], 'OR');
  }

}
