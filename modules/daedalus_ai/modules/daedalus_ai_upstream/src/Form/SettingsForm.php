<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_upstream\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\daedalus_ai_upstream\Plugin\AiProvider\DaedalusAnthropicProvider;

/**
 * The settings of the interim Anthropic provider plugin.
 *
 * The replay toggle is off by default because it trades something the
 * ledger has not measured yet; the thinking display is omitted by default
 * because it is a diagnostic a production site's records do without. The
 * form exists so a site can flip either and read the difference.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [DaedalusAnthropicProvider::SETTINGS];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'daedalus_ai_upstream_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(DaedalusAnthropicProvider::SETTINGS);

    $form['deprecated'] = [
      '#markup' => '<p>' . $this->t("This module is deprecated by design: the Anthropic (Daedalus upstream) provider plugin carries the context spine's accelerators until they land in drupal/ai and ai_provider_anthropic, then retires. The key, host and prompt cache settings are the Anthropic provider's own.") . '</p>',
    ];
    $form['replay_thinking'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Replay thinking blocks'),
      '#description' => $this->t("Send each assistant turn's thinking blocks (with their signatures) back on later legs, so the model keeps its reasoning across a tool loop. The request also asks the API to drop a block whose conversation prefix changed (a segment boundary re-sending a turn) instead of failing. Off by default until the ledger shows what re-thinking each leg costs."),
      '#default_value' => (bool) $config->get('replay_thinking'),
    ];
    $form['thinking_display'] = [
      '#type' => 'select',
      '#title' => $this->t('Thinking display'),
      '#description' => $this->t("What the thinking blocks of a response carry. Omitted (the API's default on the models that think by default) returns them with empty text; summarized returns the model's reasoning summary, which the transcript keeps and the logs show. Visibility only: the model thinks and is billed the same either way. Sent when the request's thinking is adaptive; a budgeted (enabled) thinking mode is left as configured."),
      '#options' => [
        DaedalusAnthropicProvider::DISPLAY_OMITTED => $this->t('Omitted'),
        DaedalusAnthropicProvider::DISPLAY_SUMMARIZED => $this->t('Summarized'),
      ],
      '#default_value' => $config->get('thinking_display') ?? DaedalusAnthropicProvider::DISPLAY_OMITTED,
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config(DaedalusAnthropicProvider::SETTINGS)
      ->set('replay_thinking', (bool) $form_state->getValue('replay_thinking'))
      ->set('thinking_display', (string) $form_state->getValue('thinking_display'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
