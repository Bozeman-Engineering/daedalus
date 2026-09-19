<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai_dev_tools\Plugin\AiProvider;

use Drupal\ai\Attribute\AiProvider;
use Drupal\ai\Base\AiProviderClientBase;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatInterface;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_blueprint_ai_dev_tools\PromptCapture;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Records the assembled chat input instead of calling a real LLM.
 *
 * The prompt-audit command loads the active assistant, points its llm_provider
 * at this provider, and drives the genuine prompt-assembly pipeline. By the
 * time chat() runs, the agent runner, the BuildSystemPromptEvent subscribers,
 * and the ProviderProxy system-prompt coupling have all run, so the ChatInput
 * received here is the complete prompt surface. We record it to the shared
 * PromptCapture service and return a tool-free response so the agent loop
 * finishes immediately without any network call.
 */
#[AiProvider(
  id: 'prompt_capture',
  label: new TranslatableMarkup('Prompt Capture (dev tools)'),
)]
class PromptCaptureProvider extends AiProviderClientBase implements ChatInterface {

  /**
   * The pseudo-model this provider advertises.
   *
   * Public so the prompt-audit command can set it as the assistant's llm_model
   * when swapping this provider in.
   */
  public const MODEL_ID = 'capture';

  /**
   * The canned assistant response returned in place of an LLM completion.
   */
  protected const RESPONSE_TEXT = 'Prompt captured by daedalus_blueprint_ai_dev_tools; no LLM was called.';

  /**
   * The prompt capture sink shared with the prompt-audit command.
   *
   * @var \Drupal\daedalus_blueprint_ai_dev_tools\PromptCapture
   */
  protected PromptCapture $promptCapture;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->promptCapture = $container->get('daedalus_blueprint_ai_dev_tools.prompt_capture');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getModelSettings(string $model_id, array $generalConfig = []): array {
    return $generalConfig;
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguredModels(?string $operation_type = NULL, array $capabilities = []): array {
    return [self::MODEL_ID => 'Prompt Capture'];
  }

  /**
   * {@inheritdoc}
   */
  public function isUsable(?string $operation_type = NULL, array $capabilities = []): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedOperationTypes(): array {
    return ['chat'];
  }

  /**
   * {@inheritdoc}
   */
  public function setAuthentication(mixed $authentication): void {
    // No authentication: this provider never contacts an external service.
  }

  /**
   * {@inheritdoc}
   */
  public function chat(array|string|ChatInput $input, string $model_id, array $tags = []): ChatOutput {
    if ($input instanceof ChatInput) {
      $this->promptCapture->record($input, $model_id, $tags, $this->getConfiguration());
    }
    // Return a plain assistant message with no tool calls so the agent runner
    // treats the turn as finished and does not loop.
    $message = new ChatMessage('assistant', self::RESPONSE_TEXT);
    return new ChatOutput($message, ['prompt_capture' => TRUE], []);
  }

}
