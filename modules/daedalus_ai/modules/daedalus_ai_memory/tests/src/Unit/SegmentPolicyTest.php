<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai_memory\Unit;

use Drupal\ai\Dto\HostnameFilterDto;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutputInterface;
use Drupal\ai\OperationType\Chat\Tools\ToolsInput;
use Drupal\daedalus_ai\Context\Block;
use Drupal\daedalus_ai\Context\BlockKind;
use Drupal\daedalus_ai\Provider\ContextCeilingInterface;
use Drupal\daedalus_ai\Provider\RenderingPlan;
use Drupal\daedalus_ai_memory\Form\SettingsForm;
use Drupal\daedalus_ai_memory\Segment\CarryOverGenerator;
use Drupal\daedalus_ai_memory\Segment\CloseReason;
use Drupal\daedalus_ai_memory\Segment\LastLeg;
use Drupal\daedalus_ai_memory\Segment\SegmentBudget;
use Drupal\daedalus_ai_memory\Segment\SegmentPolicy;
use Drupal\daedalus_ai_memory\Transcript\Entry;
use Drupal\daedalus_ai_memory\Transcript\Rendering;
use Drupal\daedalus_ai_memory\Transcript\ToolCall;
use Drupal\daedalus_ai_memory\Transcript\Transcript;
use Drupal\daedalus_blueprint\SkillsResolverInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the segment policy: the budget, the close decision, the carry-over.
 *
 * What is load-bearing: a segment closes only on a measurement the ledger
 * made of this segment (never on an estimate, never on a row from the
 * segment before), on budget only once a turn has finished, and on a
 * reframing skill only past the minimum and only for the leg that
 * absorbed its result (so a re-sent result cannot close the segment it
 * was re-sent into); the effective budget is the smallest bound that
 * applies, the configured budget and every override first clamped up to
 * the minimum (the provider's ceiling and maximum input, each less the
 * headroom, still cut below it), with drupal/ai's 1024 placeholder
 * maximum input ignored; the hard limit is the provider's smallest bound
 * less the headroom and the effective budget never exceeds it, a segment
 * measured past it is past the limit, and a finished turn past it closes
 * as Ceiling before Budget; the carry-over request is the closing
 * segment's exact prefix plus one
 * instruction, with an assistant message carrying unanswered tool calls
 * left off; and the current turn re-sent on a reframe starts at the user's
 * own turn, not at a message the wrapper interjected.
 */
#[Group('daedalus_ai_memory')]
class SegmentPolicyTest extends UnitTestCase {

  /**
   * The effective budget is the smallest bound that applies.
   */
  #[DataProvider('budgets')]
  public function testBudgetBounds(SegmentBudget $budget, int $effective, string $source): void {
    $this->assertSame($effective, $budget->effective());
    $this->assertSame($source, $budget->source());
  }

