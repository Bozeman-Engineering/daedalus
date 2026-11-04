<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_test\Plugin\AiProvider;

use Drupal\ai\Attribute\AiProvider;
use Drupal\ai\Base\AiProviderClientBase;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatInterface;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_ai\Provider\ContextCeilingInterface;
use Drupal\daedalus_ai_test\ChatCapture;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A scripted chat provider: no network call, the capture decides the reply.
 *
 * A test points an assistant or an agent run at this provider (provider
 * id daedalus_ai_test_stub, model stub) to drive the genuine runner and
 * prompt-assembly pipeline without an API key. Unscripted, the reply is a
 * canned text with no tool calls, so the loop finishes on the first leg;
 * a test that scripts replies on the daedalus_ai_test.chat_capture
 * service (ChatCapture::setScript()) can make a leg answer a tool call
 * the wrapper then really executes, or a distinct text for a tagged
 * call. What the provider was handed (the assembled ChatInput, the tags
 * the call carries after the pre-generate event, with the operation type
 * the proxy adds, the configuration on the instance) is recorded in the
 * same service, per call, for tests that assert on the assembled prompt.
 *
 * It declares a context ceiling only when a test states one under the
 * state key CONTEXT_CEILING_STATE, so a test of the hard line can bound
 * the model while every other test runs with no ceiling (drupal/ai's
 * placeholder maximum input, which the segment budget ignores).
 */
#[AiProvider(
  id: 'daedalus_ai_test_stub',
  label: new TranslatableMarkup('Stub (daedalus_ai_test)'),
)]
class StubProvider extends AiProviderClientBase implements ChatInterface, ContextCeilingInterface {

  /**
   * The model this provider advertises first, the one a test runs by default.
   */
  public const MODEL_ID = 'stub';

  /**
   * A second model id the provider lists, for a test of a model switch.
   *
   * The chat ignores which model is asked for; the capture records the id
   * per call, and the ledger row names it, so a test can tell which model
   * a leg ran on without the reply differing.
   */
  public const ALT_MODEL_ID = 'stub-alt';

  /**
   * The state key a test sets the context ceiling under (an integer).
   */
  public const CONTEXT_CEILING_STATE = 'daedalus_ai_test.context_ceiling';

  /**
   * The canned assistant response returned in place of an LLM completion.
   */
  public const RESPONSE_TEXT = 'Stub reply from daedalus_ai_test; no LLM was called.';

  /**
   * The capture shared with the test.
   */
  protected ChatCapture $chatCapture;

  /**
   * The state the ceiling is read from.
   */
  protected StateInterface $state;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->chatCapture = $container->get('daedalus_ai_test.chat_capture');
    $instance->state = $container->get('state');
    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * An instance built without its container (a unit fixture) has no state
   * to read and declares no ceiling.
   */
  public function contextCeiling(string $model_id): ?int {
    if (!isset($this->state)) {
      return NULL;
    }
    $ceiling = $this->state->get(self::CONTEXT_CEILING_STATE);
    return $ceiling === NULL ? NULL : (int) $ceiling;
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
    return [self::MODEL_ID => 'Stub', self::ALT_MODEL_ID => 'Stub (alternate)'];
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
   * Answers a chat leg with the capture's scripted reply.
   *
   * Its own docblock rather than the interface's: ChatInterface names the
   * input class under Drupal\ai\Operation\Chat, a namespace that does not
   * exist, so an inherited docblock types the parameter as a missing class.
   *
   * @param array|string|\Drupal\ai\OperationType\Chat\ChatInput $input
   *   The chat array, string or ChatInput.
   * @param string $model_id
   *   The model id to use.
   * @param array $tags
   *   Extra tags to set.
   *
   * @return \Drupal\ai\OperationType\Chat\ChatOutput
   *   The scripted output.
   */
  public function chat(array|string|ChatInput $input, string $model_id, array $tags = []): ChatOutput {
    $reply = NULL;
    if ($input instanceof ChatInput) {
      // The tags a leg really carries are the plugin's, not the argument's:
      // the provider proxy sets them from the caller's before the
      // pre-generate event and again from the event after it (a listener
      // may add one, as the agent runner's drift tag), then invokes this
      // method with the caller's original argument and feeds the plugin's
      // tags to the post-response event the usage ledger reads. Record
      // what the ledger sees; the argument alone is the unproxied case.
      $tags = $this->getTags() ?: $tags;
      $this->chatCapture->record($input, $model_id, $tags, $this->getConfiguration());
      $reply = $this->chatCapture->scriptedReply($input, $model_id, $tags);
    }
    // Unscripted, a plain assistant message with no tool calls: the agent
    // runner treats the turn as finished and does not loop. The usage is
    // the capture's scripted DTO (a fixed one unless a test set its own),
    // so the usage ledger and the segment window have numbers to read off
    // a stub leg.
    return new ChatOutput(
      $reply ?? new ChatMessage('assistant', self::RESPONSE_TEXT),
      ['daedalus_ai_test_stub' => TRUE],
      [],
      $this->chatCapture->usage(),
    );
  }

}
