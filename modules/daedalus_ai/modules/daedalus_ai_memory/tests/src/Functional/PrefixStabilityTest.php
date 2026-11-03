<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai_memory\Functional;

use Drupal\ai\Dto\TokenUsageDto;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai_agents\Event\BuildSystemPromptEvent;
use Drupal\ai_assistant_api\Service\AgentRunner;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\daedalus_ai_ledger\UsageLedger;
use Drupal\daedalus_ai_memory\Enum\AgentState;
use Drupal\daedalus_ai_memory\Segment\CarryOverGenerator;
use Drupal\daedalus_ai_memory\Service\AgentMemoryRunner;
use Drupal\daedalus_ai_memory\Transcript\Entry;
use Drupal\daedalus_ai_memory\Transcript\EntryType;
use Drupal\daedalus_ai_memory\Transcript\Transcript;
use Drupal\daedalus_ai_test\ChatCapture;
use Drupal\daedalus_ai_test\Plugin\AiProvider\StubProvider;
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves the property the context spine rests on: the prompt is append-only.
 *
 * A provider's prompt cache pays off only while each request is a byte
 * extension of the one before it: tools and system prompt identical, every
 * earlier message identical, new messages only at the end. Before the
 * spine, every new user turn rewrote the first user message (the per-turn
 * blocks were stripped and re-injected), so each turn re-paid the whole
 * conversation as a cache write. This test drives the real decorated
 * runner, the real daedalus agent and the real eb_skill tool through the
 * scripted stub provider and reads every prompt the provider was handed
 * back from the chat capture, then asserts on the bytes:
 *
 * - Within a segment, across legs of one turn (a tool call, then its result)
 *   and across turns, every prompt is a byte prefix of the next.
 * - A reframe close (a reframing skill answered past the reframe minimum)
 *   generates the carry-over over the closing segment's exact prefix, then
 *   resets the next leg to the floor (the same tools and system prompt)
 *   plus the carry-over, the skills list and the current turn re-sent.
 * - A budget close (a finished turn measured past the budget) does the
 *   same for the next turn, which opens the new segment alone.
 * - Twenty-plus UI turns through a session capped the way the chat memory
 *   caps it never reset the agent: the record is continued on every turn,
 *   the segment keeps growing, no client-supplied line is ever replayed.
 * - The frontend's session context never reaches the system prompt. Every
 *   turn carries the context the sidebar sends, alternating between its
 *   first request (the server-rendered current_route plus the JS
 *   providers: view_mode, selected_elements, ajax_page_state) and its
 *   poll (the same without current_route), over a
 *   real node the route resolves to; the prefix assertions above hold
 *   across every alternation because the spine delivers that context as
 *   per-turn blocks and keeps ai_assistant_api's rendering of it out of
 *   the prompt (FrontendContextSubscriber). Before it, the contrib
 *   subscriber appended the set to the system prompt and the first
 *   alternation rewrote the floor.
 * - A deliberate drift is detected and reported: a listener appends one
 *   line to the system prompt for one turn, and that turn's call carries
 *   the ledger's drift tag, its row reads prefix_drift, the segment reads
 *   drifted, and the ledger's status report entry warns naming this
 *   conversation; with the listener gone the next turn is clean again and
 *   the segment's hash history has grown by exactly the one hash.
 *
 * The measurements the segment policy reads come from the usage ledger,
 * which the stub feeds with the usage the capture scripts: a leg "costs"
 * what the test says it does, so the closes are driven without a prompt
 * anywhere near the real budget. The prompts are compared through a
 * self-delimiting serialization (tools, system prompt, then each message
 * with its role, tool id, text and calls, NUL-terminated), so a message
 * that grew in place would not pass as an appended one.
 *
 * This is a plain Functional test: what it reads (the provider's inputs,
 * the tempstore record) is reachable only in-process, and the sidebar's
 * wiring is covered elsewhere. One test method keeps the site install
 * plus recipe apply paid once; the turns themselves are cheap.
 *
 * @group daedalus_ai_memory
 */
#[RunTestsInSeparateProcesses]
class PrefixStabilityTest extends AgentMemoryFunctionalTestBase {

  /**
   * The chat memory's cap on the session the runner is handed.
   *
   * The ai_assistant_api chat memory keeps max_messages pairs plus one
   * (PrivateTempStoreChatMemory::saveMessages() slices to
   * max_messages * 2 + 1; 20 pairs on the shipped assistant), so after
   * twenty turns the session stops growing.
   */
  private const SESSION_CAP = 41;

