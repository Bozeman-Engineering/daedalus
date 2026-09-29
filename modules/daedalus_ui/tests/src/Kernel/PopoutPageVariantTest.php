<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Kernel;

use Drupal\Core\Render\PageDisplayVariantSelectionEvent;
use Drupal\Core\Render\RenderEvents;
use Drupal\Core\Routing\RouteMatch;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The pop-out page variant over the _daedalus_ui.popout route option.
 *
 * A route that declares the option renders as the daedalus_ui_popout
 * variant (no block chrome, no page title); every other route keeps
 * whatever variant was selected before the subscriber ran. The responsive
 * preview is the module's own opt-in; daedalus_stylish's inspector pop-out
 * is asserted in daedalus_stylish's tier.
 */
#[Group('daedalus_ui')]
#[RunTestsInSeparateProcesses]
class PopoutPageVariantTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * The module's hard dependencies are invisible to kernel environments and
   * must be listed explicitly or the container fails to compile.
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'link',
    'options',
    'file',
    'image',
    'media',
    'block',
    'block_content',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint',
    'daedalus_blueprint_api',
    'daedalus_ui',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Dispatches the real selection event for a named route.
   */
  private function selectVariantFor(string $route_name): string {
    $route = $this->container->get('router.route_provider')->getRouteByName($route_name);
    $event = new PageDisplayVariantSelectionEvent('block_page', new RouteMatch($route_name, $route));
    $this->container->get('event_dispatcher')->dispatch($event, RenderEvents::SELECT_PAGE_DISPLAY_VARIANT);
    return $event->getPluginId();
  }

  /**
   * The responsive preview route declares the option and gets the variant.
   *
   * The plugin must also exist: a selected id the display variant manager
   * cannot instantiate is a fatal at render time, not a wrong variant.
   */
  public function testResponsivePreviewSelectsPopoutVariant(): void {
    $this->assertSame('daedalus_ui_popout', $this->selectVariantFor('daedalus_ui.responsive_preview'));
    $variant = $this->container->get('plugin.manager.display_variant')->createInstance('daedalus_ui_popout');
    $build = $variant->setTitle('Responsive preview')->setMainContent(['#markup' => 'shell'])->build();
    $this->assertArrayHasKey('messages', $build['content']);
    $this->assertArrayHasKey('main_content', $build['content']);
    $this->assertArrayNotHasKey('page_title', $build['content']);
  }

  /**
   * A route without the option keeps the incoming variant.
   */
  public function testUndeclaredRouteKeepsItsVariant(): void {
    $this->assertSame('block_page', $this->selectVariantFor('daedalus_ui.settings'));
  }

}
