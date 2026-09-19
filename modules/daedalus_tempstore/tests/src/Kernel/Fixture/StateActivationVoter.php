<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_tempstore\Kernel\Fixture;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\State\StateInterface;
use Drupal\daedalus_tempstore\ParamConverter\ActivationVote;
use Drupal\daedalus_tempstore\ParamConverter\TempstoreActivationVoterInterface;

/**
 * Test voter whose vote is driven by state, so tests can flip it at runtime.
 */
final class StateActivationVoter implements TempstoreActivationVoterInterface {

  public function __construct(
    private readonly StateInterface $state,
    private readonly string $key,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function vote(): ActivationVote {
    return match ($this->state->get($this->key)) {
      'allow' => ActivationVote::Allow,
      'deny' => ActivationVote::Deny,
      default => ActivationVote::Neutral,
    };
  }

  /**
   * {@inheritdoc}
   */
  public function onEntitySwapped(EntityInterface $entity): void {
    $swapped = $this->state->get($this->key . '.swapped', []);
    $swapped[] = $entity->label();
    $this->state->set($this->key . '.swapped', $swapped);
  }

}
