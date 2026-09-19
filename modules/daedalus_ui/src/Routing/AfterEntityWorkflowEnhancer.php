<?php

namespace Drupal\daedalus_ui\Routing;

use Drupal\Core\Routing\EnhancerInterface;
use Drupal\daedalus_ui\DaedalusUi;
use Drupal\Core\Routing\RouteObjectInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\daedalus_ui\Controller\WorkspaceSwitcher;

/**
 * Swaps the controller for routes that need to check for an active workspace.
 */
class AfterEntityWorkflowEnhancer implements EnhancerInterface {

  public function __construct(
    protected readonly DaedalusUi $daedalusUi,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function enhance(array $defaults, Request $request) {
    if ($this->daedalusUi->getMode() !== 'edit') {
      return $defaults;
    }
    /** @var \Symfony\Component\Routing\Route $route */
    $route = $defaults[RouteObjectInterface::ROUTE_OBJECT];
    if ($route->hasOption('_daedalus_ui.require_workspace')) {
      if ($defaults['_controller'] === '\Drupal\entity_workflow_content\Controller\WorkspaceSwitcherController::switcher') {
        // Use our own workspace switcher that will wrap the switcher form in
        // data-daedalus-ui-entity-wrapper and conditionally return it as an
        // AJAX response.
        if ($defaults['_route'] === 'daedalus_ui.load_editable_page') {
          $defaults['_controller'] = WorkspaceSwitcher::class . '::switcherAjax';
        }
        else {
          $defaults['_controller'] = WorkspaceSwitcher::class . '::switcherResponse';
          foreach ($route->getOption('parameters') as $name => $info) {
            if (!empty($info['type']) && str_contains($info['type'], 'entity:')) {
              $defaults['entity'] = $defaults[$name];
            }
          }
        }
      }
    }

    return $defaults;
  }

}
