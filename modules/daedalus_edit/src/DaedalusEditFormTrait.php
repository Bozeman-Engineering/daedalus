<?php

namespace Drupal\daedalus_edit;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\daedalus_edit\Event\FieldAttributes;
use Drupal\daedalus_tempstore\EntityTempstoreRepository;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\layout_builder\SectionStorageInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Shared machinery for entity forms hosted in the Daedalus Edit sidebar.
 *
 * The hosting half of the form path: a form fetched into the panel is
 * prepared for inline editing (its actions reduced to the Update button,
 * its advanced tabs dissolved, its field widgets attributed with the
 * identities the client correlates to the rendered markup, the panel kit's
 * look applied) and wrapped for the form cache. Nothing here writes: a
 * form commit is the client naming one field on the form's own submit
 * (FormCommit::FIELD_INPUT), the submit handler handing that field to the
 * op spine (FormCommit::commit), and the callback answering the mutation
 * payload (commitResponse).
 *
 * The request stack is deliberately NOT declared here, exactly like
 * sectionStorage (read via `$this->sectionStorage ?? NULL` below): core's
 * FormBase declares an UNTYPED protected $requestStack, and a typed trait
 * property fatals against an untyped parent property when the trait is
 * composed into a FormBase descendant (PanelUpdateBlockForm). Every
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
   * The entity display repository.
   */
  protected EntityDisplayRepositoryInterface $entityDisplayRepository;

  /**
   * The form transport's spine door.
   */
  protected FormCommit $formCommit;

  /**
   * Answers a form-transport commit's AJAX callback.
   *
   * Validation errors mean the submit handlers never ran, so nothing was
   * written: the rebuilt form goes back with its errors. Otherwise the
   * handler's outcome answers as the mutation-response payload, rendered in
   * the view mode the canvas shows the root in.
   *
   * @param array $form
   *   The form, as the callback received it.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The transport's answer.
   */
  protected function commitResponse(array &$form, FormStateInterface $form_state): AjaxResponse {
    $entity = $this->getFormEntity($form, $form_state);
    if ($form_state->hasAnyErrors()) {
      return $this->formCommit->rejected(self::getDaedalusEditFormId($entity), $this->wrapForm($form, $entity));
    }
    $outcome = $form_state->getTemporaryValue(FormCommit::OUTCOME);
    if (!$outcome instanceof FormCommitOutcome) {
      throw new \LogicException('Daedalus Edit: the form commit submit handler left no outcome for its AJAX callback.');
    }
    $view_mode = $this->mainEntityViewMode($form, $form_state, $this->getMainEntity($form, $form_state));
    return $this->formCommit->respond($this->requestStack->getCurrentRequest(), $outcome, $view_mode);
  }

  /**
   * Prepares an entity form for inline editing in the sidebar.
   *
   * @param array $form
   *   The entity form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function prepareFormForInlineEditing(&$form, FormStateInterface $form_state) {
    $this->dissolveAdvancedTabs($form);

    // The Update button stays visible: it is the panel's explicit commit
    // gesture, the edit session's commit-and-leave door (Cmd+Enter's
    // twin, taken by daedalus_edit's engine in the browser), never the
    // form's own submit. Every other action (preview, delete) is not part
    // of the inline-editing surface. The block form prepares its inline
    // block's subform through here, which carries no actions of its own
    // (the block form's Update button sits on the outer form).
    if (isset($form['actions'])) {
      foreach (Element::children($form['actions']) as $action) {
        if ($action !== 'submit') {
          $form['actions'][$action]['#access'] = FALSE;
        }
      }
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
    $this->followGroupedWidgets($form, $form_state);
    $this->applyPanelGrammar($form);
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
   * Moves a field's wrapper identity onto a widget delta a group took.
   *
   * A widget whose delta declares a #group (the path widget's URL alias
   * details, grouped into the advanced container) renders inside the
   * group, not inside the field's own element: the group copies the delta
   * at pre-render and prints nothing at the origin. The wrapper identity
   * FormDefaultFieldAttributes stamped on the field element (the form-item
   * wrapper id, the widget id, the hidden class) would then wrap an empty
   * element while the widget the user sees carries no identity at all, so
   * neither the panel's field-state judge nor the form transport could
   * find the field. The identity follows the widget: the grouped delta
   * becomes the field's wrapper, the origin element keeps none.
   *
   * @param array $form
   *   The entity form, after build.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  private function followGroupedWidgets(array &$form, FormStateInterface $form_state): void {
    $entity = $this->getFormEntity($form, $form_state);
    $entity = $this->tempstoreRepository->get($entity);
    foreach (array_keys($entity->getFieldDefinitions()) as $field_name) {
      if (empty($form[$field_name]['widget']) || !isset($form[$field_name]['#attributes']['data-daedalus-edit-form-item-wrapper-id'])) {
        continue;
      }
      $grouped = [];
      foreach (Element::children($form[$field_name]['widget']) as $delta) {
        if (!empty($form[$field_name]['widget'][$delta]['#group'])) {
          $grouped[] = $delta;
        }
      }
      if (!$grouped) {
        continue;
      }
      $attributes = &$form[$field_name]['#attributes'];
      $identity = [
        'data-daedalus-edit-form-item-wrapper-id' => $attributes['data-daedalus-edit-form-item-wrapper-id'],
        'data-daedalus-edit-form-item-widget' => $attributes['data-daedalus-edit-form-item-widget'] ?? NULL,
      ];
      unset($attributes['data-daedalus-edit-form-item-wrapper-id'], $attributes['data-daedalus-edit-form-item-widget']);
      $attributes['class'] = array_values(array_diff(
        $attributes['class'] ?? [],
        ['daedalus-edit-form-item-wrapper', 'daedalus-edit-hidden'],
      ));
      foreach ($grouped as $delta) {
        $element = &$form[$field_name]['widget'][$delta];
        $element['#attributes'] = ($element['#attributes'] ?? []) + array_filter($identity, fn ($value) => $value !== NULL);
        $element['#attributes']['class'][] = 'daedalus-edit-form-item-wrapper';
        $element['#attributes']['class'][] = 'daedalus-edit-hidden';
      }
    }
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
  }

  /**
   * The view mode the main entity is displayed with on the edited page.
   *
   * The main-entity form edits the displayed entity itself, so the form's
   * own view mode is the answer. Block forms edit nested content: the root
   * display's view mode is the section storage's (FormCommit::storageViewMode).
   */
  protected function mainEntityViewMode(array &$form, FormStateInterface $form_state, EntityInterface $main): string {
    $form_entity = $this->getFormEntity($form, $form_state);
    if ($form_entity->getEntityTypeId() === $main->getEntityTypeId() && $form_entity->id() === $main->id()) {
      return $this->getViewMode($form, $form_state, $main);
    }
    $storage = $this->sectionStorage ?? NULL;
    return $storage instanceof SectionStorageInterface ? FormCommit::storageViewMode($storage) : 'full';
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
   * The view mode rides the query of the request that fetches the form
   * (entity-form.js), and core copies that query onto every #ajax URL the
   * form builds, so a widget's own round (remove, add more, a media
   * library insert) and the form transport's commits carry it the same
   * way. Nothing is remembered server-side: a form is not cached until
   * its first rebuild, so the first widget round always builds from
   * scratch and must find the view mode on its request. A programmatic
   * submit (a kernel test) may post it in the body instead.
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
    $request = $this->requestStack->getCurrentRequest();
    $view_mode = $request->query->get('viewMode') ?: $request->request->get('viewMode');
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

}
