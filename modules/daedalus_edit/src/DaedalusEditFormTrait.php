<?php

namespace Drupal\daedalus_edit;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_ui\Ajax\UpdateElement;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Component\Utility\NestedArray;
use Drupal\daedalus_edit\Event\FieldAttributes;
use Drupal\daedalus_blueprint\EntityStorageHandler;
use Drupal\daedalus_layout\NestedSectionStorageInterface;
use Drupal\daedalus_ui\BlueprintMirror;
use Drupal\daedalus_ui\Message\EditModeAjaxMessages;
use Drupal\daedalus_tempstore\EntityTempstoreRepository;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Drupal\field_sample_value\SampleValueEntityGeneratorInterface;

/**
 * Shared machinery for entity forms hosted in the Daedalus Edit sidebar.
 *
 * The request stack is deliberately NOT declared here, exactly like
 * sectionStorage (read via `$this->sectionStorage ?? NULL` below): core's
 * FormBase declares an UNTYPED protected $requestStack, and a typed trait
 * property fatals against an untyped parent property when the trait is
 * composed into a FormBase descendant (UpdateBlockFormForEditTool). Every
 * user of the request-stack-reading methods carries its own property:
 * FormBase descendants inherit it, the others promote a typed one in their
 * constructor.
 */
trait DaedalusEditFormTrait {

  /**
   * The entity tempstore repository.
   */
  protected EntityTempstoreRepository $tempstoreRepository;

  /**
   * The event dispatcher.
   */
  protected EventDispatcherInterface $dispatcher;

  /**
   * The sample value generator.
   */
  protected SampleValueEntityGeneratorInterface $fieldGenerator;

  /**
   * The entity display repository.
   */
  protected EntityDisplayRepositoryInterface $entityDisplayRepository;

  /**
   * Prepares an entity form for inline editing in the sidebar.
   *
   * @param array $form
   *   The entity form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function prepareFormForInlineEditing(&$form, FormStateInterface $form_state) {
    $entity = $this->getFormEntity($form, $form_state);
    $entity = $this->tempstoreRepository->get($entity);

    $this->dissolveAdvancedTabs($form);

    // Hide the form buttons.
    $form['actions']['#attributes']['class'][] = 'daedalus-edit-hidden';

    // Let the JS report that a field has been emptied.
    // @see entity-form.js updateTempstore()
    $form['empty_field'] = [
      '#type' => 'hidden',
      '#value' => NULL,
      '#attributes' => [
        'id' => 'empty-field',
      ],
    ];

    // Pass the view mode along to subsequent page requests.
    $form['view_mode'] = [
      '#type' => 'hidden',
      '#attributes' => ['class' => ['daedalus-edit-view-mode']],
      '#value' => $this->getViewMode($form, $form_state, $entity),
    ];

    // Set a flag for entity-form.js updateTempstore to only update the markup
    // of the changed editable element instead of the entire entity. This
    // allows users to continue editing other fields.
    $form['only_update_element'] = [
      '#type' => 'hidden',
      '#attributes' => ['class' => ['daedalus-edit-only-update-element']],
      '#value' => NULL,
    ];

    foreach ($entity->getFieldDefinitions() as $field_name => $field_definition) {
      if (!empty($form[$field_name])) {
        $this->addFieldToPageButton($form, $entity, $field_name);
      }
    }

    $empty_field = $form_state->getTemporaryValue('emptyField');
    if ($empty_field) {
      $form['empty_fields'][$empty_field]['#attributes']['class'][] = 'call-attention-to';
    }

    // Attribute form items so they are correlated with the rendered markup.
    $this->attributeFormItemsEventDispatcher($form, $form_state, FieldAttributes::ALTER);
    $form['#after_build'][] = [$this, 'prepareFormItemsForInlineEditingAfterBuild'];
  }

  /**
   * After-build callback: attributes the form items and applies the grammar.
   *
   * @param array $form
   *   The entity form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The form.
   */
  public function prepareFormItemsForInlineEditingAfterBuild($form, FormStateInterface $form_state) {
    $this->attributeFormItemsEventDispatcher($form, $form_state, FieldAttributes::AFTER_BUILD);
    $this->applyPanelGrammar($form);
    $form_state->set('added_to_page', NULL);
    return $form;
  }