  /**
   * The carry-over text the script answers a carry-over call with.
   */
  private const CARRY_OVER = 'Carry-over %d: the user is making a landing page for the bakery and wants it warm and simple.';

  /**
   * The header the renderer puts on a carry-over block.
   */
  private const CARRY_OVER_HEADER = 'Carried over from earlier in this conversation';

  /**
   * The header the renderer puts on the skills block.
   */
  private const SKILLS_HEADER = 'The following skills are available for use with the Skill tool:';

  /**
   * The conversation's runner id.
   */
  private const JOB_ID = 'prefix-stability-test';

  /**
   * The lead of the block ai_assistant_api renders the context into.
   *
   * PassContextToAgentSubscriber appends "The following context about the
   * user's current session was provided by the assistant frontend:" plus
   * one line per context key to the system prompt; for a managed agent the
   * spine empties the context before it renders, so no prompt carries it.
   */
  private const CONTRIB_CONTEXT_LEAD = 'provided by the assistant frontend';

  /**
   * The line the deliberate drift appends to the system prompt.
   */
  private const DRIFT_LINE = 'Drift: a line only one turn\'s prompt carries.';

  /**
   * The user-visible session, as the chat memory would hold it.
   */
  private array $session = [];

  /**
   * The timestamp of the next session message.
   */
  private int $clock = 1000;

  /**
   * How many turns were run.
   */
  private int $turns = 0;

  /**
   * The scripted replies the current turn's legs consume, in order.
   *
   * @var \Drupal\ai\OperationType\Chat\ChatMessage[]
   */
  private array $queue = [];

  /**
   * How many carry-over calls the script answered.
   */
  private int $carryOvers = 0;

  /**
   * The decorated agent runner.
   */
  private AgentRunner $runner;

  /**
   * The stub provider's capture, shared with the test.
   */
  private ChatCapture $capture;

  /**
   * The tempstore bin the runner persists records into.
   */
  private PrivateTempStore $store;

  /**
   * The usage ledger, read for the drifted leg's row.
   */
  private UsageLedger $ledger;

  /**
   * The page the conversation is on: what current_route resolves to.
   */
  private NodeInterface $page;

  /**
   * The provider and model every turn runs on.
   */
  private array $defaults = ['provider_id' => 'daedalus_ai_test_stub', 'model_id' => StubProvider::MODEL_ID];

