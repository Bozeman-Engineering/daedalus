<?php

declare(strict_types=1);

namespace Drupal\daedalus_edit\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CloseDialogCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\daedalus_edit\FormCommit;
use Drupal\daedalus_edit\FormCommitOutcome;
use Drupal\daedalus_layout\NestedSectionStorageInterface;
use Drupal\layout_builder\OverridesSectionStorageInterface;
use Drupal\layout_builder\SectionStorageInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The Configure dialog on the form transport.
 *
 * The Configure verb (daedalus_layout) opens core's own Layout Builder
 * forms (UpdateBlockForm for a placement, ConfigureSectionForm for a
 * section) in the edit-mode dialog. The core form stays the widget host:
 * its build, its widgets, its validation and a site's own submit handlers
 * all run as they do anywhere. What changes is the write and the answer. Core's
 * submit handler (the whole-storage Layout Builder tempstore set, the
 * write path beside the spine) leaves the submit chain for this alter's
 * formSubmit(), which hands the properties the dialog changed to the op
 * spine as one update_element on the subject (FormCommit), and core's
 * AJAX callback (the whole #layout-builder rebuild plus the off-canvas
 * close) is replaced by ajaxSubmit(), which answers the standard mutation
 * payload as one daedalusBlueprintApplyResponse command, swapping the
 * subject by key, plus the close of the dialog the verb opened. The client
 * half (js/daedalus_edit/configure-dialog.js) finds the form by
 * DIALOG_ATTRIBUTE and runs its submit as a job on the op queue, FIFO with
 * the ops around it.
 *
 * Only in Edit Mode (daedalus_edit_form_alter() gates on the mode) and only
 * over an overrides storage (the page's own layout): the stock Layout
 * Builder admin UI is not this dialog and keeps core's path.
 */
abstract class ConfigureDialogFormAlterBase {

  use StringTranslationTrait;
  use DependencySerializationTrait;

  /**
   * The form attribute the client half finds a Configure dialog's form by.
   *
   * Its value is the subject's selectable key ('block:{uuid}',
   * 'container:{uuid}'), the failure display's anchor.
   */
  public const DIALOG_ATTRIBUTE = 'data-daedalus-edit-configure-dialog';

  /**
   * The attribute the client half finds the dialog's Update button by.
   *
   * Core's Layout Builder forms declare no actions container (no
   * `#type => 'actions'`), so the button renders bare in the form (no
   * .form-actions wrapper, no dialog button pane); the stamp names it
   * whatever the markup around it.
   */
  public const SUBMIT_ATTRIBUTE = 'data-daedalus-edit-configure-dialog-submit';

  /**
   * The dialog the Configure verb opens (ModePluginBase.dialog's target).
   */
  public const DIALOG_SELECTOR = '#layout-builder-modal';

  public function __construct(
    protected FormCommit $formCommit,
    protected RequestStack $requestStack,
  ) {}

  /**
   * Alters a core Layout Builder form into a Configure dialog on the transport.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $form_id
   *   The form id.
   */
  abstract public function formAlter(array &$form, FormStateInterface $form_state, string $form_id): void;

  /**
   * Submit handler: the properties the dialog changed, on the spine.
   *
   * Leaves the FormCommitOutcome as the FormCommit::OUTCOME temporary value
   * for the AJAX callback.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  abstract public function formSubmit(array &$form, FormStateInterface $form_state): void;

  /**
   * The section storage the form was built on.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\layout_builder\SectionStorageInterface
   *   The route's section storage (nested-scoped for a nested placement).
   */
  abstract protected function sectionStorage(FormStateInterface $form_state): SectionStorageInterface;

  /**
   * Puts the form on the transport.
   *
   * Core's own submit handler (the form object's ::submitForm, the
   * whole-storage tempstore set) leaves the chain; a site's handlers stay,
   * running at stage time on the form-built values as they do on every
   * form the edit mode hosts. The button's AJAX callback becomes this
   * alter's answer, and the form and its button are stamped for the
   * client half.
   *
   * @param array $form
   *   The form.
   * @param string $key
   *   The subject's selectable key.
   */
  protected function takeOver(array &$form, string $key): void {
    $handlers = array_values(array_filter(
      $form['#submit'] ?? [],
      static fn ($handler) => $handler !== '::submitForm',
    ));
    $handlers[] = [$this, 'formSubmit'];
    $form['actions']['submit']['#submit'] = $handlers;
    if (isset($form['actions']['submit']['#ajax'])) {
      $form['actions']['submit']['#ajax']['callback'] = [$this, 'ajaxSubmit'];
    }
    $form['actions']['submit']['#attributes'][self::SUBMIT_ATTRIBUTE] = '';
    $form['#attributes'][self::DIALOG_ATTRIBUTE] = $key;
  }

  /**
   * The root entity a storage's layout belongs to, or NULL off the page.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface $storage
   *   The section storage, nested-scoped or not.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface|null
   *   The overrides storage's entity; NULL for any other storage (a
   *   display's defaults, the stock admin UI), which is not this dialog.
   */
  protected function rootOf(SectionStorageInterface $storage): ?FieldableEntityInterface {
    $root = $storage instanceof NestedSectionStorageInterface ? $storage->getRootStorage() : $storage;
    if (!$root instanceof OverridesSectionStorageInterface) {
      return NULL;
    }
    $entity = $root->getContextValue('entity');
    return $entity instanceof FieldableEntityInterface ? $entity : NULL;
  }

  /**
   * The Update button's AJAX callback: the transport's answer.
   *
   * A submit the form refused keeps core's own answer for a dialog form
   * (AjaxFormHelperTrait::ajaxSubmit): the form back in place with its
   * messages, nothing written, the dialog open. Otherwise the outcome
   * answers as the mutation payload rendered in the view mode the canvas
   * shows the root in, and a successful one closes the dialog; a failed
   * result (the spine refused) leaves it open with the payload's errors
   * for the client's failure display.
   *
   * @param array $form
   *   The form, as the callback received it.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The answer.
   */
  public function ajaxSubmit(array &$form, FormStateInterface $form_state): AjaxResponse {
    if ($form_state->hasAnyErrors()) {
      $form['status_messages'] = [
        '#type' => 'status_messages',
        '#weight' => -1000,
      ];
      $form['#sorted'] = FALSE;
      $response = new AjaxResponse();
      $response->addCommand(new ReplaceCommand('[data-drupal-selector="' . $form['#attributes']['data-drupal-selector'] . '"]', $form));
      return $response;
    }
    $outcome = $form_state->getTemporaryValue(FormCommit::OUTCOME);
    if (!$outcome instanceof FormCommitOutcome) {
      throw new \LogicException('Daedalus Edit: the Configure dialog submit handler left no outcome for its AJAX callback.');
    }
    $view_mode = FormCommit::storageViewMode($this->sectionStorage($form_state));
    $response = $this->formCommit->respond($this->requestStack->getCurrentRequest(), $outcome, $view_mode);
    if ($outcome->result->isSuccess()) {
      $response->addCommand(new CloseDialogCommand(self::DIALOG_SELECTOR));
    }
    return $response;
  }

}
