<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_memory\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for Daedalus AI Memory settings.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * The AI agent plugin manager.
   *
   * @var \Drupal\ai_agents\PluginManager\AiAgentManager|null
   */
  protected $aiAgentPluginManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->aiAgentPluginManager = $container->get('plugin.manager.ai_agents', ContainerInterface::NULL_ON_INVALID_REFERENCE);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['daedalus_ai_memory.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'daedalus_ai_memory_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('daedalus_ai_memory.settings');

    $options = [];
    if ($this->aiAgentPluginManager) {
      foreach ($this->aiAgentPluginManager->getDefinitions() as $id => $definition) {
        $options[$id] = $definition['label'] ?? $id;
      }
    }

    $form['enabled_agents'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Enabled agents'),
      '#description' => $this->t('Only checked agents will have persistent state across conversation turns.'),
      '#options' => $options,
      '#default_value' => $config->get('enabled_agents') ?: [],
    ];

    $form['max_history_messages'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum history messages'),
      '#description' => $this->t('Total messages to keep in persisted state. Older middle turns are dropped when this limit is exceeded.'),
      '#default_value' => $config->get('max_history_messages') ?: 100,
      '#min' => 10,
      '#required' => TRUE,
    ];

    $form['keep_recent_turns'] = [
      '#type' => 'number',
      '#title' => $this->t('Keep recent turns'),
      '#description' => $this->t('Always preserve this many recent conversation turns regardless of trimming.'),
      '#default_value' => $config->get('keep_recent_turns') ?: 5,
      '#min' => 1,
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $enabled = array_values(array_filter($form_state->getValue('enabled_agents')));

    $this->config('daedalus_ai_memory.settings')
      ->set('enabled_agents', $enabled)
      ->set('max_history_messages', (int) $form_state->getValue('max_history_messages'))
      ->set('keep_recent_turns', (int) $form_state->getValue('keep_recent_turns'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