  /**
   * Cases for testBudgetBounds().
   */
  public static function budgets(): array {
    $case = static fn (int $configured, ?int $ceiling, ?int $max_input, int $headroom, int $effective, string $source, int $minimum = 50000): array => [
      new SegmentBudget($configured, $ceiling, $max_input, $headroom, $minimum),
      $effective,
      $source,
    ];
    return [
      'configured alone' => $case(80000, NULL, NULL, 32000, 80000, SegmentBudget::SOURCE_CONFIGURED),
      'ceiling less headroom below' => $case(80000, 60000, NULL, 32000, 28000, SegmentBudget::SOURCE_CEILING),
      'ceiling less headroom above' => $case(80000, 200000, NULL, 32000, 80000, SegmentBudget::SOURCE_CONFIGURED),
      'ceiling just above the configured budget before the headroom' => $case(80000, 100000, NULL, 32000, 68000, SegmentBudget::SOURCE_CEILING),
      'ceiling no larger than the headroom ignored' => $case(80000, 32000, NULL, 32000, 80000, SegmentBudget::SOURCE_CONFIGURED),
      'max input less headroom below' => $case(80000, NULL, 100000, 32000, 68000, SegmentBudget::SOURCE_MAX_INPUT),
      'max input less headroom above' => $case(80000, NULL, 200000, 32000, 80000, SegmentBudget::SOURCE_CONFIGURED),
      'placeholder max input ignored' => $case(80000, NULL, 1024, 32000, 80000, SegmentBudget::SOURCE_CONFIGURED),
      'max input equal to headroom ignored' => $case(80000, NULL, 32000, 32000, 80000, SegmentBudget::SOURCE_CONFIGURED),
      'smallest of all three' => $case(80000, 50000, 70000, 32000, 18000, SegmentBudget::SOURCE_CEILING),
      'configured wins a tie' => $case(60000, 60000, NULL, 0, 60000, SegmentBudget::SOURCE_CONFIGURED),
      'no headroom' => $case(80000, NULL, 50000, 0, 50000, SegmentBudget::SOURCE_MAX_INPUT),
      'no headroom on the ceiling' => $case(80000, 50000, NULL, 0, 50000, SegmentBudget::SOURCE_CEILING),
      'under the minimum clamped up to it' => $case(20000, NULL, NULL, 32000, 50000, SegmentBudget::SOURCE_MINIMUM),
      'at the minimum is the configured budget' => $case(50000, NULL, NULL, 32000, 50000, SegmentBudget::SOURCE_CONFIGURED),
      'above the minimum untouched' => $case(80000, NULL, NULL, 32000, 80000, SegmentBudget::SOURCE_CONFIGURED),
      'ceiling under the minimum still cuts' => $case(20000, 40000, NULL, 32000, 8000, SegmentBudget::SOURCE_CEILING),
      'max input under the minimum still cuts' => $case(20000, NULL, 70000, 32000, 38000, SegmentBudget::SOURCE_MAX_INPUT),
      'minimum wins a tie with the ceiling' => $case(20000, 82000, NULL, 32000, 50000, SegmentBudget::SOURCE_MINIMUM),
      'no minimum' => $case(20000, NULL, NULL, 32000, 20000, SegmentBudget::SOURCE_CONFIGURED, 0),
    ];
  }

  /**
   * The hard limit is the provider's smallest bound less the headroom.
   */
  #[DataProvider('hardLimits')]
  public function testHardLimit(SegmentBudget $budget, ?int $limit): void {
    $this->assertSame($limit, $budget->hardLimit());
    if ($limit !== NULL) {
      $this->assertLessThanOrEqual($limit, $budget->effective(), 'The effective budget never exceeds the hard limit.');
    }
  }

  /**
   * Cases for testHardLimit().
   */
  public static function hardLimits(): array {
    $case = static fn (?int $ceiling, ?int $max_input, int $headroom, ?int $limit): array => [
      new SegmentBudget(80000, $ceiling, $max_input, $headroom, 50000),
      $limit,
    ];
    return [
      'ceiling only' => $case(100000, NULL, 32000, 68000),
      'max input only' => $case(NULL, 100000, 32000, 68000),
      'both, the ceiling smaller' => $case(50000, 90000, 32000, 18000),
      'both, the max input smaller' => $case(100000, 90000, 32000, 58000),
      'neither' => $case(NULL, NULL, 32000, NULL),
      'max input under the headroom' => $case(NULL, 1024, 32000, NULL),
      'max input under the headroom beside a ceiling' => $case(100000, 1024, 32000, 68000),
      'ceiling under the headroom' => $case(30000, NULL, 32000, NULL),
      'no headroom' => $case(200000, 500000, 0, 200000),
      'a limit under the configured budget' => $case(40000, NULL, 32000, 8000),
    ];
  }

  /**
   * A budget refuses a bound that is no bound.
   */
  public function testBudgetRefusals(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('at least 1 token');
    new SegmentBudget(0, NULL, NULL, 0, 0);
  }

  /**
   * A budget refuses a negative minimum.
   */
  public function testBudgetRefusesNegativeMinimum(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('minimum must not be negative');
    new SegmentBudget(80000, NULL, NULL, 0, -1);
  }