  /**
   * Every prompt is a byte prefix of the next until a close resets it.
   */
  public function testPromptsAreByteStablePrefixes(): void {
    // PrivateTempStore keys by the current user when authenticated, so run as
    // a real account that both the runner's writes and this test's reads
    // agree on; an editor with the sidebar's permission, like a real user.
    $this->setCurrentUser($this->drupalCreateUser(['use ai assistant'], 'prefix-tester'));

    $enabled = \Drupal::config('daedalus_ai_memory.settings')->get('enabled_agents') ?? [];
    $this->assertContains('daedalus', $enabled, 'The recipe enabled cross-turn memory for the daedalus agent.');

    $this->runner = \Drupal::service('ai_assistant_api.agent_runner');
    $this->assertInstanceOf(AgentMemoryRunner::class, $this->runner, 'daedalus_ai_memory decorates the assistant agent runner.');
    $this->store = \Drupal::service('tempstore.private')->get(self::THREAD_BIN);
    $this->ledger = \Drupal::service('daedalus_ai_ledger.usage_ledger');
    $this->capture = \Drupal::service('daedalus_ai_test.chat_capture');
    $this->capture->reset();

    // The page the sidebar is open on: the first request's current_route
    // is its canonical path, which the current-page and print producers
    // resolve to a host the editor may view.
    $this->page = $this->drupalCreateNode(['type' => 'page', 'title' => 'Bakery landing page']);

    // The script: a carry-over call (tagged by the generator) is answered
    // with a numbered carry-over text whatever leg is in flight; any other
    // call takes the turn's next queued reply, and the stub's canned text
    // once the queue is empty.
    $this->capture->setScript(function (ChatInput $input, string $model, array $tags): ?ChatMessage {
      if (in_array(CarryOverGenerator::TAG, $tags, TRUE)) {
        $this->carryOvers++;
        return new ChatMessage('assistant', sprintf(self::CARRY_OVER, $this->carryOvers));
      }
      return array_shift($this->queue);
    });

    $budget = \Drupal::config('daedalus_ai_memory.settings');
    $this->assertSame(80000, (int) $budget->get('segment_budget_tokens'), 'The shipped segment budget this test drives past.');
    $this->assertSame(25000, (int) $budget->get('reframe_minimum_tokens'), 'The shipped reframe minimum this test drives past.');

    // Segment 1, turn 1 (Fresh): one leg, the sidebar's first request. The
    // segment's first user message carries the skills list, the one block
    // a segment states once, and the current page the route resolved to.
    [$leg] = $this->turn('Remember the number 42.', $this->firstRequest());
    $this->assertSegmentTag($leg, 1);
    $this->assertCount(1, $leg['input']->getMessages(), 'The first leg sends the one user message.');
    $opening = $leg['input']->getMessages()[0]->getText();
    $this->assertStringContainsString(self::SKILLS_HEADER, $opening, 'The segment opens with the skills list on its first user message.');
    $this->assertStringNotContainsString(self::CARRY_OVER_HEADER, $opening, 'The first segment has nothing to carry over.');
    $this->assertStringContainsString($this->pageIdentity(), $opening, 'The first request\'s current_route resolved to the page and rides the user message as the current-page block.');
    $this->assertStringContainsString('View mode: "default".', $opening, 'The view mode rides the same block.');
    $previous = self::wire($leg['input']);

    // Turn 2: two legs, the sidebar's poll shape (no current_route). The
    // scripted first reply calls the real eb_skill tool, the wrapper
    // executes it, and the second leg's prompt carries the call and its
    // result after everything already sent: within the turn as across
    // turns, each prompt extends the last. The per-turn blocks differ
    // from turn 1's (no page this time), the floor does not.
    [$leg_one, $leg_two] = $this->turn('What does the design skill say?', $this->poll(), [self::skillCall('call-turn-2', 'design')]);
    $this->assertSegmentTag($leg_one, 1);
    $this->assertSegmentTag($leg_two, 1);
    $this->assertPrefix($previous, self::wire($leg_one['input']), 'Turn 2 extends turn 1.');
    $this->assertPrefix(self::wire($leg_one['input']), self::wire($leg_two['input']), 'Leg 2 of turn 2 extends leg 1 (the tool call and its result are appended).');
    $messages = $leg_two['input']->getMessages();
    $this->assertSame(
      ['user', 'assistant', 'user', 'assistant', 'tool'],
      array_map(static fn (ChatMessage $m): string => $m->getRole(), $messages),
      'Leg 2 sends turn 1 and its reply, turn 2, the call and its result.',
    );
    $this->assertStringNotContainsString($this->pageIdentity(), $messages[2]->getText(), 'A poll carries no current_route, so its user message names no page.');
    $this->assertStringContainsString('View mode: "default".', $messages[2]->getText(), 'The poll\'s view mode still rides its block.');
    $this->assertSame('call-turn-2', $messages[4]->getToolsId(), 'The result answers the scripted call by id.');
    $this->assertStringNotContainsString('Unknown skill', $messages[4]->getText(), 'The real skill tool ran and composed the design skill.');
    $this->assertSame(['skill:design'], $this->transcript()->loadedKeys(), 'The skill result is keyed as a loaded fact.');
    $previous = self::wire($leg_two['input']);

    // Turn 3: one leg, still segment 1, still an extension.
    [$leg] = $this->turn('And the number I asked you to remember?', $this->firstRequest());
    $this->assertSegmentTag($leg, 1);
    $this->assertPrefix($previous, self::wire($leg['input']), 'Turn 3 extends leg 2 of turn 2.');
    $this->assertCount(1, $this->transcript()->segments(), 'Three short turns stay in one segment.');
    $previous = self::wire($leg['input']);

    // The reframe close. The stub now reports a prompt past the reframe
    // minimum (and under the budget), and turn 4's first leg invokes a
    // reframing skill (design declares reframe: true). Before leg
    // 2 the runner absorbs the keyed result, the policy answers Reframe,
    // the carry-over is generated over segment 1's exact prefix, and
    // segment 2 opens with the carry-over and the current turn re-sent.
    // Three provider calls: leg 1, the carry-over, leg 2.
    $this->capture->setUsage(new TokenUsageDto(input: 30000, output: 200, total: 30200, cached: 0));
    [$leg_one, $carry, $leg_two] = $this->turn('Now make me a landing page for the bakery.', $this->poll(), [self::skillCall('call-turn-4', 'design')]);
    $this->assertSegmentTag($leg_one, 1);
    $this->assertPrefix($previous, self::wire($leg_one['input']), 'Leg 1 of turn 4 extends turn 3.');

    $this->assertContains(CarryOverGenerator::TAG, $carry['tags'], 'The second call is the carry-over request.');
    $this->assertSegmentTag($carry, 1, 'The carry-over call is attributed to the closing segment.');
    $this->assertPrefix(self::wire($leg_one['input']), self::wire($carry['input']), 'The carry-over request reuses the closing segment\'s exact prefix: leg 1\'s prompt, then the call, the result and the instruction.');
    $carry_messages = $carry['input']->getMessages();
    $this->assertCount(count($leg_one['input']->getMessages()) + 3, $carry_messages, 'The carry-over request adds the call, its result and the instruction to the last leg\'s prompt.');
    $this->assertSame(CarryOverGenerator::INSTRUCTION . "\n\nLoaded facts: skill:design.", end($carry_messages)->getText(), 'The instruction names the facts loaded in the closing segment.');

    $this->assertSame(self::floor($leg_one['input']), self::floor($leg_two['input']), 'After the reframe close the floor (tools and system prompt) is byte-identical.');
    $this->assertFalse(str_starts_with(self::wire($leg_two['input']), self::wire($leg_one['input'])), 'After the reframe close the messages are reset, not extended.');
    $messages = $leg_two['input']->getMessages();
    $this->assertSame(['user', 'assistant', 'tool'], array_map(static fn (ChatMessage $m): string => $m->getRole(), $messages), 'Segment 2 opens with the current turn re-sent: the user message, the call, its result.');
    $opening = $messages[0]->getText();
    $this->assertStringContainsString(self::CARRY_OVER_HEADER, $opening, 'The new segment\'s first user message carries the carry-over block.');
    $this->assertStringContainsString(sprintf(self::CARRY_OVER, 1), $opening, 'The carry-over text is the one the model answered.');
    $this->assertStringContainsString(self::SKILLS_HEADER, $opening, 'The re-sent turn carries the skills list into the new segment.');
    $this->assertStringEndsWith('Now make me a landing page for the bakery.', $opening, 'The user\'s words close the message, after the blocks.');
    $this->assertSame('call-turn-4', $messages[1]->getTools()[0]->getToolId(), 'The re-sent call keeps its id.');
    $this->assertSame('call-turn-4', $messages[2]->getToolsId(), 'The re-sent result still answers it.');
    $this->assertSame($carry_messages[count($carry_messages) - 2]->getText(), $messages[2]->getText(), 'The re-sent result is the one the tool gave, byte for byte.');

    $transcript = $this->transcript();
    $this->assertCount(2, $transcript->segments(), 'The reframe opened segment 2.');
    [$one, $two] = $transcript->segments();
    $this->assertNull($one->carryOver());
    $this->assertSame(sprintf(self::CARRY_OVER, 1), $two->carryOver(), 'Segment 2 holds the carry-over it opened with.');
    $this->assertSame(['user', 'assistant', 'tool', 'assistant'], array_map(static fn (Entry $e): string => $e->type->role(), $two->entries()), 'Segment 2 holds the re-sent turn and the leg that finished it.');
    $this->assertSame(['skill:design'], $transcript->loadedKeys(), 'The re-sent result keeps its key in the new segment.');
    $this->assertFalse($one->prefixDrifted(), 'Segment 1 saw one prefix hash on every leg.');
    $previous = self::wire($leg_two['input']);

    // The budget close. The stub now reports a prompt past the budget; the
    // turn runs one leg (an extension of segment 2's last leg), and once it
    // has finished the policy answers Budget: the carry-over is generated
    // over segment 2's prefix and segment 3 opens empty, for the next turn.
    $this->capture->setUsage(new TokenUsageDto(input: 90000, output: 200, total: 90200, cached: 0));
    [$leg, $carry] = $this->turn('Make the hero warmer.', $this->firstRequest());
    $this->assertSegmentTag($leg, 2);
    $this->assertPrefix($previous, self::wire($leg['input']), 'Turn 5 extends leg 2 of turn 4 within segment 2.');
    $this->assertContains(CarryOverGenerator::TAG, $carry['tags'], 'The second call is the carry-over request.');
    $this->assertSegmentTag($carry, 2, 'The carry-over call is attributed to the closing segment.');
    $this->assertPrefix(self::wire($leg['input']), self::wire($carry['input']), 'The carry-over request reuses segment 2\'s exact prefix.');
    $carry_messages = $carry['input']->getMessages();
    $this->assertSame(CarryOverGenerator::INSTRUCTION . "\n\nLoaded facts: skill:design.", end($carry_messages)->getText());

    $transcript = $this->transcript();
    $this->assertCount(3, $transcript->segments(), 'The budget close opened segment 3.');
    $three = $transcript->segments()[2];
    $this->assertSame(sprintf(self::CARRY_OVER, 2), $three->carryOver(), 'Segment 3 holds the second carry-over.');
    $this->assertSame([], $three->entries(), 'Segment 3 waits for the next user turn.');
    $this->assertFalse($transcript->segments()[1]->prefixDrifted(), 'Segment 2 saw one prefix hash on every leg.');
    $floor = self::floor($leg['input']);
    $reset_from = self::wire($leg['input']);
    $this->capture->setUsage(NULL);

    // Turn 6 opens segment 3 alone: the floor again, then one user message
    // carrying the carry-over, the skills list and the user's words.
    [$leg] = $this->turn('Tone down the headline.', $this->poll());
    $this->assertSegmentTag($leg, 3);
    $this->assertSame($floor, self::floor($leg['input']), 'After the budget close the floor is byte-identical.');
    $this->assertFalse(str_starts_with(self::wire($leg['input']), $reset_from), 'After the budget close the messages are reset, not extended.');
    $messages = $leg['input']->getMessages();
    $this->assertCount(1, $messages, 'Segment 3 opens with the one user message.');
    $this->assertStringContainsString(sprintf(self::CARRY_OVER, 2), $messages[0]->getText(), 'The new segment\'s first user message carries the second carry-over.');
    $this->assertStringContainsString(self::SKILLS_HEADER, $messages[0]->getText(), 'The segment\'s first turn states the skills list.');
    $this->assertStringEndsWith('Tone down the headline.', $messages[0]->getText());
    $previous = self::wire($leg['input']);

    // Twenty-one more turns through the capped session. From the twenty-first
    // turn on, the session the runner is handed holds the last 41 messages
    // and stops growing, which is exactly what used to read as Stale and
    // discard the record on every later turn. Identity-based detection
    // continues the record instead: every turn is a cross turn, every prompt
    // extends the last, and the segment accumulates every turn. The turns
    // keep alternating between the sidebar's first-request context and its
    // poll context, so the prefix assertion also proves on every turn that
    // the frontend context never reaches the system prompt: before
    // FrontendContextSubscriber the contrib subscriber appended the set to
    // the prompt and the first alternation rewrote the floor.
    $first_capped_turn = NULL;
    for ($i = 7; $i <= 27; $i++) {
      [$leg] = $this->turn(sprintf('Turn %d: tweak the copy a little.', $i), $i % 2 === 1 ? $this->firstRequest() : $this->poll());
      $this->assertSegmentTag($leg, 3);
      $this->assertPrefix($previous, self::wire($leg['input']), sprintf('Turn %d extends turn %d.', $i, $i - 1));
      $this->assertCount(3, $this->transcript()->segments(), sprintf('Turn %d continued the record (no reset).', $i));
      $previous = self::wire($leg['input']);
      if ($first_capped_turn === NULL && count($this->session) >= self::SESSION_CAP) {
        $first_capped_turn = $i;
      }
    }
    $this->assertSame(21, $first_capped_turn, 'The session reached the chat memory\'s cap at turn 21 and was handed capped from then on.');
    $this->assertGreaterThan(self::SESSION_CAP, count($this->session), 'The session outgrew the cap by the end.');

    $transcript = $this->transcript();
    $this->assertCount(3, $transcript->segments(), 'Twenty-one capped turns never reset the agent.');
    $texts = array_map(static fn (Entry $e): string => $e->text, $transcript->entries());
    $user_texts = array_values(array_filter(array_map(
      static fn (Entry $e): ?string => $e->type === EntryType::User ? $e->text : NULL,
      $transcript->entries(),
    )));
    $expected = ['Tone down the headline.'];
    for ($i = 7; $i <= 27; $i++) {
      $expected[] = sprintf('Turn %d: tweak the copy a little.', $i);
    }
    $this->assertSame($expected, $user_texts, 'Segment 3 holds every user turn since it opened, in order.');
    foreach ($texts as $text) {
      $this->assertStringStartsNotWith('Reply ', $text, 'No client-supplied assistant line was ever replayed into the transcript (a Fresh rebuild would have).');
    }
    foreach ($transcript->segments() as $segment) {
      $this->assertFalse($segment->prefixDrifted(), sprintf('Segment %d saw one prefix hash on every leg.', $segment->number()));
    }
    $this->assertSame(RequirementSeverity::OK, $this->prefixDriftRequirement()['severity'], 'With no drift in 27 turns the ledger\'s status report entry is OK.');

    // The deliberate drift. A listener appends one line to the system
    // prompt for one turn: the floor changes, the runner notes the new
    // hash and tags the leg that paid for it, the ledger keeps the flag on
    // its row, and the status report warns naming this conversation. The
    // messages are still append-only: drift rewrites the floor, nothing
    // else.
    $hashes_before = count($this->transcript()->segments()[2]->prefixHashes());
    $this->assertSame(1, $hashes_before, 'Segment 3 holds the one hash every leg so far produced.');
    $dispatcher = \Drupal::service('event_dispatcher');
    $drift = static function (BuildSystemPromptEvent $event): void {
      $event->setSystemPrompt($event->getSystemPrompt() . "\n\n" . self::DRIFT_LINE);
    };
    $dispatcher->addListener(BuildSystemPromptEvent::EVENT_NAME, $drift, -100);
    [$leg] = $this->turn('Turn 28: and the footer.', $this->poll());
    $dispatcher->removeListener(BuildSystemPromptEvent::EVENT_NAME, $drift);

    $this->assertStringContainsString(self::DRIFT_LINE, $leg['input']->getSystemPrompt(), 'The drifted leg\'s system prompt carries the appended line.');
    $this->assertNotSame($floor, self::floor($leg['input']), 'The floor changed: that is the drift.');
    $this->assertPrefix(self::body($previous), self::body(self::wire($leg['input'])), 'Turn 28 still extends turn 27 below the floor: the drift rewrote the prefix, not the transcript.');
    $this->assertSegmentTag($leg, 3);
    $this->assertContains(UsageLedger::PREFIX_DRIFT_TAG, $leg['tags'], 'The drifted leg\'s own call carries the drift tag.');
    $row = $this->ledger->newestLeg(self::JOB_ID);
    $this->assertSame(self::JOB_ID, $row->conversation(), 'The newest ledger row is this conversation\'s.');
    $this->assertTrue($row->prefixDrifted(), 'The ledger row for the drifted leg reads prefix_drift.');
    $this->assertCount(3, $this->transcript()->segments(), 'The drift opened no segment.');
    $this->assertTrue($this->transcript()->segments()[2]->prefixDrifted(), 'Segment 3 saw a second prefix hash.');
    $entry = $this->prefixDriftRequirement();
    $this->assertSame(RequirementSeverity::Warning, $entry['severity'], 'The status report warns on the drifted leg.');
    $this->assertStringContainsString('1 leg re-sent a rewritten prompt prefix in 1 conversation', (string) $entry['value']);
    $this->assertStringContainsString('Conversations affected: ' . self::JOB_ID . '.', (string) $entry['description'], 'The warning names this conversation.');
    $previous = self::wire($leg['input']);

    // With the listener gone the prompt is the floor's again. That leg is
    // drift once more (its prefix differs from the leg before it, and the
    // provider cached that leg under the other prefix: a feeder that
    // varies the prompt on some requests and not others pays on every
    // flip), but the hash is one the segment knows, so its history does
    // not grow. The leg after it holds the prefix and is clean.
    [$leg] = $this->turn('Turn 29: that will do.', $this->firstRequest());
    $this->assertStringNotContainsString(self::DRIFT_LINE, $leg['input']->getSystemPrompt(), 'The drift line is gone from the system prompt.');
    $this->assertSame($floor, self::floor($leg['input']), 'The floor is byte-identical to the floor before the drift.');
    $this->assertPrefix(self::body($previous), self::body(self::wire($leg['input'])), 'Turn 29 extends turn 28 below the floor.');
    $this->assertSegmentTag($leg, 3);
    $this->assertContains(UsageLedger::PREFIX_DRIFT_TAG, $leg['tags'], 'The flip back is a prefix change relative to the drifted leg, so it is tagged too.');
    $this->assertTrue($this->ledger->newestLeg(self::JOB_ID)->prefixDrifted());
    $previous = self::wire($leg['input']);

    [$leg] = $this->turn('Turn 30: thanks.', $this->poll());
    $this->assertSame($floor, self::floor($leg['input']), 'The floor holds.');
    $this->assertPrefix($previous, self::wire($leg['input']), 'Turn 30 extends turn 29, floor included.');
    $this->assertSegmentTag($leg, 3);
    $this->assertNotContains(UsageLedger::PREFIX_DRIFT_TAG, $leg['tags'], 'A prefix that holds from the leg before it is not drift.');
    $this->assertFalse($this->ledger->newestLeg(self::JOB_ID)->prefixDrifted(), 'The ledger row for the clean leg reads no drift.');
    $segment = $this->transcript()->segments()[2];
    $this->assertCount($hashes_before + 1, $segment->prefixHashes(), 'The three turns grew the segment\'s hash list by exactly one: the drifted hash.');
    [$floor_hash, $drift_hash] = $segment->prefixHashes();
    $this->assertSame($floor_hash, $segment->prefixHash(), 'The latest leg was sent with the floor\'s hash.');
    $this->assertNotSame($floor_hash, $drift_hash);
    $this->assertTrue($segment->prefixDrifted(), 'The drift stays in the segment\'s history.');
    $this->assertSame(RequirementSeverity::Warning, $this->prefixDriftRequirement()['severity'], 'The status report keeps warning while the drifted legs are inside its window.');
  }

