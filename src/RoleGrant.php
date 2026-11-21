<?php

declare(strict_types=1);

namespace Drupal\daedalus;

use Drupal\user\Entity\Role;

/**
 * Grants permissions to the roles a test over their permissions picks.
 *
 * The one grant step the update functions share. A gesture that newly
 * asks for a permission would be refused to a role that could make it
 * before; the module that asks grants the permission, in a post update,
 * to every role holding the older permission the gesture already needed.
 * An administrator role holds every permission by its flag and is never
 * touched.
 */
final class RoleGrant {

  /**
   * Grants permissions to each matching role that is no administrator.
   *
   * A role is saved only when it gains a permission, so a second run
   * changes nothing and answers no line.
   *
   * @param callable(string[]): bool $matches
   *   The test over a role's permissions: TRUE when the role is granted.
   * @param string[] $permissions
   *   The permissions to grant.
   *
   * @return string[]
   *   One line per role that gained a permission, naming the role and
   *   what it gained, in role id order.
   */
  public static function grant(callable $matches, array $permissions): array {
    $roles = Role::loadMultiple();
    ksort($roles);
    $lines = [];
    foreach ($roles as $role) {
      if ($role->isAdmin() || !$matches($role->getPermissions())) {
        continue;
      }
      $gained = array_values(array_diff($permissions, $role->getPermissions()));
      if ($gained === []) {
        continue;
      }
      foreach ($gained as $permission) {
        $role->grantPermission($permission);
      }
      $role->save();
      $lines[] = sprintf('The "%s" role (%s) gained: %s.', $role->label(), $role->id(), implode(', ', $gained));
    }
    return $lines;
  }

}
