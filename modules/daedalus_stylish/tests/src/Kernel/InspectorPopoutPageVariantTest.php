<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\Core\Render\PageDisplayVariantSelectionEvent;
use Drupal\Core\Render\RenderEvents;
use Drupal\Core\Routing\RouteMatch;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The inspector pop-out route opts into daedalus_ui's pop-out variant.
 *
 * The daedalus_stylish.inspector_popout route declares the _daedalus_ui.popout
 * option; daedalus_ui's PopoutPageVariant reads it and selects the
 * daedalus_ui_popout variant, so the window renders without block chrome
 * and without a page title (the inspector renders its own "Styles"
 * header). This is the cheapest tier that sees the option dropped from
 * the route.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class InspectorPopoutPageVariantTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'daedalus_blueprint',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'file',
    'daedalus_ui',
    'daedalus_stylish',
  ];

  /**
   * The inspector pop-out route selects the pop-out variant.
   */
  public function testInspectorPopoutSelectsPopoutVariant(): void {
    $this->container->get('router.builder')->rebuild();
    $route = $this->container->get('router.route_provider')->getRouteByName('daedalus_stylish.inspector_popout');
    $event = new PageDisplayVariantSelectionEvent('block_page', new RouteMatch('daedalus_stylish.inspector_popout', $route));
    $this->container->get('event_dispatcher')->dispatch($event, RenderEvents::SELECT_PAGE_DISPLAY_VARIANT);
    $this->assertSame('daedalus_ui_popout', $event->getPluginId());
  }

}