  /**
   * The sidebar's first-request context: the routed page plus the JS providers.
   *
   * ChatSidebar renders current_route into the connect object's contexts;
   * the JS providers (daedalus-ai-layout-context.js, -chat-chips.js) add
   * view_mode, selected_elements and ajax_page_state.
   */
  private function firstRequest(): array {
    return ['current_route' => '/node/' . $this->page->id()] + $this->poll();
  }

  /**
   * The sidebar's poll context: the JS providers alone, no current_route.
   *
   * The continuation request ai_chatbot builds carries no contexts and
   * daedalus's fetch shim fills it with the JS providers; current_route is
   * not one, so no poll ever carries it.
   */
  private function poll(): array {
    return [
      'view_mode' => 'default',
      'selected_elements' => [],
      'ajax_page_state' => ['theme' => 'stark', 'theme_token' => '', 'libraries' => 'core/drupal,core/once'],
    ];
  }

  /**
   * The host line the current-page producer states for the page.
   */
  private function pageIdentity(): string {
    return sprintf('node (ID: %s, UUID: %s, type: page, title: "%s").', $this->page->id(), $this->page->uuid(), $this->page->label());
  }

  /**
   * The ledger's prompt cache entry on the status report.
   */
  private function prefixDriftRequirement(): array {
    $requirements = \Drupal::moduleHandler()->invoke('daedalus_ai_ledger', 'runtime_requirements') ?? [];
    $this->assertArrayHasKey('daedalus_ai_ledger_prefix_drift', $requirements, 'The ledger states its prompt cache entry.');
    return $requirements['daedalus_ai_ledger_prefix_drift'];
  }

