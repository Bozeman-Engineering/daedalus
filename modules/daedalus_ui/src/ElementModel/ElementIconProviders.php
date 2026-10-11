<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\ElementModel;

use Drupal\Core\Cache\CacheableMetadata;

/**
 * Collects the element icon providers and answers for all of them.
 *
 * The `daedalus_ui.element_icon_provider` collector: providers arrive in
 * service priority order (the service_collector pass sorts them), and the
 * first non-null answer wins. With no provider registered every answer is
 * NULL, so a container and an inline block carry no icon until a module
 * that knows them (daedalus_layout) is installed.
 *
 * @see ElementIconProviderInterface
 */
final class ElementIconProviders implements ElementIconProviderInterface {

  /**
   * The collected providers, in priority order.
   *
   * @var \Drupal\daedalus_ui\ElementModel\ElementIconProviderInterface[]
   */
  private array $providers = [];

  /**
   * Registers a provider (daedalus_ui.element_icon_provider tag).
   */
  public function addProvider(ElementIconProviderInterface $provider): void {
    $this->providers[] = $provider;
  }

  /**
   * {@inheritdoc}
   */
  public function icon(array $component, string $kind, CacheableMetadata $cacheability): ?string {
    foreach ($this->providers as $provider) {
      // A provider that answers NULL may still have read something to
      // decide so (a block type with no icon set); what it read rides the
      // accumulator either way, exactly as IconResolver merges a media's
      // tags whether or not a file resolves.
      $icon = $provider->icon($component, $kind, $cacheability);
      if ($icon !== NULL) {
        return $icon;
      }
    }
    return NULL;
  }

}
