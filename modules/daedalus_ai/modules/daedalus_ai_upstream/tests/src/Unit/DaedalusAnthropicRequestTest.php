<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai_upstream\Unit;

// cspell:ignore toolu
use Anthropic\Messages\Message;
use Anthropic\Messages\MessageCreateParams;
use Anthropic\Models\ModelCapabilities;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutputInterface;
use Drupal\daedalus_ai\Provider\CacheBreakpointsInterface;
use Drupal\daedalus_ai\Provider\ContextCeilingInterface;
use Drupal\daedalus_ai\Provider\MidConversationSystemInterface;
use Drupal\daedalus_ai\Provider\OpaqueContentInterface;
use Drupal\daedalus_ai\Provider\RenderingPlan;
use Drupal\daedalus_ai\Provider\ReportedCostInterface;
use Drupal\daedalus_ai_upstream\Plugin\AiProvider\DaedalusAnthropicProvider;
use Drupal\Tests\daedalus_ai_upstream\Unit\Fixture\ExposedProvider;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the wire shapes the interim Anthropic provider sends for a plan.
 *
 * Everything is read off the typed MessageCreateParams through its JSON
 * form (what the SDK puts on the wire) and off the named arguments the
 * plugin hands the SDK (the beta header among them), through a subclass
 * that exposes the builder and answers settings and the Models API (the
 * maximum input, the capabilities) from test values.
 */
#[Group('daedalus_ai_upstream')]
class DaedalusAnthropicRequestTest extends UnitTestCase {

  /**
   * The plugin declares the four accelerators and no reported cost.
   */
  public function testCapabilities(): void {
    $provider = ExposedProvider::bare();
    $this->assertInstanceOf(CacheBreakpointsInterface::class, $provider);
    $this->assertInstanceOf(MidConversationSystemInterface::class, $provider);
    $this->assertInstanceOf(OpaqueContentInterface::class, $provider);
    $this->assertInstanceOf(ContextCeilingInterface::class, $provider);
    $this->assertNotInstanceOf(ReportedCostInterface::class, $provider, 'Anthropic direct reports no cost; the ledger prices it from the table.');

    $this->assertSame([RenderingPlan::TTL_5M, RenderingPlan::TTL_1H], $provider->supportedCacheTtls('claude-opus-5-5'));

    $supported = [
      'claude-opus-5-5',
      'claude-opus-5',
      'claude-opus-4-8',
      'claude-fable-5-1',
      'claude-sonnet-5-5',
      'claude-haiku-5-5',
    ];
    foreach ($supported as $model) {
      $this->assertTrue($provider->supportsMidConversationSystem($model), $model);
    }
    foreach (['claude-sonnet-5', 'claude-sonnet-4-6', 'claude-opus-4-7', 'claude-haiku-4-5'] as $model) {
      $this->assertFalse($provider->supportsMidConversationSystem($model), $model);
    }

    $this->assertNull($provider->contextCeiling('claude-opus-5-5'), 'No Models API figure, no ceiling.');
    $this->assertSame(1024, $provider->getMaxInputTokens('claude-opus-5-5'), 'drupal/ai\'s placeholder when the API states nothing.');
    $provider->maxInput = 1000000;
    $this->assertSame(1000000, $provider->contextCeiling('claude-opus-5-5'));
    $this->assertSame(1000000, $provider->getMaxInputTokens('claude-opus-5-5'));
  }

  /**
   * Without a plan the request is the contrib provider's.
   */
  public function testNoPlanIsContrib(): void {
    $provider = ExposedProvider::bare(['prompt_cache' => TRUE, 'prompt_cache_ttl' => '1h']);
    $input = new ChatInput([
      new ChatMessage('user', 'hello'),
      new ChatMessage('assistant', 'hi'),
      new ChatMessage('system', 'Reminder: use the tools.'),
    ]);
    $input->setSystemPrompt('You are the page builder.');

    $params = $provider->build($input, 'claude-opus-5-5');
    $wire = self::wire($params);

    $this->assertSame(['user', 'assistant'], array_column($wire['messages'], 'role'), 'The role=system message was promoted into the top-level system, as contrib does.');
    $this->assertSame('You are the page builder.', $wire['system'][0]['text'], 'The input\'s own system prompt wins over the promoted message.');
    $this->assertSame(['type' => 'ephemeral', 'ttl' => '1h'], $wire['system'][0]['cache_control']);
    $this->assertSame(['type' => 'ephemeral', 'ttl' => '1h'], $wire['cache_control'], 'Contrib\'s top-level automatic marker rides without a plan.');
    $this->assertArrayNotHasKey('requestOptions', $provider->arguments($params), 'No betas without a plan.');
  }

