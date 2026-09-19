<?php

declare(strict_types=1);

namespace Drupal\daedalus_tempstore\ParamConverter;

/**
 * A single voter's opinion on whether tempstore entity swapping is active.
 *
 * Votes compose order-independently in ActivationVoters: any Deny wins over
 * any Allow, and Neutral defers entirely. The three states exist so that
 * "no opinion" and "blocked" are distinct answers, which a boolean cannot
 * express.
 */
enum ActivationVote {

  case Allow;
  case Deny;
  case Neutral;

}
