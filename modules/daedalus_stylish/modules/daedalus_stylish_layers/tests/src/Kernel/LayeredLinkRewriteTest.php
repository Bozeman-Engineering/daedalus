<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish_layers\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the link → layered @import rewrite in the renderer decorator.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class LayeredLinkRewriteTest extends KernelTestBase {

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
   * Renders a CSS asset collection through the decorated service.
   */
  private function renderAssets(array $assets): array {
    return $this->container->get('asset.css.collection_renderer')->render($assets);
  }

  /**
   * A plain stamped file asset with default media (no preprocessing).
   */
  private function fileAsset(array $overrides = []): array {
    return $overrides + [
      'type' => 'file',
      'data' => 'core/misc/dialog/off-canvas/css/reset.css',
      'media' => 'all',
      'preprocess' => FALSE,
      'license' => FALSE,
      'daedalus_stylish_layers' => 'site',
    ];
  }

  /**
   * A non-preprocessed file link becomes a layered @import style element.
   */
  public function testFileLinkRewritten(): void {
    $elements = $this->renderAssets([$this->fileAsset()]);

    $this->assertCount(1, $elements);
    $this->assertSame('style', $elements[0]['#tag']);
    // Core's URL generation still applies: path resolved by the file URL
    // generator plus the cache-busting query string.
    $query_string = $this->container->get('asset.query_string')->get();
    $this->assertMatchesRegularExpression(
      '{^@import url\("[^"]*reset\.css\?' . preg_quote($query_string, '{}') . '"\) layer\(site\);$}',
      $elements[0]['#value'],
    );
  }

  /**
   * A media-qualified file keeps its condition after layer().
   */
  public function testMediaConditionFollowsLayer(): void {
    $elements = $this->renderAssets([$this->fileAsset(['media' => 'print'])]);

    $this->assertSame('style', $elements[0]['#tag']);
    $this->assertStringEndsWith(') layer(site) print;', $elements[0]['#value']);
  }

  /**
   * External stylesheets are rewritten too, even when aggregation is on.
   */
  public function testExternalRewritten(): void {
    $elements = $this->renderAssets([
      [
        'type' => 'external',
        'data' => 'https://example.com/external.css',
        'media' => 'all',
        'preprocess' => FALSE,
        'license' => FALSE,
        'daedalus_stylish_layers' => 'site',
      ],
    ]);

    $this->assertSame('style', $elements[0]['#tag']);
    $this->assertSame('@import url("https://example.com/external.css") layer(site);', $elements[0]['#value']);
  }

  /**
   * Aggregates and attributed links pass through untouched, order preserved.
   */
  public function testPassThroughsKeepTheirLinks(): void {
    $elements = $this->renderAssets([
      // Aggregate: already wrapped in the layer by the optimizer decorator.
      $this->fileAsset(['data' => 'assets/css/css_abc123.css', 'preprocessed' => TRUE]),
      // Rewritable file between the two pass-throughs.
      $this->fileAsset(),
      // Extra link attributes have no @import equivalent.
      $this->fileAsset(['attributes' => ['integrity' => 'sha384-abc']]),
    ]);

    $this->assertCount(3, $elements);
    $this->assertSame(['link', 'style', 'link'], array_column($elements, '#tag'));
    // Aggregate link: no cache-busting query string, exactly like core.
    $this->assertStringNotContainsString('?', $elements[0]['#attributes']['href']);
    $this->assertSame('sha384-abc', $elements[2]['#attributes']['integrity']);
    $this->assertSame('stylesheet', $elements[0]['#attributes']['rel']);
  }

  /**
   * The asset's stamp names the layer in the import.
   */
  public function testStampNamesTheLayer(): void {
    $elements = $this->renderAssets([$this->fileAsset(['daedalus_stylish_layers' => 'my_site'])]);

    $this->assertStringContainsString(') layer(my_site);', $elements[0]['#value']);
  }

  /**
   * Unstamped (disabled or exempt) assets render as core's untouched links.
   */
  public function testUnstampedPassThrough(): void {
    $elements = $this->renderAssets([
      $this->fileAsset(['daedalus_stylish_layers' => NULL]),
      [
        'type' => 'external',
        'data' => 'https://example.com/external.css',
        'media' => 'all',
        'preprocess' => FALSE,
        'license' => FALSE,
      ],
    ]);

    $this->assertSame(['link', 'link'], array_column($elements, '#tag'));
    $this->assertStringContainsString('reset.css?', $elements[0]['#attributes']['href']);
    $this->assertSame('https://example.com/external.css', $elements[1]['#attributes']['href']);
  }

}
