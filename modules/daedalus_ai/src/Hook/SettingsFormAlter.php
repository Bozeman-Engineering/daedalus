<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Adds Daedalus AI settings to the Daedalus settings form.
 */
class SettingsFormAlter {

  use StringTranslationTrait;

  /**
   * Constructs a SettingsFormAlter object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Implements hook_form_daedalus_ui_settings_alter().
   */
  #[Hook('form_daedalus_ui_settings_alter')]
  public function formAlter(array &$form, FormStateInterface $form_state): void {
    $ai_config = $this->configFactory->get('daedalus_ai.settings');
    $assistants = $this->entityTypeManager->getStorage('ai_assistant')->loadMultiple();
    $options = [];
    foreach ($assistants as $id => $assistant) {
      $options[$id] = $assistant->label();
    }

    $form['daedalus_ai'] = [
      '#type' => 'details',
      '#title' => $this->t('Daedalus AI'),
      '#open' => TRUE,
    ];
    $form['daedalus_ai']['assistant_id'] = [
      '#type' => 'select',
      '#title' => $this->t('AI Assistant'),
      '#options' => $options,
      '#empty_option' => $this->t('- None -'),
      '#default_value' => $ai_config->get('assistant_id') ?: '',
      '#description' => $this->t('The AI Assistant the chat sidebar talks to. The module ships no default; until one is chosen the sidebar is not shown.'),
    ];
    $form['daedalus_ai']['image_generation_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable AI image generation'),
      '#default_value' => $ai_config->get('image_generation_enabled') ?? TRUE,
      '#description' => $this->t('When enabled, the AI can generate new images for image fields. When disabled, AI-minted media keep the placeholder image as their final image and no images are generated. Users can also turn generation off for themselves from the Edit Mode toolbar.'),
    ];
    $form['daedalus_ai']['webdriver_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('WebDriver endpoint for the AI render tool'),
      '#default_value' => $ai_config->get('webdriver_url') ?? '',
      '#description' => $this->t('URL of a WebDriver endpoint (e.g. a selenium standalone container, http://selenium-chrome:4444) the AI uses to render pages and verify its work visually. Leave empty to disable the render tool.'),
    ];
    $form['daedalus_ai']['placeholder_image'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Placeholder image'),
      '#default_value' => $ai_config->get('placeholder_image') ?? '',
      '#description' => $this->t('Stream URI (e.g. public://placeholder.png) or app-root-relative path of the image AI-minted media carry while their generated image is pending. Leave empty to use the neutral image shipped with Daedalus AI.'),
    ];

    $form['#submit'][] = [$this, 'submitForm'];
  }

  /**
   * Submit handler for Daedalus AI settings.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->configFactory->getEditable('daedalus_ai.settings')
      ->set('assistant_id', (string) $form_state->getValue('assistant_id'))
      ->set('image_generation_enabled', (bool) $form_state->getValue('image_generation_enabled'))
      ->set('placeholder_image', trim((string) $form_state->getValue('placeholder_image')))
      ->set('webdriver_url', trim((string) $form_state->getValue('webdriver_url')))
      ->save();
  }

}
