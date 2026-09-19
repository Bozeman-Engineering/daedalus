<?php

declare(strict_types=1);

namespace Drupal\daedalus_tempstore\ParamConverter;

use Drupal\Core\Entity\EntityInterface;

/**
 * Collects activation voters and composes their votes.
 *
 * Voters register via the `tempstore_activation_voter` service tag and are
 * gathered by the service_collector pass. Composition is order-independent:
 * any Deny deactivates swapping, otherwise at least one Allow activates it,
 * otherwise (no voters, or only Neutral votes) it stays inactive, keeping
 * daedalus_tempstore side-effect free when installed standalone.
 */
final class ActivationVoters {

  /**
   * The registered voters.
   *
   * @var \Drupal\daedalus_tempstore\ParamConverter\TempstoreActivationVoterInterface[]
   */
  private array $voters = [];

  /**
   * Registers a voter.
   *
   * Called by the service_collector pass for each tagged service.
   *
   * @param \Drupal\daedalus_tempstore\ParamConverter\TempstoreActivationVoterInterface $voter
   *   The voter to register.
   */
  public function addVoter(TempstoreActivationVoterInterface $voter): void {
    $this->voters[] = $voter;
  }

  /**
   * Determines if tempstore swapping should be active.
   *
   * @return bool
   *   TRUE when at least one voter allows and none deny.
   */
  public function isActive(): bool {
    $allowed = FALSE;
    foreach ($this->voters as $voter) {
      $vote = $voter->vote();
      if ($vote === ActivationVote::Deny) {
        return FALSE;
      }
      $allowed = $allowed || $vote === ActivationVote::Allow;
    }
    return $allowed;
  }

  /**
   * Notifies every voter that an entity was swapped in.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The tempstore entity that was swapped in.
   */
  public function onEntitySwapped(EntityInterface $entity): void {
    foreach ($this->voters as $voter) {
      $voter->onEntitySwapped($entity);
    }
  }

}
