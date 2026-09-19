<?php

declare(strict_types=1);

namespace Drupal\daedalus_tempstore\ParamConverter;

use Drupal\Core\Entity\EntityInterface;

/**
 * Casts a vote on whether tempstore entity swapping should be active.
 *
 * Register implementations as services tagged `tempstore_activation_voter`.
 * Votes compose order-independently: swapping activates only when at least
 * one voter allows and none deny. Modules that provide an editing surface
 * vote Allow when their surface is active; modules that gate editing vote
 * Deny when their requirements are not met.
 */
interface TempstoreActivationVoterInterface {

  /**
   * Casts this voter's opinion on tempstore entity swapping.
   *
   * @return \Drupal\daedalus_tempstore\ParamConverter\ActivationVote
   *   Allow to request swapping, Deny to veto it regardless of other
   *   voters, Neutral to defer.
   */
  public function vote(): ActivationVote;

  /**
   * Called when an entity is swapped with its tempstore version.
   *
   * Every registered voter is notified, regardless of how it voted.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The tempstore entity that was swapped in.
   */
  public function onEntitySwapped(EntityInterface $entity): void;

}
