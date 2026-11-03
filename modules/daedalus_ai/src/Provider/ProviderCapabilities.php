<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\Provider;

use Drupal\ai\Plugin\ProviderProxy;

/**
 * Reads the capability interfaces off a provider, proxied or not.
 *
 * The provider instance the spine is handed is a ProviderProxy
 * (AiProviderPluginManager::createInstance() always wraps the plugin), and
 * the proxy implements none of the plugin's interfaces: an instanceof on it
 * never matches a capability, and its __get forwards to the plugin's
 * properties, not its own. Every capability check in the spine goes through
 * this helper, which unwraps the proxy first. drupal/ai 1.5 exposes the
 * plugin through ProviderProxy::getPlugin(); 1.4 keeps it in the protected
 * property only, so the helper reads that when the getter is absent.
 */
final class ProviderCapabilities {

  /**
   * The provider plugin behind a proxy, or the object itself when unwrapped.
   *
   * @param object $provider
   *   A provider plugin, or the proxy drupal/ai wraps it in.
   */
  public static function plugin(object $provider): object {
    if (!$provider instanceof ProviderProxy) {
      return $provider;
    }
    if (method_exists($provider, 'getPlugin')) {
      return $provider->getPlugin();
    }
    $property = new \ReflectionProperty(ProviderProxy::class, 'plugin');
    return $property->getValue($provider);
  }

  /**
   * Whether the provider plugin declares a capability interface.
   *
   * @param object $provider
   *   A provider plugin or its proxy.
   * @param class-string $interface
   *   The capability interface.
   */
  public static function has(object $provider, string $interface): bool {
    return self::plugin($provider) instanceof $interface;
  }

  /**
   * The provider plugin as a capability interface, or NULL without it.
   *
   * @param object $provider
   *   A provider plugin or its proxy.
   * @param class-string<T> $interface
   *   The capability interface.
   *
   * @return T|null
   *   The plugin, typed by the interface, or NULL when it lacks it.
   *
   * @template T of object
   */
  public static function as(object $provider, string $interface): ?object {
    $plugin = self::plugin($provider);
    return $plugin instanceof $interface ? $plugin : NULL;
  }

}
