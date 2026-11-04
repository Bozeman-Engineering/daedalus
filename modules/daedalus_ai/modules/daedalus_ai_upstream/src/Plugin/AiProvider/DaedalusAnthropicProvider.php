<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_upstream\Plugin\AiProvider;

use Anthropic\Core\Exceptions\APIException;
use Anthropic\Messages\Message;
use Anthropic\Messages\MessageCreateParams;
use Anthropic\Messages\MessageParam;
use Anthropic\Messages\RedactedThinkingBlock;
use Anthropic\Messages\RedactedThinkingBlockParam;
use Anthropic\Messages\TextBlockParam;
use Anthropic\Messages\ThinkingBlock;
use Anthropic\Messages\ThinkingBlockParam;
use Anthropic\Messages\ThinkingConfigAdaptive;
use Anthropic\RequestOptions;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\AiProvider;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\OutputInterface;
use Drupal\ai_provider_anthropic\Plugin\AiProvider\AnthropicProvider;
use Drupal\daedalus_ai\Provider\CacheBreakpointsInterface;
use Drupal\daedalus_ai\Provider\ContextCeilingInterface;
use Drupal\daedalus_ai\Provider\MidConversationSystemInterface;
use Drupal\daedalus_ai\Provider\OpaqueContentInterface;
use Drupal\daedalus_ai\Provider\RenderingPlan;
use Symfony\Component\Yaml\Yaml;

/**
 * The contrib Anthropic provider plus the context spine's accelerators.
 *
 * Deprecated by design: every behavior this subclass adds is proposed
 * upstream to ai_provider_anthropic and drupal/ai, and the plugin retires
 * when those land. Until then it is the spine's landing on the installed
 * base, reached through the same key, host and cache settings as the
 * contrib provider (it reads ai_provider_anthropic.settings and the contrib
 * api_defaults) so a site switches by pointing the chat defaults at it.
 *
 * With no RenderingPlan on the ChatInput (any other caller on the site) it
 * behaves exactly as the contrib provider. With a plan it renders the
 * spine's orders instead of the contrib defaults:
 * - the stable prefix gets one cache breakpoint on the system text block
 *   at the plan's prefix TTL, and each planned message index gets one on
 *   its last content block; the contrib top-level automatic marker is not
 *   sent (the spine places the tail marker itself);
 * - a role=system message in the history is passed through as a
 *   mid-conversation system message in messages[] when the Messages API
 *   allows one there (directly after a user message), never folded into
 *   the top-level system prompt; one the API would refuse (after an
 *   assistant message, the agent wrapper's "required tools" reminder) is
 *   sent as the universal form, a user message carrying the text in a
 *   <system-reminder> wrapper;
 * - the opaque content on the plan (the thinking blocks the previous legs
 *   answered) is replayed verbatim ahead of the assistant text and tool
 *   calls when the replay_thinking setting is on, with the thinking
 *   binding beta asking the API to drop a block whose prefix changed
 *   instead of failing the request.
 *
 * Independent of the plan it sends thinking.display: summarized when the
 * thinking_display setting asks for it and the request's thinking is
 * adaptive (the reasoning summary then rides the response's thinking
 * blocks; a diagnostic, free of tokens), fills TokenUsageDto::$cachedWrite
 * from the response usage, answers the thinking blocks of a response as
 * opaque content, and answers the model's maximum input (from the Models
 * API) as the context ceiling and as getMaxInputTokens().
 */
#[AiProvider(
  id: 'daedalus_anthropic',
  label: new TranslatableMarkup('Anthropic (Daedalus upstream)'),
)]
class DaedalusAnthropicProvider extends AnthropicProvider implements CacheBreakpointsInterface, MidConversationSystemInterface, OpaqueContentInterface, ContextCeilingInterface {

  /**
   * The contrib module whose settings and api defaults this plugin reads.
   */
  public const UPSTREAM_MODULE = 'ai_provider_anthropic';

  /**
   * This module's own settings: the replay toggle and the thinking display.
   */
  public const SETTINGS = 'daedalus_ai_upstream.settings';

  /**
   * The beta that lets thinking.block_binding ask for drop_block.
   */
  public const BETA_THINKING_BINDING = 'thinking-binding-controls-2026-08-01';

  /**
   * The thinking_display value that leaves the API's default (empty text).
   */
  public const DISPLAY_OMITTED = 'omitted';

  /**
   * The thinking_display value that asks for the reasoning summary.
   */
  public const DISPLAY_SUMMARIZED = 'summarized';

  /**
   * The Messages API's limit on cache breakpoints per request.
   */
  public const MAX_BREAKPOINTS = 4;

