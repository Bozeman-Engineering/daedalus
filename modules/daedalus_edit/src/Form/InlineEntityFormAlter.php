<?php

namespace Drupal\daedalus_edit\Form;

use Drupal\Core\Cache\Cache;
use Drupal\daedalus_edit\DaedalusEditFormTrait;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Entity\Element\EntityAutocomplete;
use Drupal\daedalus_ui\DaedalusUiFormTrait;
use Drupal\daedalus_tempstore\EntityTempstoreRepository;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\field_sample_value\SampleValueEntityGeneratorInterface;

/**
 * Inline Entity Form Alter.
 */
class InlineEntityFormAlter implements EntityFormInterface {

  use MessengerTrait;
  use DaedalusEditFormTrait;
  use StringTranslationTrait;
  use DaedalusUiFormTrait;
  use DependencySerializationTrait;

  /**
   * The current request.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected Request $request;

  public function __construct(
    protected EntityDisplayRepositoryInterface $entityDisplayRepository,
    protected SampleValueEntityGeneratorInterface $fieldGenerator,
    protected EntityTempstoreRepository $tempstoreRepository,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EventDispatcherInterface $dispatcher,
    protected RendererInterface $renderer,
    protected RequestStack $requestStack,
  ) {
    $this->request = $this->requestStack->getCurrentRequest();
  }

  /**
   * Daedalus Edit inline entity form alter.
   */
  public function formAlter(&$form, FormStateInterface $form_state) {
    $form['actions']['submit']['#ajax'] = [
      'callback' => [$this, 'ajaxSubmit'],
    ];
    // Update a tempstore version instead of saving the entity.
    $save_key = array_search('::save', $form['actions']['submit']['#submit']);
    unset($form['actions']['submit']['#submit'][$save_key]);

    $form['actions']['submit']['#submit'][] = [$this, 'update'];
    $form['actions']['submit']['#value'] = $this->t('Update');
    $form['actions']['submit']['#attributes']['class'][] = 'daedalus-edit-update-button';

    $this->prepareFormForInlineEditing($form, $form_state);
    array_unshift($form['#process'], [$this, 'unsetChangedInput']);
  }

  /**
   * Submit handler that updates the Daedalus Edit tempstore instead of saving.
   *
   * Overrides the default entity save behavior because saving all the time
   * would create a ton of revisions.
   *
   * @param array $form
   *   The entity form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function update(array &$form, FormStateInterface $form_state) {
    $entity = $form_state->getFormObject()->getEntity();
    $this->tempstoreRepository->set($entity);
    Cache::invalidateTags([daedalus_edit_cache_tag($entity)]);
    $form_state->setTemporaryValue('updatePage', TRUE);

    $input = $form_state->getUserInput();
    if (!empty($input['empty_field'])) {
      $form_state->setTemporaryValue('emptyField', $input['empty_field']);
      $input['empty_field'] = NULL;
      $form_state->setUserInput($input);
      $form_state->setRebuild(TRUE);
      $form_state->setTemporaryValue('updateForm', TRUE);
    }
  }

  /**
   * Submit button (now tempstore update) AJAX callback.
   */
  public function ajaxSubmit(array &$form, FormStateInterface $form_state) {
    return $this->updateAjaxSubmit($form, $form_state);
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

  /**
   * {@inheritdoc}
   */
  public function getUserInput(FormStateInterface $form_state) {
    return $form_state->getUserInput();
  }

  /**
   * {@inheritdoc}
   */
  public function setUserInput(FormStateInterface $form_state, array $user_input) {
    return $form_state->setUserInput($user_input);
  }

  /**
   * {@inheritdoc}
   */
  public function updateFormState(array &$form, FormStateInterface $form_state, FieldItemListInterface $field, EntityInterface $entity) {
    $field_name = $field->getName();
    $input = $form_state->getUserInput();
    $value = $this->getValue($field, $form_state);
    $input[$field_name] = $value;
    $form_state->setUserInput($input);
    $form_state->setValue($field_name, array_replace($form_state->getValue($field_name), $value));
    $form_state->getFormObject()->setEntity($entity);
    $this->tempstoreRepository->set($entity);

    // Reset the field storage so that things like the media library won't have
    // a stale state when you make subsequent edits.
    $storage = $form_state->getStorage();
    unset($storage['field_storage']);
    $form_state->setStorage($storage);
  }

  /**
   * Gets a field's value in the shape its widget expects as user input.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $field
   *   The field.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return mixed
   *   The widget-shaped value.
   */
  public function getValue(FieldItemListInterface $field, FormStateInterface $form_state) {

    // Special case entity reference values cause they are tricky.
    if ($field->getFieldDefinition()->getType() === 'entity_reference') {
      $widget = $form_state->getFormObject()->getFormDisplay($form_state)->getComponent($field->getName());

      switch ($widget['type']) {
        case 'entity_reference_autocomplete':
        case 'entity_reference_autocomplete_tags':
          $referenced_entities = $field->referencedEntities();
          $value = [];
          foreach ($referenced_entities as $entity) {
            $value[$entity->id()] = ['target_id' => EntityAutocomplete::getEntityLabels([$entity])];
          }
          return $value;

        case 'options_buttons':
        case 'options_select':
          $target_ids = array_column($field->getValue(), 'target_id');
          if ($widget['type'] === 'options_buttons') {
            return array_combine($target_ids, $target_ids);
          }
          else {
            return array_values($target_ids);
          }

        default:
          return $field->getValue();
      }
    }

    return $field->getValue();

  }

  /**
   * {@inheritdoc}
   */
  public function entityContent(array &$form, FormStateInterface $form_state, ?string $view_mode = NULL) {
    $entity = $this->tempstoreRepository->get($form_state->getFormObject()->getEntity());
    return $this->entityTypeManager->getViewBuilder($entity->getEntityTypeId())->view($entity, $view_mode);
  }

}
