<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai_memory\Functional;

use Drupal\ai\Dto\TokenUsageDto;
use Drupal\ai\Exception\AiBadRequestException;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\daedalus_ai_ledger\UsageLedger;
use Drupal\daedalus_ai_memory\Segment\CarryOverGenerator;
use Drupal\daedalus_ai_memory\Service\AgentMemoryRunner;
use Drupal\daedalus_ai_memory\Transcript\Entry;
use Drupal\daedalus_ai_memory\Transcript\Transcript;
use Drupal\daedalus_ai_test\Plugin\AiProvider\StubProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves a turn ends at the provider's hard line before the provider refuses.
 *
 * The sidebar runs one model leg per HTTP request (verbose mode) and polls
 * again only while the reply carries tool calls; a leg's tool calls
 * execute at the start of the next poll. Nothing but max_loops bounded a
 * turn, so a long tool loop reached the model's window and the provider
 * refused the leg, then the carry-over call over the same oversized prompt,
 * and the runner lost the record. This test drives the real decorated
 * runner through the stub provider with a context ceiling stated in state
 * (100000; the default headroom of 32000 makes the hard limit 68000) and
 * the segment budget left at its default of 80000, so the budget alone
 * would never close: poll 1 measures 40000 and the reply carries the tool
 * call (the front end would poll); poll 2 runs the tool, measures 70000
 * and answers the failure template with the turn-bounded reason and no
 * tools (the front end stops), the carry-over call follows it over the
 * leg's exact prefix with the unanswered call left off, the record reads
 * finished with segment 1 closed and segment 2 current, and the next user
 * message opens segment 2 with the floor and the carry-over, the bounded
 * turn absent, and runs normally.
 *
 * The second method proves the other half of the same defect: a carry-over
 * call the provider refuses leaves the segment open and the record intact.
 * With no ceiling, a finished turn measured past the budget asks for the
 * carry-over and the script refuses it with the ai module's exception (an
 * \Exception, not a \RuntimeException); the reply is the model's, segment 1
 * stays current, and the next finished turn, whose carry-over the script
 * answers, closes it into segment 2.
 *
 * Plain Functional, not JavaScript: the whole mechanism is runner, ledger
 * and tempstore state readable only in-process; the sidebar's wiring is
 * untouched (the reply shape it polls on, tools or none, is asserted on
 * the ChatOutput).
 *
 * @group daedalus_ai_memory
 */
#[RunTestsInSeparateProcesses]
class TurnBoundTest extends AgentMemoryFunctionalTestBase {

  /**
   * The carry-over text the script answers the tagged call with.
   */
  private const CARRY_OVER = 'Carry-over: the editor wants every section restyled; the skins catalog was loaded.';

  /**
   * How the renderer heads the carry-over block.
   */
  private const CARRY_OVER_HEADER = 'Carried over from earlier in this conversation';

  /**
   * The job id (the conversation) every turn runs under.
   */
  private const JOB_ID = 'turn-bound-test';

