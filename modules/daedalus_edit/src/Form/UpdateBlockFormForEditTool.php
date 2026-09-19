<?php

namespace Drupal\daedalus_edit\Form;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Render\Element;
use Drupal\Core\Form\SubformState;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\daedalus_edit\DaedalusEditFormTrait;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\layout_builder\Form\UpdateBlockForm;
use Drupal\block_content\BlockContentUuidLookup;
use Drupal\daedalus_layout\NestedSectionStorageInterface;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\daedalus_ui\DaedalusUiFormTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Extends core's UpdateBlockForm to add Daedalus Edit inline editing integration.
 *
 * This form is used when both daedalus_layout and daedalus_edit modules are enabled,
 * providing inline editing capabilities for blocks via the Edit tool.
 *
 * Nested layout support is handled transparently by:
 * - NestedRouteEnhancer: scopes the section storage and fixes delta
 * - DialogCloseSubscriber: retargets core's off-canvas close command to the
 *   Edit Mode dialog
 *
 * This class is registered via DaedalusEditServiceProvider only when daedalus_layout
 * is available.
 */
class UpdateBlockFormForEditTool extends UpdateBlockForm implements EntityFormInterface {

  use DaedalusEditFormTrait;
  use DaedalusUiFormTrait;

  /**
   * The block content UUID lookup.
   */
  protected BlockContentUuidLookup $blockContentUuidLookup;

