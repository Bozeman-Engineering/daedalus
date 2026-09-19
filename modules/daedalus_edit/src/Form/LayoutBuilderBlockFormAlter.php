<?php

namespace Drupal\daedalus_edit\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\daedalus_edit\Event\BlockPropertiesEvent;
use Drupal\layout_builder\LayoutTempstoreRepositoryInterface;
use Drupal\daedalus_layout\NestedSectionStorageInterface;
use Drupal\layout_builder\Plugin\Block\InlineBlock;
use Drupal\layout_builder\Form\ConfigureBlockFormBase;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\daedalus_layout\SectionStorage\NestedAwareSectionStorage;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;

/**
 * Layout Builder block form alter.
 */
class LayoutBuilderBlockFormAlter {

  use StringTranslationTrait;
  use DependencySerializationTrait;

  public function __construct(
    protected EventDispatcherInterface $eventDispatcher,
    protected LayoutTempstoreRepositoryInterface $tempstoreRepository,
  ) {}

  /**
   * Alters Layout Builder block forms to add custom block properties.
   *
   * Dispatches a BlockPropertiesEvent so subscribers can add custom form
   * elements (e.g., site-specific display toggles) to inline blocks. The
   * properties are saved to the block plugin configuration on submit.
   */
  public function formAlter(&$form, FormStateInterface $form_state, string $form_id) {
    if (!in_array($form_id, [
      'layout_builder_update_block',
      'layout_builder_add_block',
    ])) {
      return;
    }

    $form_object = $form_state->getFormObject();
    if (!$form_object instanceof ConfigureBlockFormBase) {
      return;
    }

    // Nested sections exist only with daedalus_layout, an optional integration.
    if (!class_exists(NestedAwareSectionStorage::class)) {
      return;
    }
    $storage = NestedAwareSectionStorage::wrap($form_object->getSectionStorage());
    if (!$storage instanceof NestedSectionStorageInterface) {
      return;
    }

    $section_component = $form_object->getCurrentComponent();
    $inline_block = $section_component->getPlugin();
    if (!$inline_block instanceof InlineBlock) {
      return;
    }

    $inline_block_configuration = $inline_block->getConfiguration();
    $block_content = $storage->getBlockContentFromPlugin($inline_block);

    if (!$block_content) {
      return;
    }

    $form['actions']['submit']['#submit'] = array_merge($form['#submit'], [[$this, 'formSubmit']]);

    // Dispatch the event for subscribers to add custom properties.
    $block_properties_event = $this->eventDispatcher->dispatch(
      new BlockPropertiesEvent(
        $storage,
        $section_component,
        $block_content,
        $inline_block_configuration,
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

  /**
   * Submit handler that saves block properties to component configuration.
   *
   * Runs after core's ConfigureBlockFormBase::submitForm() so the component
   * already has the base configuration saved to tempstore. We merge our custom
   * properties into the component's configuration and re-save to tempstore.
   */
  public function formSubmit($form, FormStateInterface $form_state) {
    $form_object = $form_state->getFormObject();
    $section_component = $form_object->getCurrentComponent();
    $config = $section_component->get('configuration');
    $block_properties = $form_state->get('block_properties');
    if (!empty($block_properties)) {
      foreach ($block_properties as $block_property) {
        $value = $form_state->getValue($block_property);
        $key = is_array($block_property) ? end($block_property) : $block_property;
        $config[$key] = $value;
      }
      $section_storage = $form_object->getSectionStorage();
      $section_component->setConfiguration($config);
      $this->tempstoreRepository->set($section_storage);
    }
  }

}
