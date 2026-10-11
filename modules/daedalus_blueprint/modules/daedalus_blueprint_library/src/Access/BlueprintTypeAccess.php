<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_library\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\daedalus_blueprint_library\BlueprintPermissions;
use Drupal\daedalus_blueprint_library\BlueprintTypeInterface;
use Symfony\Component\Routing\Route;

/**
 * "May this actor work on blueprints of some type?": the any-type gate.
 *
 * The question every surface that is not about ONE blueprint asks: the
 * admin listing, the gesture routes whose reference resolves inside the
 * controller (details, delete, release, transfer), daedalus_layout's
 * dialogs (chrome for a gesture the actor must be allowed to complete),
 * the Add panel's `canSaveBlueprints` flag and daedalus_stylish's
 * ownership gate. Each names the operations it is chrome for, and the
 * answer is allowed when the actor holds that operation's permission on
 * ANY blueprint type (BlueprintPermissions::permission(), the one
 * spelling). The entity's own access handler stays the authority on the
 * blueprint a gesture finally touches; this gate only opens the door to
 * the surface.
 *
 * As a route requirement: `_blueprint_type_access: 'create'`, or a
 * comma-separated list (`'create,update,delete'` for the listing). As a
 * service, anyType() answers the same question to code; the two optional
 * consumers outside this module (daedalus_layout, daedalus_stylish) take
 * the service as `@?daedalus_blueprint_library.type_access` and answer
 * FALSE without it, since without the library there is no blueprint to
 * work on.
 */
final class BlueprintTypeAccess implements AccessInterface {

  /**
   * The route requirement this check applies to.
   */
  public const REQUIREMENT = '_blueprint_type_access';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * The route gate: the requirement's operations, any type.
   */
  public function access(Route $route, AccountInterface $account): AccessResultInterface {
    $operations = array_values(array_filter(array_map('trim', explode(',', (string) $route->getRequirement(self::REQUIREMENT)))));
    return $this->anyType($account, ...$operations);
  }

  /**
   * Whether the account holds any of the operations' permissions on any type.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The actor.
   * @param string ...$operations
   *   One or more of BlueprintPermissions::OPERATIONS.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Allowed when any one permission is held; neutral otherwise. Varies by
   *   the actor's permissions and the set of types.
   */
  public function anyType(AccountInterface $account, string ...$operations): AccessResultInterface {
    if ($operations === []) {
      throw new \InvalidArgumentException('The blueprint type gate needs at least one operation (create, update or delete).');
    }
    $allowed = FALSE;
    foreach ($this->entityTypeManager->getStorage(BlueprintTypeInterface::ENTITY_TYPE_ID)->loadMultiple() as $type) {
      foreach ($operations as $operation) {
        if ($account->hasPermission(BlueprintPermissions::permission($type->id(), $operation))) {
          $allowed = TRUE;
          break 2;
        }
      }
    }
    return AccessResult::allowedIf($allowed)
      ->addCacheTags($this->entityTypeManager->getDefinition(BlueprintTypeInterface::ENTITY_TYPE_ID)->getListCacheTags())
      ->cachePerPermissions();
  }

}
