<?php

declare(strict_types=1);

namespace Drupal\daedalus_edit\ParamConverter;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\daedalus_tempstore\ParamConverter\ActivationVote;
use Drupal\daedalus_tempstore\ParamConverter\TempstoreActivationVoterInterface;

/**
 * Vetoes tempstore entity swapping for users without inline editing access.
 *
 * The deny is a veto, not a request: it wins over any other voter's allow,
 * regardless of registration order. Users with the permission get a neutral
 * vote; some editing surface still has to allow the swap.
 */
final class DaedalusEditActivationVoter implements TempstoreActivationVoterInterface {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function vote(): ActivationVote {
    if (!$this->currentUser->hasPermission('access inline editing')) {
      return ActivationVote::Deny;
    }
    return ActivationVote::Neutral;
  }

  /**
   * {@inheritdoc}
   */
  public function onEntitySwapped(EntityInterface $entity): void {
    // Track this entity as having been swapped so form alters can find it.
    daedalus_edit_active_tempstore_entities($entity);
  }

}
