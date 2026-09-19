<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Unit\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\Tests\UnitTestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use PHPUnit\Framework\Attributes\Group;
use Drupal\daedalus_layout\Event\PlaceBlockEvent;
use Drupal\Core\Block\BlockPluginInterface;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Unit tests for PlaceBlockEvent.
 */
#[CoversClass(PlaceBlockEvent::class)]
#[Group('daedalus_layout')]
class PlaceBlockEventTest extends UnitTestCase {

  use ProphecyTrait;

  /**
   * Tests constructor with simple plugin ID (no bundle).
   */
  public function testConstructorWithSimplePluginId(): void {
    $blockPlugin = $this->prophesize(BlockPluginInterface::class);
    $blockPlugin->getPluginId()->willReturn('system_breadcrumb_block');

    $event = new PlaceBlockEvent($blockPlugin->reveal());

    $this->assertSame('system_breadcrumb_block', $event->getBlockPluginId());
    $this->assertNull($event->getBundle());
  }

  /**
   * Tests constructor with bundled plugin ID (inline_block:bundle format).
   */
  public function testConstructorWithBundledPluginId(): void {
    $blockPlugin = $this->prophesize(BlockPluginInterface::class);
    $blockPlugin->getPluginId()->willReturn('inline_block:accordion');

    $event = new PlaceBlockEvent($blockPlugin->reveal());

    $this->assertSame('inline_block', $event->getBlockPluginId());
    $this->assertSame('accordion', $event->getBundle());
  }

  /**
   * Tests constructor with various inline block bundles.
   */
  public function testConstructorWithVariousInlineBlockBundles(): void {
    $bundles = [
      'inline_block:callout' => ['inline_block', 'callout'],
      'inline_block:hero_image' => ['inline_block', 'hero_image'],
      'inline_block:text_section' => ['inline_block', 'text_section'],
      'inline_block:call_to_action' => ['inline_block', 'call_to_action'],
      'inline_block:layout' => ['inline_block', 'layout'],
    ];

    foreach ($bundles as $pluginId => $expected) {
      $blockPlugin = $this->prophesize(BlockPluginInterface::class);
      $blockPlugin->getPluginId()->willReturn($pluginId);

      $event = new PlaceBlockEvent($blockPlugin->reveal());

      $this->assertSame($expected[0], $event->getBlockPluginId(), "Failed for plugin ID: $pluginId");
      $this->assertSame($expected[1], $event->getBundle(), "Failed for plugin ID: $pluginId");
    }
  }

  /**
   * Tests constructor with system block plugins (no bundle).
   */
  public function testConstructorWithSystemBlocks(): void {
    $systemBlocks = [
      'system_main_block',
      'page_title_block',
      'local_tasks_block',
      'system_menu_block:main',
    ];

    foreach ($systemBlocks as $pluginId) {
      $blockPlugin = $this->prophesize(BlockPluginInterface::class);
      $blockPlugin->getPluginId()->willReturn($pluginId);

      $event = new PlaceBlockEvent($blockPlugin->reveal());

      // Even system_menu_block:main should parse into plugin ID and bundle.
      if (str_contains($pluginId, ':')) {
        [$expectedPluginId, $expectedBundle] = explode(':', $pluginId);
        $this->assertSame($expectedPluginId, $event->getBlockPluginId());
        $this->assertSame($expectedBundle, $event->getBundle());
      }
      else {
        $this->assertSame($pluginId, $event->getBlockPluginId());
        $this->assertNull($event->getBundle());
      }
    }
  }

  /**
   * Tests getBlockPlugin returns the original plugin instance.
   */
  public function testGetBlockPluginReturnsOriginalInstance(): void {
    $blockPlugin = $this->prophesize(BlockPluginInterface::class);
    $blockPlugin->getPluginId()->willReturn('inline_block:accordion');
    $pluginInstance = $blockPlugin->reveal();

    $event = new PlaceBlockEvent($pluginInstance);

    $this->assertSame($pluginInstance, $event->getBlockPlugin());
  }

  /**
   * Tests getBlockPluginId returns parsed plugin ID without bundle.
   */
  public function testGetBlockPluginIdReturnsParsedId(): void {
    $blockPlugin = $this->prophesize(BlockPluginInterface::class);
    $blockPlugin->getPluginId()->willReturn('inline_block:video');

    $event = new PlaceBlockEvent($blockPlugin->reveal());

    // Should return just 'inline_block', not the full 'inline_block:video'.
    $this->assertSame('inline_block', $event->getBlockPluginId());
  }

  /**
   * Tests getBundle returns parsed bundle from plugin ID.
   */
  public function testGetBundleReturnsParsedBundle(): void {
    $blockPlugin = $this->prophesize(BlockPluginInterface::class);
    $blockPlugin->getPluginId()->willReturn('inline_block:buystrip');

    $event = new PlaceBlockEvent($blockPlugin->reveal());

    $this->assertSame('buystrip', $event->getBundle());
  }

  /**
   * Tests getBundle returns null for plugins without bundle.
   */
  public function testGetBundleReturnsNullForNonBundledPlugins(): void {
    $blockPlugin = $this->prophesize(BlockPluginInterface::class);
    $blockPlugin->getPluginId()->willReturn('views_block__content_recent');

    $event = new PlaceBlockEvent($blockPlugin->reveal());

    $this->assertNull($event->getBundle());
  }

  /**
   * Tests that plugin ID with multiple colons only splits on the first.
   */
  public function testPluginIdWithMultipleColons(): void {
    $blockPlugin = $this->prophesize(BlockPluginInterface::class);
    // This is a hypothetical edge case - plugin IDs typically don't have
    // multiple colons, but we should verify the parsing behavior.
    $blockPlugin->getPluginId()->willReturn('some_plugin:bundle:extra');

    $event = new PlaceBlockEvent($blockPlugin->reveal());

    // Explode with limit 2 would give ['some_plugin', 'bundle:extra']
    // But current implementation doesn't limit, so it would give
    // ['some_plugin', 'bundle', 'extra'] and only take first two.
    $this->assertSame('some_plugin', $event->getBlockPluginId());
    $this->assertSame('bundle', $event->getBundle());
  }

  /**
   * Tests event with empty string plugin ID.
   */
  public function testEventWithEmptyPluginId(): void {
    $blockPlugin = $this->prophesize(BlockPluginInterface::class);
    $blockPlugin->getPluginId()->willReturn('');

    $event = new PlaceBlockEvent($blockPlugin->reveal());

    $this->assertSame('', $event->getBlockPluginId());
    $this->assertNull($event->getBundle());
  }

  /**
   * Tests that the event extends the correct base class.
   */
  public function testEventExtendsCorrectBaseClass(): void {
    $blockPlugin = $this->prophesize(BlockPluginInterface::class);
    $blockPlugin->getPluginId()->willReturn('test_block');

    $event = new PlaceBlockEvent($blockPlugin->reveal());

    $this->assertInstanceOf(Event::class, $event);
  }

}
