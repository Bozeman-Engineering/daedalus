<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_ui\Plugin\Layout\ComposedLayout;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the daedalus_stylish_container layout plugin and the consumer filter.
 *
 * The daedalus_stylish_layouts ships ONE layout, daedalus_stylish_container: daedalus_ui's
 * ComposedLayout with no template and no configuration form — the
 * universal container kind's declaration is generative (`composed: true`),
 * so the declaration's root forms ARE the section markup. Its vestigial
 * single region renders no wrapper. The page builder offers only this
 * layout: the module filters the layout_builder consumer and the daedalus_layout
 * consumer (the default-section form) down to its own definitions, while
 * core/layout_discovery layouts stay valid plugins for anything already
 * rendering with them.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class DaedalusStylishLayoutsTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'block',
    'contextual',
    'layout_discovery',
    'layout_builder',
    // daedalus_stylish_layouts' container-migration services reference daedalus_stylish's
    // condition manager, so the info.yml dependency chain must be spelled
    // out (kernel tests do not resolve it).
    'user',
    'field',
    'daedalus_blueprint',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'file',
    'daedalus_ui',
    'daedalus_stylish',
    'daedalus_stylish_layouts',
  ];

  /**
   * The container layout registers with its vestigial region.
   *
   * The daedalus_stylish_container is the container model's ONE layout: a single
   * "content" region that is a storage vestige (core's SectionComponent
   * requires a region name) with no markup of its own. The retired
   * daedalus_stylish_onecol..fourcol plugins are gone; their stored data converges
   * through ContainerMigration's frozen shapes.
   */
  public function testLayoutDefinitions(): void {
    $definitions = $this->container->get('plugin.manager.core.layout')->getDefinitions();
    $this->assertArrayHasKey('daedalus_stylish_container', $definitions);
    $definition = $definitions['daedalus_stylish_container'];
    $this->assertSame(['content'], $definition->getRegionNames());
    $this->assertSame('daedalus_stylish_layouts', $definition->getProvider());
    // The composed layout plugin class: no template, no theme hook — the
    // declaration renders through the composed_container element.
    $this->assertSame(ComposedLayout::class, trim($definition->getClass(), '\\'));
    $this->assertNull($definition->getTemplate());
    // The retired multi-region layouts no longer exist as plugins.
    foreach (['daedalus_stylish_onecol', 'daedalus_stylish_twocol', 'daedalus_stylish_threecol', 'daedalus_stylish_fourcol'] as $retired) {
      $this->assertArrayNotHasKey($retired, $definitions);
    }
    // Core layouts stay valid plugins; only the pickers filter them out.
    $this->assertArrayHasKey('layout_onecol', $definitions);
    $this->assertArrayHasKey('layout_twocol_section', $definitions);
  }

  /**
   * Both page builder consumers see only daedalus_stylish layouts.
   */
  public function testPageBuilderFilter(): void {
    $layout_manager = $this->container->get('plugin.manager.core.layout');
    foreach (['layout_builder', 'daedalus_layout'] as $consumer) {
      $filtered = $layout_manager->getFilteredDefinitions($consumer);
      $this->assertNotEmpty($filtered);
      foreach ($filtered as $id => $definition) {
        $this->assertSame('daedalus_stylish_layouts', $definition->getProvider(), "$consumer offers non-daedalus_stylish layout $id.");
      }
      $this->assertSame(['daedalus_stylish_container'], array_keys($filtered));
    }
  }

  /**
   * The container's ROOT shape: ONE element, a <section>, children direct.
   *
   * Without daedalus_layout's nested marker every render is root-shaped: the
   * declaration's {tag: section, present: {depth: root}} form resolves and
   * the region's children mount directly inside the section root — no
   * inner part element exists at any depth, and the "content" region is a
   * storage vestige that renders NO wrapper of its own. The nested (div)
   * shape and the empty forms are covered by ContainerLayoutTest and
   * EmptyContainerAffordanceTest, which have daedalus_layout's embedding
   * machinery.
   */
  public function testContainerRootShape(): void {
    $layout = $this->container->get('plugin.manager.core.layout')->createInstance('daedalus_stylish_container');
    $build = $layout->build(['content' => ['child' => ['#markup' => 'Container child']]]);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);

    $dom = new \DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
    $xpath = new \DOMXPath($dom);
    $root = $xpath->query('//section');
    $this->assertSame(1, $root->length, 'The root form resolves a real <section> at root depth.');
    // One box: no part element, no part class, anywhere.
    $this->assertStringNotContainsString('daedalus-stylish-layout-container', $html);
    $this->assertSame(0, $xpath->query('//section/div')->length, 'No inner element sits between the root and its children.');
    $this->assertStringContainsString('Container child', $dom->saveHTML($root->item(0)));
    // No region wrapper exists anywhere in the container's markup.
    $this->assertStringNotContainsString('layout__region', $html);
    // A populated container matched no empty form: no marker attribute.
    $this->assertStringNotContainsString('data-container-empty', $html);
  }

}
