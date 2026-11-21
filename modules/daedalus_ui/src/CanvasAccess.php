<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui;

use Drupal\daedalus_blueprint\Capability\VerdictStamper;
use Drupal\daedalus_blueprint\Host\HostInterface;

/**
 * Whether the current user has an editing canvas on a host.
 *
 * The one question behind Edit Mode on a page: asked by the mode's own
 * availability (ShouldNotEditMode, which decides the chrome) and by the
 * entity view alter (EntityViewAlter, which decides the canvas), so the
 * two never disagree. The answer is the capability spine's
 * (daedalus_blueprint's resolver, the same verdicts every read carries):
 * with no capability on anything, the root and every element of the
 * document the host renders, there is no canvas. The host access policy is
 * what answers it for an actor without update access; a workspace, a
 * translation or a role refusing everything answers the same way, with no
 * second access grammar here.
 */
final class CanvasAccess {

  public function __construct(
    private readonly VerdictStamper $verdictStamper,
  ) {}

  /**
   * Whether the current user may edit anything on the host.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface|null $host
   *   The host the route is about, or NULL when it is about none.
   *
   * @return bool
   *   FALSE with no host (no host, no canvas) and when the actor has no
   *   capability on the root or on any element of the host's document.
   */
  public function allows(?HostInterface $host): bool {
    return $host !== NULL && $this->verdictStamper->allowsAnywhere($host);
  }

}