  /**
   * The renderer.
   */
  protected RendererInterface $renderer;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->entityDisplayRepository = $container->get('entity_display.repository');
    $instance->fieldGenerator = $container->get('field_sample_value.generator');
    $instance->tempstoreRepository = $container->get('daedalus_tempstore.entity_tempstore_repository');
    $instance->blockContentUuidLookup = $container->get('block_content.uuid_lookup');
    $instance->dispatcher = $container->get('event_dispatcher');
    $instance->renderer = $container->get('renderer');
    $instance->requestStack = $container->get('request_stack');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?SectionStorageInterface $section_storage = NULL, $delta = NULL, $region = NULL, $uuid = NULL) {
    // Call core's UpdateBlockForm to build the base form.
    $form = parent::buildForm($form, $form_state, $section_storage, $delta, $region, $uuid);

    $form['actions']['submit']['#attributes']['class'][] = 'daedalus-edit-update-button';
    $form['actions']['submit']['#attributes']['class'][] = 'daedalus-edit-hidden';

    // Add process callback for inline editing preparation.
    if (isset($form['settings']['block_form'])) {
      $form['settings']['block_form']['#process'][] = [$this, 'processBlockForm'];
    }
    $form['#after_build'][] = [$this, 'autoSubmitProperties'];
    $form['#after_build'][] = [$this, 'wrapFormAfterBuild'];

    $this->attributeLabel($form, $form_state);

    return $form;
  }

  /**
   * Process callback for the block form.
   *
   * @param array $element
   *   The block plugin form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The block plugin form.
   */
  public function processBlockForm(array $element, FormStateInterface $form_state) {
    $this->prepareFormForInlineEditing($element, $form_state);

    // Submit the form after adding an empty field to the page.
    if (!empty($element['empty_fields'])) {
      foreach (Element::children($element['empty_fields']) as $field_name) {
        $element['empty_fields'][$field_name]['#submit'][] = [$this, 'submitForm'];
      }
    }

    return $element;
  }

  /**
   * After build callback to wrap form for AJAX.
   */
  public function wrapFormAfterBuild(array $element, FormStateInterface $form_state) {
    $block = $this->getFormEntity($element, $form_state);
    if ($this->requestStack->getCurrentRequest()->request->get('ajaxReturnForm')) {
      // The form is being built because the AJAX submit button has been
      // clicked by JS.
      // @see entity-form.js ajaxReturnForm
      return $this->wrapForm($element, $block);
    }
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $subform_state = SubformState::createForSubform($form['settings'], $form, $form_state);
    $this->getPluginForm($this->block)->validateConfigurationForm($form['settings'], $subform_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    $form_state->setRebuild(TRUE);

    // Clear the page cache for daedalus_edit.
    Cache::invalidateTags([
      daedalus_edit_cache_tag($this->getMainEntity($form, $form_state)),
      daedalus_edit_cache_tag($this->getFormEntity($form, $form_state)),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function ajaxSubmit(array &$form, FormStateInterface $form_state) {
    if ($form_state->hasAnyErrors()) {
      $response = new AjaxResponse();
      $this->handleErrors($response, $form, $form_state);
    }
    else {
      $response = $this->successfulAjaxSubmit($form, $form_state);
    }
    return $response;
  }

  /**
   * {@inheritdoc}
   */
  protected function successfulAjaxSubmit(array $form, FormStateInterface $form_state) {
    $form_state->setTemporaryValue('updatePage', TRUE);
    return $this->updateAjaxSubmit($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormEntity(array &$form, FormStateInterface $form_state): EntityInterface {
    $storage = $this->sectionStorage;
    if (!$storage instanceof NestedSectionStorageInterface) {
      throw new \RuntimeException('Section storage must be NestedSectionStorageInterface.');
    }

    $component = $storage->getSection($this->delta)->getComponent($this->uuid);
    return $storage->getBlockContentFromPlugin($component->getPlugin());
  }

  /**
   * {@inheritdoc}
   */
  public function getMainEntity(array &$form, FormStateInterface $form_state): EntityInterface {
    $storage = $this->sectionStorage;
    if ($storage instanceof NestedSectionStorageInterface) {
      return $storage->getRootStorage()->getContextValue('entity');
    }
    return $storage->getContextValue('entity');
  }

  /**
   * {@inheritdoc}
   */
  public function updateFormState(array &$form, FormStateInterface $form_state, FieldItemListInterface $field, EntityInterface $entity) {
    $field_name = $field->getName();
    $input = $form_state->getUserInput();
    $value = $field->getValue();

    $form_display = $this->entityDisplayRepository->getFormDisplay($entity->getEntityTypeId(), $entity->bundle());
    $widget = $form_display->getComponent($field_name)['type'];

    if ($widget === 'media_library_widget') {
      $value = $this->transformForMediaLibraryWidget($value);
    }

    $input['settings']['block_form'][$field_name] = $value;
    $form_state->setUserInput($input);
    $current_value = $form_state->getValue(['settings', 'block_form', $field_name]);
    $form_state->setValue(
      ['settings', 'block_form', $field_name],
      array_replace($current_value, $value)
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getUserInput(FormStateInterface $form_state) {
    $input = $form_state->getUserInput();
    if (!empty($input['settings']['block_form'])) {
      $input = $input['settings']['block_form'];
    }
    return $input;
  }

  /**
   * {@inheritdoc}
   */
  public function setUserInput(FormStateInterface $form_state, array $tweaked_user_input) {
    $form_state_input = $form_state->getUserInput();
    if (!empty($form_state_input['settings']['block_form'])) {
      $form_state_input['settings']['block_form'] = $tweaked_user_input;
      $new_user_input = $form_state_input;
    }
    else {
      $new_user_input = $tweaked_user_input;
    }
    return $form_state->setUserInput($new_user_input);
  }

  /**
   * {@inheritdoc}
   */
  public function entityContent(array &$form, FormStateInterface $form_state, ?string $view_mode = NULL) {
    $component = $this->sectionStorage->getSection($this->delta)->getComponent($this->uuid);
    $main_entity = $this->getMainEntity($form, $form_state);
    $contexts = ['layout_builder.entity' => EntityContext::fromEntity($main_entity)];

    return [
      // Add a wrapper so Drupal.behaviors.DaedalusEditEnable can find the
      // entity wrapper which is context in this case.
      // @todo This wrapper isn't necessary, fix this on the JS side.
      '#type' => 'container',
      'component' => $component->toRenderArray($contexts),
    ];
  }

  /**
   * Attributes the label field for inline editing.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  protected function attributeLabel(array &$form, FormStateInterface $form_state) {
    if (!isset($form['settings']['label'])) {
      return;
    }

    $entity = $this->getFormEntity($form, $form_state);
    if (!$entity) {
      return;
    }

    $form_item_id = sprintf('%s::%s::%s::%s::%s', $entity->getEntityTypeId(), daedalus_ui_entity_identifier($entity), 'label', 0, 'block_property');
    $form['settings']['label']['#wrapper_attributes']['data-daedalus-edit-form-item-id'] = $form_item_id;
    $form['settings']['label']['#wrapper_attributes']['class'][] = 'daedalus-edit-form-item';
    $form['settings']['label']['#wrapper_attributes']['class'][] = 'daedalus-edit-hidden';
    $form['settings']['label_wrapper'] = [
      '#type' => 'container',
      'label' => $form['settings']['label'],
      '#attributes' => [
        'data-daedalus-edit-form-item-wrapper-id' => sprintf('%s::%s::%s::%s', $entity->getEntityTypeId(), daedalus_ui_entity_identifier($entity), 'label', 'block_property'),
        'data-daedalus-edit-form-item-widget' => 'string_textfield',
        'class' => ['daedalus-edit-form-item-wrapper'],
      ],
      // This ensures the value is stored under $values['settings']['label']
      // even though we added a wrapper.
      '#parents' => ['settings'],
    ];
    unset($form['settings']['label']);
  }

  /**
   * Auto-submit properties.
   *
   * Attribute block properties so they can be auto-saved when changed in the
   * sidebar.
   *
   * @param array $form
   *   The block form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The block form.
   */
  public function autoSubmitProperties(array $form, FormStateInterface $form_state) {
    if (!isset($form['settings'])) {
      return $form;
    }

    $exclude = ['label', 'provider', 'block_form', 'context_mapping'];
    $block_properties = array_diff(Element::children($form['settings']), $exclude);
    foreach ($block_properties as $property) {
      $form_item =& $form['settings'][$property];
      if (isset($form_item['#type']) && $form_item['#type'] !== 'item') {
        $form_item['#attributes']['data-daedalus-edit-auto-submit'] = '';
      }
    }
    return $form;
  }

  /**
   * Transform field values to MediaLibraryWidget expected format.
   *
   * @param array $value
   *   The field values.
   *
   * @return array
   *   Transformed values for MediaLibraryWidget.
   */
  private function transformForMediaLibraryWidget(array $value): array {
    $selection = [];
    foreach ($value as $delta => $item) {
      if (isset($item['target_id'])) {
        $selection[] = [
          'target_id' => $item['target_id'],
          'weight' => $delta,
        ];
      }
    }
    return ['selection' => $selection];
  }

}
