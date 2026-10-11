<?php

declare(strict_types=1);

namespace Drupal\daedalus_edit\Form;

use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\Core\Plugin\PluginFormFactoryInterface;
use Drupal\Core\Plugin\PluginWithFormsInterface;
use Drupal\daedalus_blueprint\ExtractedValue;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_blueprint\LayoutBuilder\PluginBlockConfiguration;
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
 * Every block plugin's dialog commits through the spine as ONE
 * update_element on the component; core's tempstore submit never runs in
 * edit mode. The dialog hosts the placement's settings (the display label
 * and its Display title toggle on every block) beside what the plugin
 * builds: an inline block's own field widgets, its view mode and whatever
 * a site adds through BlockPropertiesEvent; a plugin block's configuration
 * form (a field block's formatter type and settings, a menu's depth, a
 * view's items). Only what the dialog changed rides the op: the placement
 * values as the spine's `label` and `label_display`, an inline block's
 * `view_mode`, managed keys and block fields as ExtractedValues off the
 * form-built block (every widget's value, extracted by the form display
 * exactly as InlineBlock's own submit does), a plugin block's own settings
 * keys as the plugin's submit left them, compared to the placement's. An
 * untouched dialog commits nothing and just closes.
 *
 * The panel's block form (PanelUpdateBlockForm) shares core's form
 * id but is not this dialog: its own submit is the one-property form
 * transport, and it is left alone here.
 */
final class LayoutBuilderBlockFormAlter extends ConfigureDialogFormAlterBase {

  public function __construct(
    FormCommit $formCommit,
    RequestStack $requestStack,
    HostResolver $hostResolver,
    protected EventDispatcherInterface $eventDispatcher,
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected PluginFormFactoryInterface $pluginFormFactory,
    protected PluginBlockConfiguration $pluginBlockConfiguration,
  ) {
    parent::__construct($formCommit, $requestStack, $hostResolver);
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
    $plugin = $component->getPlugin();
    if ($plugin instanceof InlineBlock) {
      // A hosted storage always commits through the spine: a dialog this
      // alter cannot put on the transport must not fall back onto core's
      // whole-storage submit.
      if (!isset($form['settings']['block_form']['#block'])) {
        throw new \LogicException(sprintf('Daedalus Edit: the Configure dialog of inline block %s built no block form to commit through the spine.', $component->getUuid()));
      }
      $block_content = $storage->getBlockContentFromPlugin($plugin);
      if ($block_content === NULL) {
        throw new \LogicException(sprintf('Daedalus Edit: the Configure dialog of inline block %s resolves no block content to commit through the spine.', $component->getUuid()));
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
          $plugin->getConfiguration(),
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
    }

    $this->takeOver($form, SelectableKey::block($component->getUuid()));
  }

  /**
   * {@inheritdoc}
   *
   * The placement values compare against the component's configuration,
   * an inline block's fields against the working copy's block (the
   * storage's, the form built on a clone), a plugin block's own settings
   * against the component's: only what the dialog changed rides the op,
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
    $plugin = $component->getPlugin();

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

    if ($plugin instanceof InlineBlock) {
      $properties += $this->inlineBlockProperties($form, $form_state, $storage, $plugin, $configuration);
      $title = (string) ($configuration['label'] ?? '');
      if ($title === '') {
        $bundle = $form['settings']['block_form']['#block']->bundle();
        $title = (string) ($this->bundleInfo->getBundleInfo('block_content')[$bundle]['label'] ?? $bundle);
      }
    }
    else {
      $properties += $this->pluginBlockProperties($form, $form_state, $plugin, $configuration);
      $title = (string) ($configuration['label'] ?? '');
      if ($title === '') {
        $title = (string) ($plugin->getPluginDefinition()['admin_label'] ?? $plugin->getPluginId());
      }
    }

    $outcome = $this->formCommit->commit(
      $root,
      $component->getUuid(),
      $properties,
      $root->entity()->language()->getId(),
      $this->t('Configured @label', ['@label' => $title]),
      $properties !== [],
    );
    $form_state->setTemporaryValue(FormCommit::OUTCOME, $outcome);
  }

  /**
   * An inline block's changed properties beyond the placement's label.
   *
   * The view mode, a site's own properties (BlockPropertiesEvent: the
   * block type's managed configuration keys, the spine refuses an
   * undeclared one) and the block's fields the dialog changed.
   *
   * @return array
   *   The properties.
   */
  protected function inlineBlockProperties(array $form, FormStateInterface $form_state, NestedSectionStorageInterface $storage, BlockPluginInterface $plugin, array $configuration): array {
    $properties = [];
    $view_mode = $form_state->getValue(['settings', 'view_mode']);
    if (is_string($view_mode) && $view_mode !== '' && $view_mode !== ($configuration['view_mode'] ?? 'full')) {
      $properties['view_mode'] = $view_mode;
    }
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
    $staged = $storage->getBlockContentFromPlugin($plugin);
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
    return $properties;
  }

  /**
   * A plugin block's changed settings: what its own submit left.
   *
   * The plugin's configuration form submit runs as core's
   * ConfigureBlockFormBase::submitForm would run it, on the plugin
   * instance the component builds (never the form's own instance, which
   * nothing reads afterwards), and every own settings key whose value
   * differs from the placement's rides the op; label, label_display and
   * provider the plugin's submit also sets are the placement's, answered
   * above, and the context mapping is the spine's.
   *
   * @return array
   *   The properties.
   */
  protected function pluginBlockProperties(array $form, FormStateInterface $form_state, BlockPluginInterface $plugin, array $configuration): array {
    $subform_state = SubformState::createForSubform($form['settings'], $form, $form_state);
    $plugin_form = $plugin instanceof PluginWithFormsInterface
      ? $this->pluginFormFactory->createInstance($plugin, 'configure')
      : $plugin;
    $plugin_form->submitConfigurationForm($form, $subform_state);
    // The submit assigns what the form built (a partial map: a formatter
    // with no third-party settings element leaves that key out); the
    // placement holds the plugin-normalized settings the spine stored, so
    // the comparison is made on the same footing.
    $plugin->setConfiguration($plugin->getConfiguration());
    $submitted = $plugin->getConfiguration();

    $properties = [];
    foreach ($this->pluginBlockConfiguration->ownKeys($plugin->getPluginId()) as $key) {
      $value = $submitted[$key] ?? NULL;
      if (!static::sameValue($value, $configuration[$key] ?? NULL)) {
        $properties[$key] = $value;
      }
    }
    return $properties;
  }

  /**
   * Whether two settings values are the same, key order aside.
   *
   * A form rebuilds a nested map (a field block's formatter) in its own key
   * order; the placement's is the schema's. Order is not a change.
   */
  protected static function sameValue(mixed $a, mixed $b): bool {
    if (is_array($a) && is_array($b)) {
      if (count($a) !== count($b)) {
        return FALSE;
      }
      foreach ($a as $key => $value) {
        if (!array_key_exists($key, $b) || !static::sameValue($value, $b[$key])) {
          return FALSE;
        }
      }
      return TRUE;
    }
    return $a === $b;
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
