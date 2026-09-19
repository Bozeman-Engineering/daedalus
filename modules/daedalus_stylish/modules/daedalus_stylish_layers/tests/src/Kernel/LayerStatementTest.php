<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish_layers\Kernel;

use Drupal\daedalus_stylish_layers\Asset\LayeredCssCollectionRenderer;
use Drupal\daedalus_stylish_layers\Asset\LayeredCssCollectionOptimizer;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish_layers\Layers;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the layer-order statement emitted first-in-head.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class LayerStatementTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'daedalus_stylish_layers',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['daedalus_stylish_layers']);
  }

  /**
   * Runs hook_page_attachments() and returns the attachments array.
   */
  private function pageAttachments(): array {
    $attachments = [];
    $this->container->get('module_handler')
      ->invoke('daedalus_stylish_layers', 'page_attachments', [&$attachments]);
    return $attachments;
  }

  /**
   * The statement is attached with the contract's key, value, and weight.
   */
  public function testOrderStatementAttached(): void {
    $attachments = $this->pageAttachments();

    $head = $attachments['#attached']['html_head'] ?? [];
    $this->assertCount(1, $head);
    [$element, $key] = $head[0];

    $this->assertSame('daedalus_stylish_layers_layer_order', $key);
    $this->assertSame('html_tag', $element['#type']);
    $this->assertSame('style', $element['#tag']);
    $this->assertSame('@layer daedalus_stylish_force, site;', $element['#value']);
    // The charset meta is -1000 and MUST stay first (security); the layer
    // statement sits directly after it and before everything else, so its
    // first-occurrence layer ordering is established before any stylesheet.
    $this->assertSame(-999, $element['#weight']);
    $this->assertGreaterThan(-1000, $element['#weight']);

    // Pages vary on the settings config.
    $this->assertContains('config:daedalus_stylish_layers.settings', $attachments['#cache']['tags']);
  }

  /**
   * Disabling the kill-switch stops emission but keeps cacheability.
   */
  public function testDisabled(): void {
    $this->config('daedalus_stylish_layers.settings')->set('enabled', FALSE)->save();

    $attachments = $this->pageAttachments();
    $this->assertArrayNotHasKey('#attached', $attachments);
    $this->assertContains('config:daedalus_stylish_layers.settings', $attachments['#cache']['tags']);
  }

  /**
   * A configured wrapper layer name is used; invalid names fall back.
   */
  public function testWrapperLayerName(): void {
    $layers = $this->container->get('daedalus_stylish_layers.layers');

    $this->config('daedalus_stylish_layers.settings')->set('wrapper_layer', 'my_site')->save();
    $this->assertSame('@layer daedalus_stylish_force, my_site;', $layers->getOrderStatement());

    // Not a CSS identifier: fall back to the default rather than emit a
    // broken statement that would leave layer order undefined.
    $this->config('daedalus_stylish_layers.settings')->set('wrapper_layer', 'bad name}')->save();
    $this->assertSame(Layers::DEFAULT_WRAPPER_LAYER, $layers->getWrapperLayer());
    $this->assertSame('@layer daedalus_stylish_force, site;', $layers->getOrderStatement());
  }

  /**
   * The decorators are registered and wrap the core asset services.
   */
  public function testDecoratorsRegistered(): void {
    $this->assertInstanceOf(
      LayeredCssCollectionOptimizer::class,
      $this->container->get('asset.css.collection_optimizer'),
    );
    $this->assertInstanceOf(
      LayeredCssCollectionRenderer::class,
      $this->container->get('asset.css.collection_renderer'),
    );
  }

}
