<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai\Kernel;

use Drupal\daedalus_ai\ImageDimension\AspectRatioDimensionAdapter;
use Drupal\daedalus_ai\ImageDimension\ImageDimensionAdapterManager;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the dimension adapter manager against the real AI provider manager.
 *
 * The gate/handler kernel tests stub the manager's provider lookup; this test
 * wires the manager to the REAL ai.provider service to cover the boundary the
 * stub hides: a site with no text_to_image default provider configured. The ai
 * module returns NULL for that lookup, and the manager must express it as "no
 * defaults" (catch-all adapter, empty-string provider id) rather than a
 * TypeError, because an unconfigured image provider is a legitimate site state
 * and this path runs on every blueprint schema build.
 *
 * Only the ai module and its direct dependencies are enabled; the manager and
 * adapter are constructed directly (the daedalus_ai module's own hard AI-stack
 * dependencies are unrelated to the unit under test, see DaedalusAiKernelTestBase).
 *
 * @coversDefaultClass \Drupal\daedalus_ai\ImageDimension\ImageDimensionAdapterManager
 * @group daedalus_ai
 */
class ImageDimensionAdapterManagerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'file',
    'key',
    'ai',
  ];

  /**
   * The manager under test, wired to the real ai.provider service.
   *
   * @var \Drupal\daedalus_ai\ImageDimension\ImageDimensionAdapterManager
   */
  protected ImageDimensionAdapterManager $manager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // ai.settings ships with no default_providers.text_to_image entry, so the
    // real provider lookup returns NULL: the exact unconfigured state under
    // test.
    $this->installConfig(['ai']);
    $this->manager = new ImageDimensionAdapterManager($this->container->get('ai.provider'));
  }

  /**
   * No configured provider degrades to the catch-all adapter, not a TypeError.
   *
   * @covers ::getAdapter
   * @covers ::getGuidance
   * @covers ::getKey
   * @covers ::getValues
   * @covers ::extractConfiguration
   */
  public function testNoConfiguredProviderFallsBackToCatchAllAdapter(): void {
    $adapter = new AspectRatioDimensionAdapter();
    $this->manager->addAdapter($adapter);

    $this->assertSame($adapter, $this->manager->getAdapter());
    $this->assertSame($adapter->getGuidance(), $this->manager->getGuidance());
    $this->assertSame('aspect_ratio', $this->manager->getKey());
    $this->assertSame(['1:1', '4:3', '16:9'], $this->manager->getValues());
    $this->assertStringNotContainsString('aspect_ratio', $adapter->getGuidance(), 'The guidance is door-neutral: the key is grammar, stated by getKey().');
    $this->assertSame(
      ['aspect_ratio' => '4:3'],
      $this->manager->extractConfiguration(['_generate' => 'a portrait', 'aspect_ratio' => '4:3']),
    );
  }

  /**
   * No provider and no adapters means the feature is simply unavailable.
   *
   * @covers ::getAdapter
   * @covers ::getGuidance
   * @covers ::getKey
   * @covers ::getValues
   * @covers ::extractConfiguration
   */
  public function testNoConfiguredProviderAndNoAdaptersDegradesToNothing(): void {
    $this->assertNull($this->manager->getAdapter());
    $this->assertSame('', $this->manager->getGuidance());
    $this->assertNull($this->manager->getKey());
    $this->assertSame([], $this->manager->getValues());
    $this->assertSame([], $this->manager->extractConfiguration(['_generate' => 'a portrait', 'aspect_ratio' => '4:3']));
  }

}
