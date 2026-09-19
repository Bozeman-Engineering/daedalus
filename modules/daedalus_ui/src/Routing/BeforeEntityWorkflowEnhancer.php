<?php

namespace Drupal\daedalus_ui\Routing;

use Drupal\Core\Routing\EnhancerInterface;
use Drupal\daedalus_ui\DaedalusUi;
use Drupal\Core\Routing\RouteObjectInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Swaps the controller for routes that need to check for an active workspace.
 *
 * BeforeEntityWorkflowEnhancer,
 * Drupal\entity_workflow_content\Routing\RouteEnhancer and
 * AfterEntityWorkflowEnhancer work together as "one route enhancer".
 */
class BeforeEntityWorkflowEnhancer implements EnhancerInterface {

  public function __construct(
    protected readonly DaedalusUi $daedalusUi,
  ) {}

  /**
   * Enhance.
   *
   * Flag that reloading the page in order to get the editable page elements
   * requires a workspace.
   *
   * @see WorkspaceSwitcher
   */
  public function enhance(array $defaults, Request $request) {
    if (!\Drupal::currentUser()->hasPermission('use daedalus ui edit mode')) {
      return $defaults;
    }
    if ($this->daedalusUi->getMode() !== 'edit') {
      return $defaults;
    }

    /** @var \Symfony\Component\Routing\Route $route */
    $route = $defaults[RouteObjectInterface::ROUTE_OBJECT];
    // Reload via AJAX controller.
    if ($defaults['_route'] === 'daedalus_ui.load_editable_page') {
      $route->setOption('_entity_workflow_content.require_workspace', TRUE);
      $route->setOption('_entity_workflow_content.entity_type_id', $defaults['entity_type']);
      $route->setOption('_daedalus_ui.require_workspace', TRUE);
    }

    // Full page reload.
    if (str_starts_with($defaults['_route'], 'entity.') && str_ends_with($defaults['_route'], '.canonical')) {
      $route->setOption('_entity_workflow_content.require_workspace', TRUE);
      [, $entity_type] = explode('.', $defaults['_route']);
      $route->setOption('_entity_workflow_content.entity_type_id', $entity_type);
      $route->setOption('_daedalus_ui.require_workspace', TRUE);
    }

    return $defaults;
  }

}