  /**
   * Dissolve the advanced vertical tabs into a container of stacked details.
   *
   * Vertical tabs' split menu/pane model is the wrong interaction shape for
   * the narrow sidebar rail, so sidebar-hosted forms replace the widget
   * (the normal entity edit page keeps it). Container runs the same
   * processGroup/preRenderGroup pipeline as the details that vertical_tabs
   * injects, so after the #type swap every `#group => 'advanced'` pane,
   * including nested ones like the path widget's details, still registers
   * with the group and renders inside the container as a stacked details
   * while its values keep processing at their original parents. Core's
   * vertical-tabs library rides the vertical_tabs element and disappears
   * with it.
   */
  private function dissolveAdvancedTabs(array &$form): void {
    if (($form['advanced']['#type'] ?? NULL) !== 'vertical_tabs') {
      return;
    }
    $form['advanced'] = [
      '#type' => 'container',
      '#weight' => $form['advanced']['#weight'] ?? 99,
      '#attributes' => ['class' => ['daedalus-edit-advanced']],
    ];
  }

  /**
   * Give every control in a sidebar-hosted form the shared panel-kit look.
   *
   * The panel control kit (daedalus_ui css/panel-controls.css) is the
   * one control grammar in the rail: details wear daedalus-ui-details, text inputs
   * wear daedalus-ui-input, buttons wear daedalus-ui-pill-button; per-widget styling was
   * drift. Runs at after_build so widget-created elements are covered;
   * group members are injected at pre-render by copy, so classes added
   * here on the originals carry into the rendered panes. Checkboxes and
   * selects need no class: the edit-mode form grammar in daedalus_ui
   * css/form.css already skins them by element.
   */
  private function applyPanelGrammar(array &$element): void {
    $type = $element['#type'] ?? NULL;
    if ($type === 'details') {
      $element['#attributes']['class'][] = 'daedalus-ui-details';
    }
    elseif (in_array($type, [
      'textfield',
      'textarea',
      'entity_autocomplete',
      'machine_name',
      'email',
      'url',
      'tel',
      'number',
      'search',
      'password',
    ], TRUE)) {
      $element['#attributes']['class'][] = 'daedalus-ui-input';
    }
    elseif ($type === 'submit' || $type === 'button') {
      $element['#attributes']['class'][] = 'daedalus-ui-pill-button';
    }
    foreach (Element::children($element) as $key) {
      $this->applyPanelGrammar($element[$key]);
    }
  }

  /**
   * Dispatches the field attributes event for each field on the form.
   *
   * @param array $form
   *   The entity form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $event_name
   *   FieldAttributes::ALTER or FieldAttributes::AFTER_BUILD.
   */
  private function attributeFormItemsEventDispatcher(&$form, FormStateInterface $form_state, string $event_name) {
    $entity = $this->getFormEntity($form, $form_state);
    $entity = $this->tempstoreRepository->get($entity);
    $view_mode = $this->getViewMode($form, $form_state, $entity);
    // Add attributes to associate the form item with the rendered markup.
    foreach ($entity->getFieldDefinitions() as $field_name => $field_definition) {
      if (!empty($form[$field_name])) {
        $event = new FieldAttributes($form, $view_mode, $field_name, $entity, $form_state);
        $this->dispatcher->dispatch($event, $event_name);
        $form = $event->getForm();
      }
    }
    // Auto submit the items in the advanced area. The panes register with
    // the dissolved advanced container's group during processing.
    // @see self::dissolveAdvancedTabs()
    if (!empty($form['advanced']['#groups']['advanced'])) {
      foreach ($form['advanced']['#groups']['advanced'] as $advanced_form_item) {
        if (is_array($advanced_form_item)) {
          foreach ($advanced_form_item as $key => $advanced_form_item_component) {
            if (!str_starts_with($key, '#') && !empty($advanced_form_item_component['#array_parents'])) {
              $form_item =& NestedArray::getValue($form, $advanced_form_item_component['#array_parents']);
              $form_item['#attributes']['data-daedalus-edit-auto-submit'] = '';
            }
          }
        }
      }
    }
  }