  /**
   * Runs one user turn through the runner and answers the calls it made.
   *
   * The session grows by the user's message before the turn and by a
   * client-side assistant line after it (the text the chat memory would
   * hold, deliberately not the stub's, so a replay of it is visible), and
   * the runner is handed the session capped the way the chat memory caps
   * it, with the context the sidebar's request would carry. The turn's
   * legs consume the scripted replies in order; the last leg answers the
   * stub's canned text, which is what the turn's output must carry (a
   * thrown turn would answer the assistant's error template instead). No
   * call's system prompt carries ai_assistant_api's rendering of the
   * context.
   *
   * @param string $text
   *   The user's words.
   * @param array $context
   *   The frontend context handed to the runner (firstRequest() or poll()).
   * @param \Drupal\ai\OperationType\Chat\ChatMessage[] $replies
   *   The scripted replies for the turn's legs, in order; the legs after
   *   them answer the canned text.
   *
   * @return array<int, array{input: \Drupal\ai\OperationType\Chat\ChatInput, model_id: string, tags: string[], configuration: array}>
   *   The provider calls the turn made, in order (ChatCapture::calls()).
   */
  private function turn(string $text, array $context, array $replies = []): array {
    $this->turns++;
    $this->queue = $replies;
    $this->session[] = ['role' => 'user', 'message' => $text, 'timestamp' => $this->clock++];
    $handed = array_slice($this->session, -self::SESSION_CAP);
    $before = count($this->capture->calls());

    $output = $this->runner->runAsAgent('daedalus', $handed, $this->defaults, self::JOB_ID, FALSE, $context);
    $reply = $output->getNormalized()->getText();
    $this->assertSame(StubProvider::RESPONSE_TEXT, $reply, sprintf('Turn %d ("%s") ran to the stub\'s text reply; got: %s', $this->turns, $text, $reply));
    $this->assertSame([], $this->queue, sprintf('Turn %d consumed every scripted reply.', $this->turns));
    foreach (array_slice($this->capture->calls(), $before) as $call) {
      $this->assertStringNotContainsString(self::CONTRIB_CONTEXT_LEAD, $call['input']->getSystemPrompt(), sprintf('Turn %d: the frontend context never reaches the system prompt.', $this->turns));
    }

    $this->session[] = [
      'role' => 'assistant',
      'message' => sprintf('Reply %d.', $this->turns),
      'timestamp' => $this->clock++,
    ];

    $record = $this->store->get(self::JOB_ID);
    $this->assertNotNull($record, sprintf('Turn %d left a record.', $this->turns));
    $this->assertSame(AgentState::identity($handed), $record['last_user'], sprintf('Turn %d ran for the session\'s last user message.', $this->turns));
    $this->assertTrue($record['finished'], sprintf('Turn %d finished.', $this->turns));

    return array_values(array_slice($this->capture->calls(), $before));
  }

