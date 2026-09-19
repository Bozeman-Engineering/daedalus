<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Adds Daedalus Layout settings to the Daedalus settings form.
 */
class SettingsFormAlter {

  use StringTranslationTrait;

  /**
   * Constructs a SettingsFormAlter object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Implements hook_form_daedalus_ui_settings_alter().
   */
  #[Hook('form_daedalus_ui_settings_alter')]
  public function formAlter(array &$form, FormStateInterface $form_state): void {
    $lb_config = $this->configFactory->get('daedalus_layout.settings');

    $form['daedalus_layout'] = [
      '#type' => 'details',
      '#title' => $this->t('Daedalus Layout'),
      '#open' => TRUE,
    ];
    $form['daedalus_layout']['contextual_links'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show Block Contextual links'),
      '#default_value' => $lb_config->get('contextual_links'),
    ];

    $form['#submit'][] = [$this, 'submitForm'];
  }

  /**
   * Submit handler for Daedalus Layout settings.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->configFactory->getEditable('daedalus_layout.settings')
      ->set('contextual_links', $form_state->getValue('contextual_links'))
      ->save();
  }

}
