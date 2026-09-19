<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai_dev_tools;

use Drupal\ai\OperationType\Chat\ChatInput;

/**
 * Collects the fully-assembled chat input captured at the provider boundary.
 *
 * The prompt-audit Drush command swaps a capture provider in for the real LLM
 * provider, drives the genuine prompt-assembly pipeline, and reads the result
 * back from this service. The capture provider and the command share this same
 * service instance within the one Drush process, so it is the hand-off point
 * for the observed ChatInput. Nothing here is persisted.
 */
final class PromptCapture {

  /**
   * The captured chat input, or NULL if nothing has been recorded yet.
   *
   * @var \Drupal\ai\OperationType\Chat\ChatInput|null
   */
  protected ?ChatInput $input = NULL;

  /**
   * The model id the runner asked the provider to use.
   *
   * @var string|null
   */
  protected ?string $modelId = NULL;

  /**
   * The tags passed alongside the chat request.
   *
   * @var array
   */
  protected array $tags = [];

  /**
   * The provider configuration in effect at chat() time.
   *
   * @var array
   */
  protected array $providerConfiguration = [];

  /**
   * Whether a chat input has been recorded.
   *
   * @var bool
   */
  protected bool $captured = FALSE;

  /**
   * Records the assembled chat input captured at the provider boundary.
   *
   * @param \Drupal\ai\OperationType\Chat\ChatInput $input
   *   The fully-assembled chat input handed to the provider's chat() call.
   * @param string $model_id
   *   The model id the runner requested.
   * @param array $tags
   *   The tags passed alongside the chat request.
   * @param array $provider_configuration
   *   The provider's configuration at chat() time (max_tokens, temperature,
   *   ...), so callers can assert what the runner actually put on the
   *   provider instance, not just what the config entities say.
   */
  public function record(ChatInput $input, string $model_id, array $tags = [], array $provider_configuration = []): void {
    $this->input = $input;
    $this->modelId = $model_id;
    $this->tags = $tags;
    $this->providerConfiguration = $provider_configuration;
    $this->captured = TRUE;
  }

  /**
   * Determines whether a chat input has been captured.
   *
   * @return bool
   *   TRUE if a chat input has been recorded.
   */
  public function hasCapture(): bool {
    return $this->captured;
  }

  /**
   * Gets the captured chat input.
   *
   * @return \Drupal\ai\OperationType\Chat\ChatInput|null
   *   The captured chat input, or NULL if nothing has been recorded.
   */
  public function getInput(): ?ChatInput {
    return $this->input;
  }

  /**
   * Gets the model id the runner requested.
   *
   * @return string|null
   *   The model id, or NULL if nothing has been recorded.
   */
  public function getModelId(): ?string {
    return $this->modelId;
  }

  /**
   * Gets the tags passed alongside the chat request.
   *
   * @return array
   *   The request tags.
   */
  public function getTags(): array {
    return $this->tags;
  }

  /**
   * Gets the provider configuration in effect at chat() time.
   *
   * @return array
   *   The provider configuration.
   */
  public function getProviderConfiguration(): array {
    return $this->providerConfiguration;
  }

  /**
   * Resets the captured state.
   */
  public function reset(): void {
    $this->input = NULL;
    $this->modelId = NULL;
    $this->tags = [];
    $this->providerConfiguration = [];
    $this->captured = FALSE;
  }

}
