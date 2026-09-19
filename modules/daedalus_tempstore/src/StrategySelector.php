<?php

namespace Drupal\daedalus_tempstore;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\daedalus_tempstore\Strategy\TempstoreStrategyInterface;

/**
 * Selects the appropriate tempstore strategy for a given subject.
 *
 * This service uses lazy instantiation - strategies are only loaded from
 * the container when needed, avoiding circular dependency issues.
 *
 * Strategy service IDs are provided in priority order (first = highest
 * priority).
 */
class StrategySelector {

  /**
   * Constructs a StrategySelector.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param string[] $strategyIds
   *   Array of strategy service IDs in priority order (first = highest
   *   priority).
   */
  public function __construct(
    protected ContainerInterface $container,
    protected array $strategyIds = [],
  ) {}

  /**
   * Gets the appropriate strategy for the given subject.
   *
   * Iterates through strategies in order (highest priority first),
   * lazy-loading each one from the container only when needed.
   *
   * @param mixed $subject
   *   The subject to find a strategy for.
   *
   * @return \Drupal\daedalus_tempstore\Strategy\TempstoreStrategyInterface
   *   The first strategy that supports the subject.
   *
   * @throws \RuntimeException
   *   If no strategy supports the subject.
   */
  public function getStrategy($subject): TempstoreStrategyInterface {
    foreach ($this->strategyIds as $strategy_id) {
      $strategy = $this->container->get($strategy_id);
      if ($strategy->supports($subject)) {
        return $strategy;
      }
    }

    throw new \RuntimeException(sprintf(
      'No tempstore strategy found for subject of type: %s',
      is_object($subject) ? get_class($subject) : gettype($subject)
    ));
  }

}