  /**
   * The prefix and message breakpoints are the plan's; no automatic marker.
   */
  public function testBreakpoints(): void {
    $provider = ExposedProvider::bare(['prompt_cache' => TRUE]);
    $input = new ChatInput([
      new ChatMessage('user', 'hello'),
      new ChatMessage('assistant', 'hi'),
      new ChatMessage('user', 'edit the page'),
    ]);
    $input->setSystemPrompt('You are the page builder.');
    RenderingPlan::empty()->withPrefixTtl(RenderingPlan::TTL_1H)->withBreakpoint(2, RenderingPlan::TTL_1H)->attachTo($input);

    $params = $provider->build($input, 'claude-opus-5-5');
    $wire = self::wire($params);

    $this->assertSame([
      [
        'type' => 'text',
        'text' => 'You are the page builder.',
        'cache_control' => ['type' => 'ephemeral', 'ttl' => '1h'],
      ],
    ], $wire['system']);
    $this->assertArrayNotHasKey('cache_control', $wire, 'The contrib top-level marker is not sent with a plan.');
    $this->assertArrayNotHasKey('cache_control', $wire['messages'][0]['content'][0]);
    $this->assertSame(['type' => 'ephemeral', 'ttl' => '1h'], $wire['messages'][2]['content'][0]['cache_control']);
    $this->assertArrayNotHasKey('requestOptions', $provider->arguments($params));

    // An empty plan: the spine asked for nothing, so nothing is placed,
    // even with the contrib cache toggle on.
    $input = new ChatInput([new ChatMessage('user', 'hello')]);
    $input->setSystemPrompt('You are the page builder.');
    RenderingPlan::empty()->attachTo($input);
    $wire = self::wire($provider->build($input, 'claude-opus-5-5'));
    $this->assertSame('You are the page builder.', $wire['system'], 'A bare string: no block, no marker.');
    $this->assertArrayNotHasKey('cache_control', $wire);
    $this->assertArrayNotHasKey('cache_control', $wire['messages'][0]['content'][0]);
  }

  /**
   * A 5m breakpoint rides as the API's default marker (no ttl key).
   */
  public function testFiveMinuteBreakpointOmitsTtl(): void {
    $provider = ExposedProvider::bare();
    $input = new ChatInput([new ChatMessage('user', 'hello')]);
    $input->setSystemPrompt('S');
    RenderingPlan::empty()->withPrefixTtl(RenderingPlan::TTL_5M)->withBreakpoint(0, RenderingPlan::TTL_5M)->attachTo($input);
    $wire = self::wire($provider->build($input, 'claude-opus-5-5'));
    $this->assertSame(['type' => 'ephemeral'], $wire['system'][0]['cache_control']);
    $this->assertSame(['type' => 'ephemeral'], $wire['messages'][0]['content'][0]['cache_control']);
  }

  /**
   * More breakpoints than the API takes is refused, never silently trimmed.
   */
  public function testTooManyBreakpoints(): void {
    $provider = ExposedProvider::bare();
    $messages = [];
    $plan = RenderingPlan::empty()->withPrefixTtl(RenderingPlan::TTL_5M);
    for ($i = 0; $i < 4; $i++) {
      $messages[] = new ChatMessage($i % 2 ? 'assistant' : 'user', "m$i");
      $plan = $plan->withBreakpoint($i, RenderingPlan::TTL_5M);
    }
    $input = new ChatInput($messages);
    $input->setSystemPrompt('S');
    $plan->attachTo($input);
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('5 cache breakpoints');
    $provider->build($input, 'claude-opus-5-5');
  }

  /**
   * Breakpoints land on the last block of a tool-result or tool-call message.
   */
  public function testBreakpointOnToolMessages(): void {
    $provider = ExposedProvider::bare();
    $call = new ChatMessage('assistant', '');
    $call->setTools([$this->toolCall('toolu_1', 'page_read', ['id' => 3])]);
    $result = new ChatMessage('tool', 'the print');
    $result->setToolsId('toolu_1');
    $input = new ChatInput([new ChatMessage('user', 'read it'), $call, $result]);
    $input->setSystemPrompt('S');
    RenderingPlan::empty()->withBreakpoint(1, RenderingPlan::TTL_5M)->withBreakpoint(2, RenderingPlan::TTL_1H)->attachTo($input);

    $wire = self::wire($provider->build($input, 'claude-opus-5-5'));
    $this->assertSame('tool_use', $wire['messages'][1]['content'][0]['type']);
    $this->assertSame(['id' => 3], $wire['messages'][1]['content'][0]['input']);
    $this->assertSame(['type' => 'ephemeral'], $wire['messages'][1]['content'][0]['cache_control']);
    $this->assertSame('user', $wire['messages'][2]['role']);
    $this->assertSame('tool_result', $wire['messages'][2]['content'][0]['type']);
    $this->assertSame('toolu_1', $wire['messages'][2]['content'][0]['tool_use_id']);
    $this->assertSame(['type' => 'ephemeral', 'ttl' => '1h'], $wire['messages'][2]['content'][0]['cache_control']);
  }