  /**
   * The budget read from config, the provider and the model.
   *
   * The default applies to a model without a row; a row overrides it; the
   * default and a row under the minimum are clamped up to it and name it;
   * a provider declaring a ceiling bounds it; a provider whose maximum
   * input is the placeholder does not.
   */
  public function testBudgetFromConfigAndProvider(): void {
    $policy = $this->policy([
      'segment_budget_overrides' => [
        ['model' => 'small', 'tokens' => 60000],
        ['model' => 'tiny', 'tokens' => 20000],
      ],
    ]);

    $plain = new \stdClass();
    $budget = $policy->budget($plain, 'large');
    $this->assertSame(80000, $budget->effective());
    $this->assertSame(SegmentBudget::SOURCE_CONFIGURED, $budget->source(), 'A budget above the minimum is untouched.');
    $this->assertSame(50000, $budget->minimum);
    $this->assertSame(60000, $policy->budget($plain, 'small')->effective());
    $budget = $policy->budget($plain, 'tiny');
    $this->assertSame(20000, $budget->configured, 'The override is kept as configured.');
    $this->assertSame(50000, $budget->effective(), 'An override under the minimum is clamped up to it.');
    $this->assertSame(SegmentBudget::SOURCE_MINIMUM, $budget->source());
    $budget = $this->policy(['segment_budget_tokens' => 20000])->budget($plain, 'large');
    $this->assertSame(50000, $budget->effective(), 'A default budget under the minimum is clamped up to it.');
    $this->assertSame(SegmentBudget::SOURCE_MINIMUM, $budget->source());
    $this->assertSame(20000, $this->policy(['segment_budget_tokens' => 20000, 'segment_budget_minimum_tokens' => 0])->budget($plain, 'large')->effective(), 'A minimum of zero is no floor.');

    $placeholder = new class() {

      /**
       * What drupal/ai's ChatTrait answers when a provider never overrode it.
       */
      public function getMaxInputTokens(string $model_id): int {
        return 1024;
      }

    };
    $budget = $policy->budget($placeholder, 'large');
    $this->assertSame(1024, $budget->maxInput);
    $this->assertSame(80000, $budget->effective(), 'The placeholder bounds nothing.');

    $capable = new class() implements ContextCeilingInterface {

      /**
       * {@inheritdoc}
       */
      public function contextCeiling(string $model_id): ?int {
        return $model_id === 'cliff' ? 200000 : NULL;
      }

      /**
       * A real maximum input.
       */
      public function getMaxInputTokens(string $model_id): int {
        return $model_id === 'cliff' ? 500000 : 100000;
      }

    };
    $budget = $policy->budget($capable, 'cliff');
    $this->assertSame(200000, $budget->ceiling);
    $this->assertSame(500000, $budget->maxInput);
    $this->assertSame(32000, $budget->headroom);
    $this->assertSame(80000, $budget->effective(), 'The configured budget is below both.');
    $this->assertSame(168000, $budget->hardLimit(), 'The hard limit is the ceiling less the headroom.');
    $this->assertNull($policy->budget($plain, 'large')->hardLimit(), 'A provider declaring nothing has no hard limit.');
    $this->assertNull($policy->budget($placeholder, 'large')->hardLimit(), 'The placeholder is no hard limit either.');
    $this->assertSame(68000, $policy->budget($capable, 'large')->effective(), 'The maximum input less the headroom bounds below the configured budget.');
    $this->assertSame(68000, $policy->budget($capable, 'large')->hardLimit(), 'With no ceiling the maximum input less the headroom is the hard limit.');
    $this->assertSame(60000, $policy->budget($capable, 'small')->effective(), 'The override is below the maximum input bound.');
    $budget = $policy->budget($capable, 'tiny');
    $this->assertSame(50000, $budget->effective(), 'The clamped override is below the maximum input bound.');
    $this->assertSame(SegmentBudget::SOURCE_MINIMUM, $budget->source());
    $this->assertSame(38000, $this->policy(['segment_headroom_tokens' => 62000])->budget($capable, 'tiny')->effective(), 'The maximum input less the headroom still cuts under the minimum.');
    $budget = $this->policy(['segment_budget_tokens' => 20000])->budget($capable, 'cliff');
    $this->assertSame(50000, $budget->effective(), 'The clamp applies before the ceiling, which is above it here.');
    $this->assertSame(SegmentBudget::SOURCE_MINIMUM, $budget->source());
  }

  /**
   * A last leg measures only the segment it ran in.
   */
  public function testLastLegMeasures(): void {
    $leg = new LastLeg(2, 90000);
    $this->assertSame(90000, $leg->measures(2));
    $this->assertNull($leg->measures(3), 'A row from the segment before (its last leg, or the carry-over call) is no measurement of the next.');
    $this->assertNull((new LastLeg(NULL, 90000))->measures(1), 'An untagged row measures nothing.');
    $this->assertNull((new LastLeg(1, NULL))->measures(1), 'An unreported prompt size measures nothing.');
  }

