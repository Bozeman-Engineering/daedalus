<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Drush\Commands;

use Drupal\Core\Session\AccountInterface;
use Symfony\Component\Console\Exception\RuntimeException;

/**
 * Runs a CLI operation as the user named by the command's --user option.
 *
 * Drush 14 has no global --user switch, so commands whose output is enforced
 * against the current user (schema building, deserialize/save) expose their own
 * --user option (default uid 1) and switch the account for the operation,
 * mirroring daedalus_blueprint_ai_dev_tools' prompt-audit command. Drush itself
 * bootstraps as the anonymous user, under which those operations would be
 * denied, so a deliberate, operator-chosen account is required.
 *
 * The host class must provide $this->accountSwitcher
 * (\Drupal\Core\Session\AccountSwitcherInterface) and $this->entityTypeManager
 * (\Drupal\Core\Entity\EntityTypeManagerInterface).
 */
trait RunsAsSelectedUserTrait {

  /**
   * Runs an operation as the given user, restoring the prior account after.
   *
   * @param int $uid
   *   The user ID to run as, from the --user option.
   * @param callable $operation
   *   The operation to run while switched to the user. Its return value is
   *   returned verbatim.
   *
   * @return mixed
   *   Whatever $operation returns.
   *
   * @throws \Symfony\Component\Console\Exception\RuntimeException
   *   When no user with the given ID exists.
   */
  protected function runAsUser(int $uid, callable $operation): mixed {
    $account = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$account instanceof AccountInterface) {
      throw new RuntimeException(sprintf('Cannot run as user %d: no such user.', $uid));
    }
    $this->accountSwitcher->switchTo($account);
    try {
      return $operation();
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
  }

}
