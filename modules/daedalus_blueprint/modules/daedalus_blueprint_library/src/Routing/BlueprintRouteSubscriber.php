<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_library\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\Core\Routing\RoutingEvents;
use Drupal\daedalus_blueprint\Host\BlueprintHost;
use Symfony\Component\Routing\RouteCollection;

/**
 * Removes core's overrides layout routes for the blueprint type.
 *
 * A blueprint has one canvas, its canonical route, over one storage, the
 * `blueprint` section storage (daedalus_blueprint's BlueprintSectionStorage,
 * which builds no layout routes of its own). Core's overrides storage
 * builds `/blueprint/{blueprint}/layout` and its local task for every
 * fieldable type with a canonical route, blueprint included, and that page
 * would edit the blueprint through core's plain overrides storage: a second
 * canvas over a second tempstore key. The door is closed here, after core's
 * LayoutBuilderRoutes (ALTER at -110) has built it.
 */
final class BlueprintRouteSubscriber extends RouteSubscriberBase {

  /**
   * The prefix of core's overrides routes for the blueprint type.
   */
  private const PREFIX = 'layout_builder.overrides.' . BlueprintHost::ENTITY_TYPE_ID . '.';

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [RoutingEvents::ALTER => ['onAlterRoutes', -120]];
  }

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    foreach (array_keys($collection->all()) as $route_name) {
      if (str_starts_with($route_name, self::PREFIX)) {
        $collection->remove($route_name);
      }
    }
  }

}
