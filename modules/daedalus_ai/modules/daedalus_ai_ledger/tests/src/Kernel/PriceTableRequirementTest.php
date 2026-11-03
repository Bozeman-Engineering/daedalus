<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai_ledger\Kernel;

use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\daedalus_ai_ledger\CostResolver;
use Drupal\daedalus_ai_ledger\UsageLedger;
use Drupal\daedalus_ai_test\Plugin\AiProvider\StubProvider;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\daedalus_ai_ledger\Traits\DaedalusAiServicesTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The status report's price table entry, read off the ledger rows.
 *
 * Rows are written the way UsageLedgerFeedTest writes them (genuine stub
 * provider calls through the ai module's plugin manager, so the real proxy
 * dispatches the real event and the ledger writes the row), then the hook
 * is invoked through the module handler, not called on the class: a
 * #[Hook] the container never registered would pass a direct call and
 * still be invisible to the site.
 */
#[Group('daedalus_ai_ledger')]
#[RunTestsInSeparateProcesses]
class PriceTableRequirementTest extends KernelTestBase {

  use DaedalusAiServicesTrait;
  use UserCreationTrait;

  /**
   * The entry's key on the status report.
   */
  private const KEY = 'daedalus_ai_ledger_prices';

  /**
   * A second model id the stub answers for (the proxy validates none).
   */
  private const OTHER_MODEL = 'stub-alt';

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
   * Runs one leg through the stub provider on a model.
   *
   * @param string $model
   *   The model id the leg runs on.
   */
  private function leg(string $model = StubProvider::MODEL_ID): void {
    $this->provider->chat(new ChatInput([new ChatMessage('user', 'Hello')]), $model, [
      UsageLedger::RUNNER_TAG_PREFIX . 'job-1',
      UsageLedger::SEGMENT_TAG_PREFIX . '1',
    ]);
  }

  /**
   * Replaces the price table.
   *
   * @param list<string> $models
   *   The model ids to give a row to, every bucket priced.
   */
  private function prices(array $models): void {
    $rows = [];
    foreach ($models as $model) {
      $rows[] = [
        'model' => $model,
        'input' => 1000.0,
        'output' => 2500.0,
        'cache_read' => 100.0,
        'cache_write' => 1250.0,
        'per_call' => 0.0,
      ];
    }
    $this->config(CostResolver::SETTINGS)->set(CostResolver::PRICES_KEY, $rows)->save();
  }

  /**
   * The ledger's price table entry, through the hook system.
   */
  private function requirement(): array {
    $requirements = $this->container->get('module_handler')->invoke('daedalus_ai_ledger', 'runtime_requirements') ?? [];
    $this->assertArrayHasKey('daedalus_ai_ledger_prefix_drift', $requirements, 'The prompt cache entry is still there beside it.');
    $this->assertArrayHasKey(self::KEY, $requirements);
    $entry = $requirements[self::KEY];
    $this->assertSame('Daedalus AI price table', (string) $entry['title']);
    return $entry;
  }