  /**
   * A turn past the hard line ends with the template and a closed segment.
   */
  public function testTurnEndsAtTheHardLine(): void {
    $this->setCurrentUser($this->drupalCreateUser(['use ai assistant'], 'bound-tester'));
    $enabled = \Drupal::config('daedalus_ai_memory.settings')->get('enabled_agents') ?? [];
    $this->assertContains('daedalus', $enabled, 'The recipe enabled cross-turn memory for the daedalus agent.');

    $runner = \Drupal::service('ai_assistant_api.agent_runner');
    $this->assertInstanceOf(AgentMemoryRunner::class, $runner);
    $store = \Drupal::service('tempstore.private')->get(self::THREAD_BIN);
    $ledger = \Drupal::service('daedalus_ai_ledger.usage_ledger');
    $capture = \Drupal::service('daedalus_ai_test.chat_capture');
    $capture->reset();

    // The bounds: the shipped budget and headroom, and the stub's ceiling
    // from state, read through the provider proxy into the policy.
    $settings = \Drupal::config('daedalus_ai_memory.settings');
    $this->assertSame(80000, (int) $settings->get('segment_budget_tokens'), 'The shipped budget, which this turn never passes.');
    $this->assertSame(32000, (int) $settings->get('segment_headroom_tokens'), 'The shipped headroom, the margin under the ceiling.');
    \Drupal::state()->set(StubProvider::CONTEXT_CEILING_STATE, 100000);
    $provider = \Drupal::service('ai.provider')->createInstance('daedalus_ai_test_stub');
    $budget = \Drupal::service('daedalus_ai_memory.segment_policy')->budget($provider, StubProvider::MODEL_ID);
    $this->assertSame(100000, $budget->ceiling, 'The stub declares the ceiling stated in state.');
    $this->assertSame(68000, $budget->hardLimit(), 'The hard limit is the ceiling less the headroom.');
    $this->assertSame(68000, $budget->effective(), 'The effective budget never exceeds the hard limit.');

    // The script: the carry-over call is answered with text; every other
    // leg calls the eb_skill tool for the skins skill, which does not
    // reframe, so no close but the hard line's can fire.
    $calls = 0;
    $capture->setScript(static function (ChatInput $input, string $model, array $tags) use (&$calls): ?ChatMessage {
      if (in_array(CarryOverGenerator::TAG, $tags, TRUE)) {
        return new ChatMessage('assistant', self::CARRY_OVER);
      }
      $calls++;
      return self::skillCall('call-' . $calls, 'skins');
    });

    $defaults = ['provider_id' => 'daedalus_ai_test_stub', 'model_id' => StubProvider::MODEL_ID];
    $session = [['role' => 'user', 'message' => 'Restyle every section of the page.', 'timestamp' => 1000]];

    // Poll 1 (Fresh, one leg): the reply calls the tool, so the front end
    // would poll; the leg measured 40000, under the limit, and the record
    // is unfinished with the call pending.
    $capture->setUsage(new TokenUsageDto(input: 40000, output: 200, total: 40200, cached: 0));
    $output = $runner->runAsAgent('daedalus', $session, $defaults, self::JOB_ID, TRUE, []);
    $reply = $output->getNormalized();
    $this->assertNotEmpty($reply->getTools(), 'Poll 1 answers the tool call: the front end keeps polling.');
    $this->assertCount(1, $capture->calls(), 'Poll 1 is one provider call.');
    $record = $store->get(self::JOB_ID);
    $this->assertNotNull($record);
    $this->assertFalse($record['finished'], 'The turn is still running.');
    $row = $ledger->newestLeg(self::JOB_ID);
    $this->assertSame('1', $row->segment(), 'The leg ran in segment 1.');
    $this->assertSame(40000, $row->promptTokens(), 'The leg measured under the limit.');
    $this->assertCount(1, Transcript::fromArray($record['transcript'])->segments(), 'Segment 1 stays open under the limit.');

    // Poll 2 (SameTurn, one leg): the pending call executes first, the leg
    // calls the tool again and measures 70000, past the limit. The runner
    // ends the turn: the template reply with the bounded reason and no
    // tools, the carry-over call over this leg's exact prefix, the record
    // finished with segment 2 current.
    $capture->setUsage(new TokenUsageDto(input: 70000, output: 200, total: 70200, cached: 0));
    $output = $runner->runAsAgent('daedalus', $session, $defaults, self::JOB_ID, TRUE, []);
    $reply = $output->getNormalized();
    $this->assertSame('Sorry, I could not process that request: ' . AgentMemoryRunner::TURN_BOUNDED_REASON, $reply->getText(), 'The reply is the assistant\'s failure template carrying the bounded reason.');
    $this->assertEmpty($reply->getTools(), 'The reply carries no tools: the front end stops polling and the pending call never runs.');

    $calls_made = $capture->calls();
    $this->assertCount(3, $calls_made, 'Poll 2 is the leg and then the carry-over call.');
    [, $leg, $carry] = $calls_made;
    $this->assertContains(UsageLedger::SEGMENT_TAG_PREFIX . '1', $leg['tags'], 'The leg ran in segment 1.');
    $this->assertNotContains(CarryOverGenerator::TAG, $leg['tags']);
    $leg_messages = $leg['input']->getMessages();
    $this->assertSame(['user', 'assistant', 'tool'], array_map(static fn (ChatMessage $m): string => $m->getRole(), $leg_messages), 'Poll 2 sent the turn, poll 1\'s call and its result (executed at the start of the poll).');
    $this->assertSame('call-1', $leg_messages[2]->getToolsId(), 'The result answers poll 1\'s call.');

    $this->assertContains(CarryOverGenerator::TAG, $carry['tags'], 'The call after the leg is the carry-over request.');
    $this->assertContains(UsageLedger::SEGMENT_TAG_PREFIX . '1', $carry['tags'], 'The carry-over call is attributed to the closing segment.');
    $this->assertSame($leg['input']->getSystemPrompt(), $carry['input']->getSystemPrompt(), 'The carry-over request reuses the leg\'s system prompt.');
    $this->assertSame($leg['input']->getChatTools()?->renderToolsArray(), $carry['input']->getChatTools()?->renderToolsArray(), 'The carry-over request reuses the leg\'s tools.');
    $carry_messages = $carry['input']->getMessages();
    $this->assertCount(count($leg_messages) + 1, $carry_messages, 'The carry-over request is the leg\'s prompt plus the instruction: the reply with its unanswered call is left off.');
    $this->assertSame(['user', 'assistant', 'tool', 'user'], array_map(static fn (ChatMessage $m): string => $m->getRole(), $carry_messages), 'The answered call and its result stay (the exact prefix); the instruction follows the result, with no unanswered call before it.');
    foreach ($carry_messages as $message) {
      foreach ($message->getTools() ?? [] as $call) {
        $this->assertNotSame('call-2', $call->getToolId(), 'The call the bounded leg made, never answered, is left off the carry-over request.');
      }
    }
    $this->assertSame(CarryOverGenerator::INSTRUCTION . "\n\nLoaded facts: skill:skins.", end($carry_messages)->getText(), 'The instruction names the fact the closing segment loaded.');

    $record = $store->get(self::JOB_ID);
    $this->assertTrue($record['finished'], 'The bounded turn is persisted finished.');
    $transcript = Transcript::fromArray($record['transcript']);
    $this->assertCount(2, $transcript->segments(), 'The hard line closed segment 1 and opened segment 2.');
    [$one, $two] = $transcript->segments();
    $this->assertSame(2, $transcript->currentSegment()->number(), 'Segment 2 is current.');
    $this->assertSame(['user', 'assistant', 'tool', 'assistant'], array_map(static fn (Entry $e): string => $e->type->role(), $one->entries()), 'Segment 1 keeps the turn, the first call, its result and the reply whose call was never run.');
    $this->assertNotEmpty($one->last()->toolCalls, 'The record keeps the assistant entry with its pending call.');
    $this->assertSame(self::CARRY_OVER, $two->carryOver(), 'Segment 2 opened with the carry-over the model answered.');
    $this->assertSame([], $two->entries(), 'Segment 2 waits for the next user turn.');
    $this->assertSame(['skill:skins'], $one->resultsByKey() === [] ? [] : array_keys($one->resultsByKey()), 'The closing segment loaded the skins skill.');

    // Turn 2 (CrossTurn): the next user message opens segment 2 with the
    // same floor and one user message carrying the carry-over, the skills
    // list and the user's words; the bounded turn is absent. The stub is
    // back to text replies and default usage, so the turn runs normally.
    $capture->setScript(NULL);
    $capture->setUsage(NULL);
    $session[] = ['role' => 'assistant', 'message' => $reply->getText(), 'timestamp' => 1001];
    $session[] = ['role' => 'user', 'message' => 'Start with the hero.', 'timestamp' => 1060];
    $output = $runner->runAsAgent('daedalus', $session, $defaults, self::JOB_ID, TRUE, []);
    $this->assertSame(StubProvider::RESPONSE_TEXT, $output->getNormalized()->getText(), 'The next turn ran to the stub\'s reply.');
    $this->assertCount(4, $capture->calls(), 'Turn 2 is one leg.');
    $next = $capture->calls()[3];
    $this->assertContains(UsageLedger::SEGMENT_TAG_PREFIX . '2', $next['tags'], 'The leg ran in segment 2.');
    $this->assertSame($leg['input']->getSystemPrompt(), $next['input']->getSystemPrompt(), 'The floor\'s system prompt is byte-identical across the close.');
    $this->assertSame($leg['input']->getChatTools()?->renderToolsArray(), $next['input']->getChatTools()?->renderToolsArray(), 'The floor\'s tools are byte-identical across the close.');
    $messages = $next['input']->getMessages();
    $this->assertCount(1, $messages, 'Segment 2 opens with the one user message.');
    $opening = $messages[0]->getText();
    $this->assertStringContainsString(self::CARRY_OVER_HEADER, $opening, 'The user message carries the carry-over block.');
    $this->assertStringContainsString(self::CARRY_OVER, $opening, 'The carry-over text is the one the model answered.');
    $this->assertStringNotContainsString('Restyle every section of the page.', $opening, 'The bounded turn is not re-sent.');
    $this->assertStringEndsWith('Start with the hero.', $opening, 'The user\'s words close the message.');

    $record = $store->get(self::JOB_ID);
    $this->assertTrue($record['finished']);
    $transcript = Transcript::fromArray($record['transcript']);
    $this->assertCount(2, $transcript->segments(), 'Turn 2 stayed in segment 2.');
    $this->assertSame(['user', 'assistant'], array_map(static fn (Entry $e): string => $e->type->role(), $transcript->entries()), 'Segment 2 holds the new turn and its reply.');
    $this->assertSame('2', $ledger->newestLeg(self::JOB_ID)->segment(), 'The ledger row names segment 2.');
  }