  /**
   * The persisted transcript.
   */
  private function transcript(): Transcript {
    return Transcript::fromArray($this->store->get(self::JOB_ID)['transcript']);
  }

  /**
   * Asserts a call carried its segment's tag.
   */
  private function assertSegmentTag(array $call, int $segment, string $message = ''): void {
    $this->assertContains(UsageLedger::SEGMENT_TAG_PREFIX . $segment, $call['tags'], $message !== '' ? $message : sprintf('The call is tagged with segment %d.', $segment));
  }

  /**
   * Asserts the later prompt is a byte extension of the earlier one.
   *
   * On failure the message names the first diverging byte with the text
   * around it on both sides, which is where a rewrite happened.
   */
  private function assertPrefix(string $earlier, string $later, string $message): void {
    if (str_starts_with($later, $earlier)) {
      $this->addToAssertionCount(1);
      return;
    }
    $limit = min(strlen($earlier), strlen($later));
    $at = 0;
    while ($at < $limit && $earlier[$at] === $later[$at]) {
      $at++;
    }
    $around = static fn (string $wire): string => str_replace("\x00", '|', substr($wire, max(0, $at - 80), 160));
    $this->fail(sprintf(
      "%s The later prompt diverges from the earlier one at byte %d (earlier %d bytes, later %d bytes).\nEarlier around the divergence: %s\nLater around the divergence:   %s",
      $message,
      $at,
      strlen($earlier),
      strlen($later),
      $around($earlier),
      $around($later),
    ));
  }

