<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control for fonts: the swatch governance model, type-scoped.
 *
 * Per-gesture permissions, each matching a lifecycle gesture:
 * - "create fonts": minting from files. On strict-guide sites this IS the
 *   vocabulary-extension gate: a site whose type must stay curated simply
 *   does not grant it.
 * - "update fonts": face edits, rename, and revision restore, the
 *   site-wide-blast-radius gestures (editing a face restyles every
 *   element set in the family).
 * - "delete fonts" / "delete fonts in use": the two delete grades. Entity
 *   access answers the OR of both; the contextual gate (a family in use
 *   needs the in-use grade) lives in the delete gesture itself, where the
 *   usage is readable. Cached access results cannot depend on reference
 *   counts.
 * - "administer fonts": everything.
 *
 * USING a font needs no permission here: it is an ordinary edit to a host
 * entity the user can already edit, exactly like applying a variant.
 * Published fonts are viewable by everyone: their at-rules compile into a
 * public stylesheet and every editor's Materials section lists them.
 * Unpublished fonts (workspace drafts) are administer-only.
 */
class DaedalusStylishFontAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    return match ($operation) {
      'view' => $entity instanceof EntityPublishedInterface && $entity->isPublished()
        ? AccessResult::allowed()->addCacheableDependency($entity)
        : AccessResult::allowedIfHasPermission($account, 'administer fonts')->addCacheableDependency($entity),
      'update' => AccessResult::allowedIfHasPermissions($account, ['update fonts', 'administer fonts'], 'OR'),
      'delete' => AccessResult::allowedIfHasPermissions($account, [
        'delete fonts',
        'delete fonts in use',
        'administer fonts',
      ], 'OR'),
      default => AccessResult::neutral(),
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermissions($account, ['create fonts', 'administer fonts'], 'OR');
  }

}