  /**
   * A refused carry-over leaves the segment open and the record kept.
   */
  public function testRefusedCarryOverLeavesTheSegmentOpen(): void {
    $this->setCurrentUser($this->drupalCreateUser(['use ai assistant'], 'bound-tester'));
    $runner = \Drupal::service('ai_assistant_api.agent_runner');
    $this->assertInstanceOf(AgentMemoryRunner::class, $runner);
    $store = \Drupal::service('tempstore.private')->get(self::THREAD_BIN);
    $capture = \Drupal::service('daedalus_ai_test.chat_capture');
    $capture->reset();

    // No ceiling: the stub declares none unless state says so, and
    // drupal/ai's placeholder maximum input is ignored, so the shipped
    // budget is the only bound and a finished turn past it closes.
    $this->assertNull(\Drupal::state()->get(StubProvider::CONTEXT_CEILING_STATE));
    $provider = \Drupal::service('ai.provider')->createInstance('daedalus_ai_test_stub');
    $budget = \Drupal::service('daedalus_ai_memory.segment_policy')->budget($provider, StubProvider::MODEL_ID);
    $this->assertNull($budget->hardLimit(), 'The stub declares no bound, so there is no hard line.');
    $this->assertSame(80000, $budget->effective(), 'The shipped budget is the one bound.');

    // The script: every leg answers the canned text (the turn finishes on
    // one leg) and the carry-over call is refused the way a provider
    // refuses a request, with the ai module's exception.
    $refusals = 0;
    $capture->setScript(static function (ChatInput $input, string $model, array $tags) use (&$refusals): ?ChatMessage {
      if (in_array(CarryOverGenerator::TAG, $tags, TRUE)) {
        $refusals++;
        throw new AiBadRequestException('prompt is too long: 90000 tokens > 80000 maximum');
      }
      return NULL;
    });
    $capture->setUsage(new TokenUsageDto(input: 90000, output: 200, total: 90200, cached: 0));

    $defaults = ['provider_id' => 'daedalus_ai_test_stub', 'model_id' => StubProvider::MODEL_ID];
    $session = [['role' => 'user', 'message' => 'Restyle every section of the page.', 'timestamp' => 1000]];

    // Turn 1 (Fresh): the leg finishes the turn measured past the budget,
    // the carry-over is asked for and refused, and the turn is unharmed.
    $output = $runner->runAsAgent('daedalus', $session, $defaults, self::JOB_ID, TRUE, []);
    $this->assertSame(StubProvider::RESPONSE_TEXT, $output->getNormalized()->getText(), 'The reply is the model\'s: the refused carry-over never reaches the user.');
    $this->assertSame(1, $refusals, 'The carry-over call was made once and refused.');
    $record = $store->get(self::JOB_ID);
    $this->assertNotNull($record, 'The record survived the refused carry-over.');
    $this->assertTrue($record['finished'], 'The turn finished.');
    $transcript = Transcript::fromArray($record['transcript']);
    $this->assertCount(1, $transcript->segments(), 'Segment 1 stays open: the close was not made.');
    $this->assertSame(1, $transcript->currentSegment()->number(), 'Segment 1 is still current.');
    $this->assertSame(['user', 'assistant'], array_map(static fn (Entry $e): string => $e->type->role(), $transcript->entries()), 'Segment 1 holds the turn and its reply.');

    // Turn 2 (CrossTurn): the script answers the carry-over this time; the
    // finished leg measures past the budget again and the close is made,
    // over the leg's exact prefix, into segment 2.
    $capture->setScript(static function (ChatInput $input, string $model, array $tags): ?ChatMessage {
      return in_array(CarryOverGenerator::TAG, $tags, TRUE) ? new ChatMessage('assistant', self::CARRY_OVER) : NULL;
    });
    $session[] = ['role' => 'assistant', 'message' => StubProvider::RESPONSE_TEXT, 'timestamp' => 1001];
    $session[] = ['role' => 'user', 'message' => 'Start with the hero.', 'timestamp' => 1060];
    $output = $runner->runAsAgent('daedalus', $session, $defaults, self::JOB_ID, TRUE, []);
    $this->assertSame(StubProvider::RESPONSE_TEXT, $output->getNormalized()->getText(), 'The next turn ran to the stub\'s reply.');
    $this->assertSame(1, $refusals, 'The refusing script was replaced; no second refusal.');

    $calls = $capture->calls();
    $carry = end($calls);
    $leg = prev($calls);
    $this->assertContains(CarryOverGenerator::TAG, $carry['tags'], 'The last call is the carry-over request.');
    $this->assertContains(UsageLedger::SEGMENT_TAG_PREFIX . '1', $carry['tags'], 'The carry-over call is attributed to the closing segment.');
    $this->assertNotContains(CarryOverGenerator::TAG, $leg['tags']);
    $this->assertSame($leg['input']->getSystemPrompt(), $carry['input']->getSystemPrompt(), 'The carry-over request reuses the leg\'s system prompt.');
    $this->assertSame(['user', 'assistant', 'user', 'assistant', 'user'], array_map(static fn (ChatMessage $m): string => $m->getRole(), $carry['input']->getMessages()), 'The carry-over request is both turns and the instruction.');

    $record = $store->get(self::JOB_ID);
    $this->assertTrue($record['finished']);
    $transcript = Transcript::fromArray($record['transcript']);
    $this->assertCount(2, $transcript->segments(), 'The answered carry-over closed segment 1 and opened segment 2.');
    [$one, $two] = $transcript->segments();
    $this->assertSame(2, $transcript->currentSegment()->number(), 'Segment 2 is current.');
    $this->assertSame(['user', 'assistant', 'user', 'assistant'], array_map(static fn (Entry $e): string => $e->type->role(), $one->entries()), 'Segment 1 closed holding both turns.');
    $this->assertSame(self::CARRY_OVER, $two->carryOver(), 'Segment 2 opened with the carry-over the model answered.');
    $this->assertSame([], $two->entries(), 'Segment 2 waits for the next user turn.');
  }

}
