<?php

namespace Drupal\daedalus_layout\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Marks core Layout Builder form routes as non-admin.
 *
 * Daedalus Layout uses these forms and Edit Mode is disabled on admin routes.
 */
class LayoutBuilderRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    $route_names = [
      'layout_builder.choose_section',
      'layout_builder.add_section',
      'layout_builder.configure_section',
      'layout_builder.remove_section',
      'layout_builder.choose_block',
      'layout_builder.add_block',
      'layout_builder.choose_inline_block',
      'layout_builder.update_block',
      'layout_builder.move_block_form',
      'layout_builder.remove_block',
      'layout_builder.move_block',
    ];
    foreach ($route_names as $route_name) {
      if ($route = $collection->get($route_name)) {
        $route->setOption('_admin_route', FALSE);
      }
    }
  }

}
