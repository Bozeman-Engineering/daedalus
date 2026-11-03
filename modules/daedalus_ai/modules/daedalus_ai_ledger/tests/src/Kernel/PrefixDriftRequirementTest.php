<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai_ledger\Kernel;

use Drupal\ai\Dto\TokenUsageDto;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\daedalus_ai_ledger\PrefixReport;
use Drupal\daedalus_ai_ledger\UsageLedger;
use Drupal\daedalus_ai_test\Plugin\AiProvider\StubProvider;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\daedalus_ai_ledger\Traits\DaedalusAiServicesTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The status report's prompt cache entry, read off the ledger rows.
 *
 * Rows are written the way UsageLedgerFeedTest writes them (genuine stub
 * provider calls through the ai module's plugin manager, so the real proxy
 * dispatches the real event and the ledger computes the prefix loss from
 * the scripted counts), then the hook is invoked through the module
 * handler, not called on the class: a #[Hook] the container never
 * registered would pass a direct call and still be invisible to the site.
 */
#[Group('daedalus_ai_ledger')]
#[RunTestsInSeparateProcesses]
class PrefixDriftRequirementTest extends KernelTestBase {

  use DaedalusAiServicesTrait;
  use UserCreationTrait;

  /**
   * The entry's key on the status report.
   */
  private const KEY = 'daedalus_ai_ledger_prefix_drift';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'key',
    'ai',
    'daedalus_ai_test',
    'daedalus_ai_ledger',
  ];

  /**
   * The provider proxy the legs run through.
   */
  private object $provider;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $this->registerDaedalusAiServices($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('ai_usage');
    $this->installConfig(['system', 'daedalus_ai_ledger']);
    $this->setUpCurrentUser(['name' => 'editor']);
    $this->provider = $this->container->get('ai.provider')->createInstance('daedalus_ai_test_stub');
  }

  /**
   * Runs one leg through the stub provider inside a conversation.
   *
   * @param string $conversation
   *   The runner id the leg is tagged with.
   * @param string[] $extra_tags
   *   Tags beside the runner and segment tags.
   */
  private function leg(string $conversation, array $extra_tags = []): void {
    $this->provider->chat(new ChatInput([new ChatMessage('user', 'Hello')]), StubProvider::MODEL_ID, [
      UsageLedger::RUNNER_TAG_PREFIX . $conversation,
      UsageLedger::SEGMENT_TAG_PREFIX . '1',
      ...$extra_tags,
    ]);
  }

  /**
   * Scripts the stub's usage for the next legs.
   *
   * @param int $input
   *   The uncached input tokens.
   * @param int $cached
   *   The cache reads.
   */
  private function usage(int $input, int $cached): void {
    $this->container->get('daedalus_ai_test.chat_capture')->setUsage(new TokenUsageDto(input: $input, output: 10, total: $input + 10 + $cached, cached: $cached));
  }

  /**
   * Moves a conversation's drifted rows out of the report's window.
   *
   * @param string $conversation
   *   The runner id.
   */
  private function ageDriftedRows(string $conversation): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('ai_usage');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('conversation', $conversation)
      ->condition('prefix_drift', 1)
      ->execute();
    $this->assertNotEmpty($ids);
    $old = $this->container->get('datetime.time')->getRequestTime() - UsageLedger::REPORT_WINDOW - 60;
    foreach ($storage->loadMultiple($ids) as $row) {
      $row->set('created', $old)->save();
    }
  }

  /**
   * The ledger's entry, through the hook system.
   */
  private function requirement(): array {
    $requirements = $this->container->get('module_handler')->invoke('daedalus_ai_ledger', 'runtime_requirements') ?? [];
    $this->assertArrayHasKey(self::KEY, $requirements);
    $entry = $requirements[self::KEY];
    $this->assertSame('Daedalus AI prompt cache', (string) $entry['title']);
    return $entry;
  }

  /**
   * Info with nothing measured, OK with the expiry number, Warning on drift.
   */
  public function testEntryFollowsTheRows(): void {
    $entry = $this->requirement();
    $this->assertSame(RequirementSeverity::Info, $entry['severity'], 'An empty ledger is informational.');
    $this->assertSame('No conversations measured yet', (string) $entry['value']);
    $this->assertArrayNotHasKey('description', $entry);

    // A conversation's first leg measures nothing (no predecessor), so the
    // ledger is still unmeasured.
    $this->leg('job-1');
    $entry = $this->requirement();
    $this->assertSame(RequirementSeverity::Info, $entry['severity'], 'A row with no measured loss is not a measurement.');

    // Leg 2 reads the whole previous prompt (1500) from cache: measured,
    // no loss.
    $this->usage(40, 1500);
    $this->leg('job-1');
    $entry = $this->requirement();
    $this->assertSame(RequirementSeverity::OK, $entry['severity']);
    $this->assertSame('No prefix drift in 1 leg over the last 7 days', (string) $entry['value']);
    $this->assertArrayNotHasKey('description', $entry, 'With nothing re-sent there is nothing to say.');

    // Leg 3 reads 1000 under the previous size (1540) with no drift tag:
    // the cache expired between turns. Still OK, with the number stated.
    // Its own prompt is 1580.
    $this->usage(1040, 540);
    $this->leg('job-1');
    $entry = $this->requirement();
    $this->assertSame(RequirementSeverity::OK, $entry['severity'], 'An expiry re-send is data, not a warning.');
    $this->assertSame('No prefix drift in 2 legs over the last 7 days', (string) $entry['value']);
    $this->assertStringContainsString('1000 tokens were re-sent after the provider cache expired between turns', (string) $entry['description']);

    // Leg 4 carries the runner's drift tag and re-sends 1480 of 1580.
    $this->usage(1480, 100);
    $this->leg('job-1', [UsageLedger::PREFIX_DRIFT_TAG]);
    $entry = $this->requirement();
    $this->assertSame(RequirementSeverity::Warning, $entry['severity'], 'A drifted leg warns.');
    $this->assertSame('1 leg re-sent a rewritten prompt prefix in 1 conversation (1480 tokens re-paid)', (string) $entry['value']);
    $description = (string) $entry['description'];
    $this->assertStringContainsString('system prompt or the tool list', $description);
    $this->assertStringContainsString('Conversations affected: job-1.', $description, 'The conversation is named.');
    $this->assertStringContainsString('daedalus_ai_memory', $description, 'The admin is told where the exact leg is logged.');
    $this->assertStringNotContainsString('1000 tokens were re-sent', $description, 'The expiry number stays off the warning.');

    // A second conversation drifts too (its first leg ran on the scripted
    // 1580 prompt, so its drifted leg loses 1480 as well): both counted,
    // both named, the newest drift first.
    $this->leg('job-2');
    $this->usage(1480, 100);
    $this->leg('job-2', [UsageLedger::PREFIX_DRIFT_TAG]);
    $entry = $this->requirement();
    $this->assertSame(RequirementSeverity::Warning, $entry['severity']);
    $this->assertSame('2 legs re-sent a rewritten prompt prefix in 2 conversations (2960 tokens re-paid)', (string) $entry['value']);
    $this->assertStringContainsString('Conversations affected: job-2, job-1.', (string) $entry['description']);

    // The report itself. No leg carried the rebuilt tag, so no
    // conversation counts as rebuilt and the description says nothing of
    // it.
    $report = $this->container->get('daedalus_ai_ledger.usage_ledger')->prefixReport(0);
    $this->assertSame(4, $report->measuredLegs);
    $this->assertSame(2, $report->driftedLegs);
    $this->assertSame(2, $report->driftedConversations);
    $this->assertSame(2960, $report->driftedTokens);
    $this->assertSame(1000, $report->expiryTokens);
    $this->assertSame(0, $report->rebuiltConversations);
    $this->assertFalse($report->rebuilt());
    $this->assertStringNotContainsString('rebuilt', (string) $entry['description'], 'Without a rebuilt row the sentence is left out.');

    // A drifted leg older than seven days does not count.
    $this->ageDriftedRows('job-2');
    $entry = $this->requirement();
    $this->assertSame(RequirementSeverity::Warning, $entry['severity']);
    $this->assertSame('1 leg re-sent a rewritten prompt prefix in 1 conversation (1480 tokens re-paid)', (string) $entry['value']);
    $this->assertStringContainsString('Conversations affected: job-1.', (string) $entry['description']);
    $this->assertStringNotContainsString('job-2', (string) $entry['description']);

    // With every drifted leg aged out the entry is OK again over the two
    // measured legs left inside the window (job-2's first leg measured
    // nothing), the expiry number still inside it.
    $this->ageDriftedRows('job-1');
    $entry = $this->requirement();
    $this->assertSame(RequirementSeverity::OK, $entry['severity'], 'A fixed break clears on its own.');
    $this->assertSame('No prefix drift in 2 legs over the last 7 days', (string) $entry['value']);
    $this->assertStringContainsString('1000 tokens were re-sent', (string) $entry['description']);
  }

  /**
   * Rebuilt conversations are counted in one sentence at every severity.
   *
   * A rebuilt row inside the window adds the sentence with the count to
   * the description, whatever the drift answer set (Info, OK, Warning), and
   * the count is per conversation, not per row; aged out of the window the
   * sentence goes.
   */
  public function testRebuiltConversationsAreCounted(): void {
    $ledger = $this->container->get('daedalus_ai_ledger.usage_ledger');
    $sentence = "the agent's record had expired or could not be read";

    // A rebuilt conversation's first leg measures nothing: still Info, and
    // the sentence is the whole description.
    $this->leg('job-1', [UsageLedger::REBUILT_TAG]);
    $entry = $this->requirement();
    $this->assertSame(RequirementSeverity::Info, $entry['severity'], 'A rebuild changes nothing of the drift answer.');
    $this->assertSame("1 conversation was rebuilt from the visible chat ($sentence).", (string) $entry['description']);
    $this->assertSame(1, $ledger->prefixReport(0)->rebuiltConversations);

    // Its second leg measures with no loss: OK, the sentence kept.
    $this->usage(40, 1500);
    $this->leg('job-1');
    $entry = $this->requirement();
    $this->assertSame(RequirementSeverity::OK, $entry['severity']);
    $this->assertSame('No prefix drift in 1 leg over the last 7 days', (string) $entry['value']);
    $this->assertStringContainsString("1 conversation was rebuilt from the visible chat ($sentence).", (string) $entry['description']);

    // A second rebuilt conversation, and a second rebuilt row in the first
    // (its record read back unreadable twice): two conversations, not
    // three rows.
    $this->leg('job-2', [UsageLedger::REBUILT_TAG]);
    $this->leg('job-1', [UsageLedger::REBUILT_TAG]);
    $entry = $this->requirement();
    $this->assertSame(RequirementSeverity::OK, $entry['severity']);
    $this->assertStringContainsString("2 conversations were rebuilt from the visible chat ($sentence).", (string) $entry['description']);
    $this->assertSame(2, $ledger->prefixReport(0)->rebuiltConversations);

    // With an expiry re-send the two numbers share the description, the
    // expiry first.
    $this->usage(1000, 580);
    $this->leg('job-1');
    $entry = $this->requirement();
    $this->assertSame(RequirementSeverity::OK, $entry['severity']);
    $description = (string) $entry['description'];
    $this->assertStringContainsString('tokens were re-sent after the provider cache expired', $description);
    $this->assertStringContainsString("2 conversations were rebuilt from the visible chat ($sentence).", $description);
    $this->assertLessThan(strpos($description, '2 conversations were rebuilt'), strpos($description, 'tokens were re-sent'), 'The drift answer\'s own sentence comes first.');

    // Drift: the Warning's description ends with the same sentence.
    $this->usage(1480, 100);
    $this->leg('job-2', [UsageLedger::PREFIX_DRIFT_TAG]);
    $entry = $this->requirement();
    $this->assertSame(RequirementSeverity::Warning, $entry['severity']);
    $description = (string) $entry['description'];
    $this->assertStringContainsString('Conversations affected: job-2.', $description);
    $this->assertStringEndsWith("2 conversations were rebuilt from the visible chat ($sentence).", $description);

    // Rebuilt rows older than the window do not count.
    $storage = $this->container->get('entity_type.manager')->getStorage('ai_usage');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('rebuilt', 1)->execute();
    $this->assertCount(3, $ids);
    $old = $this->container->get('datetime.time')->getRequestTime() - UsageLedger::REPORT_WINDOW - 60;
    foreach ($storage->loadMultiple($ids) as $row) {
      $row->set('created', $old)->save();
    }
    $entry = $this->requirement();
    $this->assertSame(RequirementSeverity::Warning, $entry['severity'], 'The drift answer is untouched.');
    $this->assertStringNotContainsString('rebuilt', (string) $entry['description'], 'With every rebuilt row aged out the sentence goes.');
    $this->assertSame(0, $ledger->prefixReport($old)->rebuiltConversations);
  }

  /**
   * The message names at most five conversations and counts the rest.
   */
  public function testConversationSampleIsCapped(): void {
    // Each conversation: a first leg on a 1580 prompt, then a drifted leg
    // reading 100 of it (a loss of 1480).
    for ($i = 1; $i <= PrefixReport::CONVERSATION_SAMPLE + 2; $i++) {
      $this->usage(1080, 500);
      $this->leg("job-$i");
      $this->usage(1480, 100);
      $this->leg("job-$i", [UsageLedger::PREFIX_DRIFT_TAG]);
    }
    $entry = $this->requirement();
    $this->assertSame(RequirementSeverity::Warning, $entry['severity']);
    $this->assertSame('7 legs re-sent a rewritten prompt prefix in 7 conversations (10360 tokens re-paid)', (string) $entry['value']);
    $description = (string) $entry['description'];
    $this->assertStringContainsString('and 2 more', $description);
    $this->assertSame(PrefixReport::CONVERSATION_SAMPLE, preg_match_all('/job-\d+/', $description), 'Five ids are named.');

    $report = $this->container->get('daedalus_ai_ledger.usage_ledger')->prefixReport(0);
    $this->assertSame(7, $report->driftedConversations);
    $this->assertCount(PrefixReport::CONVERSATION_SAMPLE, $report->driftedConversationIds);
  }

}
