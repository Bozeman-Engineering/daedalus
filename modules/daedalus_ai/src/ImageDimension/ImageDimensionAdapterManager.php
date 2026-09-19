<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\ImageDimension;

use Drupal\ai\AiProviderPluginManager;

/**
 * Collects image-dimension adapters and picks the one for the active provider.
 *
 * Adapters are registered (highest priority first) via the
 * daedalus_ai.image_dimension_adapter service collector. The manager resolves the
 * configured text_to_image provider once and returns the first adapter that
 * supports it; a default adapter (registered at the lowest priority with a
 * catch-all supports()) handles every provider that has no dedicated adapter.
 */
class ImageDimensionAdapterManager {

  /**
   * The registered adapters, in collection (priority) order.
   *
   * @var \Drupal\daedalus_ai\ImageDimension\ImageDimensionAdapterInterface[]
   */
  protected array $adapters = [];

  public function __construct(
    protected AiProviderPluginManager $aiProvider,
  ) {}

  /**
   * Registers an adapter (called by the service collector).
   *
   * @param \Drupal\daedalus_ai\ImageDimension\ImageDimensionAdapterInterface $adapter
   *   The adapter to register.
   */
  public function addAdapter(ImageDimensionAdapterInterface $adapter): void {
    $this->adapters[] = $adapter;
  }

  /**
   * Returns the adapter for the configured text_to_image provider.
   *
   * @return \Drupal\daedalus_ai\ImageDimension\ImageDimensionAdapterInterface|null
   *   The first adapter supporting the active provider, or NULL if none match
   *   (only possible when no default adapter is registered).
   */
  public function getAdapter(): ?ImageDimensionAdapterInterface {
    return $this->getAdapterForProvider($this->getDefaults()['provider_id'] ?? '');
  }

  /**
   * Returns the active provider's image-sizing guidance fragment.
   *
   * @return string
   *   The guidance to append to the image-generation field guidance, or an
   *   empty string when no adapter matches.
   */
  public function getGuidance(): string {
    $adapter = $this->getAdapter();
    return $adapter ? $adapter->getGuidance() : '';
  }

  /**
   * The active provider's sizing key, or NULL when no adapter matches.
   */
  public function getKey(): ?string {
    return $this->getAdapter()?->getKey();
  }

  /**
   * The active provider's sizing values, empty when no adapter matches.
   *
   * @return string[]
   *   The values.
   */
  public function getValues(): array {
    return $this->getAdapter()?->getValues() ?? [];
  }

  /**
   * Builds the active provider's configuration from an AI _generate item.
   *
   * Resolves the configured provider and model and delegates to the matching
   * adapter, so callers stay provider-blind.
   *
   * @param array $item
   *   The _generate item (carries an optional provider-specific sizing key).
   *
   * @return array
   *   Configuration for AiProviderInterface::setConfiguration(), or an empty
   *   array to use the provider's default.
   */
  public function extractConfiguration(array $item): array {
    $defaults = $this->getDefaults();
    $adapter = $this->getAdapterForProvider($defaults['provider_id'] ?? '');
    return $adapter ? $adapter->extractConfiguration($item, $defaults['model_id'] ?? '') : [];
  }

  /**
   * Selects the first registered adapter supporting a provider.
   *
   * @param string $provider_id
   *   The text_to_image provider id.
   *
   * @return \Drupal\daedalus_ai\ImageDimension\ImageDimensionAdapterInterface|null
   *   The matching adapter, or NULL when none (and no default) matches.
   */
  protected function getAdapterForProvider(string $provider_id): ?ImageDimensionAdapterInterface {
    foreach ($this->adapters as $adapter) {
      if ($adapter->supports($provider_id)) {
        return $adapter;
      }
    }
    return NULL;
  }

  /**
   * Resolves the configured text_to_image provider defaults.
   *
   * @return array
   *   The default provider info (provider_id, model_id); empty when none is
   *   configured (the default adapter still matches an empty provider id via
   *   its catch-all supports()).
   */
  protected function getDefaults(): array {
    // A site with no text_to_image default provider is a legitimate state: the
    // ai module returns NULL for it, which this manager expresses as "no
    // defaults" so the catch-all adapter path documented above still applies.
    return $this->aiProvider->getDefaultProviderForOperationType('text_to_image') ?? [];
  }

}