  /**
   * A role=system message is passed through where the API allows it.
   *
   * After a user message (a tool result counts) it rides as role=system in
   * messages[]; after an assistant message (the wrapper's "required tools"
   * reminder) the API would refuse it, so it rides as the universal form.
   * Neither is folded into the top-level system prompt and neither needs a
   * beta.
   */
  public function testMidConversationSystemPassthrough(): void {
    $provider = ExposedProvider::bare();
    $result = new ChatMessage('tool', 'done');
    $result->setToolsId('toolu_1');
    $input = new ChatInput([
      new ChatMessage('user', 'hello'),
      new ChatMessage('system', 'Terse mode.'),
      new ChatMessage('assistant', 'ok'),
      new ChatMessage('system', 'Reminder: use the tools.'),
      new ChatMessage('assistant', 'using them'),
      $result,
      new ChatMessage('system', 'After the tool result.'),
    ]);
    $input->setSystemPrompt('S');
    RenderingPlan::empty()->attachTo($input);

    $params = $provider->build($input, 'claude-opus-5-5');
    $wire = self::wire($params);

    $this->assertSame('S', $wire['system'], 'Nothing was folded into the top-level system prompt.');
    $this->assertSame(['user', 'system', 'assistant', 'user', 'assistant', 'user', 'system'], array_column($wire['messages'], 'role'));
    $this->assertSame([['type' => 'text', 'text' => 'Terse mode.']], $wire['messages'][1]['content']);
    $this->assertSame("<system-reminder>\nReminder: use the tools.\n</system-reminder>", $wire['messages'][3]['content'][0]['text'], 'A system message after an assistant message rides as user text in the universal wrapper.');
    $this->assertSame([['type' => 'text', 'text' => 'After the tool result.']], $wire['messages'][6]['content']);
    $this->assertArrayNotHasKey('requestOptions', $provider->arguments($params), 'Mid-conversation system messages need no beta.');
  }

  /**
   * Opaque content is replayed ahead of the assistant's text when switched on.
   */
  public function testThinkingReplay(): void {
    $opaque = [
      ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-1'],
      ['type' => 'redacted_thinking', 'data' => 'enc-1'],
    ];
    $call = new ChatMessage('assistant', 'reading');
    $call->setTools([$this->toolCall('toolu_1', 'page_read', [])]);
    $result = new ChatMessage('tool', 'the print');
    $result->setToolsId('toolu_1');
    $input = new ChatInput([new ChatMessage('user', 'read it'), $call, $result]);
    $input->setSystemPrompt('S');
    RenderingPlan::empty()->withOpaque(1, $opaque)->attachTo($input);

    // Off (the default): nothing replayed, no binding, no beta.
    $provider = ExposedProvider::bare(['top_k' => 5]);
    $params = $provider->build($input, 'claude-opus-5-5');
    $wire = self::wire($params);
    $this->assertSame(['text', 'tool_use'], array_column($wire['messages'][1]['content'], 'type'));
    $this->assertArrayNotHasKey('thinking', $wire);
    $this->assertSame(5, $wire['top_k']);
    $this->assertArrayNotHasKey('requestOptions', $provider->arguments($params));

    // On: the blocks lead the assistant content verbatim, the request
    // carries adaptive thinking with the drop_block binding (and the
    // parent's thinking rules: temperature 1, no top_k) and the beta.
    $provider->settings = ['replay_thinking' => TRUE];
    $params = $provider->build($input, 'claude-opus-5-5');
    $wire = self::wire($params);
    $this->assertSame(['thinking', 'redacted_thinking', 'text', 'tool_use'], array_column($wire['messages'][1]['content'], 'type'));
    $this->assertSame(['type' => 'thinking', 'signature' => 'sig-1', 'thinking' => ''], $wire['messages'][1]['content'][0]);
    $this->assertSame(['type' => 'redacted_thinking', 'data' => 'enc-1'], $wire['messages'][1]['content'][1]);
    $this->assertSame(['type' => 'adaptive', 'block_binding' => ['prefix_mismatch_behavior' => 'drop_block']], $wire['thinking']);
    $this->assertEquals(1.0, $wire['temperature']);
    $this->assertArrayNotHasKey('top_k', $wire);
    $this->assertSame(DaedalusAnthropicProvider::BETA_THINKING_BINDING, $provider->arguments($params)['requestOptions']->extraHeaders['anthropic-beta']);

    // An unknown stored block type is refused rather than guessed at.
    RenderingPlan::empty()->withOpaque(1, [['type' => 'mystery']])->attachTo($input);
    $this->expectException(\InvalidArgumentException::class);
    $provider->build($input, 'claude-opus-5-5');
  }