  /**
   * The universal rendering of an operator instruction inside a user turn.
   *
   * The same wrapper the context spine's renderer uses at the floor; a
   * provider with the mid-conversation capability still needs it for the
   * one placement the API refuses (a system message after an assistant
   * message).
   */
  public const REMINDER_OPEN = "<system-reminder>\n";

  public const REMINDER_CLOSE = "\n</system-reminder>";

  /**
   * The models that take a role=system message inside messages[].
   *
   * Claude Opus 5 and 5.5, Opus 4.8, Fable 5 and 5.1, Mythos 5 and 5.1,
   * Sonnet 5.5 and Haiku 5.5; not Sonnet 5, not the 4.7-and-earlier
   * families.
   */
  private const MID_CONVERSATION_SYSTEM_MODELS = '/^claude-(opus-5|opus-4-8|fable-5|mythos-5|sonnet-5-5|haiku-5-5)/i';

  /**
   * The plan of the request being built, NULL when the caller attached none.
   */
  private ?RenderingPlan $plan = NULL;

  /**
   * The beta names the request being built needs on its anthropic-beta header.
   *
   * @var string[]
   */
  private array $betas = [];

  /**
   * Whether the request being built replays at least one thinking block.
   */
  private bool $replayed = FALSE;

  /**
   * {@inheritdoc}
   *
   * The contrib provider's settings (key, host, cache, models cache TTL):
   * this plugin is that provider under another id, not a second account.
   */
  public function getConfig(): ImmutableConfig {
    return $this->configFactory->get(self::UPSTREAM_MODULE . '.settings');
  }

