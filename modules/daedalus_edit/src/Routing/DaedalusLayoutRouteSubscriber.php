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
 *
 * Access is the entity form endpoint's: the surface permission beside
 * update access on the storage's host (BlockFormController::access). What
 * the form may then write is the spine's capability verdict at the op
 * door (core's Layout Builder permissions through the role policy), the
 * one grammar; the route carries no second one.
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
        '_permission' => 'access inline editing',
        '_custom_access' => BlockFormController::class . '::access',
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
