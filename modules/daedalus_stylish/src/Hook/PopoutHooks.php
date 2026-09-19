<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\OrderAfter;
use Drupal\Core\Routing\RouteMatchInterface;

/**
 * Strips page_top chrome from the inspector pop-out.
 *
 * The pop-out route declares the _daedalus_ui.popout option, so
 * daedalus_ui's PopoutPageVariant already forces the simple_page
 * variant, which removes BLOCK chrome, but page_top chrome (core
 * navigation's rail and top bar, the toolbar, daedalus_ui's mode bars
 * and sidebar toggles such as daedalus_ai's chat button) renders via
 * hook_page_top regardless of the page display variant. The pop-out is a
 * floating panel whose only session logic is the BroadcastChannel link to
 * the leader page; hiding that chrome with CSS would still load and run it
 * all (including a second live AI chat client) inside the satellite
 * window. So it is removed server-side: this hook is ordered after every
 * module that contributes page_top chrome and empties the region wholesale
 * on the pop-out route. No curated subset: the pop-out wants no page chrome
 * from anyone.
 */
final class PopoutHooks {

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
  ) {}

  /**
   * Implements hook_page_top().
   */
  #[Hook('page_top', order: new OrderAfter(modules: ['navigation', 'toolbar', 'daedalus_ui']))]
  public function pageTop(array &$page_top): void {
    if ($this->routeMatch->getRouteName() === 'daedalus_stylish.inspector_popout') {
      $page_top = [];
    }
  }

}
