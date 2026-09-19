<?php

namespace Drupal\daedalus_ui\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CloseModalDialogCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\daedalus_ui\ModePluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The per-bundle configuration form of a mode plugin.
 */
class ModeConfigureForm extends FormBase {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ModePluginManager $modeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.modes'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'daedalus_ui_mode_configure';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $plugin_id = NULL, ?string $entity_type_id = NULL, ?string $entity_bundle_id = NULL) {
    $form['#tree'] = TRUE;
    $form['settings'] = [];
    $subform_state = SubformState::createForSubform($form['settings'], $form, $form_state);
    $plugin = $this->getPlugin($plugin_id);
    $form['settings'] = $plugin->buildConfigurationForm($form['settings'], $subform_state, $entity_type_id, $entity_bundle_id);
    $form['plugin'] = [
      '#type' => 'value',
      '#value' => $plugin,
    ];
    $form['entity_type_id'] = [
      '#type' => 'value',
      '#value' => $entity_type_id,
    ];
    $form['entity_bundle_id'] = [
      '#type' => 'value',
      '#value' => $entity_bundle_id,
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#ajax' => [
        'callback' => '::ajaxSubmit',
        'event' => 'click',
      ],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\daedalus_ui\ModeInterface|\Drupal\Core\Plugin\PluginFormInterface $plugin */
    $plugin = $form_state->getValue('plugin');
    $entity_type_id = $form_state->getValue('entity_type_id');
    $entity_bundle_id = $form_state->getValue('entity_bundle_id');
    $plugin->submitConfigurationForm($form['settings'], SubformState::createForSubform($form['settings'], $form, $form_state));
    $bundled_entity = $this->entityTypeManager->getDefinition($entity_type_id);
    $bundle_entity_type = $bundled_entity->getBundleEntityType();
    if ($bundle_entity_type) {
      /** @var \Drupal\Core\Config\Entity\ConfigEntityInterface $bundle_entity */
      $bundle_entity = $this->entityTypeManager
        ->getStorage($bundle_entity_type)
        ->load($entity_bundle_id);
      $configured_modes = $bundle_entity->getThirdPartySetting('daedalus_ui', 'modes', []);
      $configured_modes[$plugin->getPluginId()] = $plugin->getConfiguration();
      $bundle_entity->setThirdPartySetting('daedalus_ui', 'modes', $configured_modes);
      daedalus_ui_save_outside_workspace($bundle_entity);
    }
  }

  /**
   * Closes the dialog and refreshes the bundle form after an AJAX submit.
   */
  public function ajaxSubmit(array &$form, FormStateInterface $form_state) {
    $plugin = $form_state->getValue('plugin');
    $response = new AjaxResponse();
    $response->addCommand(new CloseModalDialogCommand());
    $response->addCommand(new ReplaceCommand('[data-drupal-selector=edit-modes-edit] td:nth-child(2)', '<td>' . $plugin->getSummary() . '</td>'));
    return $response;
  }

  /**
   * Instantiates the mode plugin being configured.
   *
   * @param string $plugin_id
   *   The mode plugin id.
   * @param array $configuration
   *   The plugin configuration.
   *
   * @return \Drupal\Core\Plugin\PluginFormInterface
   *   The mode plugin.
   */
  protected function getPlugin(string $plugin_id, array $configuration = []) {
    return $this->modeManager->createInstance($plugin_id, $configuration);
  }

}