  /**
   * The close decision over the measurement, the budget and the leg.
   */
  public function testCloseReason(): void {
    $policy = $this->policy();
    $budget = new SegmentBudget(80000, NULL, NULL, 32000, 50000);
    $transcript = new Transcript();
    $segment = $transcript->openSegment();
    $design = Entry::toolResult('doctrine', 'c1', 'skill:design');
    $skins = Entry::toolResult('catalog', 'c2', 'skill:skins');
    $schema = Entry::toolResult('schema', 'c3', 'schema:node.page');
    $reply = Entry::assistant('done');

    // Nothing measured, nothing closes, however the leg went.
    $this->assertNull($policy->closeReason($segment, NULL, $budget, [$design, $reply], TRUE));
    $this->assertNull($policy->closeReason($segment, new LastLeg(2, 500000), $budget, [$design, $reply], TRUE), 'A row from another segment is no measurement.');

    // Budget: over, and only once the turn has finished.
    $this->assertSame(CloseReason::Budget, $policy->closeReason($segment, new LastLeg(1, 80001), $budget, [$reply], TRUE));
    $this->assertNull($policy->closeReason($segment, new LastLeg(1, 80001), $budget, [$reply], FALSE), 'A turn is never cut in half on budget.');
    $this->assertNull($policy->closeReason($segment, new LastLeg(1, 80000), $budget, [$reply], TRUE), 'At the budget is not past it.');
    $this->assertSame(CloseReason::Budget, $policy->closeReason($segment, new LastLeg(1, 50001), new SegmentBudget(20000, NULL, NULL, 0, 50000), [$reply], TRUE), 'The effective budget (the minimum here), not the configured one.');
    $this->assertSame(CloseReason::Ceiling, $policy->closeReason($segment, new LastLeg(1, 60001), new SegmentBudget(80000, 60000, NULL, 0, 50000), [$reply], TRUE), 'A provider bound is the hard limit too (no headroom here), so past it the reason is the ceiling.');

    // Reframe: a reframing skill answered in this leg, past the minimum.
    $this->assertSame(
      CloseReason::Reframe,
      $policy->closeReason($segment, new LastLeg(1, 25001), $budget, [$design, $reply], FALSE),
    );
    $this->assertSame(
      CloseReason::Reframe,
      $policy->closeReason($segment, new LastLeg(1, 25001), $budget, [$design, $reply], TRUE),
      'A finished turn reframes too: the doctrine is re-sent for the next request.',
    );
    $this->assertNull($policy->closeReason($segment, new LastLeg(1, 25000), $budget, [$design, $reply], FALSE), 'At the minimum is not past it.');
    $this->assertNull($policy->closeReason($segment, new LastLeg(1, 25001), $budget, [$skins, $reply], FALSE), 'A skill that does not reframe does not close.');
    $this->assertNull($policy->closeReason($segment, new LastLeg(1, 25001), $budget, [$schema, $reply], FALSE), 'A keyed result that is no skill does not close.');
    $this->assertNull(
      $policy->closeReason($segment, new LastLeg(1, 25001), $budget, [$reply], FALSE),
      'The entries absorbed in this leg decide, not the segment: a re-sent result cannot close the segment it was re-sent into.',
    );
    $this->assertSame(
      CloseReason::Reframe,
      $policy->closeReason($segment, new LastLeg(1, 90000), $budget, [$design, $reply], TRUE),
      'Past both, the reframe is the reason: the turn is re-sent.',
    );

    $this->assertTrue($policy->reframes('design'));
    $this->assertFalse($policy->reframes('skins'));
    $this->assertFalse($policy->reframes('unknown'), 'A skill the resolver does not know for the account reframes nothing.');
    $this->assertSame(25000, $policy->reframeMinimum());

    // Ceiling: past the hard limit at a finished turn, named before Budget.
    $bounded = new SegmentBudget(60000, 100000, NULL, 32000, 50000);
    $this->assertSame(60000, $bounded->effective());
    $this->assertSame(68000, $bounded->hardLimit());
    $this->assertSame(CloseReason::Ceiling, $policy->closeReason($segment, new LastLeg(1, 70000), $bounded, [$reply], TRUE), 'Past both the budget and the limit, the limit is the reason.');
    $this->assertSame(CloseReason::Budget, $policy->closeReason($segment, new LastLeg(1, 65000), $bounded, [$reply], TRUE), 'Past the budget and under the limit, the budget is the reason.');
    $this->assertNull($policy->closeReason($segment, new LastLeg(1, 70000), $bounded, [$reply], FALSE), 'An unfinished turn past the limit does not close here: the runner ends the turn first.');
    $this->assertSame(
      CloseReason::Reframe,
      $policy->closeReason($segment, new LastLeg(1, 70000), $bounded, [$design, $reply], TRUE),
      'A reframing leg past the limit still reframes: the turn is re-sent.',
    );
  }

