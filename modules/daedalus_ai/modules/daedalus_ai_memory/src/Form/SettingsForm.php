<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_memory\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\daedalus_ai\Provider\RenderingPlan;
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

    $form['cache_ttl'] = [
      '#type' => 'select',
      '#title' => $this->t('Prompt cache TTL'),
      '#description' => $this->t('How long a provider that places cache breakpoints on request is asked to keep the conversation cached. Five minutes suits legs seconds apart; an hour keeps the conversation warm across a pause between messages at a higher write price.'),
      '#options' => array_combine(RenderingPlan::TTLS, RenderingPlan::TTLS),
      '#default_value' => $config->get('cache_ttl') ?? RenderingPlan::TTL_5M,
    ];

    $form['segments'] = [
      '#type' => 'details',
      '#title' => $this->t('Segments'),
      '#description' => $this->t('The model-facing transcript is held in segments that are never edited. A segment closes when a finished turn measured past the budget, or when a reframing skill is invoked past the minimum; a carry-over opens the next one. The user-visible chat is untouched.'),
      '#open' => TRUE,
    ];
    $form['segments']['segment_budget_tokens'] = [
      '#type' => 'number',
      '#title' => $this->t('Segment budget (tokens)'),
      '#description' => $this->t("The prompt size, as the provider billed the last leg, past which a finished turn closes the segment. The provider's ceiling and the model's maximum input less the headroom bound it from above; the minimum below bounds it from below."),
      '#default_value' => $config->get('segment_budget_tokens'),
      '#min' => 1,
      '#required' => TRUE,
    ];
    $form['segments']['segment_budget_minimum_tokens'] = [
      '#type' => 'number',
      '#title' => $this->t('Segment budget minimum (tokens)'),
      '#description' => $this->t("The floor under the budget and every per-model budget. A budget under the prompt's own floor (tools, system prompt and catalog) would close every finished turn and pay a carry-over call each time; a value set under this minimum by other means is raised to it, and this form refuses one."),
      '#default_value' => $config->get('segment_budget_minimum_tokens'),
      '#min' => 0,
      '#required' => TRUE,
    ];
    $form['segments']['segment_budget_overrides'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Per-model budgets'),
      '#description' => $this->t('One <code>model: tokens</code> line per model whose budget differs from the default, for example <code>claude-haiku-5-5: 40000</code>.'),
      '#default_value' => implode("\n", array_map(
        static fn (array $override): string => $override['model'] . ': ' . $override['tokens'],
        $config->get('segment_budget_overrides') ?? [],
      )),
      '#rows' => 3,
    ];
    $form['segments']['segment_headroom_tokens'] = [
      '#type' => 'number',
      '#title' => $this->t("Headroom below the model's window (tokens)"),
      '#description' => $this->t("Kept free below the provider's bounds (the model's context ceiling and maximum input), since a whole turn may grow the prompt after the last measurement. It is also the turn's margin under the window: a turn whose last leg measured past the bounds less this headroom is ended before the provider refuses, and the carry-over call runs in the room it leaves."),
      '#default_value' => $config->get('segment_headroom_tokens'),
      '#min' => 0,
      '#required' => TRUE,
    ];
    $form['segments']['reframe_minimum_tokens'] = [
      '#type' => 'number',
      '#title' => $this->t('Reframe minimum (tokens)'),
      '#description' => $this->t('A reframing skill (design) closes the segment only once the last leg measured past this size.'),
      '#default_value' => $config->get('reframe_minimum_tokens'),
      '#min' => 0,
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $minimum = (int) $form_state->getValue('segment_budget_minimum_tokens');
    $budget = (int) $form_state->getValue('segment_budget_tokens');
    if ($budget < $minimum) {
      $form_state->setErrorByName('segment_budget_tokens', $this->t('The segment budget of @budget tokens is under the minimum of @minimum tokens.', [
        '@budget' => $budget,
        '@minimum' => $minimum,
      ]));
    }
    try {
      $overrides = self::parseOverrides((string) $form_state->getValue('segment_budget_overrides'));
    }
    catch (\InvalidArgumentException $exception) {
      $form_state->setErrorByName('segment_budget_overrides', $exception->getMessage());
      return;
    }
    foreach ($overrides as $override) {
      if ($override['tokens'] < $minimum) {
        $form_state->setErrorByName('segment_budget_overrides', $this->t('The budget of @tokens tokens for @model is under the minimum of @minimum tokens.', [
          '@tokens' => $override['tokens'],
          '@model' => $override['model'],
          '@minimum' => $minimum,
        ]));
      }
    }
    $form_state->set('segment_budget_overrides', $overrides);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $enabled = array_values(array_filter($form_state->getValue('enabled_agents')));

    $this->config('daedalus_ai_memory.settings')
      ->set('enabled_agents', $enabled)
      ->set('cache_ttl', (string) $form_state->getValue('cache_ttl'))
      ->set('segment_budget_tokens', (int) $form_state->getValue('segment_budget_tokens'))
      ->set('segment_budget_minimum_tokens', (int) $form_state->getValue('segment_budget_minimum_tokens'))
      ->set('segment_budget_overrides', $form_state->get('segment_budget_overrides'))
      ->set('segment_headroom_tokens', (int) $form_state->getValue('segment_headroom_tokens'))
      ->set('reframe_minimum_tokens', (int) $form_state->getValue('reframe_minimum_tokens'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * The per-model budget rows from the textarea's lines.
   *
   * @param string $text
   *   One `model: tokens` line per model; blank lines are skipped.
   *
   * @return list<array{model: string, tokens: int}>
   *   The rows, in order.
   *
   * @throws \InvalidArgumentException
   *   When a line is not `model: tokens` with a positive token count, or a
   *   model is listed twice.
   */
  public static function parseOverrides(string $text): array {
    $overrides = [];
    $seen = [];
    foreach (preg_split('/\R/', $text) as $number => $line) {
      $line = trim($line);
      if ($line === '') {
        continue;
      }
      $parts = array_map('trim', explode(':', $line, 2));
      if (count($parts) !== 2 || $parts[0] === '' || !ctype_digit($parts[1]) || (int) $parts[1] < 1) {
        throw new \InvalidArgumentException(sprintf('Line %d must read "model: tokens" with a positive token count, got "%s".', $number + 1, $line));
      }
      if (isset($seen[$parts[0]])) {
        throw new \InvalidArgumentException(sprintf('Line %d lists %s a second time.', $number + 1, $parts[0]));
      }
      $seen[$parts[0]] = TRUE;
      $overrides[] = ['model' => $parts[0], 'tokens' => (int) $parts[1]];
    }
    return $overrides;
  }

}
