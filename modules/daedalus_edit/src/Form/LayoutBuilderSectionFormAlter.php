<?php

declare(strict_types=1);

namespace Drupal\daedalus_edit\Form;

use Drupal\Component\Plugin\ContextAwarePluginInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\Core\Layout\LayoutInterface;
use Drupal\Core\Plugin\PluginFormFactoryInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\Plugin\PluginWithFormsInterface;
use Drupal\daedalus_blueprint\LayoutBuilder\DaedalusLayoutAdapter;
use Drupal\daedalus_blueprint\LayoutBuilder\OpaqueDataMerger;
use Drupal\daedalus_blueprint_api\Render\SelectableKey;
use Drupal\daedalus_edit\FormCommit;
use Drupal\daedalus_layout\NestedSectionStorageInterface;
use Drupal\daedalus_layout\SectionStorage\NestedAwareSectionStorage;
use Drupal\layout_builder\Form\ConfigureSectionForm;
use Drupal\layout_builder\SectionStorageInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The section Configure dialog: core's ConfigureSectionForm on the transport.
 *
 * The dialog hosts the section's layout plugin form (the label, the
 * layout's own settings such as column widths). Its submit runs the plugin
 * form's own submit on the section's layout plugin, exactly as core does,
 * and commits what changed as ONE update_element on the section: the
 * label as the spine's `label` property, the rest as `layout_settings`
 * (the label's slot inside the settings is not writable there; the
 * property is its one spelling). An untouched dialog commits nothing and
 * just closes.
 *
 * Only an existing section's dialog: the same form adds a section on the
 * stock admin UI's route (a plugin id in the build arguments), which is not
 * this dialog and keeps core's path.
 */
final class LayoutBuilderSectionFormAlter extends ConfigureDialogFormAlterBase {

  public function __construct(
    FormCommit $formCommit,
    RequestStack $requestStack,
    protected PluginFormFactoryInterface $pluginFormFactory,
    protected DaedalusLayoutAdapter $layoutAdapter,
  ) {
    parent::__construct($formCommit, $requestStack);
  }

  /**
   * {@inheritdoc}
   */
  public function formAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    if ($form_id !== 'layout_builder_configure_section') {
      return;
    }
    $form_object = $form_state->getFormObject();
    if (!$form_object instanceof ConfigureSectionForm) {
      return;
    }
    if (($form_state->getBuildInfo()['args'][2] ?? NULL) !== NULL) {
      return;
    }
    $storage = NestedAwareSectionStorage::wrap($form_object->getSectionStorage());
    if (!$storage instanceof NestedSectionStorageInterface || $this->rootOf($storage) === NULL) {
      return;
    }
    $section_uuid = $this->layoutAdapter->getSectionUuid($form_object->getCurrentSection());
    if ($section_uuid === NULL) {
      return;
    }

    $this->takeOver($form, SelectableKey::container($section_uuid));
  }

  /**
   * {@inheritdoc}
   *
   * The layout plugin the form built on is a detached instance over the
   * section's settings (Section::getLayout() creates one per call), so its
   * submit changes nothing but the instance's configuration; the diff
   * against the section's current settings is what rides the op.
   */
  public function formSubmit(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\layout_builder\Form\ConfigureSectionForm $form_object */
    $form_object = $form_state->getFormObject();
    $storage = NestedAwareSectionStorage::wrap($form_object->getSectionStorage());
    $root = $this->rootOf($storage);
    if ($root === NULL) {
      throw new \LogicException('Daedalus Edit: the section Configure dialog submitted off an overrides layout.');
    }
    $section = $form_object->getCurrentSection();
    $section_uuid = $this->layoutAdapter->getSectionUuid($section);
    if ($section_uuid === NULL) {
      throw new \LogicException('Daedalus Edit: the section Configure dialog submitted for a section without a uuid.');
    }

    $layout = $form_object->getCurrentLayout();
    $subform_state = SubformState::createForSubform($form['layout_settings'], $form, $form_state);
    $this->pluginForm($layout)->submitConfigurationForm($form['layout_settings'], $subform_state);
    if ($layout instanceof ContextAwarePluginInterface) {
      $layout->setContextMapping($subform_state->getValue('context_mapping', []));
    }
    $configuration = $layout->getConfiguration();
    $current = $section->getLayoutSettings();

    $properties = [];
    $label = (string) ($configuration['label'] ?? '');
    if ($label !== (string) ($current['label'] ?? '')) {
      $properties['label'] = $label;
    }
    // The patch contract: a sent key is set, an explicit empty value clears
    // it, an omitted key keeps its value. So a key the section does not
    // hold whose value is empty (a context-aware layout's empty context
    // mapping) is already clear and not a change.
    $settings = [];
    foreach ($configuration as $key => $value) {
      if ($key === 'label') {
        continue;
      }
      if (array_key_exists($key, $current) ? $current[$key] !== $value : !OpaqueDataMerger::isEmptyValue($value)) {
        $settings[$key] = $value;
      }
    }
    if ($settings !== []) {
      $properties['layout_settings'] = $settings;
    }

    $title = (string) ($current['label'] ?? '');
    if ($title === '') {
      $title = (string) $layout->getPluginDefinition()->getLabel();
    }
    $outcome = $this->formCommit->commit(
      $root,
      $section_uuid,
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
    if (!$form_object instanceof ConfigureSectionForm) {
      throw new \LogicException('Daedalus Edit: the section Configure dialog answers core\'s ConfigureSectionForm only.');
    }
    return $form_object->getSectionStorage();
  }

  /**
   * The layout plugin's configuration form, as core resolves it.
   *
   * @param \Drupal\Core\Layout\LayoutInterface $layout
   *   The layout plugin.
   *
   * @return \Drupal\Core\Plugin\PluginFormInterface
   *   The plugin form.
   */
  private function pluginForm(LayoutInterface $layout): PluginFormInterface {
    if ($layout instanceof PluginWithFormsInterface) {
      return $this->pluginFormFactory->createInstance($layout, 'configure');
    }
    if ($layout instanceof PluginFormInterface) {
      return $layout;
    }
    throw new \InvalidArgumentException(sprintf('The "%s" layout does not provide a configuration form', $layout->getPluginId()));
  }

}
