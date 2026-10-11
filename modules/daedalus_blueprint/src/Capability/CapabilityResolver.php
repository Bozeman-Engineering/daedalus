<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Capability;

use Drupal\Core\Session\AccountInterface;
use Drupal\daedalus_blueprint\Host\HostInterface;

/**
 * The one answer to "may this actor do this to this element on this host".
 *
 * Policies (services tagged `daedalus_blueprint.capability_policy`) are
 * intersected: the most restrictive wins, and the first refusal in
 * priority order supplies the explanation. Enforced at the op door
 * (BlueprintOperations::applyOperation() before dispatch, the deserializer
 * at the root) and published on reads, so every surface sees the same
 * verdict and no surface interprets a cause on its own.
 */
final class CapabilityResolver {

  /**
   * The policies, sorted by priority (highest first).
   *
   * @var array<array{priority: int, policy: \Drupal\daedalus_blueprint\Capability\CapabilityPolicyInterface}>
   */
  private array $policies = [];

  /**
   * Adds a policy (the service_collector pass's call).
   *
   * @param \Drupal\daedalus_blueprint\Capability\CapabilityPolicyInterface $policy
   *   The policy.
   * @param int $priority
   *   The priority; a higher priority's refusal explains first.
   */
  public function addPolicy(CapabilityPolicyInterface $policy, int $priority = 0): void {
    $this->policies[] = ['priority' => $priority, 'policy' => $policy];
    usort($this->policies, static fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);
  }

  /**
   * The verdict on one capability of an actor on one target.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host the gesture is made on (the translation when one is named).
   * @param \Drupal\Core\Session\AccountInterface $actor
   *   The account making the gesture.
   * @param \Drupal\daedalus_blueprint\Capability\Capability $capability
   *   The capability asked.
   * @param string $target
   *   The element uuid, or the host's uuid for the root.
   *
   * @return \Drupal\daedalus_blueprint\Capability\Verdict
   *   Allowed when no policy refuses; otherwise the first refusal.
   */
  public function verdict(HostInterface $host, AccountInterface $actor, Capability $capability, string $target): Verdict {
    foreach ($this->policies as ['policy' => $policy]) {
      $refusal = $policy->judge($host, $actor, $capability, $target);
      if ($refusal !== NULL) {
        return Verdict::refused($refusal);
      }
    }
    return Verdict::allowed();
  }

}