  /**
   * Add empty field to page button.
   *
   * Only fields with values are rendered on the canonical page, so we'd
   * have nothing to click to inline edit the field... Let's add a button in the
   * sidebar (entity form) that let's you add the field to the page with
   * auto-generated content.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param string $form_item
   *   The field name/form item to possibly add a button for if it's empty.
   */
  public function addFieldToPageButton(&$form, EntityInterface $entity, string $form_item) {
    $field = $entity->get($form_item);
    $field_definition = $field->getFieldDefinition();
    if ($field->isEmpty() && method_exists($field_definition, 'label')) {
      // Empty field items get a button to add them to the page. The details
      // is a tree so the button's value path is empty_fields/{field}, not
      // {field}: the panel reveals an empty field's own widget beside this
      // button (panel-widget-visibility.js), and a flat button would share
      // the widget's value key, so cleanValues() would drop the widget's
      // submitted value with the button's on every update.
      $form['empty_fields']['#type'] = 'details';
      $form['empty_fields']['#open'] = TRUE;
      $form['empty_fields']['#tree'] = TRUE;
      $form['empty_fields']['#title'] = t('Add field');
      $form['empty_fields'][$form_item] = [
        '#type' => 'submit',
        '#value' => t('@label', ['@label' => $field_definition->label()]),
        '#name' => "add_empty_field::$form_item",
        '#submit' => [
          [$this, 'populateEmptyField'],
        ],
        '#ajax' => [
          'callback' => [$this, 'addEmptyField'],
        ],
      ];
    }
  }

  /**
   * Add empty field submit handler.
   *
   * Generate field values before adding an empty field to the page.
   */
  public function populateEmptyField(&$form, FormStateInterface $form_state) {
    $entity = $this->tempstoreRepository->get($this->getFormEntity($form, $form_state));
    $input = $form_state->getUserInput();
    $field_name = explode('::', $input['_triggering_element_name'])[1];
    $field = $entity->get($field_name);

    // Generate a value for the field about to be placed on the page. The user
    // explicitly asked to add this field, so it must end up with a value even
    // when no sample value generator is configured: an empty field would
    // render nothing, breaking the gesture.
    $this->fieldGenerator->populateWithSampleValue($field);
    if ($field->isEmpty()) {
      $field->applyDefaultValue();
    }
    if ($field->isEmpty()) {
      $field->generateSampleItems();
    }
    $this->updateFormState($form, $form_state, $field, $entity);
    Cache::invalidateTags([daedalus_edit_cache_tag($entity)]);

    // Flag that the newly added field needs an "added" effect.
    $form_state->set('added_to_page', $field_name);
    $form_state->setRebuild(TRUE);
  }

  /**
   * Ajax callback.
   *
   * Now that a field had its value set in populateEmptyField, update the
   * form and page.
   */
  public function addEmptyField(&$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    $this->updatePage($response, $form, $form_state);
    $this->updateForm($response, $form, $form_state);

    return $response;
  }

