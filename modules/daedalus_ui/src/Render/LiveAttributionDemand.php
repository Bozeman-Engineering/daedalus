<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Render;

use Drupal\Core\Entity\EntityInterface;

/**
 * Collects live-attribution providers and answers for all of them.
 *
 * With no providers registered every answer is FALSE, which preserves the
 * default behavior: selection identity is stamped for editors only.
 *
 * @see LiveAttributionProviderInterface
 */
final class LiveAttributionDemand implements LiveAttributionProviderInterface {

  /**
   * The collected providers.
   *
   * @var \Drupal\daedalus_ui\Render\LiveAttributionProviderInterface[]
   */
  private array $providers = [];

  /**
   * Registers a provider (daedalus_ui.live_attribution_provider tag).
   */
  public function addProvider(LiveAttributionProviderInterface $provider): void {
    $this->providers[] = $provider;
  }

  /**
   * {@inheritdoc}
   */
  public function demandsKey(string $key, EntityInterface $entity): bool {
    foreach ($this->providers as $provider) {
      if ($provider->demandsKey($key, $entity)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function demandsKeyPrefix(string $prefix, EntityInterface $entity): bool {
    foreach ($this->providers as $provider) {
      if ($provider->demandsKeyPrefix($prefix, $entity)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