  /**
   * A segment is past the limit only when measured above a declared one.
   */
  public function testPastLimit(): void {
    $policy = $this->policy();
    $segment = (new Transcript())->openSegment();
    $bounded = new SegmentBudget(80000, 100000, NULL, 32000, 50000);
    $this->assertSame(68000, $bounded->hardLimit());

    $this->assertTrue($policy->pastLimit($segment, new LastLeg(1, 68001), $bounded), 'Above the limit.');
    $this->assertFalse($policy->pastLimit($segment, new LastLeg(1, 68000), $bounded), 'At the limit is not past it.');
    $this->assertFalse($policy->pastLimit($segment, new LastLeg(1, 40000), $bounded), 'Under the limit.');
    $this->assertFalse($policy->pastLimit($segment, NULL, $bounded), 'Unmeasured: no row yet.');
    $this->assertFalse($policy->pastLimit($segment, new LastLeg(2, 500000), $bounded), 'Unmeasured: a row from another segment.');
    $this->assertFalse($policy->pastLimit($segment, new LastLeg(1, NULL), $bounded), 'Unmeasured: an unreported prompt size.');
    $this->assertFalse($policy->pastLimit($segment, new LastLeg(1, 500000), new SegmentBudget(80000, NULL, NULL, 32000, 50000)), 'No limit: the provider declares no bound.');
    $this->assertFalse($policy->pastLimit($segment, new LastLeg(1, 500000), new SegmentBudget(80000, NULL, 1024, 32000, 50000)), 'No limit: the placeholder maximum input is no bound.');
    $this->assertTrue($policy->pastLimit($segment, new LastLeg(1, 58001), new SegmentBudget(80000, 100000, 90000, 32000, 50000)), 'The smaller declared bound is the limit.');
  }

  /**
   * The current turn starts at the user's own turn, and is re-sent whole.
   */
  public function testCurrentTurnAndResend(): void {
    $transcript = new Transcript();
    $first = $transcript->openSegment();
    $this->assertSame([], $first->currentTurn(), 'No user turn yet.');

    $transcript->appendUserTurn('first', [new Block(BlockKind::CurrentPage, 'node (ID: 1).')]);
    $first->append(Entry::assistant('done'));
    $turn = $transcript->appendUserTurn('second', [new Block(BlockKind::CurrentPage, 'node (ID: 1).')]);
    $call = Entry::assistant('', [new ToolCall('c1', 'eb_skill', ['skill' => 'design'])]);
    $first->append($call);
    $result = Entry::toolResult('doctrine', 'c1', 'skill:design');
    $first->append($result);
    $shots = Entry::user('Screenshot(s) from the tool results above:', [], FALSE);
    $first->append($shots);
    $next = Entry::assistant('', [new ToolCall('c2', 'eb_read_schema', ['entity_type' => 'node', 'bundle' => 'page'])]);
    $first->append($next);

    $this->assertSame([$turn, $call, $result, $shots, $next], $first->currentTurn(), 'From the user\'s own turn on; the interjected screenshots message does not start one.');

    $second = $transcript->openSegment('carried', $first->currentTurn());
    $this->assertSame(2, $second->number());
    $this->assertSame('carried', $second->carryOver());
    $this->assertSame([$turn, $call, $result, $shots, $next], $second->entries(), 'The turn is re-sent at the head of the new segment, the same entries.');
    $this->assertSame(['skill:design'], $transcript->loadedKeys(), 'The re-sent result keeps its key.');
    $this->assertSame($call->toolCalls[0], $second->toolCall('c1'), 'The re-sent call is found by id in the new segment.');
    $this->assertCount(7, $first->entries(), 'The closed segment keeps its entries, the first turn among them.');

    $record = Transcript::fromArray(json_decode(json_encode($transcript->toArray(), JSON_THROW_ON_ERROR), TRUE, flags: JSON_THROW_ON_ERROR));
    $this->assertFalse($record->segments()[1]->entries()[3]->turn, 'The interjection flag survives the record.');
    $this->assertTrue($record->segments()[1]->entries()[0]->turn);
    $this->assertCount(5, $record->currentSegment()->currentTurn());

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('turn flag must be a boolean');
    Transcript::fromArray([
      'version' => 1,
      'segments' => [
        ['number' => 1, 'prefix_hashes' => [], 'entries' => [['type' => 'user', 'text' => '', 'turn' => 'yes']]],
      ],
    ]);
  }