  /**
   * Update the rendered page.
   *
   * @param \Drupal\Core\Ajax\AjaxResponse $response
   *   The ajax response.
   * @param array $form
   *   The entity form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function updatePage(AjaxResponse $response, array &$form, FormStateInterface $form_state) {
    $entity = $this->getFormEntity($form, $form_state);
    $view_mode = $this->getViewMode($form, $form_state, $entity);
    $content = $this->entityContent($form, $form_state, $view_mode);
    $input = $this->getUserInput($form_state);
    if (!empty($content['component']['content']['#view_mode']) && $content['component']['content']['#view_mode'] !== $view_mode) {
      // It's possible that the view mode was changed in this update.
      // $view_mode needs to remain as it was for replacing the old content on
      // the page, but let's update the view mode for passing it on down the
      // line.
      if (!empty($form['settings']['block_form']['view_mode']['#value'])) {
        // @todo This only covers inline blocks I think since it looks for the
        //   new view mode in component > content > #view_mode. Ensure that we
        //   don't need to cover other situations like entity forms.
        $form['settings']['block_form']['view_mode']['#value'] = $content['component']['content']['#view_mode'];
      }

      $input['view_mode'] = $content['component']['content']['#view_mode'];
      $this->setUserInput($form_state, $input);
    }

    $selectors = [];
    $element_id = $input['only_update_element'] ?? NULL;
    if ($element_id) {
      $parts = explode('::', $element_id);
      $wrapper_id = implode('::', [$parts[0], $parts[2], $parts[3], $parts[8]]);
      $selectors[] = sprintf('[data-daedalus-edit-field-value-wrapper="%s"]', $wrapper_id);
      // An outside-rendered label (the theme's page-title block) is not in
      // the entity render, and updateElement treats a live-only selector as
      // a removal: the H1 would vanish on commit. Deliver a fresh
      // page-title render beside the entity content so the selector matches
      // and the H1 swaps like any field. Inert for every other field.
      if ($entity instanceof FieldableEntityInterface) {
        $page_title = $this->pageTitleEditing()->titleRender($entity, $parts[3]);
        if ($page_title !== NULL) {
          $content = ['entity' => $content, 'page_title' => $page_title];
        }
      }
    }
    else {
      $selectors[] = sprintf('[data-daedalus-ui-entity-wrapper="%s::%s::%s"]', $entity->getEntityTypeId(), daedalus_ui_entity_identifier($entity), $entity->bundle());
    }

    $this->refreshBlueprintMirror($content, $form, $form_state);
    $response->addCommand(new UpdateElement($content, $selectors));
  }

  /**
   * Rides a fresh blueprint mirror envelope on the form-pipeline response.
   *
   * The form pipeline (the configure dialog, inline field edits) is the
   * write path that bypasses the daedalus_blueprint op spine, so unlike spine
   * mutation responses it carries no blueprint half. Without this the
   * client mirror — and the page elements panel projected from it — keeps
   * stale component labels until a reload re-boots it. The envelope rides
   * the UpdateElement content's attachments; the mirror re-syncs from
   * settings on behavior attach.
   *
   * @param array $content
   *   The UpdateElement content render array the envelope attaches to.
   * @param array $form
   *   The entity form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function refreshBlueprintMirror(array &$content, array &$form, FormStateInterface $form_state): void {
    $main = $this->getMainEntity($form, $form_state);
    // The staged root, wherever the submit that just ran wrote it (entity
    // tempstore, LB tempstore, or untouched in the db).
    $staged = $this->blueprintStorageHandler()->load($main->getEntityTypeId(), $main->id());
    if ($staged === NULL) {
      return;
    }
    $this->blueprintMirror()->attach($content, $staged, $this->mainEntityViewMode($form, $form_state, $main));
  }

  /**
   * The view mode the main entity is displayed with on the edited page.
   *
   * The main-entity form edits the displayed entity itself, so the form's
   * own view mode is the answer. Block forms edit nested content: the root
   * display's view mode lives on the root section storage's context, with
   * the mutation endpoint's 'full' fallback when no storage carries one.
   */
  protected function mainEntityViewMode(array &$form, FormStateInterface $form_state, EntityInterface $main): string {
    $form_entity = $this->getFormEntity($form, $form_state);
    if ($form_entity->getEntityTypeId() === $main->getEntityTypeId() && $form_entity->id() === $main->id()) {
      return $this->getViewMode($form, $form_state, $main);
    }
    $storage = $this->sectionStorage ?? NULL;
    if ($storage instanceof NestedSectionStorageInterface) {
      $storage = $storage->getRootStorage();
    }
    $view_mode = $storage?->getContexts()['view_mode'] ?? NULL;
    $view_mode = $view_mode?->getContextValue();
    return is_string($view_mode) && $view_mode !== '' ? $view_mode : 'full';
  }