  /**
   * The thinking display rides the request when set, on adaptive thinking.
   *
   * Omitted (the default) sends nothing; summarized sends
   * thinking.display on the adaptive parameter, adding the parameter (and
   * the parent's thinking rules) when the parent sent none, with or
   * without a plan; a budgeted enabled mode is left alone; with the replay
   * on, the display and the block_binding compose on the one parameter.
   */
  public function testThinkingDisplay(): void {
    $input = new ChatInput([new ChatMessage('user', 'hello')]);
    $input->setSystemPrompt('S');
    RenderingPlan::empty()->attachTo($input);

    // Omitted: the contrib request, thinking parameter and all.
    $provider = ExposedProvider::bare(['top_k' => 5]);
    $provider->settings = ['thinking_display' => DaedalusAnthropicProvider::DISPLAY_OMITTED];
    $params = $provider->build($input, 'claude-opus-5-5');
    $wire = self::wire($params);
    $this->assertArrayNotHasKey('thinking', $wire, 'Omitted is the API\'s default: nothing is sent.');
    $this->assertSame(5, $wire['top_k']);
    $this->assertArrayNotHasKey('requestOptions', $provider->arguments($params), 'The display needs no beta.');

    // Summarized with thinking_mode unset: adaptive with the display, under
    // the parent's thinking rules.
    $provider->settings = ['thinking_display' => DaedalusAnthropicProvider::DISPLAY_SUMMARIZED];
    $params = $provider->build($input, 'claude-opus-5-5');
    $wire = self::wire($params);
    $this->assertSame(['type' => 'adaptive', 'display' => 'summarized'], $wire['thinking']);
    $this->assertEquals(1.0, $wire['temperature']);
    $this->assertArrayNotHasKey('top_k', $wire);
    $this->assertArrayNotHasKey('requestOptions', $provider->arguments($params));

    // Without a plan too: the display is a site diagnostic, not a plan's.
    $bare = new ChatInput([new ChatMessage('user', 'hello')]);
    $bare->setSystemPrompt('S');
    $this->assertSame(['type' => 'adaptive', 'display' => 'summarized'], self::wire($provider->build($bare, 'claude-opus-5-5'))['thinking']);

    // Summarized with thinking_mode adaptive: the parent's own parameter
    // gains the display.
    $provider = ExposedProvider::bare(['thinking_mode' => 'adaptive']);
    $provider->settings = ['thinking_display' => DaedalusAnthropicProvider::DISPLAY_SUMMARIZED];
    $provider->capabilities = self::capabilities(adaptive: TRUE, enabled: TRUE);
    $this->assertSame(['type' => 'adaptive', 'display' => 'summarized'], self::wire($provider->build($input, 'claude-opus-5-5'))['thinking']);

    // Summarized with a budgeted enabled mode: left as the parent built it.
    $provider = ExposedProvider::bare(['thinking_mode' => 'enabled', 'thinking_budget' => 2048]);
    $provider->settings = ['thinking_display' => DaedalusAnthropicProvider::DISPLAY_SUMMARIZED];
    $provider->capabilities = self::capabilities(adaptive: FALSE, enabled: TRUE);
    $this->assertSame(['type' => 'enabled', 'budget_tokens' => 2048], self::wire($provider->build($input, 'claude-opus-5-5'))['thinking']);

    // Summarized and the replay on: both controls on the one parameter.
    $call = new ChatMessage('assistant', 'reading');
    $call->setTools([$this->toolCall('toolu_1', 'page_read', [])]);
    $result = new ChatMessage('tool', 'the print');
    $result->setToolsId('toolu_1');
    $replayed = new ChatInput([new ChatMessage('user', 'read it'), $call, $result]);
    $replayed->setSystemPrompt('S');
    RenderingPlan::empty()->withOpaque(1, [['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-1']])->attachTo($replayed);
    $provider = ExposedProvider::bare();
    $provider->settings = [
      'replay_thinking' => TRUE,
      'thinking_display' => DaedalusAnthropicProvider::DISPLAY_SUMMARIZED,
    ];
    $params = $provider->build($replayed, 'claude-opus-5-5');
    $wire = self::wire($params);
    $this->assertSame([
      'type' => 'adaptive',
      'display' => 'summarized',
      'block_binding' => ['prefix_mismatch_behavior' => 'drop_block'],
    ], $wire['thinking']);
    $this->assertSame('thinking', $wire['messages'][1]['content'][0]['type']);
    $this->assertSame(DaedalusAnthropicProvider::BETA_THINKING_BINDING, $provider->arguments($params)['requestOptions']->extraHeaders['anthropic-beta']);

    // The replay on with the display omitted: the binding alone, as before.
    $provider->settings = ['replay_thinking' => TRUE];
    $this->assertSame(['type' => 'adaptive', 'block_binding' => ['prefix_mismatch_behavior' => 'drop_block']], self::wire($provider->build($replayed, 'claude-opus-5-5'))['thinking']);
  }

