<?php

namespace Drupal\daedalus_ui;

use Drupal\workspaces\Plugin\Validation\Constraint\EntityWorkspaceConflictConstraint;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Builds the editable page response the controllers and the view alter share.
 */
trait LoadEditablePageResponseTrait {

  /**
   * Get empty content.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param string $view_mode
   *   The view mode.
   *
   * @return array
   *   A wrapped empty item that content can be added to.
   */
  public function getEmptyContent(EntityInterface $entity, string $view_mode) {
    $content['#type'] = 'item';
    $content['#wrapper_attributes']['data-daedalus-ui-entity-wrapper'] = $this->getWrapperId($entity);

    return $content;
  }

  /**
   * Get Ajax replace response.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param string $view_mode
   *   The view mode.
   * @param array $content
   *   The content to replace into the page.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   An AJAX response to swap out the entity on the page with the given
   *   content.
   */
  public function getAjaxReplaceResponse(EntityInterface $entity, string $view_mode, array $content) {
    $content['#cache']['contexts'][] = 'daedalus_ui_mode';
    $response = new AjaxResponse();
    $selector = "[data-daedalus-ui-entity-wrapper='" . $this->getWrapperId($entity) . "'][data-daedalus-ui-view-mode='$view_mode']";
    $response->addCommand(new ReplaceCommand($selector, $content));
    return $response;
  }

  /**
   * Get wrapper ID.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return string
   *   The wrapper ID.
   */
  private function getWrapperId(EntityInterface $entity) {
    return daedalus_ui_entity_wrapper_id($entity);
  }

  /**
   * Ensure workspace.
   *
   * We use Drupal\entity_workflow_content\Routing\RouteEnhancer (along with
   * BeforeEntityWorkflowEnhancer and AfterEntityWorkflowEnhancer) to lock
   * editing down to one workspace at a time. Those routes should be protected,
   * but as a belt and suspenders approach, lets ensure we have a workspace.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being used for Edit Mode.
   *
   * @return false|\Drupal\Core\Render\Markup
   *   The workspace conflict or false.
   *
   * @see daedalus_ui_entity_view_alter()
   */
  public function ensureWorkspace(EntityInterface $entity) {
    $mode = $this->daedalusUi()->getMode();
    if ($this->moduleHandler()->moduleExists('workspaces') && $mode === 'edit') {
      $constraints = array_values(array_filter($entity->getTypedData()->getConstraints(), function ($constraint) {
        return $constraint instanceof EntityWorkspaceConflictConstraint;
      }));

      if (!empty($constraints)) {
        $violations = $this->typedDataManager()->getValidator()->validate(
          $entity->getTypedData(),
          $constraints[0]
        );
        if (count($violations)) {
          return $violations->get(0)->getMessage();
        }
      }
    }
    return FALSE;
  }

  /**
   * Gets the module handler.
   */
  protected function moduleHandler(): ModuleHandlerInterface {
    return \Drupal::moduleHandler();
  }

  /**
   * Gets the Daedalus UI UI service.
   */
  protected function daedalusUi() {
    return \Drupal::service('daedalus_ui.ui');
  }

  /**
   * Gets the typed data manager.
   */
  protected function typedDataManager() {
    return \Drupal::service('typed_data_manager');
  }

}