  /**
   * The carry-over request: the exact prefix, then the one instruction.
   */
  public function testCarryOverRequest(): void {
    $template = new ChatInput([new ChatMessage('user', 'the leg as sent')]);
    $template->setSystemPrompt('SYSTEM');
    $tools = new ToolsInput([]);
    $template->setChatTools($tools);
    $filter = new HostnameFilterDto(fullTrust: TRUE);
    $template->setHostnameFilter($filter);

    $user = new ChatMessage('user', "<system-reminder>\nCurrent page (as of this message):\nnode (ID: 1).\n</system-reminder>\n\nMake it bolder");
    $assistant = new ChatMessage('assistant', 'I will.');
    $plan = RenderingPlan::empty()->withPrefixTtl(RenderingPlan::TTL_1H)->withBreakpoint(1, RenderingPlan::TTL_1H);
    $rendering = new Rendering([$user, $assistant], $plan);

    $request = CarryOverGenerator::request($template, $rendering, ['schema:node.page', 'skill:design']);
    $this->assertSame('SYSTEM', $request->getSystemPrompt());
    $this->assertSame($tools, $request->getChatTools(), 'The tools ride the request: they render first and the system block\'s cache marker covers them.');
    $this->assertSame($filter, $request->getHostnameFilter());
    $this->assertFalse($request->isStreamedOutput());
    $this->assertSame($plan->toArray(), RenderingPlan::fromInput($request)->toArray(), 'The plan rides the request so a capable provider places the same breakpoints.');
    $messages = $request->getMessages();
    $this->assertCount(3, $messages);
    $this->assertSame($user, $messages[0], 'The rendered messages are the request\'s, the same objects.');
    $this->assertSame($assistant, $messages[1]);
    $this->assertSame('user', $messages[2]->getRole());
    $this->assertSame(CarryOverGenerator::INSTRUCTION . "\n\nLoaded facts: schema:node.page, skill:design.", $messages[2]->getText());
    $this->assertStringStartsWith('<system-reminder>', CarryOverGenerator::INSTRUCTION);
    $headings = [
      'Intent',
      'Decisions and constraints',
      'Preferences',
      'Tried and rejected',
      'Draft text',
      'The page in hand',
      'Files',
      'Loaded facts',
    ];
    foreach ([...$headings, 'no tool call'] as $needle) {
      $this->assertStringContainsString($needle, CarryOverGenerator::INSTRUCTION);
    }

    $this->assertStringEndsWith('Loaded facts: none.', CarryOverGenerator::request($template, $rendering, [])->getMessages()[2]->getText());

    $bare = new ChatInput([]);
    $bare->setSystemPrompt('S');
    $request = CarryOverGenerator::request($bare, $rendering, []);
    $this->assertNull($request->getChatTools(), 'No tools on the leg, none on the request.');
    $this->assertNull($request->getHostnameFilter());

    // Unanswered tool calls on the last assistant message: it is left off,
    // since the providers refuse a user message after them; the calls are
    // re-sent with the turn.
    $pending = new ChatMessage('assistant', '');
    $pending->setTools([$this->createMock(ToolsFunctionOutputInterface::class)]);
    $request = CarryOverGenerator::request($template, new Rendering([$user, $assistant, $pending], $plan), []);
    $messages = $request->getMessages();
    $this->assertCount(3, $messages);
    $this->assertSame([$user, $assistant], [$messages[0], $messages[1]]);
    $this->assertSame('user', $messages[2]->getRole());

    $answered = new ChatMessage('tool', 'result');
    $answered->setToolsId('c1');
    $request = CarryOverGenerator::request($template, new Rendering([$user, $pending, $answered], $plan), []);
    $this->assertCount(4, $request->getMessages(), 'A tool result last: everything stays, the ask follows it.');

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('at least one message');
    CarryOverGenerator::request($template, new Rendering([$pending], $plan), []);
  }

