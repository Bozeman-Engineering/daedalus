<?php

declare(strict_types=1);

namespace Drupal\daedalus_edit\Form;

use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\daedalus_blueprint\ExtractedValue;
use Drupal\daedalus_blueprint_api\Render\SelectableKey;
use Drupal\daedalus_edit\Event\BlockPropertiesEvent;
use Drupal\daedalus_edit\FormCommit;
use Drupal\daedalus_layout\NestedSectionStorageInterface;
use Drupal\daedalus_layout\SectionStorage\NestedAwareSectionStorage;
use Drupal\layout_builder\Form\UpdateBlockForm;
use Drupal\layout_builder\Plugin\Block\InlineBlock;
use Drupal\layout_builder\SectionStorageInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The block Configure dialog: core's UpdateBlockForm on the form transport.
 *
 * The dialog hosts the placement's settings (the display label, its
 * Display title toggle, the view mode, and whatever a site adds through
 * BlockPropertiesEvent) beside the inline block's own field widgets. Its
 * submit commits every property the dialog changed as ONE update_element
 * on the component: the placement values as the spine's `label`,
 * `label_display`, `view_mode` and managed configuration keys, the block
 * fields as ExtractedValues off the form-built block (every widget's
 * value, extracted by the form display exactly as InlineBlock's own
 * submit does). An untouched dialog commits nothing and just closes.
 *
 * The panel's block form (PanelUpdateBlockForm) shares core's form
 * id but is not this dialog: its own submit is the one-property form
 * transport, and it is left alone here.
 */
final class LayoutBuilderBlockFormAlter extends ConfigureDialogFormAlterBase {

  public function __construct(
    FormCommit $formCommit,
    RequestStack $requestStack,
    protected EventDispatcherInterface $eventDispatcher,
    protected EntityTypeBundleInfoInterface $bundleInfo,
  ) {
    parent::__construct($formCommit, $requestStack);
  }

  /**
   * {@inheritdoc}
   */
  public function formAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    if ($form_id !== 'layout_builder_update_block') {
      return;
    }
    $form_object = $form_state->getFormObject();
    if (!$form_object instanceof UpdateBlockForm || $form_object instanceof PanelUpdateBlockForm) {
      return;
    }
    $storage = NestedAwareSectionStorage::wrap($form_object->getSectionStorage());
    if (!$storage instanceof NestedSectionStorageInterface || $this->rootOf($storage) === NULL) {
      return;
    }
    $component = $form_object->getCurrentComponent();
    $inline_block = $component->getPlugin();
    if (!$inline_block instanceof InlineBlock || !isset($form['settings']['block_form']['#block'])) {
      return;
    }
    $block_content = $storage->getBlockContentFromPlugin($inline_block);
    if ($block_content === NULL) {
      return;
    }

    // The form builds on its own copy of the block (the rule the panel's
    // block form follows, see PanelUpdateBlockForm::buildForm): the
    // plugin's block can be the entity memory cache's instance for that
    // revision, the same object the spine's working copy resolves, and the
    // widgets extract onto it at validation. The form-built block is never
    // the working copy's object; the spine writes through the op.
    $form['settings']['block_form']['#block'] = clone $form['settings']['block_form']['#block'];

    // Dispatch the event for subscribers to add custom properties.
    $block_properties_event = $this->eventDispatcher->dispatch(
      new BlockPropertiesEvent(
        $storage,
        $component,
        $block_content,
        $inline_block->getConfiguration(),
        $form,
        $form_state
      ),
      BlockPropertiesEvent::class
    );

    $form_state = $block_properties_event->getFormState();

    // Determine what form items were added by event subscribers.
    $initial_settings = array_keys($form['settings']);
    $form = $block_properties_event->getForm();
    $settings_added = array_diff_key(array_keys($form['settings']), $initial_settings);
    $block_properties = [];
    foreach ($settings_added as $setting) {
      $block_properties[] = ['settings', $setting];
    }
    $block_properties = array_merge($block_properties, $block_properties_event->getBlockProperties());
    $form_state->set('block_properties', $block_properties);

