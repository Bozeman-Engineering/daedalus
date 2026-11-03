<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai_upstream\Unit\Fixture;

use Anthropic\Messages\Message;
use Anthropic\Messages\MessageCreateParams;
use Anthropic\Models\ModelCapabilities;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\daedalus_ai_upstream\Plugin\AiProvider\DaedalusAnthropicProvider;

/**
 * The provider plugin with its request builder exposed and no services.
 *
 * The settings and the Models API lookups (the maximum input, the
 * capabilities) are the things the builder reaches a service for; the
 * fixture answers them from test-set values so the unit test pins the
 * wire shapes without a container or a key.
 */
final class ExposedProvider extends DaedalusAnthropicProvider {

  /**
   * The settings the test scripts.
   *
   * @var array{replay_thinking?: bool, thinking_display?: string}
   */
  public array $settings = [];

  /**
   * The maximum input the scripted Models API answers.
   */
  public ?int $maxInput = NULL;

  /**
   * The capabilities the scripted Models API answers (NULL: unverifiable).
   */
  public ?ModelCapabilities $capabilities = NULL;

  /**
   * An instance built without the constructor, with a configuration.
   *
   * @param array $configuration
   *   The per-call configuration (max_tokens, temperature, ...).
   */
  public static function bare(array $configuration = []): self {
    $provider = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
    $provider->setConfiguration($configuration + ['max_tokens' => 1000]);
    return $provider;
  }

  /**
   * The typed request the plugin would send for an input.
   */
  public function build(ChatInput $input, string $model_id): MessageCreateParams {
    return $this->buildMessageCreateParams($input, $model_id);
  }

  /**
   * The named arguments and request options of a built request.
   */
  public function arguments(MessageCreateParams $params): array {
    return $this->buildMessageArguments($params);
  }

  /**
   * The ChatOutput parsed from a typed SDK message.
   */
  public function parse(Message $message, ?ChatInput $input = NULL): ChatOutput {
    return $this->parseMessageResponse($message, $input);
  }

  /**
   * {@inheritdoc}
   */
  protected function upstreamSettings(): array {
    return $this->settings;
  }

  /**
   * {@inheritdoc}
   */
  protected function modelMaxInputTokens(string $model_id): ?int {
    return $this->maxInput;
  }

  /**
   * {@inheritdoc}
   */
  protected function getModelCapabilities(string $model_id): ?ModelCapabilities {
    return $this->capabilities;
  }

}
