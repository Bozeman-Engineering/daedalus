<?php

namespace Drupal\daedalus_layout\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\Core\Routing\RoutingEvents;
use Symfony\Component\Routing\RouteCollection;

/**
 * Closes core's Layout Builder write routes for any storage Daedalus hosts.
 *
 * Every write to a hosted document goes through the op spine (the
 * blueprint mutation endpoint, the form transport); core's own routes
 * (add, choose, remove and move a block; add, choose and remove a
 * section; revert an override) write the storage beside it, so they are
 * denied for a storage whose host Daedalus resolves (an entity's
 * overrides, a display's defaults) and left as core built them for a
 * storage no host owns (core's navigation layout). The two dialog routes
 * (update_block, configure_section) stay open: daedalus_edit's Configure
 * dialog is core's form on the spine's transport, fetched in Edit Mode,
 * which is disabled on admin routes.
 */
class LayoutBuilderRouteSubscriber extends RouteSubscriberBase {

  /**
   * The requirement the hosted-storage access check answers.
   */
  public const REQUIREMENT = '_daedalus_layout_hostless_storage';

  /**
   * Core's routes that write a storage beside the spine.
   */
  protected const CLOSED_ROUTES = [
    'layout_builder.choose_section',
    'layout_builder.add_section',
    'layout_builder.remove_section',
    'layout_builder.choose_block',
    'layout_builder.add_block',
    'layout_builder.choose_inline_block',
    'layout_builder.move_block_form',
    'layout_builder.remove_block',
    'layout_builder.move_block',
  ];

  /**
   * Core's dialog routes the Configure verb opens in Edit Mode.
   */
  protected const DIALOG_ROUTES = [
    'layout_builder.update_block',
    'layout_builder.configure_section',
  ];

  /**
   * {@inheritdoc}
   *
   * After core's LayoutBuilderRoutes (ALTER at -110), which builds each
   * storage's revert route this closes.
   */
  public static function getSubscribedEvents(): array {
    return [RoutingEvents::ALTER => ['onAlterRoutes', -120]];
  }

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    foreach (self::DIALOG_ROUTES as $route_name) {
      if ($route = $collection->get($route_name)) {
        $route->setOption('_admin_route', FALSE);
      }
    }
    foreach ($collection->all() as $route_name => $route) {
      if (in_array($route_name, self::CLOSED_ROUTES, TRUE) || preg_match('/^layout_builder\..+\.revert$/', $route_name)) {
        $route->setRequirement(self::REQUIREMENT, 'TRUE');
      }
    }
  }

}
