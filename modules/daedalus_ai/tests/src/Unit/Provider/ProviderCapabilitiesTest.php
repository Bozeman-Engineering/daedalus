<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai\Unit\Provider;

use Drupal\ai\OperationType\OutputInterface;
use Drupal\ai\Plugin\ProviderProxy;
use Drupal\daedalus_ai\Provider\CacheBreakpointsInterface;
use Drupal\daedalus_ai\Provider\ContextCeilingInterface;
use Drupal\daedalus_ai\Provider\MidConversationSystemInterface;
use Drupal\daedalus_ai\Provider\OpaqueContentInterface;
use Drupal\daedalus_ai\Provider\ProviderCapabilities;
use Drupal\daedalus_ai\Provider\RenderingPlan;
use Drupal\daedalus_ai\Provider\ReportedCostInterface;
use Drupal\Tests\daedalus_ai\Unit\Provider\Fixture\CapableProvider;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins how the spine reads capability interfaces off a provider.
 *
 * Every caller of drupal/ai gets a ProviderProxy, which implements none of
 * the plugin's interfaces; a capability check that forgot to unwrap would
 * silently answer "floor" for every provider and the accelerators would
 * never run. The test pins that the proxy itself does not match (the
 * reason the helper exists), that the helper sees through it, and that a
 * plugin without an interface answers nothing rather than something.
 */
#[Group('daedalus_ai')]
class ProviderCapabilitiesTest extends UnitTestCase {

  /**
   * An unwrapped plugin answers its own interfaces.
   */
  public function testPlainPlugin(): void {
    $plugin = self::capablePlugin();

    $this->assertSame($plugin, ProviderCapabilities::plugin($plugin));
    $this->assertTrue(ProviderCapabilities::has($plugin, CacheBreakpointsInterface::class));
    $this->assertTrue(ProviderCapabilities::has($plugin, ContextCeilingInterface::class));
    $this->assertFalse(ProviderCapabilities::has($plugin, MidConversationSystemInterface::class));
    $this->assertFalse(ProviderCapabilities::has($plugin, OpaqueContentInterface::class));
    $this->assertTrue(ProviderCapabilities::has($plugin, ReportedCostInterface::class));

    $this->assertSame($plugin, ProviderCapabilities::as($plugin, CacheBreakpointsInterface::class));
    $this->assertSame([RenderingPlan::TTL_5M, RenderingPlan::TTL_1H], ProviderCapabilities::as($plugin, CacheBreakpointsInterface::class)->supportedCacheTtls('claude-test'));
    $this->assertSame(200000, ProviderCapabilities::as($plugin, ContextCeilingInterface::class)->contextCeiling('claude-test'));
    $this->assertNull(ProviderCapabilities::as($plugin, OpaqueContentInterface::class));
  }

  /**
   * The proxy drupal/ai wraps plugins in is seen through, never matched.
   */
  public function testProxyIsUnwrapped(): void {
    $plugin = self::capablePlugin();
    $proxy = (new \ReflectionClass(ProviderProxy::class))->newInstanceWithoutConstructor();
    (new \ReflectionProperty(ProviderProxy::class, 'plugin'))->setValue($proxy, $plugin);

    $this->assertNotInstanceOf(CacheBreakpointsInterface::class, $proxy, 'The proxy implements none of the plugin\'s interfaces; that is why the helper exists.');
    $this->assertSame($plugin, ProviderCapabilities::plugin($proxy));
    $this->assertTrue(ProviderCapabilities::has($proxy, CacheBreakpointsInterface::class));
    $this->assertFalse(ProviderCapabilities::has($proxy, OpaqueContentInterface::class));
    $this->assertSame($plugin, ProviderCapabilities::as($proxy, ContextCeilingInterface::class));
    $this->assertNull(ProviderCapabilities::as($proxy, OpaqueContentInterface::class));
  }

  /**
   * A plugin declaring nothing answers nothing for every capability.
   */
  public function testPluginWithoutCapabilities(): void {
    $plugin = new \stdClass();
    foreach ([
      CacheBreakpointsInterface::class,
      MidConversationSystemInterface::class,
      OpaqueContentInterface::class,
      ContextCeilingInterface::class,
      ReportedCostInterface::class,
    ] as $interface) {
      $this->assertFalse(ProviderCapabilities::has($plugin, $interface), "$interface is not declared.");
      $this->assertNull(ProviderCapabilities::as($plugin, $interface));
    }
  }

  /**
   * Each capability interface is a small, independent contract.
   *
   * A provider picks the ones it can honor; the spine renders the floor for
   * the rest. The stub below implements every one, so the test pins each
   * method's signature against the interface it belongs to.
   */
  public function testEveryInterfaceStandsAlone(): void {
    $plugin = new class() implements CacheBreakpointsInterface, MidConversationSystemInterface, OpaqueContentInterface, ContextCeilingInterface, ReportedCostInterface {

      /**
       * {@inheritdoc}
       */
      public function supportedCacheTtls(string $model_id): array {
        return [];
      }

      /**
       * {@inheritdoc}
       */
      public function supportsMidConversationSystem(string $model_id): bool {
        return TRUE;
      }

      /**
       * {@inheritdoc}
       */
      public function opaqueContentOf(OutputInterface $output): ?array {
        return ['type' => 'thinking'];
      }

      /**
       * {@inheritdoc}
       */
      public function contextCeiling(string $model_id): ?int {
        return NULL;
      }

      /**
       * {@inheritdoc}
       */
      public function reportedCostUsd(OutputInterface $output): ?float {
        return NULL;
      }

    };
    foreach ([
      CacheBreakpointsInterface::class,
      MidConversationSystemInterface::class,
      OpaqueContentInterface::class,
      ContextCeilingInterface::class,
      ReportedCostInterface::class,
    ] as $interface) {
      $this->assertTrue(ProviderCapabilities::has($plugin, $interface));
      $this->assertCount(1, (new \ReflectionClass($interface))->getMethods(), "$interface declares one method.");
    }
  }

  /**
   * A real provider plugin declaring three of the five capabilities.
   */
  private static function capablePlugin(): CapableProvider {
    return CapableProvider::bare();
  }

}
