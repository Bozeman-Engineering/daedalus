<?php

declare(strict_types=1);

namespace Drupal\daedalus_edit\Routing;

use Symfony\Component\Routing\Route;
use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;
use Drupal\daedalus_edit\Controller\BlockFormController;

/**
 * Route subscriber for daedalus_layout integration.
 *
 * This subscriber is only registered when daedalus_layout module is available
 * (via DaedalusEditServiceProvider). It adds the block-form endpoint of the
 * form path: the route the editing engine fetches a placement's Layout
 * Builder block form from (the entity form endpoint is
 * daedalus_edit.entity_form).
 *
 * The path carries placement identity only (storage + component uuid);
 * BlockFormController derives the section delta and region from the
 * storage, and daedalus_layout's NestedRouteEnhancer scopes the storage for
 * nested placements.
 */
final class DaedalusLayoutRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    $route = new Route(
      '/daedalus-edit/block-form/{section_storage_type}/{section_storage}/{uuid}',
      [
        '_controller' => BlockFormController::class . '::blockForm',
        '_title' => 'Block form',
      ],
      [
        '_layout_builder_access' => 'view',
        '_permission' => 'access inline editing',
      ],
      [
        'parameters' => [
          'section_storage' => [
            'layout_builder_tempstore' => TRUE,
          ],
        ],
      ]
    );
    $collection->add('daedalus_edit.block_form', $route);
  }

}