  /**
   * Info with no rows, Warning naming the models, OK once they have rows.
   */
  public function testEntryFollowsTheTable(): void {
    $ledger = $this->container->get('daedalus_ai_ledger.usage_ledger');

    $entry = $this->requirement();
    $this->assertSame(RequirementSeverity::Info, $entry['severity'], 'An empty ledger is informational.');
    $this->assertSame('No AI usage measured yet', (string) $entry['value']);
    $this->assertArrayNotHasKey('description', $entry);
    $this->assertSame(0, $ledger->legCount(0));
    $this->assertSame([], $ledger->modelsWithoutRow(0));

    // A leg on the stub model with no table at all: priced at nothing.
    $this->prices([]);
    $this->leg();
    $entry = $this->requirement();
    $this->assertSame(RequirementSeverity::Warning, $entry['severity'], 'A leg no row prices warns.');
    $this->assertSame('1 leg on 1 model without a price row', (string) $entry['value']);
    $description = (string) $entry['description'];
    $this->assertStringContainsString('stub (1 leg, counted at nothing)', $description);
    $this->assertStringContainsString('the usage caps did not see it', $description);
    $this->assertStringContainsString('href="/admin/config/ai/usage-ledger"', $description, 'The settings form is linked.');
    $this->assertSame(['stub' => 1], $ledger->modelsWithoutRow(0));
    $this->assertSame(1, $ledger->legCount(0));

    // With only the fallback row the same leg now reads as counted at the
    // fallback: the answer is computed live against the table, nothing
    // was stamped on the row.
    $this->prices([CostResolver::FALLBACK_MODEL]);
    $entry = $this->requirement();
    $this->assertSame(RequirementSeverity::Warning, $entry['severity']);
    $this->assertSame('1 leg on 1 model without a price row', (string) $entry['value']);
    $this->assertStringContainsString('stub (1 leg, counted at the fallback row)', (string) $entry['description']);
    $this->assertStringNotContainsString('stub (1 leg, counted at nothing)', (string) $entry['description']);

    // A second model, two more legs: named first (seen most recently), the
    // legs summed on the value, each model with its own count.
    $this->leg(self::OTHER_MODEL);
    $this->leg(self::OTHER_MODEL);
    $entry = $this->requirement();
    $this->assertSame('3 legs on 2 models without a price row', (string) $entry['value']);
    $description = (string) $entry['description'];
    $this->assertStringContainsString('stub-alt (2 legs, counted at the fallback row), stub (1 leg, counted at the fallback row)', $description);
    $this->assertSame([self::OTHER_MODEL => 2, StubProvider::MODEL_ID => 1], $ledger->modelsWithoutRow(0));

    // A row for the stub model takes it off the list; the other stays.
    $this->prices([CostResolver::FALLBACK_MODEL, StubProvider::MODEL_ID]);
    $entry = $this->requirement();
    $this->assertSame(RequirementSeverity::Warning, $entry['severity']);
    $this->assertSame('2 legs on 1 model without a price row', (string) $entry['value']);
    $this->assertStringContainsString('stub-alt (2 legs, counted at the fallback row)', (string) $entry['description']);
    $this->assertStringNotContainsString('stub (1 leg', (string) $entry['description']);

    // Rows for both: OK, with the legs still in the window.
    $this->prices([CostResolver::FALLBACK_MODEL, StubProvider::MODEL_ID, self::OTHER_MODEL]);
    $entry = $this->requirement();
    $this->assertSame(RequirementSeverity::OK, $entry['severity'], 'Every model used has a row.');
    $this->assertSame('Every model used in the last 7 days has a price row', (string) $entry['value']);
    $this->assertArrayNotHasKey('description', $entry);
    $this->assertSame([], $ledger->modelsWithoutRow(0));
    $this->assertSame(3, $ledger->legCount(0));

    // The fallback row alone is not a row of the model's own: the table
    // reduced to it warns again.
    $this->prices([CostResolver::FALLBACK_MODEL]);
    $this->assertSame(RequirementSeverity::Warning, $this->requirement()['severity']);

    // Legs older than seven days do not count: aged out, the ledger is
    // back to nothing measured.
    $storage = $this->container->get('entity_type.manager')->getStorage('ai_usage');
    $ids = $storage->getQuery()->accessCheck(FALSE)->execute();
    $this->assertCount(3, $ids);
    $old = $this->container->get('datetime.time')->getRequestTime() - UsageLedger::REPORT_WINDOW - 60;
    foreach ($storage->loadMultiple($ids) as $row) {
      $row->set('created', $old)->save();
    }
    $entry = $this->requirement();
    $this->assertSame(RequirementSeverity::Info, $entry['severity'], 'With every row aged out nothing is measured.');
    $this->assertSame('No AI usage measured yet', (string) $entry['value']);
    $this->assertSame([], $ledger->modelsWithoutRow($old));
    $this->assertSame([self::OTHER_MODEL => 2, StubProvider::MODEL_ID => 1], $ledger->modelsWithoutRow($old - 1), 'The aged rows are still there, outside the window.');
  }

}
