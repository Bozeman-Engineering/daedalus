<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish_layers\Kernel;

use Drupal\Core\Asset\AttachedAssets;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish_layers\Layers;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests stamping via hook_css_alter() and stamp-homogeneous grouping.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class LayerStampTest extends KernelTestBase {

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
   * A minimal preprocessable file asset.
   */
  private function asset(array $overrides = []): array {
    return $overrides + [
      'type' => 'file',
      'data' => 'core/misc/dialog/off-canvas/css/reset.css',
      'media' => 'all',
      'preprocess' => TRUE,
      'group' => 0,
      'license' => FALSE,
    ];
  }

  /**
   * Stamps non-exempt assets, owns the key, respects the kill-switch.
   */
  public function testStampAssets(): void {
    $layers = $this->container->get('daedalus_stylish_layers.layers');
    $css = [
      'plain' => $this->asset(),
      'exempt' => $this->asset([Layers::EXEMPT_KEY => TRUE]),
      // A stamp smuggled in via a library definition must be discarded.
      'injected' => $this->asset([Layers::STAMP_KEY => 'evil', Layers::EXEMPT_KEY => TRUE]),
    ];

    $layers->stampAssets($css);
    $this->assertSame('site', $css['plain'][Layers::STAMP_KEY]);
    $this->assertArrayNotHasKey(Layers::STAMP_KEY, $css['exempt']);
    $this->assertArrayNotHasKey(Layers::STAMP_KEY, $css['injected']);

    $this->config('daedalus_stylish_layers.settings')->set('enabled', FALSE)->save();
    $layers->stampAssets($css);
    $this->assertArrayNotHasKey(Layers::STAMP_KEY, $css['plain']);
  }

  /**
   * The alter hook stamps real resolver output; config changes propagate.
   */
  public function testResolverAssetsAreStamped(): void {
    $resolver = $this->container->get('asset.resolver');
    $attached = new AttachedAssets();
    $attached->setLibraries(['system/base']);

    $assets = $resolver->getCssAssets($attached, FALSE);
    $this->assertNotEmpty($assets);
    foreach ($assets as $asset) {
      $this->assertSame('site', $asset[Layers::STAMP_KEY]);
    }

    // Disabling must reach fresh requests through the resolver's permanent,
    // library_info-tagged cache — the config subscriber invalidates it.
    $this->config('daedalus_stylish_layers.settings')->set('enabled', FALSE)->save();
    $assets = $resolver->getCssAssets($attached, FALSE);
    $this->assertNotEmpty($assets);
    foreach ($assets as $asset) {
      $this->assertArrayNotHasKey(Layers::STAMP_KEY, $asset);
    }
  }

  /**
   * Mixed groups split into stamp-homogeneous groups, order preserved.
   */
  public function testGrouperSplitsOnStamp(): void {
    $stamped_a = $this->asset(['data' => 'a.css', Layers::STAMP_KEY => 'site']);
    $stamped_b = $this->asset(['data' => 'b.css', Layers::STAMP_KEY => 'site']);
    $exempt = $this->asset(['data' => 'exempt.css']);
    $groups = $this->container->get('asset.css.collection_grouper')->group([
      $stamped_a,
      $exempt,
      $stamped_b,
    ]);

    // Core would have made ONE group of these three preprocessable same-group
    // files; the stamp split yields three, in source order, each carrying its
    // items' stamp state at group level for optimizeGroup() and the renderer.
    $this->assertCount(3, $groups);
    $this->assertSame([['a.css'], ['exempt.css'], ['b.css']], array_map(
      fn (array $group): array => array_column($group['items'], 'data'),
      $groups,
    ));
    $this->assertSame('site', $groups[0][Layers::STAMP_KEY]);
    $this->assertArrayNotHasKey(Layers::STAMP_KEY, $groups[1]);
    $this->assertSame('site', $groups[2][Layers::STAMP_KEY]);
  }

}
