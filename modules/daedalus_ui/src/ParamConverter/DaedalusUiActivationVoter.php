<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\ParamConverter;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\daedalus_ui\ModeState;
use Drupal\daedalus_tempstore\ParamConverter\ActivationVote;
use Drupal\daedalus_tempstore\ParamConverter\TempstoreActivationVoterInterface;

/**
 * Allows tempstore entity swapping while an editing surface is active.
 *
 * 'edit' is the toolbar's edit mode; 'preview' is "render my staged state
 * without editor chrome" (the responsive preview iframe, via
 * ?navigationMode=preview). Both surfaces exist to show the user their
 * staged tempstore state, so both request the swap.
 *
 * The mode value is user-controlled input (query param or cookie), so the
 * allow additionally requires the edit mode permission; without it the vote
 * is neutral and swapping stays off no matter what mode a request claims.
 */
final class DaedalusUiActivationVoter implements TempstoreActivationVoterInterface {

  public function __construct(
    private readonly ModeState $modeState,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function vote(): ActivationVote {
    $mode = $this->modeState->getMode();
    if (($mode === 'edit' || $mode === 'preview') && $this->currentUser->hasPermission('use daedalus ui edit mode')) {
      return ActivationVote::Allow;
    }
    return ActivationVote::Neutral;
  }

  /**
   * {@inheritdoc}
   */
  public function onEntitySwapped(EntityInterface $entity): void {
  }

}
