<?php

namespace Drupal\daedalus_ui;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\daedalus_ui\Ajax\ClearMessagesCommand;

/**
 * Provides common form handling methods for Daedalus UI.
 */
trait DaedalusUiFormTrait {

  /**
   * Form AJAX submit callback.
   */
  public function updateAjaxSubmit(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();

    if ($form_state->getTemporaryValue('updatePage')) {
      $this->updatePage($response, $form, $form_state);
    }
    $input = $form_state->getUserInput();
    if (
      $form_state->getTemporaryValue('updateForm') ||
      !empty($input['previously_had_errors']) ||
      !empty($input['settings']['block_form']['empty_field'])
    ) {
      $this->removeMessages($response);
      $this->updateForm($response, $form, $form_state);
    }

    $this->handleErrors($response, $form, $form_state);

    $response->addCommand(new InvokeCommand(NULL, 'daedalusEditIsDoneUpdating'));
    return $response;
  }

  /**
   * Remove messages from the screen.
   *
   * @param \Drupal\Core\Ajax\AjaxResponse $response
   *   The AJAX response object.
   */
  public function removeMessages(AjaxResponse $response) {
    $response->addCommand(new ClearMessagesCommand());
  }

  /**
   * Gets the renderer service.
   *
   * @return \Drupal\Core\Render\RendererInterface
   *   The renderer service.
   */
  protected function getRenderer(): RendererInterface {
    return \Drupal::service('renderer');
  }

}