  /**
   * Gets the blueprint storage handler.
   */
  private function blueprintStorageHandler(): EntityStorageHandler {
    return \Drupal::service('daedalus_blueprint.storage_handler');
  }

  /**
   * Gets the blueprint mirror envelope builder.
   */
  private function blueprintMirror(): BlueprintMirror {
    return \Drupal::service('daedalus_ui.blueprint_mirror');
  }

  /**
   * Entity Content.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string|null $view_mode
   *   The view mode.
   *
   * @return array
   *   A render array of the entity content to return as the updated page.
   */
  abstract public function entityContent(array &$form, FormStateInterface $form_state, ?string $view_mode = NULL);

  /**
   * Update the entity form.
   *
   * @param \Drupal\Core\Ajax\AjaxResponse $response
   *   The ajax response.
   * @param array $form
   *   The entity form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function updateForm(AjaxResponse $response, &$form, FormStateInterface $form_state) {
    $entity = $this->getFormEntity($form, $form_state);
    $form_id = self::getDaedalusEditFormId($entity);
    $response->addCommand(new ReplaceCommand("#$form_id", $this->wrapForm($form, $entity)));
  }

  /**
   * Clear the entity form.
   *
   * @param \Drupal\Core\Ajax\AjaxResponse $response
   *   The ajax response.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity whose form needs to be cleared.
   */
  public static function clearForm(AjaxResponse $response, EntityInterface $entity) {
    $form_id = self::getDaedalusEditFormId($entity);
    $response->addCommand(new InvokeCommand(NULL, 'daedalusEditClearForm', ["#$form_id"]));
  }

  /**
   * Handle errors.
   *
   * @param \Drupal\Core\Ajax\AjaxResponse $response
   *   The ajax response.
   * @param array $form
   *   The entity form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function handleErrors(AjaxResponse $response, &$form, FormStateInterface $form_state) {
    if (!$form_state->hasAnyErrors()) {
      return;
    }
    // Flag that after the error is cleared the form needs to be updated again
    // in order to remove the red border.
    $form['previously_had_errors'] = [
      '#type' => 'hidden',
      '#name' => 'previously_had_errors',
      '#value' => 'true',
    ];
    $this->updateForm($response, $form, $form_state);

    $input = $this->getUserInput($form_state);
    $element_id = $input['only_update_element'] ?? NULL;

    // Typically messages are rendered in EditModeAjaxMessages, but let's render
    // the messages here so we can pass along the offending $element_id.
    $this->getMessageDelivery()->deliver($response, $element_id);

    if ($element_id) {
      $response->addCommand(new InvokeCommand(
        NULL,
        'epHandleValidationError',
        [$element_id]
      ));
    }
    $response->addCommand(new InvokeCommand(NULL, 'daedalusEditIsDoneUpdating'));
  }

  /**
   * Builds the wrapper id of an entity's Daedalus Edit form.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity the form edits.
   *
   * @return string
   *   The HTML id of the form wrapper.
   */
  public static function getDaedalusEditFormId(EntityInterface $entity) {
    $id = daedalus_ui_entity_identifier($entity);
    return "daedalus-edit-form_{$entity->getEntityTypeId()}-{$id}";
  }