  /**
   * {@inheritdoc}
   *
   * The contrib provider's api_defaults: same models, same configuration.
   */
  public function getApiDefinition(): array {
    $definition_file = $this->moduleHandler->getModule(self::UPSTREAM_MODULE)->getPath() . '/definitions/api_defaults.yml';
    try {
      return Yaml::parseFile($definition_file);
    }
    catch (\Exception $e) {
      throw new \RuntimeException('Failed to parse API definition file ' . $definition_file . ': ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function supportedCacheTtls(string $model_id): array {
    return [RenderingPlan::TTL_5M, RenderingPlan::TTL_1H];
  }

  /**
   * {@inheritdoc}
   */
  public function supportsMidConversationSystem(string $model_id): bool {
    return (bool) preg_match(self::MID_CONVERSATION_SYSTEM_MODELS, $model_id);
  }

  /**
   * {@inheritdoc}
   *
   * The thinking and redacted_thinking blocks of the response, in order,
   * as the wire arrays a later request replays (signatures included);
   * NULL when the response carried none or was streamed (the raw output is
   * then the stream, not a Message).
   */
  public function opaqueContentOf(OutputInterface $output): ?array {
    $raw = method_exists($output, 'getRawOutput') ? $output->getRawOutput() : NULL;
    if (!$raw instanceof Message) {
      return NULL;
    }
    $blocks = [];
    foreach ($raw->content as $block) {
      if ($block instanceof ThinkingBlock) {
        $blocks[] = ['type' => 'thinking', 'thinking' => $block->thinking, 'signature' => $block->signature];
      }
      elseif ($block instanceof RedactedThinkingBlock) {
        $blocks[] = ['type' => 'redacted_thinking', 'data' => $block->data];
      }
    }
    return $blocks === [] ? NULL : $blocks;
  }

  /**
   * {@inheritdoc}
   */
  public function contextCeiling(string $model_id): ?int {
    return $this->modelMaxInputTokens($model_id);
  }

  /**
   * {@inheritdoc}
   *
   * The real figure from the Models API instead of drupal/ai's 1024
   * placeholder, so the segment budget's model bound bites.
   */
  public function getMaxInputTokens(string $model_id): int {
    return $this->modelMaxInputTokens($model_id) ?? parent::getMaxInputTokens($model_id);
  }

  /**
   * The model's maximum input tokens from the Models API, cached a day.
   *
   * @return int|null
   *   The figure, or NULL when the API does not state one or could not be
   *   reached (logged; a missing ceiling is "no fact", never a guess).
   */
  protected function modelMaxInputTokens(string $model_id): ?int {
    $cache_key = 'daedalus_ai_upstream:max_input:' . $model_id;
    $cached = $this->cacheBackend->get($cache_key);
    if ($cached && isset($cached->data)) {
      return $cached->data ?: NULL;
    }
    $this->ensureNativeClient();
    try {
      $max_input = $this->nativeClient->getClient()->models->retrieve($model_id)->maxInputTokens;
    }
    catch (APIException $e) {
      $this->logWarning('Failed to read the maximum input of @model: @error', [
        '@model' => $model_id,
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
    $this->cacheBackend->set($cache_key, $max_input ?? 0, time() + 86400, ['ai_provider_anthropic:capabilities']);
    return $max_input;
  }

  /**
   * This module's settings: the replay toggle and the thinking display.
   *
   * @return array{replay_thinking?: bool, thinking_display?: string}
   *   The settings.
   */
  protected function upstreamSettings(): array {
    return $this->configFactory->get(self::SETTINGS)->get() ?: [];
  }

  /**
   * {@inheritdoc}
   */
  protected function buildMessageCreateParams(ChatInput $input, string $model_id): MessageCreateParams {
    $this->plan = RenderingPlan::fromInput($input);
    $this->betas = [];
    $this->replayed = FALSE;
    $params = parent::buildMessageCreateParams($input, $model_id);
    if ($this->plan !== NULL) {
      // The prefix breakpoint: the system prompt as one text block
      // carrying the plan's TTL. resolvePromptCacheSettings() answered
      // "off" to the parent, so it sent the prompt as a bare string and no
      // top-level marker; both are the spine's to place now.
      $prefix_ttl = $this->plan->prefixTtl();
      $system_prompt = $input->getSystemPrompt();
      if ($prefix_ttl !== NULL && !empty($system_prompt)) {
        $params = $params->withSystem([
          TextBlockParam::with(text: $system_prompt, cacheControl: $this->buildCacheControl($prefix_ttl)),
        ]);
      }
      $breakpoints = count($this->plan->breakpoints()) + ($prefix_ttl !== NULL ? 1 : 0);
      if ($breakpoints > self::MAX_BREAKPOINTS) {
        throw new \InvalidArgumentException(sprintf('The rendering plan asks for %d cache breakpoints; the Messages API takes at most %d per request.', $breakpoints, self::MAX_BREAKPOINTS));
      }
    }
    return $this->withThinkingControls($params);
  }

  /**
   * Composes the thinking parameter: the display and the replay binding.
   *
   * Both ride the one thinking parameter. The display (thinking_display
   * set to summarized) asks the API to return the reasoning summary as the
   * text of the thinking blocks instead of the empty text the API's
   * default (omitted) answers on the models that think by default; it
   * changes visibility only, the thinking is billed the same. Replayed
   * thinking rides with the binding control so a block whose prefix
   * changed (a segment boundary re-sending a turn) is dropped by the API
   * instead of failing the request on an enforced account. A request the
   * parent left without a thinking parameter (thinking_mode unset, the
   * model thinking by default) gets adaptive, which is what omitting the
   * parameter means on those models, under the parent's own thinking rules
   * (temperature 1, no top_p/top_k). An enabled (budgeted) mode is left as
   * the parent built it: the display is composed on adaptive thinking
   * only, the binding on either. Neither needs a plan: the display is a
   * site diagnostic, and the binding follows the replay, which does.
   */
  protected function withThinkingControls(MessageCreateParams $params): MessageCreateParams {
    $summarized = ($this->upstreamSettings()['thinking_display'] ?? self::DISPLAY_OMITTED) === self::DISPLAY_SUMMARIZED;
    if (!$summarized && !$this->replayed) {
      return $params;
    }
    $thinking = $params['thinking'] ?? NULL;
    if ($thinking === NULL) {
      $thinking = ThinkingConfigAdaptive::with();
      unset($params['topK'], $params['topP']);
      $params = $params->withTemperature(1.0);
    }
    if ($summarized && $thinking instanceof ThinkingConfigAdaptive) {
      $thinking = $thinking->withDisplay(self::DISPLAY_SUMMARIZED);
    }
    if ($this->replayed) {
      $thinking['block_binding'] = ['prefix_mismatch_behavior' => 'drop_block'];
      $this->betas[] = self::BETA_THINKING_BINDING;
    }
    return $params->withThinking($thinking);
  }

  /**
   * {@inheritdoc}
   *
   * With a plan the parent places no marker: the prefix and tail
   * breakpoints are the plan's and buildMessageCreateParams() sets them.
   */
  protected function resolvePromptCacheSettings(): array {
    if ($this->plan !== NULL) {
      return [FALSE, RenderingPlan::TTL_5M];
    }
    return parent::resolvePromptCacheSettings();
  }

  /**
   * {@inheritdoc}
   *
   * With a plan a role=system message is history, never the top-level
   * system prompt: it stays in the messages for buildMessageContent().
   */
  protected function extractSystemPrompt(ChatInput $input): array {
    if ($this->plan !== NULL) {
      return ['', $input];
    }
    return parent::extractSystemPrompt($input);
  }

  /**
   * {@inheritdoc}
   */
  protected function buildMessageContent(ChatInput $input): array {
    if ($this->plan === NULL) {
      return parent::buildMessageContent($input);
    }
    return $this->buildPlannedContent($input, $this->plan);
  }

  /**
   * Renders the messages as the plan orders.
   *
   * Each message rides the parent's own block building (text, images, PDFs,
   * tool_use, tool_result) one at a time, so the two providers agree on
   * every block; this method decides placement, roles, cache markers and
   * the replayed opaque content.
   *
   * @return list<\Anthropic\Messages\MessageParam>
   *   The wire messages.
   */
  protected function buildPlannedContent(ChatInput $input, RenderingPlan $plan): array {
    $replay = !empty($this->upstreamSettings()['replay_thinking']);
    // Wire entries: the MessageParam and the input index it came from.
    $wire = [];
    foreach (array_values($input->getMessages()) as $index => $message) {
      if (strtolower($message->getRole()) === 'system') {
        $previous = $wire === [] ? NULL : $wire[count($wire) - 1][0];
        $wire[] = $previous !== NULL && $previous['role'] === 'user'
          ? [self::textMessage('system', $message->getText()), $index]
          : [self::textMessage('user', self::REMINDER_OPEN . $message->getText() . self::REMINDER_CLOSE), $index];
        continue;
      }

      $param = parent::buildMessageContent(new ChatInput([$message]))[0];
      $opaque = $plan->opaqueAt($index);
      if ($replay && $opaque !== NULL && $param['role'] === 'assistant') {
        $param = $param->withContent([...self::opaqueBlocks($opaque), ...$param['content']]);
        $this->replayed = TRUE;
      }
      $wire[] = [$param, $index];
    }

    foreach ($plan->breakpoints() as $index => $ttl) {
      $position = NULL;
      foreach ($wire as $at => [, $from]) {
        if ($from === $index) {
          $position = $at;
          break;
        }
      }
      if ($position === NULL) {
        throw new \InvalidArgumentException(sprintf('The rendering plan places a cache breakpoint on message %d, which the request does not carry.', $index));
      }
      $content = $wire[$position][0]['content'];
      $last = count($content) - 1;
      $content[$last] = $content[$last]->withCacheControl($this->buildCacheControl($ttl));
      $wire[$position][0] = $wire[$position][0]->withContent($content);
    }

    return array_map(static fn (array $entry) => $entry[0], $wire);
  }

  /**
   * A wire message of one text block.
   */
  protected static function textMessage(string $role, string $text): MessageParam {
    return MessageParam::with(content: [TextBlockParam::with(text: $text)], role: $role);
  }

  /**
   * The wire blocks of stored opaque content, as opaqueContentOf() stored it.
   *
   * @param array $opaque
   *   The arrays opaqueContentOf() answered.
   *
   * @return list<\Anthropic\Messages\ThinkingBlockParam|\Anthropic\Messages\RedactedThinkingBlockParam>
   *   The blocks to replay ahead of the assistant's text.
   */
  protected static function opaqueBlocks(array $opaque): array {
    $blocks = [];
    foreach ($opaque as $block) {
      $blocks[] = match ($block['type'] ?? NULL) {
        'thinking' => ThinkingBlockParam::with(signature: (string) ($block['signature'] ?? ''), thinking: (string) ($block['thinking'] ?? '')),
        'redacted_thinking' => RedactedThinkingBlockParam::with(data: (string) ($block['data'] ?? '')),
        default => throw new \InvalidArgumentException(sprintf('Unknown opaque content block type "%s".', (string) ($block['type'] ?? ''))),
      };
    }
    return $blocks;
  }

  /**
   * {@inheritdoc}
   *
   * The betas the planned request needs ride the anthropic-beta header.
   */
  public function buildMessageArguments(MessageCreateParams $params): array {
    $args = parent::buildMessageArguments($params);
    if ($this->betas !== []) {
      $args['requestOptions'] = RequestOptions::with(extraHeaders: [
        'anthropic-beta' => implode(',', array_values(array_unique($this->betas))),
      ]);
    }
    return $args;
  }

  /**
   * {@inheritdoc}
   *
   * Fills the DTO's cachedWrite (drupal/ai 1.5) from the response usage,
   * beside the metadata the parent already writes.
   */
  protected function parseMessageResponse(Message $message, ?ChatInput $input = NULL): ChatOutput {
    $output = parent::parseMessageResponse($message, $input);
    $usage = $output->getTokenUsage();
    if (property_exists($usage, 'cachedWrite') && $message->usage->cacheCreationInputTokens !== NULL) {
      $usage->cachedWrite = (int) $message->usage->cacheCreationInputTokens;
    }
    return $output;
  }

}
