<?php

namespace Drupal\daedalus_edit\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Ajax\CloseDialogCommand;
use Drupal\media_library\MediaLibraryState;
use Drupal\daedalus_ui\Ajax\EditModeMessageCommand;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\media_library\Plugin\views\field\MediaLibrarySelectForm;

/**
 * Scopes the media library widget's AJAX updates to the edited block.
 */
class ViewsFormMediaLibraryWidgetAlter {

  use StringTranslationTrait;

  public function __construct(
    protected RequestStack $requestStack,
  ) {}

  /**
   * Implements hook_form_FORM_ID_alter() for views_form_media_library_widget.
   *
   * @param array $form
   *   Forms.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function viewsFormMediaLibraryAlter(&$form, FormStateInterface $form_state) {
    $form['actions']['submit']['#ajax']['callback'] = [static::class, 'mediaLibrarySelectFormUpdateWidgetOverride'];
  }

  /**
   * Implements hook_form_FORM_ID_alter() for media_library_add_form_upload.
   *
   * @param array $form
   *   Forms.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function mediaLibraryAddFormUpload(&$form, FormStateInterface $form_state) {
    if (!empty($form['actions']['save_insert'])) {
      $form['actions']['save_insert']['#ajax']['callback'] = [$this, 'addFormBaseUpdateWidgetOverride'];
    }
  }

  /**
   * Update widget ajax callback override for the selection form.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The response with block specific media widget selectors.
   */
  public static function mediaLibrarySelectFormUpdateWidgetOverride(array &$form, FormStateInterface $form_state, Request $request) {
    $response = MediaLibrarySelectForm::updateWidget($form, $form_state, $request);
    return self::addSpecificityToWrapperId($form, $form_state, $response, $request);
  }

  /**
   * Update widget ajax callback override for the add form.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The response with block specific media widget selectors.
   */
  public function addFormBaseUpdateWidgetOverride(&$form, FormStateInterface $form_state) {
    $response = $form_state->getFormObject()->updateWidget($form, $form_state);
    return self::addSpecificityToWrapperId($form, $form_state, $response, $this->requestStack->getCurrentRequest());
  }

  /**
   * Add specificity to wrapper ID.
   *
   * There could be multiple identical field widgets on the same page. e.g.
   * Say we have a custom block with a field_hero_media field on it. If we
   * have two of the same block types being edited that means two forms on
   * the page with the same field. The AJAX wrapper in MediaLibraryWidget is
   * $field_name . '-media-library-wrapper' . $id_suffix; so we'd have duplicate
   * ID's. Let's add a little more specificity when we are clicking the Insert
   * selected button in the modal.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\Core\Ajax\AjaxResponse $response
   *   The original response, from MediaLibrarySelectForm::updateWidget() or
   *   AddFormBase::updateWidget(), both of which call
   *   MediaLibraryFieldWidgetOpener::getSelectionResponse().
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   A modified version of the response that has block specific media widget
   *   ID selectors.
   *
   * @see https://www.drupal.org/project/drupal/issues/3345064#comment-15774869
   */
  private static function addSpecificityToWrapperId(&$form, FormStateInterface $form_state, AjaxResponse $response, ?Request $request = NULL) {
    $state = MediaLibraryState::fromRequest($request);

    $parameters = $state->getOpenerParameters();
    if (empty($parameters['field_widget_id_and_block_uuid'])) {
      return $response;
    }

    $widget_id = $parameters['field_widget_id_and_block_uuid'];
    $commands = $response->getCommands();
    if (!empty($commands[0]['args'])) {
      $response_with_more_specific_selectors = new AjaxResponse();
      $response_with_more_specific_selectors
        ->addCommand(new InvokeCommand("[data-media-library-widget-value='$widget_id']", 'val', $commands[0]['args']))
        ->addCommand(new InvokeCommand("[data-media-library-widget-update='$widget_id']", 'trigger', ['mousedown']))
        ->addCommand(new CloseDialogCommand());
      return $response_with_more_specific_selectors;
    }
    elseif (!empty($commands[0]['message'])) {
      $message_response = new AjaxResponse();
      $message_response->addCommand(new EditModeMessageCommand($commands[0]['message'], $commands[0]['messageOptions']['type']));
      return $message_response;
    }
    else {
      return $response;
    }
  }

}
