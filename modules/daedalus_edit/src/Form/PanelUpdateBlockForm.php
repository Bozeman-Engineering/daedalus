<?php

namespace Drupal\daedalus_edit\Form;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Render\Element;
use Drupal\Core\Form\SubformState;
use Drupal\daedalus_blueprint\ExtractedValue;
use Drupal\daedalus_edit\DaedalusEditFormTrait;
use Drupal\daedalus_edit\EditIdentity;
use Drupal\daedalus_edit\FormCommit;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\layout_builder\Form\UpdateBlockForm;
use Drupal\daedalus_layout\NestedSectionStorageInterface;
use Drupal\layout_builder\SectionStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Extends core's UpdateBlockForm to add Daedalus Edit inline editing integration.
 *
 * The block form the panel hosts for a Layout Builder placement (the
 * block-form endpoint, BlockFormController): a widget host for the inline
 * block's fields and the placement's display label, whose own AJAX submit
 * is the form transport. Its submit never runs core's whole-component
 * tempstore set; it hands the one property the client named to the op
 * spine (FormCommit) and the callback answers the mutation payload. The
 * placement's other settings (label display, view mode) are the Configure
 * dialog's and are not offered here.
 *
 * Nested layout support is handled transparently by:
 * - NestedRouteEnhancer: scopes the section storage and fixes delta
 * - DialogCloseSubscriber: retargets core's off-canvas close command to the
 *   Edit Mode dialog
 *
 * Built by the block-form endpoint (BlockFormController), which
 * DaedalusEditServiceProvider registers only when daedalus_layout is
 * available.
 */
class PanelUpdateBlockForm extends UpdateBlockForm implements EntityFormInterface {

  use DaedalusEditFormTrait;

  /**
   * The placement settings the panel form offers beside the block's fields.
   */
  protected const PANEL_SETTINGS = [
    'label',
    'label_wrapper',
    'block_form',
    'provider',
    'admin_label',
    'context_mapping',
  ];

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->entityDisplayRepository = $container->get('entity_display.repository');
    $instance->tempstoreRepository = $container->get('daedalus_tempstore.entity_tempstore_repository');
    $instance->dispatcher = $container->get('event_dispatcher');
    $instance->requestStack = $container->get('request_stack');
    $instance->formCommit = $container->get('daedalus_edit.form_commit');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?SectionStorageInterface $section_storage = NULL, $delta = NULL, $region = NULL, $uuid = NULL) {
    // Call core's UpdateBlockForm to build the base form.
    $form = parent::buildForm($form, $form_state, $section_storage, $delta, $region, $uuid);

    // The form builds on its own copy of the block, the way
    // ContentEntityForm::buildEntity() clones the entity it edits: the
    // plugin's block is the entity memory cache's instance for that
    // revision, the same object the op spine's working copy resolves, and
    // InlineBlock's validate extracts EVERY widget onto it. A form commit
    // hands the spine exactly one field; the rest of what the form built
    // must never reach the working copy through a shared object.
    if (isset($form['settings']['block_form']['#block'])) {
      $form['settings']['block_form']['#block'] = clone $form['settings']['block_form']['#block'];
    }

    // The Update button stays visible: the panel's explicit commit gesture,
    // the edit session's commit-and-leave door (see
    // DaedalusEditFormTrait::prepareFormForInlineEditing).
    $form['actions']['submit']['#attributes']['class'][] = 'daedalus-edit-update-button';

    // Add process callback for inline editing preparation.
    if (isset($form['settings']['block_form'])) {
      $form['settings']['block_form']['#process'][] = [$this, 'processBlockForm'];
    }
    $form['#after_build'][] = [$this, 'wrapFormAfterBuild'];

    $this->attributeLabel($form, $form_state);
    $this->hidePlacementSettings($form);

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
   *
   * The form transport: the spine writes, never core's whole-component
   * tempstore set (parent::submitForm), so the root working copy holds
   * exactly the one property and the response describes it by key.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $property = FormCommit::requestedProperty($form_state);
    if ($property === NULL) {
      throw new BadRequestHttpException('Daedalus Edit: a panel form submit names the property it commits.');
    }
    $this->commitProperty($form, $form_state, $property);
  }

  /**
   * Form-transport submit handler: one property of the block placement.
   *
   * A block field leaves the form-built block_content (the inline block's
   * entity, with every widget's value extracted onto it exactly as
   * InlineBlock::blockSubmit() does) as an ExtractedValue; the placement's
   * display label leaves as the plain text the spine's `label` property
   * takes. Either is addressed to the component on the root's working copy.
   *
   * @param array $form
   *   The complete form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $property
   *   The block field name, or 'label' for the placement label.
   */
  protected function commitProperty(array $form, FormStateInterface $form_state, string $property): void {
    $root = $this->getMainEntity($form, $form_state);

    if ($property === 'label') {
      $value = (string) $form_state->getValue(['settings', 'label']);
      $title = $form['settings']['label_wrapper']['label']['#title'] ?? $form['settings']['label']['#title'] ?? $this->t('Title');
      $label = $this->t('Edited @label', ['@label' => $title]);
      $changed = $value !== (string) ($this->getCurrentComponent()->get('configuration')['label'] ?? '');
    }
    else {
      $block_form = $form['settings']['block_form'];
      /** @var \Drupal\block_content\BlockContentInterface $block */
      $block = $block_form['#block'];
      if (!$block->hasField($property)) {
        throw new BadRequestHttpException(sprintf('Daedalus Edit: the %s block has no field "%s" to commit.', $block->bundle(), $property));
      }
      EntityFormDisplay::collectRenderDisplay($block, 'edit')->extractFormValues($block, $block_form, $form_state);
      $items = $block->get($property);
      $value = new ExtractedValue($items);
      $label = $this->t('Edited @label', ['@label' => $items->getFieldDefinition()->getLabel()]);
      // The storage's block is the staged one (the form built on a clone):
      // a widget round that changed nothing extracts an equal value.
      $changed = FormCommit::fieldChanged($items, $this->getFormEntity($form, $form_state)->get($property));
    }

    $outcome = $this->formCommit->commit(
      $root,
      $this->uuid,
      [$property => $value],
      $root->language()->getId(),
      $label,
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

    $form_item_id = EditIdentity::itemId($entity, 'label', 0, EditIdentity::LABEL_PROPERTY);
    $form['settings']['label']['#wrapper_attributes']['data-daedalus-edit-form-item-id'] = $form_item_id;
    $form['settings']['label']['#wrapper_attributes']['class'][] = 'daedalus-edit-form-item';
    $form['settings']['label']['#wrapper_attributes']['class'][] = 'daedalus-edit-hidden';
    $form['settings']['label_wrapper'] = [
      '#type' => 'container',
      'label' => $form['settings']['label'],
      '#attributes' => [
        'data-daedalus-edit-form-item-wrapper-id' => EditIdentity::fieldWrapperId($entity, 'label', EditIdentity::LABEL_PROPERTY),
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
   * Keeps the placement's other settings off the panel form.
   *
   * The panel form hosts the block's fields and its display label, the
   * properties the form transport commits one at a time. Placement
   * settings such as the label display toggle and the view mode are block
   * configuration the Configure dialog edits; shown here they would be
   * controls that commit nothing.
   *
   * @param array $form
   *   The block form.
   */
  protected function hidePlacementSettings(array &$form): void {
    if (!isset($form['settings'])) {
      return;
    }
    foreach (Element::children($form['settings']) as $key) {
      if (!in_array($key, self::PANEL_SETTINGS, TRUE)) {
        $form['settings'][$key]['#access'] = FALSE;
      }
    }
  }

}