  /**
   * The carry-over call through the provider: the text, or a refusal.
   */
  public function testCarryOverGenerate(): void {
    $generator = new CarryOverGenerator();
    $request = new ChatInput([new ChatMessage('user', 'ask')]);
    $provider = new class(new ChatMessage('assistant', "  Intent: a bolder hero.\n")) {

      /**
       * The calls made: input, model id, tags.
       */
      public array $calls = [];

      public function __construct(public ChatMessage $reply) {}

      /**
       * Records the call and answers the scripted reply.
       */
      public function chat(ChatInput $input, string $model_id, array $tags = []): ChatOutput {
        $this->calls[] = [$input, $model_id, $tags];
        return new ChatOutput($this->reply, NULL, []);
      }

    };

    $text = $generator->generate($provider, 'claude-x', $request, ['ai_agents_runner_r1', 'daedalus_ai_segment_1']);
    $this->assertSame('Intent: a bolder hero.', $text, 'The reply text, trimmed.');
    $this->assertCount(1, $provider->calls);
    $this->assertSame($request, $provider->calls[0][0]);
    $this->assertSame('claude-x', $provider->calls[0][1]);
    $this->assertSame(['ai_agents_runner_r1', 'daedalus_ai_segment_1', CarryOverGenerator::TAG], $provider->calls[0][2], 'The runner\'s tags, then the carry-over tag.');

    $provider->reply = new ChatMessage('assistant', '   ');
    try {
      $generator->generate($provider, 'claude-x', $request, []);
      $this->fail('An empty answer is refused.');
    }
    catch (\RuntimeException $exception) {
      $this->assertStringContainsString('no text', $exception->getMessage());
    }

    $provider->reply = new ChatMessage('assistant', 'calling');
    $provider->reply->setTools([$this->createMock(ToolsFunctionOutputInterface::class)]);
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('tool call');
    $generator->generate($provider, 'claude-x', $request, []);
  }

  /**
   * The settings form's per-model budget lines.
   */
  public function testParseOverrides(): void {
    $this->assertSame([], SettingsForm::parseOverrides("\n  \n"));
    $this->assertSame(
      [['model' => 'claude-haiku-5-5', 'tokens' => 40000], ['model' => 'grok-4.7', 'tokens' => 150000]],
      SettingsForm::parseOverrides("claude-haiku-5-5: 40000\n\ngrok-4.7:150000\n"),
    );
    foreach (['claude: lots', 'claude: 0', 'claude', ': 5', "a: 1\na: 2"] as $text) {
      try {
        SettingsForm::parseOverrides($text);
        $this->fail(sprintf('"%s" is refused.', $text));
      }
      catch (\InvalidArgumentException) {
        $this->addToAssertionCount(1);
      }
    }
  }

  /**
   * A policy over the install defaults, with overrides, and two skills.
   *
   * @param array $settings
   *   Config values replacing the install defaults.
   */
  private function policy(array $settings = []): SegmentPolicy {
    $resolver = $this->createMock(SkillsResolverInterface::class);
    $resolver->method('getSkill')->willReturnMap([
      ['design', ['label' => 'Design', 'reframe' => TRUE]],
      ['skins', ['label' => 'Skins', 'reframe' => FALSE]],
      ['unknown', NULL],
    ]);
    return new SegmentPolicy(
      $this->getConfigFactoryStub([
        SegmentPolicy::CONFIG => $settings + [
          'segment_budget_tokens' => 80000,
          'segment_budget_minimum_tokens' => 50000,
          'segment_budget_overrides' => [],
          'segment_headroom_tokens' => 32000,
          'reframe_minimum_tokens' => 25000,
        ],
      ]),
      $resolver,
    );
  }

}