  /**
   * Scripted model capabilities stating which thinking types a model takes.
   */
  private static function capabilities(bool $adaptive, bool $enabled): ModelCapabilities {
    return ModelCapabilities::fromArray([
      'thinking' => [
        'supported' => TRUE,
        'types' => [
          'adaptive' => ['supported' => $adaptive],
          'enabled' => ['supported' => $enabled],
        ],
      ],
    ]);
  }

  /**
   * A response's thinking blocks are the opaque content; cachedWrite is filled.
   */
  public function testResponseParsing(): void {
    $provider = ExposedProvider::bare();
    $message = Message::fromArray([
      'id' => 'msg_1',
      'type' => 'message',
      'role' => 'assistant',
      'model' => 'claude-opus-5-5',
      'stop_reason' => 'end_turn',
      'stop_sequence' => NULL,
      'content' => [
        ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-1'],
        ['type' => 'redacted_thinking', 'data' => 'enc-1'],
        ['type' => 'text', 'text' => 'hello'],
      ],
      'usage' => [
        'input_tokens' => 3,
        'output_tokens' => 4,
        'cache_creation_input_tokens' => 7,
        'cache_read_input_tokens' => 9,
      ],
    ]);

    $output = $provider->parse($message);
    $this->assertSame('hello', $output->getNormalized()->getText());
    $this->assertSame(3, $output->getTokenUsage()->input);
    $this->assertSame(9, $output->getTokenUsage()->cached);
    $this->assertSame(7, $output->getTokenUsage()->cachedWrite, 'The DTO carries the cache write the contrib provider leaves in metadata only.');
    $this->assertSame(7, $output->getMetadata()['cache_creation_tokens'], 'The contrib metadata key stays for its readers.');
    $this->assertSame([
      ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-1'],
      ['type' => 'redacted_thinking', 'data' => 'enc-1'],
    ], $provider->opaqueContentOf($output));

    // A response without thinking answers no opaque content; so does an
    // output whose raw form is not an SDK message (a stream).
    $plain = Message::fromArray([
      'id' => 'msg_2',
      'type' => 'message',
      'role' => 'assistant',
      'model' => 'claude-opus-5-5',
      'stop_reason' => 'end_turn',
      'stop_sequence' => NULL,
      'content' => [['type' => 'text', 'text' => 'hi']],
      'usage' => [
        'input_tokens' => 1,
        'output_tokens' => 1,
        'cache_creation_input_tokens' => NULL,
        'cache_read_input_tokens' => NULL,
      ],
    ]);
    $output = $provider->parse($plain);
    $this->assertNull($provider->opaqueContentOf($output));
    $this->assertNull($output->getTokenUsage()->cachedWrite, 'An unreported write stays NULL, never 0 by assumption.');
    $this->assertNull($provider->opaqueContentOf(new ChatOutput(new ChatMessage('assistant', 'x'), ['not' => 'a message'], [])));
  }

  /**
   * The wire form of a typed request.
   */
  private static function wire(MessageCreateParams $params): array {
    return json_decode(json_encode($params), TRUE);
  }

  /**
   * A tool call as the agent wrapper stores one on an assistant message.
   */
  private function toolCall(string $id, string $name, array $arguments): ToolsFunctionOutputInterface {
    $tool = $this->createMock(ToolsFunctionOutputInterface::class);
    $tool->method('getOutputRenderArray')->willReturn([
      'id' => $id,
      'type' => 'function',
      'function' => ['name' => $name, 'arguments' => json_encode($arguments)],
    ]);
    return $tool;
  }

}
