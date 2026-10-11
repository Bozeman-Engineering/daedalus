<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Capability\Policy;

use Drupal\Core\Session\AccountInterface;
use Drupal\daedalus_blueprint\Access\BlueprintAccessChecker;
use Drupal\daedalus_blueprint\Capability\Capability;
use Drupal\daedalus_blueprint\Capability\CapabilityPolicyInterface;
use Drupal\daedalus_blueprint\Capability\Refusal;
use Drupal\daedalus_blueprint\Host\HostInterface;

/**
 * The coarse door: an actor who may not update the host may do nothing.
 *
 * Update access on the host's entity (create access on a new one): a
 * content host answers with its entity's, a display host with the
 * display's. Routes keep `_entity_access: entity.update` as the same door
 * from the outside; this policy is the door from the inside, so the spine
 * holds whatever reaches it directly (the AI tools, drush, a batch run
 * in code).
 */
final class HostAccessPolicy implements CapabilityPolicyInterface {

  public function __construct(
    private readonly BlueprintAccessChecker $accessChecker,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function judge(HostInterface $host, AccountInterface $actor, Capability $capability, string $target): ?Refusal {
    $operation = $host->entity()->isNew() ? 'create' : 'update';
    if ($this->accessChecker->hostAccess($host, $operation, $actor)) {
      return NULL;
    }
    return new Refusal(
      code: 'ACCESS_DENIED_ENTITY',
      message: sprintf('Access denied: cannot %s %s:%s.', $operation, $host->entityTypeId(), $host->bundle()),
      hint: 'The acting user lacks the required permission.',
    );
  }

}