  /**
   * The floor of a prompt: the tools and the system prompt, serialized.
   */
  private static function floor(ChatInput $input): string {
    return 'TOOLS ' . json_encode($input->getChatTools()?->renderToolsArray() ?? [], JSON_THROW_ON_ERROR) . "\x00"
      . 'SYSTEM ' . $input->getSystemPrompt() . "\x00";
  }

  /**
   * The messages of a wire: everything after the floor.
   *
   * What a drift leaves untouched: the floor is rewritten, the transcript
   * below it is still append-only.
   */
  private static function body(string $wire): string {
    return substr($wire, strpos($wire, "\x00", strpos($wire, "\x00") + 1) + 1);
  }

  /**
   * A prompt as bytes: the floor, then each message, NUL-terminated.
   *
   * Each message is its role, its tool id, its text and its calls (id,
   * name, arguments) closed by a NUL, so a prompt is a byte prefix of
   * another only when every one of its messages is sent again unchanged
   * and the other adds whole messages after them.
   */
  private static function wire(ChatInput $input): string {
    $wire = self::floor($input);
    foreach ($input->getMessages() as $message) {
      $wire .= 'MESSAGE ' . $message->getRole() . ' ' . ($message->getToolsId() ?? '') . "\n" . $message->getText();
      foreach ($message->getTools() ?? [] as $call) {
        $wire .= "\nCALL " . json_encode($call->getOutputRenderArray(), JSON_THROW_ON_ERROR);
      }
      $wire .= "\x00";
    }
    return $wire;
  }

}
