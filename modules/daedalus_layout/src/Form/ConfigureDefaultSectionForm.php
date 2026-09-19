<?php

namespace Drupal\daedalus_layout\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Layout\LayoutPluginManagerInterface;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a Daedalus Layout form.
 */
class ConfigureDefaultSectionForm extends FormBase {

  public function __construct(
    protected LayoutPluginManagerInterface $layoutManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.core.layout')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'daedalus_layout_configure_default_section';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?EntityViewDisplayInterface $entity = NULL) {
    $form_state->setStorage(['entity_view_display' => $entity]);

    $form['instructions']['#markup'] = $this->t('Choose a default layout and configuration combination to use for Layout builder driven pages. All newly placed blocks outside of an existing container will be automatically placed with these default configuration values.');

    $third_party_settings = $entity->getThirdPartySetting('daedalus_layout', 'default_section');
    $layout_plugin_id = $third_party_settings['layout_plugin'] ?? NULL;

    // Create a list of layout plugin options.
    $options = [];
    $definitions = $this->layoutManager->getFilteredDefinitions('daedalus_layout');
    foreach ($definitions as $definition) {
      $options[$definition->id()] = $definition->getLabel();
    }
    $form['layout_plugin'] = [
      '#type' => 'select',
      '#options' => $options,
      '#default_value' => $layout_plugin_id,
      '#empty_option' => $this->t('Choose layout plugin'),
      '#ajax' => [
        'callback' => '::layoutPluginChosen',
        'wrapper' => 'layout-form-container',
      ],
    ];
    $form['layout_form_container'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'layout-form-container'],
    ];

    // Load the layout plugin form when a user selects one.
    $input = $form_state->getUserInput();
    if (is_null($layout_plugin_id) && !empty($input['_triggering_element_name']) && $input['_triggering_element_name'] === 'layout_plugin' && !empty($input['layout_plugin']) && in_array($input['layout_plugin'], array_keys($definitions))) {
      $layout_plugin_id = $input['layout_plugin'];
    }
    // Load the layout plugin form when a user has previously selected one.
    if (!empty($layout_plugin_id)) {
      $layout_plugin = $this->layoutManager->createInstance($layout_plugin_id);
      if ($third_party_settings) {
        $layout_plugin->setConfiguration($third_party_settings);
      }
      $form['layout_form_container']['layout_plugin_form'] = $layout_plugin->buildConfigurationForm([], (new FormState()));
    }

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save default container'),
    ];

    return $form;
  }

  /**
   * AJAX callback to add the layout plugin form.
   */
  public function layoutPluginChosen(array &$form, FormStateInterface $form_state) {
    return $form['layout_form_container'];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Save the layout plugin settings to the entity view display.
    $form_state->cleanValues();
    $entity_view_display = $form_state->getStorage()['entity_view_display'];
    $entity_view_display->setThirdPartySetting('daedalus_layout', 'default_section', $form_state->getValues());
    $entity_view_display->save();
  }

}
