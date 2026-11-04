<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Access;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\daedalus_blueprint\Host\HostInterface;

/**
 * Centralized access checks for blueprint operations.
 *
 * Every read, write, reference, and schema path in Daedalus Blueprint routes
 * its access decisions through this one service, so the "enforce against the
 * current user" rule lives in a single place. Callers that legitimately bypass
 * access (trusted CLI) simply do not call the checker — the checker itself
 * always answers truthfully and has no bypass toggle.
 *
 * When no account is passed, checks run against the current user, mirroring
 * core's own `EntityInterface::access()` default.
 *
 * Access semantics differ by purpose:
 * - Enforcement checks (entity, create, field view/edit) are fail-closed:
 *   neutral counts as denied, matching core's boolean `access()` form.
 * - Definition-level field access (used only to decide what to advertise in a
 *   schema) is fail-open against neutral: a field is advertisable unless access
 *   is definitively forbidden, because a check with no saved entity behind it
 *   frequently returns neutral. It is asked with a stub of the bundle in hand,
 *   never a bare definition: core's handlers take the items as optional and
 *   then reason from them anyway (node's revision_log rule reads the type's
 *   new-revision setting off the entity for anyone without administer nodes),
 *   so a bare definition crashed the schema read for every editor.
 */
class BlueprintAccessChecker {

  /**
   * Constructs a BlueprintAccessChecker.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user, used when a caller passes no explicit account.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, for resolving access control handlers.
   */
  public function __construct(
    protected AccountProxyInterface $currentUser,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Checks entity-level access for an operation.
   *
   * Works on unsaved entities (e.g. operation 'create' on a freshly built
   * entity), since it delegates to the entity's own access handler.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to check.
   * @param string $operation
   *   The operation: 'view', 'create', 'update', or 'delete'.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account to check, or NULL for the current user.
   *
   * @return bool
   *   TRUE if the operation is allowed.
   */
  public function entityAccess(EntityInterface $entity, string $operation, ?AccountInterface $account = NULL): bool {
    $account ??= $this->currentUser;
    return $entity->access($operation, $account, TRUE)->isAllowed();
  }

  /**
   * Checks a host's access for an operation: the host entity's own.
   *
   * The one root-level check of the spine: a content host answers with
   * its entity's access, a display host with the display's (update on a
   * display is administering the bundle's display).
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host to check.
   * @param string $operation
   *   The operation: 'view', 'create', 'update', or 'delete'.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account to check, or NULL for the current user.
   *
   * @return bool
   *   TRUE if the operation is allowed.
   */
  public function hostAccess(HostInterface $host, string $operation, ?AccountInterface $account = NULL): bool {
    return $this->entityAccess($host->entity(), $operation, $account);
  }

  /**
   * Checks create access for an entity type and bundle.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param string|null $bundle
   *   The bundle machine name, or NULL when the entity type has no bundles.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account to check, or NULL for the current user.
   *
   * @return bool
   *   TRUE if the user may create entities of this type and bundle.
   */
  public function createAccess(string $entity_type, ?string $bundle = NULL, ?AccountInterface $account = NULL): bool {
    $account ??= $this->currentUser;
    return $this->entityTypeManager
      ->getAccessControlHandler($entity_type)
      ->createAccess($bundle, $account, [], TRUE)
      ->isAllowed();
  }

  /**
   * Checks whether a field's value is viewable.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The field item list.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account to check, or NULL for the current user.
   *
   * @return bool
   *   TRUE if the field may be viewed.
   */
  public function fieldVisible(FieldItemListInterface $items, ?AccountInterface $account = NULL): bool {
    $account ??= $this->currentUser;
    return $items->access('view', $account, TRUE)->isAllowed();
  }

  /**
   * Checks whether a field's value is editable.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The field item list.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account to check, or NULL for the current user.
   *
   * @return bool
   *   TRUE if the field may be edited.
   */
  public function fieldEditable(FieldItemListInterface $items, ?AccountInterface $account = NULL): bool {
    $account ??= $this->currentUser;
    return $items->access('edit', $account, TRUE)->isAllowed();
  }

  /**
   * Checks field access at the definition level, for schema advertising.
   *
   * Unlike the enforcement checks, this is fail-open against neutral: it
   * returns TRUE unless access is definitively forbidden. There is no saved
   * entity to reason about, so many handlers return neutral; hiding those
   * fields from the schema would needlessly narrow what the AI can attempt.
   * The subject is an unsaved stub of the bundle: the handler receives the
   * stub's own items for the field, so a rule that reads the entity (node's
   * revision_log rule reads the type's new-revision setting off it) has one
   * to read, where a NULL items list sent it dereferencing nothing.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition.
   * @param string $operation
   *   The operation: 'view' or 'edit'.
   * @param \Drupal\Core\Entity\FieldableEntityInterface $subject
   *   An unsaved entity of the definition's type and bundle, shown to the
   *   handler and never saved.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account to check, or NULL for the current user.
   *
   * @return bool
   *   TRUE unless access is forbidden.
   */
  public function fieldDefinitionAccess(FieldDefinitionInterface $definition, string $operation, FieldableEntityInterface $subject, ?AccountInterface $account = NULL): bool {
    $account ??= $this->currentUser;
    $entity_type = $definition->getTargetEntityTypeId();
    $name = $definition->getName();
    $items = $subject->hasField($name) ? $subject->get($name) : NULL;
    $result = $this->entityTypeManager
      ->getAccessControlHandler($entity_type)
      ->fieldAccess($operation, $definition, $account, $items, TRUE);
    return !$result->isForbidden();
  }

}
