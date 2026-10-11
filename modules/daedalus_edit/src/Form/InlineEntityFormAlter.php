<?php

namespace Drupal\daedalus_edit\Form;

use Drupal\daedalus_blueprint\ExtractedValue;
use Drupal\daedalus_edit\DaedalusEditFormTrait;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_edit\FormCommit;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\daedalus_tempstore\EntityTempstoreRepository;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Inline Entity Form Alter.
 *
 * Alters the entity form Daedalus Edit hosts in its panel (the
 * `/daedalus-edit/entity-form/{entity_type}/{entity}` route): the form is
 * a widget host and value extractor whose own AJAX submit is the form
 * transport. Its Update button no longer saves; its submit hands the one
 * field the client named to the op spine (FormCommit) and the callback
 * answers the mutation payload.
 */
class InlineEntityFormAlter implements EntityFormInterface {

  use MessengerTrait;
  use DaedalusEditFormTrait;
  use StringTranslationTrait;
  use DependencySerializationTrait;

  public function __construct(
    protected EntityDisplayRepositoryInterface $entityDisplayRepository,
    protected EntityTempstoreRepository $tempstoreRepository,
    protected EventDispatcherInterface $dispatcher,
    protected RequestStack $requestStack,
    protected FormCommit $formCommit,
    protected HostResolver $hostResolver,
  ) {}

  /**
   * Daedalus Edit inline entity form alter.
   */
  public function formAlter(&$form, FormStateInterface $form_state) {
    $form['actions']['submit']['#ajax'] = [
      'callback' => [$this, 'ajaxSubmit'],
    ];
    // Commit one field to the spine instead of saving the entity.
    $save_key = array_search('::save', $form['actions']['submit']['#submit']);
    unset($form['actions']['submit']['#submit'][$save_key]);

    $form['actions']['submit']['#submit'][] = [$this, 'update'];
    $form['actions']['submit']['#value'] = $this->t('Update');
    $form['actions']['submit']['#attributes']['class'][] = 'daedalus-edit-update-button';

    $this->prepareFormForInlineEditing($form, $form_state);
    array_unshift($form['#process'], [$this, 'unsetChangedInput']);
  }

  /**
   * Form-transport submit handler: one field of the form-built entity.
   *
   * The entity form's own submit (ContentEntityForm::submitForm) has already
   * built the entity from every widget; only the named field's items leave
   * it, as an ExtractedValue on the spine, addressed to the translation the
   * form is for. Nothing else the form built is written.
   *
   * @param array $form
   *   The entity form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state, after the entity form's own submit handler.
   */
  public function update(array &$form, FormStateInterface $form_state) {
    $field_name = FormCommit::requestedProperty($form_state);
    if ($field_name === NULL) {
      throw new BadRequestHttpException('Daedalus Edit: a panel form submit names the field it commits.');
    }
    // The form-built entity is the translation object the form is for
    // (ContentEntityForm keeps the form langcode as the entity's language),
    // so its items and its language are the commit's.
    $entity = $form_state->getFormObject()->getEntity();
    if (!$entity->hasField($field_name)) {
      throw new BadRequestHttpException(sprintf('Daedalus Edit: %s has no field "%s" to commit.', $entity->getEntityTypeId(), $field_name));
    }
    $items = $entity->get($field_name);
    // A widget round that changed nothing (the AJAX-touched trigger, an
    // "Add another item" click) extracts the staged value again: the spine
    // still answers, but nothing persists and no history entry is written.
    // The working copy is the staged entity; the form built on a clone.
    $langcode = $entity->language()->getId();
    $host = $this->hostResolver->fromEntity($entity);
    $staged = $this->formCommit->stagedRoot($host)?->fields();
    if ($staged !== NULL && $staged->hasTranslation($langcode)) {
      $staged = $staged->getTranslation($langcode);
    }
    $changed = FormCommit::fieldChanged($items, $staged?->get($field_name));
    $outcome = $this->formCommit->commit(
      $host,
      $host->uuid(),
      [$field_name => new ExtractedValue($items)],
      $langcode,
      $this->t('Edited @label', ['@label' => $items->getFieldDefinition()->getLabel()]),
      $changed,
    );
    $form_state->setTemporaryValue(FormCommit::OUTCOME, $outcome);
  }

  /**
   * The Update button's AJAX callback: the transport's answer.
   */
  public function ajaxSubmit(array &$form, FormStateInterface $form_state) {
    return $this->commitResponse($form, $form_state);
  }

  /**
   * Process callback: keeps the changed timestamp out of the user input.
   *
   * @param array $element
   *   The form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array $form
   *   The complete form.
   *
   * @return array
   *   The element.
   */
  public function unsetChangedInput($element, FormStateInterface $form_state, $form) {
    $input = $form_state->getUserInput();
    // Let the form submission process set the entity changed property. Don't
    // allow it to be set it prematurely.
    unset($input['changed']);
    $form_state->setUserInput($input);

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormEntity(array &$form, FormStateInterface $form_state): EntityInterface {
    return $form_state->getFormObject()->getEntity();
  }

  /**
   * {@inheritdoc}
   */
  public function getMainEntity(array &$form, FormStateInterface $form_state): EntityInterface {
    return $this->getFormEntity($form, $form_state);
  }

}
