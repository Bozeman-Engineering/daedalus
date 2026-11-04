<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai_ledger\Kernel;

use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\daedalus_ai_ledger\CostResolver;
use Drupal\daedalus_ai_ledger\Entity\AiUsage;
use Drupal\daedalus_ai_ledger\UsageGate;
use Drupal\daedalus_ai_ledger\UsageLedger;
use Drupal\daedalus_ai_ledger\UsageRetention;
use Drupal\daedalus_ai_ledger\Window;
use Drupal\daedalus_ai_test\Plugin\AiProvider\StubProvider;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\daedalus_ai_ledger\Traits\DaedalusAiServicesTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Rows older than the retention go on cron; the windows set its floor.
 *
 * The rows are written through the stub provider the way the feed test
 * writes them and aged by setting `created`; the purge runs through the
 * hook system (`module_handler->invoke('daedalus_ai_ledger', 'cron')`, so
 * a #[Hook] the container never registered would show), and what is
 * asserted is which rows survive: the shipped default keeps a year, a
 * retention under the longest configured window is raised to that
 * window's longest span (a rolling P7D, an anchored P1M), 0 keeps every
 * row, and a site that never had the key keeps every row too, since an
 * unset key reads as 0 until the update hook seeds it.
 */
#[Group('daedalus_ai_ledger')]
#[RunTestsInSeparateProcesses]
class UsageRetentionTest extends KernelTestBase {

  use DaedalusAiServicesTrait;
  use UserCreationTrait;

  private const DAY = 86400;

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
   * The retention under test.
   */
  private UsageRetention $retention;

  /**
   * The provider proxy the legs run through.
   */
  private object $provider;

  /**
   * The request time the rows are aged against.
   */
  private int $now;

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
    $this->retention = $this->container->get('daedalus_ai_ledger.usage_retention');
    $this->provider = $this->container->get('ai.provider')->createInstance('daedalus_ai_test_stub');
    $this->now = $this->container->get('datetime.time')->getRequestTime();
  }

  /**
   * Writes one row aged so many days back, and answers its id.
   *
   * @param int $days_old
   *   How many days before the request time the row was recorded.
   */
  private function row(int $days_old): int {
    $this->provider->chat(new ChatInput([new ChatMessage('user', 'Hello')]), StubProvider::MODEL_ID, [
      UsageLedger::RUNNER_TAG_PREFIX . 'job-1',
      UsageLedger::SEGMENT_TAG_PREFIX . '1',
    ]);
    $storage = $this->container->get('entity_type.manager')->getStorage('ai_usage');
    $ids = $storage->getQuery()->accessCheck(FALSE)->sort('id', 'DESC')->range(0, 1)->execute();
    $row = $storage->load(reset($ids));
    assert($row instanceof AiUsage);
    $row->set('created', $this->now - $days_old * self::DAY)->save();
    return (int) $row->id();
  }

  /**
   * The ids of the rows still there, ascending.
   *
   * @return list<int>
   *   The ids.
   */
  private function ids(): array {
    $ids = $this->container->get('entity_type.manager')->getStorage('ai_usage')->getQuery()
      ->accessCheck(FALSE)
      ->sort('id')
      ->execute();
    return array_values(array_map('intval', $ids));
  }

  /**
   * Runs the module's cron through the hook system.
   */
  private function cron(): void {
    $this->container->get('module_handler')->invoke('daedalus_ai_ledger', 'cron');
  }

  /**
   * Sets the retention and the caps.
   *
   * @param int $days
   *   The retention in days.
   * @param list<array<string, mixed>> $caps
   *   The cap rows.
   */
  private function configure(int $days, array $caps = []): void {
    $this->config(CostResolver::SETTINGS)
      ->set(UsageRetention::RETENTION_KEY, $days)
      ->set(UsageGate::CAPS_KEY, $caps)
      ->save();
  }

  /**
   * The shipped default keeps a year; cron deletes what is older.
   */
  public function testDefaultKeepsOneYear(): void {
    $this->assertSame(365, $this->config(CostResolver::SETTINGS)->get(UsageRetention::RETENTION_KEY), 'The install config ships a year.');
    $this->assertSame(365, $this->retention->configuredDays());
    $this->assertSame(0, $this->retention->windowFloorSeconds(), 'No windows, no floor.');
    $this->assertSame(365 * self::DAY, $this->retention->retentionSeconds());
    $this->assertTrue($this->container->get('database')->schema()->indexExists('ai_usage', 'ai_usage__created'), 'The purge reads by created alone.');

    $fresh = $this->row(0);
    $kept = $this->row(364);
    $this->row(366);
    $this->row(400);
    $this->assertCount(4, $this->ids());

    $this->cron();
    $this->assertSame([$fresh, $kept], $this->ids(), 'The rows older than a year are gone; the rest stay.');

    $this->cron();
    $this->assertSame([$fresh, $kept], $this->ids(), 'A second run finds nothing to delete.');
  }

  /**
   * A retention under the longest window is raised to that window's span.
   */
  public function testWindowsSetTheFloor(): void {
    // A rolling P7D spans seven days: a one-day retention becomes seven.
    $this->configure(1, [
      ['scope' => Window::SCOPE_USER, 'period' => 'PT5H', 'usd' => 1.0, 'anchor' => ''],
      ['scope' => Window::SCOPE_USER, 'period' => 'P7D', 'usd' => 5.0, 'anchor' => ''],
    ]);
    $this->assertSame(7 * self::DAY, $this->retention->windowFloorSeconds());
    $this->assertSame(7 * self::DAY, $this->retention->retentionSeconds(), 'One day is raised to the seven the window needs.');

    $two = $this->row(2);
    $six = $this->row(6);
    $this->row(8);
    $this->cron();
    $this->assertSame([$two, $six], $this->ids(), 'The rows inside the longest window stay; the one past it goes.');

    // An anchored P1M spans up to two periods of 31 days: sixty-two.
    $this->configure(1, [
      ['scope' => Window::SCOPE_SITE, 'period' => 'P1M', 'usd' => 20.0, 'anchor' => '2026-01-01'],
    ]);
    $this->assertSame(62 * self::DAY, $this->retention->windowFloorSeconds());
    $this->assertSame(62 * self::DAY, $this->retention->retentionSeconds());

    $forty = $this->row(40);
    $this->row(70);
    $this->cron();
    $this->assertSame([$two, $six, $forty], $this->ids(), 'A row the anchored window may still sum stays; one past two periods goes.');

    // A retention longer than every window is its own number.
    $this->configure(90, [
      ['scope' => Window::SCOPE_SITE, 'period' => 'P1M', 'usd' => 20.0, 'anchor' => '2026-01-01'],
    ]);
    $this->assertSame(90 * self::DAY, $this->retention->retentionSeconds(), 'Ninety days is above the floor and stands.');
  }

  /**
   * Zero keeps every row, and so does a site the update hook has not seeded.
   */
  public function testZeroKeepsEveryRow(): void {
    $this->configure(0, [
      ['scope' => Window::SCOPE_USER, 'period' => 'P7D', 'usd' => 5.0, 'anchor' => ''],
    ]);
    $this->assertNull($this->retention->retentionSeconds(), 'Zero is forever, windows or not.');

    $old = $this->row(1000);
    $this->cron();
    $this->assertSame([$old], $this->ids(), 'Nothing is deleted at zero.');
    $this->assertSame(0, $this->retention->purge());

    // A site installed before the setting existed has no key until
    // daedalus_ai_ledger_update_11106() seeds it; until then every row is
    // kept, the safe direction for a purge.
    $this->config(CostResolver::SETTINGS)->clear(UsageRetention::RETENTION_KEY)->save();
    $this->assertSame(0, $this->retention->configuredDays());
    $this->assertNull($this->retention->retentionSeconds());
    $this->cron();
    $this->assertSame([$old], $this->ids());
  }

  /**
   * The purge stops at its limit and takes the rest on the next run.
   */
  public function testPurgeIsBounded(): void {
    $this->configure(10);
    $first = $this->row(20);
    $second = $this->row(30);
    $third = $this->row(40);
    $kept = $this->row(5);

    $ledger = $this->container->get('daedalus_ai_ledger.usage_ledger');
    $this->assertSame(2, $ledger->deleteBefore($this->now - 10 * self::DAY, 2), 'Two of the three old rows, oldest first.');
    $this->assertSame([$first, $kept], $this->ids());
    $this->assertSame(1, $ledger->deleteBefore($this->now - 10 * self::DAY, UsageLedger::PURGE_BATCH * 3), 'The last old row goes on the next call, whatever the batch.');
    $this->assertSame([$kept], $this->ids());
    $this->assertSame(0, $ledger->deleteBefore($this->now - 10 * self::DAY, 10));
    $this->assertNotContains($second, $this->ids());
    $this->assertNotContains($third, $this->ids());
  }

}
