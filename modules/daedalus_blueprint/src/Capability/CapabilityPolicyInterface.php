<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Capability;

use Drupal\Core\Session\AccountInterface;
use Drupal\daedalus_blueprint\Host\HostInterface;

/**
 * One reason a capability may be refused.
 *
 * The resolver intersects every policy registered as a service tagged
 * `daedalus_blueprint.capability_policy`: a capability is allowed when no
 * policy refuses it, and the first refusal (highest priority first) is the
 * explanation the verdict carries. A policy answers one question and reads
 * one kind of fact (who the actor is, what the host owns, which translation
 * is being written, whether a workspace is active); adding a reason to
 * refuse adds a policy, never a check on a surface.
 */
interface CapabilityPolicyInterface {

  /**
   * Judges one capability of an actor on one target.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host the gesture is made on (the translation when one is named).
   * @param \Drupal\Core\Session\AccountInterface $actor
   *   The account making the gesture.
   * @param \Drupal\daedalus_blueprint\Capability\Capability $capability
   *   The capability asked.
   * @param string $target
   *   The element uuid, or the host's uuid for the root (its fields, its
   *   own style key, its top-level flow).
   *
   * @return \Drupal\daedalus_blueprint\Capability\Refusal|null
   *   The refusal, or NULL when this policy has no objection.
   */
  public function judge(HostInterface $host, AccountInterface $actor, Capability $capability, string $target): ?Refusal;

}
