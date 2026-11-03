<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_test;

use Drupal\ai\Dto\TokenUsageDto;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;

/**
 * What the stub provider was handed, readable by the test that drove it.
 *
 * The stub provider and the test share this one service instance within a
 * process, so a test can drive the genuine prompt-assembly pipeline into
 * the stub and read the fully-assembled ChatInput, the model id, the tags
 * and the provider configuration back (the latest call through the
 * getters, every call since the last reset through calls()), script the
 * usage the stub reports on its replies, and script the replies
 * themselves (a tool call, a distinct text) per call. Nothing is
 * persisted.
 */
final class ChatCapture {

  /**
   * The usage a stub reply reports unless a test scripts its own.
   */
  public const DEFAULT_INPUT_TOKENS = 1000;

  public const DEFAULT_OUTPUT_TOKENS = 200;

  public const DEFAULT_CACHED_TOKENS = 500;

  /**
   * The captured chat input, or NULL if nothing has been recorded yet.
   */
  private ?ChatInput $input = NULL;

  /**
   * Every call recorded since the last reset, oldest first.
   *
   * @var array<int, array{input: \Drupal\ai\OperationType\Chat\ChatInput, model_id: string, tags: string[], configuration: array}>
   */
  private array $calls = [];

  /**
   * The scripted usage, or NULL for the default.
   */
  private ?TokenUsageDto $usage = NULL;

  /**
   * The reply script, or NULL for the stub's canned text on every call.
   *
   * @var \Closure(ChatInput, string, string[]): ?ChatMessage|null
   */
  private ?\Closure $script = NULL;

  /**
   * The model id the runner asked the provider to use.
   */
  private ?string $modelId = NULL;

  /**
   * The tags passed alongside the chat request.
   */
  private array $tags = [];

  /**
   * The provider configuration in effect at chat() time.
   */
  private array $providerConfiguration = [];

  /**
   * Records what the provider was handed.
   *
   * @param \Drupal\ai\OperationType\Chat\ChatInput $input
   *   The fully-assembled chat input handed to chat().
   * @param string $modelId
   *   The model id the runner requested.
   * @param array $tags
   *   The tags passed alongside the chat request.
   * @param array $providerConfiguration
   *   The provider's configuration at chat() time (max_tokens and the
   *   rest), so a test can assert what the runner actually put on the
   *   provider instance, not just what the config entities say.
   */
  public function record(ChatInput $input, string $modelId, array $tags, array $providerConfiguration): void {
    $this->input = $input;
    $this->modelId = $modelId;
    $this->tags = $tags;
    $this->providerConfiguration = $providerConfiguration;
    $this->calls[] = [
      'input' => $input,
      'model_id' => $modelId,
      'tags' => $tags,
      'configuration' => $providerConfiguration,
    ];
  }

  /**
   * Whether a chat input has been captured.
   */
  public function hasCapture(): bool {
    return $this->input !== NULL;
  }

  /**
   * Every call recorded since the last reset, oldest first.
   *
   * A test that drives several legs or turns reads the whole sequence
   * here (the getters answer the latest call only): each call's input,
   * model id, tags and provider configuration as the stub was handed them.
   *
   * @return array<int, array{input: \Drupal\ai\OperationType\Chat\ChatInput, model_id: string, tags: string[], configuration: array}>
   *   The calls.
   */
  public function calls(): array {
    return $this->calls;
  }

  /**
   * The captured chat input, or NULL if nothing has been recorded.
   */
  public function getInput(): ?ChatInput {
    return $this->input;
  }

  /**
   * The model id the runner requested, or NULL if nothing has been recorded.
   */
  public function getModelId(): ?string {
    return $this->modelId;
  }

  /**
   * The tags passed alongside the chat request.
   */
  public function getTags(): array {
    return $this->tags;
  }

  /**
   * The provider configuration in effect at chat() time.
   */
  public function getProviderConfiguration(): array {
    return $this->providerConfiguration;
  }

  /**
   * Scripts the usage the stub provider reports on its next replies.
   *
   * NULL restores the fixed default usage.
   */
  public function setUsage(?TokenUsageDto $usage): void {
    $this->usage = $usage;
  }

  /**
   * The usage the stub provider reports on a reply.
   *
   * A fixed, round-numbered DTO unless a test scripted its own: a
   * ledger test can price it and a segment test can grow it past a
   * budget without either depending on the prompt's size.
   */
  public function usage(): TokenUsageDto {
    return $this->usage ?? new TokenUsageDto(
      input: self::DEFAULT_INPUT_TOKENS,
      output: self::DEFAULT_OUTPUT_TOKENS,
      total: self::DEFAULT_INPUT_TOKENS + self::DEFAULT_OUTPUT_TOKENS,
      cached: self::DEFAULT_CACHED_TOKENS,
    );
  }

  /**
   * Scripts the replies the stub provider answers.
   *
   * The script is asked on every call with the input, the model id and
   * the tags, and answers the assistant message to reply with (a text, a
   * tool call the wrapper then executes) or NULL for the stub's canned
   * text. A test that needs a multi-leg loop scripts a tool call for one
   * leg and lets the rest answer text; one that needs a distinct answer
   * for a tagged call (a carry-over request) reads the tags. The usage
   * reported with a scripted reply is still usage(). NULL removes the
   * script.
   *
   * @param callable(ChatInput, string, string[]): ?ChatMessage|null $script
   *   The script.
   */
  public function setScript(?callable $script): void {
    $this->script = $script === NULL ? NULL : \Closure::fromCallable($script);
  }

  /**
   * The scripted reply for a call, or NULL for the stub's canned text.
   *
   * @param \Drupal\ai\OperationType\Chat\ChatInput $input
   *   The call's input.
   * @param string $modelId
   *   The model id.
   * @param string[] $tags
   *   The call's tags.
   */
  public function scriptedReply(ChatInput $input, string $modelId, array $tags): ?ChatMessage {
    if ($this->script === NULL) {
      return NULL;
    }
    $reply = ($this->script)($input, $modelId, $tags);
    if ($reply !== NULL && !$reply instanceof ChatMessage) {
      throw new \UnexpectedValueException(sprintf('A reply script answers a ChatMessage or NULL, got %s.', get_debug_type($reply)));
    }
    return $reply;
  }

  /**
   * Forgets the capture, the calls, the scripted usage and the script.
   */
  public function reset(): void {
    $this->input = NULL;
    $this->calls = [];
    $this->modelId = NULL;
    $this->tags = [];
    $this->providerConfiguration = [];
    $this->usage = NULL;
    $this->script = NULL;
  }

}
