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
 * A route that declares the option renders as simple_page (no block
 * chrome); every other route keeps whatever variant was selected before
 * the subscriber ran. The responsive preview is the module's own opt-in;
 * daedalus_stylish's inspector pop-out is asserted in daedalus_stylish's tier.
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
   * The responsive preview route declares the option and gets simple_page.
   */
  public function testResponsivePreviewSelectsSimplePage(): void {
    $this->assertSame('simple_page', $this->selectVariantFor('daedalus_ui.responsive_preview'));
  }

  /**
   * A route without the option keeps the incoming variant.
   */
  public function testUndeclaredRouteKeepsItsVariant(): void {
    $this->assertSame('block_page', $this->selectVariantFor('daedalus_ui.settings'));
  }

}