    $this->takeOver($form, SelectableKey::block($component->getUuid()));
  }

  /**
   * {@inheritdoc}
   *
   * The placement values compare against the component's configuration
   * and the block fields against the working copy's block (the storage's,
   * the form built on a clone): only what the dialog changed rides the op,
   * so the history entry and the response's targets name exactly that.
   */
  public function formSubmit(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\layout_builder\Form\UpdateBlockForm $form_object */
    $form_object = $form_state->getFormObject();
    $storage = NestedAwareSectionStorage::wrap($form_object->getSectionStorage());
    $root = $this->rootOf($storage);
    if ($root === NULL) {
      throw new \LogicException('Daedalus Edit: the block Configure dialog submitted off an overrides layout.');
    }
    $component = $form_object->getCurrentComponent();
    $configuration = $component->get('configuration');

    $properties = [];
    $label = (string) $form_state->getValue(['settings', 'label']);
    if ($label !== (string) ($configuration['label'] ?? '')) {
      $properties['label'] = $label;
    }
    // The Display title checkbox submits its #return_value ('visible') or
    // nothing; the stored spelling is 'visible' or '0' (core's plugin
    // default is visible when the key is absent).
    $label_display = $form_state->getValue(['settings', 'label_display']) === BlockPluginInterface::BLOCK_LABEL_VISIBLE
      ? BlockPluginInterface::BLOCK_LABEL_VISIBLE
      : '0';
    if ($label_display !== (string) ($configuration['label_display'] ?? BlockPluginInterface::BLOCK_LABEL_VISIBLE)) {
      $properties['label_display'] = $label_display;
    }
    $view_mode = $form_state->getValue(['settings', 'view_mode']);
    if (is_string($view_mode) && $view_mode !== '' && $view_mode !== ($configuration['view_mode'] ?? 'full')) {
      $properties['view_mode'] = $view_mode;
    }
    // A site's own properties (BlockPropertiesEvent) are the block type's
    // managed configuration keys; the spine refuses an undeclared one.
    foreach ($form_state->get('block_properties') ?? [] as $block_property) {
      $key = is_array($block_property) ? end($block_property) : $block_property;
      $value = $form_state->getValue($block_property);
      if ($value !== ($configuration[$key] ?? NULL)) {
        $properties[$key] = $value;
      }
    }

    $block_form = $form['settings']['block_form'];
    /** @var \Drupal\block_content\BlockContentInterface $block */
    $block = $block_form['#block'];
    $display = EntityFormDisplay::collectRenderDisplay($block, 'edit');
    $display->extractFormValues($block, $block_form, $form_state);
    $staged = $storage->getBlockContentFromPlugin($component->getPlugin());
    foreach (array_keys($display->getComponents()) as $field_name) {
      if (!$block->hasField($field_name)) {
        continue;
      }
      $items = $block->get($field_name);
      $staged_items = $staged !== NULL && $staged->hasField($field_name) ? $staged->get($field_name) : NULL;
      if (FormCommit::fieldChanged($items, $staged_items)) {
        $properties[$field_name] = new ExtractedValue($items);
      }
    }

    $title = (string) ($configuration['label'] ?? '');
    if ($title === '') {
      $title = (string) ($this->bundleInfo->getBundleInfo('block_content')[$block->bundle()]['label'] ?? $block->bundle());
    }
    $outcome = $this->formCommit->commit(
      $root,
      $component->getUuid(),
      $properties,
      $root->language()->getId(),
      $this->t('Configured @label', ['@label' => $title]),
      $properties !== [],
    );
    $form_state->setTemporaryValue(FormCommit::OUTCOME, $outcome);
  }

  /**
   * {@inheritdoc}
   */
  protected function sectionStorage(FormStateInterface $form_state): SectionStorageInterface {
    $form_object = $form_state->getFormObject();
    if (!$form_object instanceof UpdateBlockForm) {
      throw new \LogicException('Daedalus Edit: the block Configure dialog answers core\'s UpdateBlockForm only.');
    }
    return $form_object->getSectionStorage();
  }

}