  /**
   * Wrap form.
   *
   * Wraps a form for easy identification in JS and ajax replacement. It's best
   * to wrap it after the form has been built and processed.
   *
   * @param array $form
   *   The entity form to wrap.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return array
   *   An entity form wrapped in a container.
   */
  public function wrapForm($form, $entity) {
    // The form's subject title ("Page", "Hero Block") ships as data on the
    // cached-form wrapper: the panel's pinned header is chrome owned by the
    // client subject model (daedalus-edit-sidebar.js), which writes this value
    // into the header whenever the form becomes the subject.
    $bundle = $entity->bundle();
    $entity_type_id = $entity->getEntityTypeId();
    $bundle_label = $this->bundleInfo()->getBundleInfo($entity_type_id)[$bundle]['label'];
    $title = $entity_type_id === 'block_content'
      ? $this->t('@bundle_label Block', ['@bundle_label' => $bundle_label])
      : $bundle_label;
    return [
        // Add an AJAX wrapper. The wrapper is a CACHED FORM inside the
        // entity-form panel's form-cache mount, not a panel of its own: the
        // panel identity (sidebar classes, type, slot, data-keeps-edit)
        // lives on the EntityFormSidebar plugin's root, and the client-side
        // subject model decides which cached form the panel shows.
        // @see \Drupal\daedalus_edit\Plugin\Sidebar\EntityFormSidebar
      '#type' => 'container',
      '#tree' => FALSE,
      '#attributes' => [
        'id' => self::getDaedalusEditFormId($entity),
        'class' => ['daedalus-edit-form'],
        'data-daedalus-edit-form-id' => sprintf('%s::%s', $entity->getEntityTypeId(), daedalus_ui_entity_identifier($entity)),
        'data-daedalus-edit-form-title' => (string) $title,
      ],
      'form' => $form,
    ];
  }

  /**
   * Get view mode.
   *
   * @param array $form
   *   The entity form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return string
   *   The view mode of the entity being edited.
   */
  public function getViewMode(array &$form, FormStateInterface $form_state, EntityInterface $entity) {
    // View mode comes from the initial JS that loads the form or from the
    // previously loaded form for subsequent form loads. e.g. Adding an empty
    // field to the page.
    $view_mode = $this->requestStack->getCurrentRequest()->request->get('viewMode');
    if (empty($view_mode)) {
      // When the form is submitted we are going to need to update the
      // rendered page so we need the view mode. Make the form aware of this
      // value so we can set it with JS before submitting.
      $input = $form_state->getUserInput();
      if (!empty($input['settings']['block_form']['view_mode'])) {
        // Block content entity form.
        $view_mode = $input['settings']['block_form']['view_mode'];
      }
      elseif (!empty($input['view_mode'])) {
        // Entity form.
        $view_mode = $input['view_mode'];
      }
    }
    // Sanitize view mode.
    $valid_view_modes = $this->entityDisplayRepository->getViewModes($entity->getEntityTypeId());
    $valid_view_modes['default'] = TRUE;
    if (empty($valid_view_modes[$view_mode])) {
      throw new \InvalidArgumentException(sprintf('Daedalus Edit: Invalid view mode for %s', $entity->label()));
    }

    return $view_mode;
  }

  /**
   * Gets the entity type bundle info service.
   *
   * @return \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   *   The bundle info service.
   */
  private function bundleInfo() {
    return \Drupal::service('entity_type.bundle.info');
  }

  /**
   * Gets the message delivery service.
   *
   * @return \Drupal\daedalus_ui\Message\EditModeAjaxMessages
   *   The message delivery service.
   */
  protected function getMessageDelivery(): EditModeAjaxMessages {
    return \Drupal::service('daedalus_ui.messages.ajax');
  }

  /**
   * Gets the page-title editing service.
   *
   * @return \Drupal\daedalus_edit\PageTitleEditing
   *   The page-title editing service.
   */
  protected function pageTitleEditing(): PageTitleEditing {
    return \Drupal::service('daedalus_edit.page_title_editing');
  }

}
