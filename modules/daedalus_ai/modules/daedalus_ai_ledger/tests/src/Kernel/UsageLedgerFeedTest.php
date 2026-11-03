<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai_ledger\Kernel;

use Drupal\ai\Dto\TokenUsageDto;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\daedalus_ai_ledger\CostResolver;
use Drupal\daedalus_ai_ledger\Entity\AiUsage;
use Drupal\daedalus_ai_ledger\UsageGate;
use Drupal\daedalus_ai_ledger\UsageLedger;
use Drupal\daedalus_ai_ledger\UsageWindows;
use Drupal\daedalus_ai_ledger\Window;
use Drupal\daedalus_ai_ledger\WindowStatus;
use Drupal\daedalus_ai_test\ChatCapture;
use Drupal\daedalus_ai_test\Plugin\AiProvider\StubProvider;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\daedalus_ai_ledger\Traits\DaedalusAiServicesTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Feeds the ledger one leg through the stub provider and reads it back.
 *
 * The feed is the whole module's seam: ProviderProxy dispatches the
 * post-response event after every provider call, the subscriber writes a
 * row from it, the caps read the rows. So the test drives a genuine
 * provider call (daedalus_ai_test's stub, instantiated through the ai
 * module's plugin manager so the real proxy dispatches the real event)
 * and asserts the row carries the user, the runner id from the request
 * tags, the stub's reported counts and the table's price; then two more
 * legs push a window past its cap and the gate refuses, naming the window
 * and the moment the oldest leg ages out, while the bypass permission is
 * honoured and the conversation's newest row answers the last prompt size.
 *
 * daedalus_ai itself is not enabled (its info.yml drags the full AI stack
 * through daedalus_blueprint_ai and ai_chatbot, none of it needed to count
 * a leg); what the ledger takes from it is registered by
 * DaedalusAiServicesTrait.
 */
#[Group('daedalus_ai_ledger')]
#[RunTestsInSeparateProcesses]
class UsageLedgerFeedTest extends KernelTestBase {

  use DaedalusAiServicesTrait;
  use UserCreationTrait;

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
   * The ledger under test.
   */
  private UsageLedger $ledger;

  /**
   * The gate under test.
   */
  private UsageGate $gate;

  /**
   * The provider proxy the legs run through.
   */
  private object $provider;

  /**
   * The user the legs run as.
   */
  private int $uid;

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
    // system's config carries the date formats the refusal message is
    // worded with.
    $this->installConfig(['system', 'daedalus_ai_ledger']);

    // Round prices for the stub's round counts: 1000 input at $1000/M is
    // $1.00, 200 output at $2500/M is $0.50, 500 cached reads at $100/M is
    // $0.05, so a stub leg costs $1.55.
    $this->config(CostResolver::SETTINGS)
      ->set(CostResolver::PRICES_KEY, [
        [
          'model' => StubProvider::MODEL_ID,
          'input' => 1000.0,
          'output' => 2500.0,
          'cache_read' => 100.0,
          'cache_write' => 1250.0,
        ],
      ])
      ->set(UsageGate::CAPS_KEY, [self::cap(Window::SCOPE_USER, 'PT1H', 4.0)])
      ->save();

    // setUpCurrentUser() creates uid 1 first, so the editor is an ordinary
    // account: the superuser passes every permission check, the bypass
    // among them, and would never be refused.
    $account = $this->setUpCurrentUser(['name' => 'editor']);
    $this->uid = (int) $account->id();
    $this->assertGreaterThan(1, $this->uid);

    $this->ledger = $this->container->get('daedalus_ai_ledger.usage_ledger');
    $this->gate = $this->container->get('daedalus_ai_ledger.usage_gate');
    $this->provider = $this->container->get('ai.provider')->createInstance('daedalus_ai_test_stub');
  }

  /**
   * Runs one leg through the stub provider inside a conversation.
   *
   * @param string $conversation
   *   The runner id the leg is tagged with.
   * @param string $text
   *   The user's words.
   * @param string $segment
   *   The segment the leg is tagged with.
   * @param string[] $extra_tags
   *   Tags beside the runner and segment tags.
   * @param string $model
   *   The model id the leg runs on.
   */
  private function leg(string $conversation, string $text = 'Hello', string $segment = '1', array $extra_tags = [], string $model = StubProvider::MODEL_ID): void {
    $this->provider->chat(new ChatInput([new ChatMessage('user', $text)]), $model, [
      UsageLedger::RUNNER_TAG_PREFIX . $conversation,
      UsageLedger::SEGMENT_TAG_PREFIX . $segment,
      ...$extra_tags,
    ]);
  }

  /**
   * A cap row as the config stores it.
   *
   * @param string $scope
   *   The window's scope.
   * @param string $period
   *   The period as an ISO 8601 duration.
   * @param float $usd
   *   The cap.
   * @param string $anchor
   *   The anchor date, or empty to roll.
   */
  private static function cap(string $scope, string $period, float $usd, string $anchor = ''): array {
    return ['scope' => $scope, 'period' => $period, 'usd' => $usd, 'anchor' => $anchor];
  }

  /**
   * Scripts the stub's usage for the next legs.
   *
   * @param int $input
   *   The uncached input tokens.
   * @param int|null $cached
   *   The cache reads, or NULL for an unreported count.
   */
  private function usage(int $input, ?int $cached): void {
    $this->container->get('daedalus_ai_test.chat_capture')->setUsage(new TokenUsageDto(input: $input, output: 10, total: $input + 10 + ($cached ?? 0), cached: $cached));
  }

  /**
   * Every ai_usage row, by id.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   The rows.
   */
  private function rows(): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('ai_usage');
    $storage->resetCache();
    return $storage->loadMultiple();
  }

  /**
   * One leg writes one row with uid, runner id and cost; the gate caps.
   */
  public function testLegWritesRowAndGateRefusesPastCap(): void {
    $this->assertTrue($this->gate->check($this->container->get('current_user'))->allowed, 'An empty ledger allows.');

    $this->leg('job-1');

    $rows = $this->rows();
    $this->assertCount(1, $rows, 'One leg through the proxy writes one row.');
    $row = reset($rows);
    $this->assertInstanceOf(AiUsage::class, $row);
    $this->assertSame($this->uid, (int) $row->getOwnerId(), 'The row runs as the current user.');
    $this->assertSame('job-1', $row->conversation(), 'The runner id comes off the request tags.');
    $this->assertSame('1', $row->segment());
    $this->assertSame('daedalus_ai_test_stub', $row->get('provider')->value);
    $this->assertSame(StubProvider::MODEL_ID, $row->get('model')->value);
    $this->assertSame('chat', $row->get('operation_type')->value);
    $this->assertNotEmpty($row->get('request_id')->value, 'The request thread id is kept to pair a streamed leg.');
    $this->assertGreaterThan(0, $row->created());

    $usage = $row->usage();
    $this->assertSame(ChatCapture::DEFAULT_INPUT_TOKENS, $usage->input);
    $this->assertSame(ChatCapture::DEFAULT_OUTPUT_TOKENS, $usage->output);
    $this->assertSame(ChatCapture::DEFAULT_CACHED_TOKENS, $usage->cachedRead);
    $this->assertNull($usage->cacheWrite, 'A count no source reported stays NULL, never 0.');
    $this->assertNull($usage->cacheWrite5m);
    $this->assertNull($usage->reasoning);
    $this->assertSame(1500, $row->promptTokens());
    $this->assertNull($usage->reportedCostUsd, 'The stub declares no ReportedCostInterface.');
    $this->assertNull($row->get('reported_cost')->value);
    $this->assertEqualsWithDelta(1.55, (float) $row->get('table_cost')->value, 1e-9);
    $this->assertEqualsWithDelta(1.55, $row->cost(), 1e-9, 'Without a report the table prices the leg.');

    // Still under the $4 cap after one leg.
    $this->assertTrue($this->gate->check($this->container->get('current_user'))->allowed);

    // The second leg brings the hour to $3.10, the third to $4.65: past
    // the cap, so the gate refuses, naming the window and the moment the
    // oldest leg leaves it (dropping it leaves $3.10, under the cap).
    $this->leg('job-1', 'Again');
    $this->assertTrue($this->gate->check($this->container->get('current_user'))->allowed);
    $this->leg('job-1', 'Once more');

    $verdict = $this->gate->check($this->container->get('current_user'));
    $this->assertFalse($verdict->allowed, 'The window has passed its cap.');
    $this->assertSame(Window::SCOPE_USER, $verdict->scope);
    $this->assertSame('PT1H', $verdict->window->periodSpec);
    $this->assertSame(4.0, $verdict->window->usd);
    $this->assertEqualsWithDelta(4.65, $verdict->spentUsd, 1e-9);
    $legs = $this->ledger->legsForUser($this->uid, 0);
    $this->assertCount(3, $legs, 'The caps read the three legs.');
    $oldest = min(array_column($legs, 'created'));
    $this->assertSame($oldest + 3600, $verdict->resetAt);
    $this->assertStringContainsString('1 hour', $verdict->message);
    $this->assertStringContainsString('resets at', $verdict->message);

    // Judged an hour and a second later the window has cleared.
    $this->assertTrue($this->gate->check($this->container->get('current_user'), $oldest + 3601)->allowed);

    // The bypass permission is never refused.
    $admin = $this->createUser([UsageGate::BYPASS_PERMISSION], 'admin');
    $this->setCurrentUser($admin);
    $this->leg('job-2');
    $this->leg('job-2');
    $this->leg('job-2');
    $this->leg('job-2');
    $this->assertTrue($this->gate->check($admin)->allowed, 'A user with the bypass permission is allowed past the cap.');
    $this->assertFalse($this->gate->check($this->container->get('entity_type.manager')->getStorage('user')->load($this->uid))->allowed, 'The editor is still refused: the caps sum per user.');

    // The conversation's newest row answers the segment window.
    $newest = $this->ledger->newestLeg('job-2');
    $this->assertNotNull($newest);
    $this->assertSame((int) $admin->id(), (int) $newest->getOwnerId());
    $this->assertSame(1500, $newest->promptTokens());
    $this->assertNull($this->ledger->newestLeg('job-none'));

    // With no windows configured everything is allowed.
    $this->config(CostResolver::SETTINGS)->set(UsageGate::CAPS_KEY, [])->save();
    $this->assertTrue($this->gate->check($this->container->get('entity_type.manager')->getStorage('user')->load($this->uid))->allowed);
  }

  /**
   * A site window sums every user's legs and refuses everyone past its cap.
   *
   * The editor's three legs reach the hour's site cap, so a second
   * account that spent nothing is refused by it, with the site wording
   * and the scope on the verdict; legsForSite() reads both users' rows
   * once the second one has a leg.
   */
  public function testSiteWindowSumsEveryUser(): void {
    $this->config(CostResolver::SETTINGS)
      ->set(UsageGate::CAPS_KEY, [self::cap(Window::SCOPE_SITE, 'PT1H', 4.0)])
      ->save();
    $editor = $this->container->get('current_user');
    $other = $this->createUser([], 'other');

    $this->leg('job-6');
    $this->leg('job-6', 'Again');
    $this->assertTrue($this->gate->check($other)->allowed, 'Under the site cap, the second account may run.');

    $this->leg('job-6', 'Once more');
    $verdict = $this->gate->check($other);
    $this->assertFalse($verdict->allowed, 'The site window refuses an account that spent nothing itself.');
    $this->assertSame(Window::SCOPE_SITE, $verdict->scope);
    $this->assertEqualsWithDelta(4.65, $verdict->spentUsd, 1e-9, 'The site window sums the other user\'s legs.');
    $this->assertStringStartsWith('This site has reached its AI usage limit for 1 hour. It resets at', $verdict->message);
    $this->assertFalse($this->gate->check($editor)->allowed, 'The spender is refused too.');

    $this->assertCount(3, $this->ledger->legsForSite(0));
    $this->assertSame([], $this->ledger->legsForUser((int) $other->id(), 0), 'The second account has no legs of its own.');

    // A leg by the second account (run directly: the gate is the runner's
    // to honour) lands in the site's legs beside the editor's.
    $this->setCurrentUser($other);
    $this->leg('job-7');
    $this->assertCount(4, $this->ledger->legsForSite(0), 'legsForSite() answers both users\' rows.');
    $this->assertCount(1, $this->ledger->legsForUser((int) $other->id(), 0));
    $this->assertCount(3, $this->ledger->legsForUser($this->uid, 0));
  }

  /**
   * The bypass permission skips user windows only.
   *
   * A user cap of one dollar and a site cap of four over the hour: the
   * bypassing account is allowed past the user cap its first leg already
   * exceeds, and refused by the site cap once three legs reach it.
   */
  public function testBypassSkipsUserWindowsOnly(): void {
    $this->config(CostResolver::SETTINGS)
      ->set(UsageGate::CAPS_KEY, [
        self::cap(Window::SCOPE_SITE, 'PT1H', 4.0),
        self::cap(Window::SCOPE_USER, 'PT1H', 1.0),
      ])
      ->save();
    $admin = $this->createUser([UsageGate::BYPASS_PERMISSION], 'admin');
    $this->setCurrentUser($admin);

    $this->leg('job-8');
    $this->assertTrue($this->gate->check($admin)->allowed, 'The bypass skips the user window the leg exceeded.');
    $editor = $this->container->get('entity_type.manager')->getStorage('user')->load($this->uid);
    $this->assertTrue($this->gate->check($editor)->allowed, 'The editor spent nothing: under both windows.');

    $this->leg('job-8', 'Again');
    $this->leg('job-8', 'Once more');
    $verdict = $this->gate->check($admin);
    $this->assertFalse($verdict->allowed, 'The site window admits no bypass.');
    $this->assertSame(Window::SCOPE_SITE, $verdict->scope);
    $this->assertStringStartsWith('This site has reached', $verdict->message);
    $this->assertFalse($this->gate->check($editor)->allowed, 'The site window refuses the editor too.');
  }

  /**
   * An anchored window sums since its boundary and resets at the next.
   *
   * A daily site window anchored at the epoch, judged in UTC: the rows
   * created now sit after today's midnight, so the window is reached and
   * the reset is tomorrow's midnight, not an aging-out moment.
   */
  public function testAnchoredWindowResetsAtTheBoundary(): void {
    $this->config('system.date')->set('timezone.default', 'UTC')->save();
    $this->config(CostResolver::SETTINGS)
      ->set(UsageGate::CAPS_KEY, [self::cap(Window::SCOPE_SITE, 'P1D', 1.0, '1970-01-01')])
      ->save();

    $this->leg('job-9');
    $verdict = $this->gate->check($this->container->get('current_user'));
    $this->assertFalse($verdict->allowed);
    $now = $this->container->get('datetime.time')->getRequestTime();
    $midnight = $now - ($now % 86400);
    $this->assertSame($midnight + 86400, $verdict->resetAt, 'The reset is the next boundary.');
    $this->assertStringStartsWith('This site has reached its AI usage limit for 1 day. It resets at', $verdict->message);

    // Judged at the next boundary the window is empty again.
    $this->assertTrue($this->gate->check($this->container->get('current_user'), $midnight + 86400)->allowed);
  }

  /**
   * The status reads each window as the fraction left and when that changes.
   *
   * No caps and a bypassing account answer nothing to show. A user cap of
   * one dollar over the hour: untouched, the whole cap is left and nothing
   * is inside to age out; after a leg costing 0.25 three quarters are left
   * and the fraction next rises when that leg ages out; after a leg
   * costing 1.0 the cap is reached, nothing is left, and the reset is the
   * window's clearing moment (dropping the quarter leg leaves 1.0, still
   * at the cap, so it is the dollar leg's aging-out). A site cap anchored
   * to the first of the month resets at the next first whether or not it
   * is reached, and sums a second user's leg; with both scopes configured
   * the site window comes first.
   */
  public function testStatus(): void {
    $editor = $this->container->get('current_user');
    $now = $this->container->get('datetime.time')->getRequestTime();
    $formatter = $this->container->get('date.formatter');

    $this->config(CostResolver::SETTINGS)->set(UsageGate::CAPS_KEY, [])->save();
    $this->assertSame([], $this->gate->status($editor), 'No caps, nothing to show.');

    // The stub's 1000 input tokens at 250 USD per million cost 0.25, the
    // other buckets counting nothing.
    $this->config(CostResolver::SETTINGS)
      ->set(CostResolver::PRICES_KEY, [self::price(250.0)])
      ->set(UsageGate::CAPS_KEY, [self::cap(Window::SCOPE_USER, 'PT1H', 1.0)])
      ->save();
    $statuses = $this->gate->status($editor);
    $this->assertCount(1, $statuses);
    $this->assertSame(Window::SCOPE_USER, $statuses[0]->scope);
    $this->assertSame('PT1H', $statuses[0]->period);
    $this->assertSame('1 hour', $statuses[0]->label($formatter));
    $this->assertSame(1.0, $statuses[0]->remaining, 'Nothing spent: the whole cap is left.');
    $this->assertFalse($statuses[0]->reached);
    $this->assertNull($statuses[0]->resetAt, 'Nothing inside, nothing to age out.');

    $this->leg('job-10');
    $created = $this->ledger->newestLeg('job-10')->created();
    $statuses = $this->gate->status($editor);
    $this->assertCount(1, $statuses);
    $this->assertEqualsWithDelta(0.75, $statuses[0]->remaining, 1e-9);
    $this->assertFalse($statuses[0]->reached);
    $this->assertSame($created + 3600, $statuses[0]->resetAt, 'An open rolling window next rises when its oldest leg ages out.');

    $admin = $this->createUser([UsageGate::BYPASS_PERMISSION], 'admin');
    $this->assertSame([], $this->gate->status($admin), 'A bypassing account over a user window alone sees no meter.');
    $this->assertSame([], $this->gate->windowsFor($admin), 'No window applies to them.');

    // A leg costing 1.0 reaches the cap.
    $this->config(CostResolver::SETTINGS)->set(CostResolver::PRICES_KEY, [self::price(1000.0)])->save();
    $this->leg('job-10', 'Again');
    $second = $this->ledger->newestLeg('job-10')->created();
    $statuses = $this->gate->status($editor);
    $this->assertCount(1, $statuses);
    $this->assertSame(0.0, $statuses[0]->remaining);
    $this->assertTrue($statuses[0]->reached);
    $this->assertSame($second + 3600, $statuses[0]->resetAt, 'The dollar leg must age out before the window clears.');
    $window = $this->gate->windows()[0];
    $this->assertSame(UsageWindows::resetAt($this->ledger->legsForUser($this->uid, 0), $window, $now), $statuses[0]->resetAt, 'A reached rolling window resets when the gate says it does.');
    $this->assertFalse($this->gate->check($editor)->allowed);

    // A site window anchored to the first of the month, in UTC: the reset
    // is the next first whatever was spent, and a second user's leg counts.
    $this->config('system.date')->set('timezone.default', 'UTC')->save();
    $this->config(CostResolver::SETTINGS)
      ->set(UsageGate::CAPS_KEY, [self::cap(Window::SCOPE_SITE, 'P1M', 10.0, '1970-01-01')])
      ->save();
    $next_first = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone('UTC'))->modify('first day of next month midnight')->getTimestamp();
    $other = $this->createUser([], 'other');
    $this->setCurrentUser($other);
    $this->leg('job-11');
    $statuses = $this->gate->status($editor);
    $this->assertCount(1, $statuses);
    $this->assertSame(Window::SCOPE_SITE, $statuses[0]->scope);
    $this->assertSame('P1M', $statuses[0]->period);
    $this->assertSame('1 month', $statuses[0]->label($formatter));
    $this->assertEqualsWithDelta(0.775, $statuses[0]->remaining, 1e-9, 'The site window sums the editor\'s 1.25 and the other user\'s 1.0 against 10.');
    $this->assertFalse($statuses[0]->reached);
    $this->assertSame($next_first, $statuses[0]->resetAt, 'An open anchored window still names its next boundary.');
    $this->assertEquals($statuses, $this->gate->status($other), 'A site window reads the same for every account.');

    $this->config(CostResolver::SETTINGS)
      ->set(UsageGate::CAPS_KEY, [
        self::cap(Window::SCOPE_USER, 'PT1H', 1.0),
        self::cap(Window::SCOPE_SITE, 'P1M', 2.0, '1970-01-01'),
      ])
      ->save();
    $statuses = $this->gate->status($editor);
    $this->assertSame([Window::SCOPE_SITE, Window::SCOPE_USER], array_map(static fn (WindowStatus $s): string => $s->scope, $statuses), 'Site windows first, whatever the config order.');
    $this->assertSame(0.0, $statuses[0]->remaining);
    $this->assertTrue($statuses[0]->reached, 'The site window is reached at 2.25 against 2.');
    $this->assertSame($next_first, $statuses[0]->resetAt, 'A reached anchored window resets at the same boundary.');
    $this->assertTrue($statuses[1]->reached, 'The user window is the editor\'s own, still at 1.25 against 1.');

    // The bypassing account over both scopes: the site window applies to
    // them (check() refuses them on it), the user window does not, and
    // windowsFor() is the one definition both read.
    $this->assertSame([Window::SCOPE_SITE], array_map(static fn (Window $w): string => $w->scope, $this->gate->windowsFor($admin)));
    $admin_statuses = $this->gate->status($admin);
    $this->assertCount(1, $admin_statuses, 'A bypassing account sees the site window alone.');
    $this->assertEquals($statuses[0], $admin_statuses[0], 'The site window reads the same for the bypasser as for the editor.');
    $this->assertFalse($this->gate->check($admin)->allowed, 'The site window refuses the bypasser as it shows on their meter.');
    $this->assertSame(Window::SCOPE_SITE, $this->gate->check($admin)->scope);
  }

  /**
   * A price row for the stub model counting input alone.
   *
   * @param float $input
   *   USD per million input tokens; the other buckets count nothing.
   */
  private static function price(float $input): array {
    return [
      'model' => StubProvider::MODEL_ID,
      'input' => $input,
      'output' => 0.0,
      'cache_read' => 0.0,
      'cache_write' => 0.0,
    ];
  }

  /**
   * The row records the prefix loss from the counts and the drift tag.
   *
   * The loss is the previous same-segment leg's prompt size less this
   * leg's cache reads: zero when the prefix held (within the jitter), the
   * re-sent tokens when it did not; NULL with no same-segment predecessor
   * or with a predecessor on another model; the drift flag is the runner's
   * tag alone.
   */
  public function testPrefixLossAndDrift(): void {
    // Leg 1: no predecessor. The stub's default usage is 1000 input and
    // 500 cached, a prompt of 1500.
    $this->leg('job-3');
    $row = $this->ledger->newestLeg('job-3');
    $this->assertNotNull($row);
    $this->assertNull($row->prefixLoss(), 'The first leg of a conversation measures nothing.');
    $this->assertFalse($row->prefixDrifted());
    $this->assertSame(1500, $row->promptTokens());

    // Leg 2 reads the whole previous prompt from cache: loss 0.
    $this->usage(40, 1500);
    $this->leg('job-3', 'Again');
    $row = $this->ledger->newestLeg('job-3');
    $this->assertSame(0, $row->prefixLoss(), 'A cached read equal to the previous prompt size is no loss.');
    $this->assertFalse($row->prefixDrifted());
    $this->assertSame(1540, $row->promptTokens());

    // Leg 3 reads 4 tokens under the previous size (1540): the framing
    // jitter, counted as zero.
    $this->usage(44, 1536);
    $this->leg('job-3', 'Once more');
    $row = $this->ledger->newestLeg('job-3');
    $this->assertSame(0, $row->prefixLoss(), 'A gap under the jitter allowance is zero.');
    $this->assertSame(1580, $row->promptTokens());

    // Leg 4 reads 1000 under the previous size (1580) with no drift tag:
    // the cache expired between turns.
    $this->usage(1000, 580);
    $this->leg('job-3', 'After a pause');
    $row = $this->ledger->newestLeg('job-3');
    $this->assertSame(1000, $row->prefixLoss(), 'The tokens the previous leg had cached that this one re-sent.');
    $this->assertFalse($row->prefixDrifted(), 'Without the tag a loss is an expiry, not drift.');
    $this->assertSame(1580, $row->promptTokens());

    // Leg 5 carries the runner's drift tag and re-sends almost everything.
    $this->usage(1480, 100);
    $this->leg('job-3', 'Drifted', '1', [UsageLedger::PREFIX_DRIFT_TAG]);
    $row = $this->ledger->newestLeg('job-3');
    $this->assertTrue($row->prefixDrifted(), 'The drift tag sets the flag.');
    $this->assertSame(1480, $row->prefixLoss());
    $this->assertSame('1', $row->get('prefix_drift')->value);

    // Leg 6 opens segment 2: the predecessor is in another segment.
    $this->usage(1480, 100);
    $this->leg('job-3', 'New segment', '2');
    $row = $this->ledger->newestLeg('job-3');
    $this->assertNull($row->prefixLoss(), 'The first leg of a segment measures nothing.');
    $this->assertFalse($row->prefixDrifted());
    $this->assertSame('2', $row->segment());

    // Leg 7 runs on another model in the same segment: a model switch
    // re-writes the cache once by design, so nothing is measured.
    $this->usage(40, 1580);
    $this->leg('job-3', 'On another model', '2', [], 'stub-alt');
    $row = $this->ledger->newestLeg('job-3');
    $this->assertSame('stub-alt', $row->model());
    $this->assertNull($row->prefixLoss(), 'A predecessor on another model measures nothing, whatever the cached count.');

    // Leg 8 is back on the first model: its predecessor is the other one.
    $this->usage(40, 1620);
    $this->leg('job-3', 'Back', '2');
    $row = $this->ledger->newestLeg('job-3');
    $this->assertSame(StubProvider::MODEL_ID, $row->model());
    $this->assertNull($row->prefixLoss(), 'The predecessor ran on another model.');

    // Leg 9 reports no cached count: unmeasured.
    $this->usage(40, NULL);
    $this->leg('job-3', 'Unreported', '2');
    $row = $this->ledger->newestLeg('job-3');
    $this->assertNull($row->usage()->cachedRead);
    $this->assertNull($row->prefixLoss(), 'An unreported cached count measures nothing.');

    // Leg 10 reads more than the predecessor's size (40 input, nothing
    // cached, nothing written): clamped at zero.
    $this->usage(10, 100);
    $this->leg('job-3', 'Over', '2');
    $row = $this->ledger->newestLeg('job-3');
    $this->assertSame(0, $row->prefixLoss(), 'A read past the previous size is clamped at zero.');

    // A leg outside any conversation measures nothing either.
    $this->provider->chat(new ChatInput([new ChatMessage('user', 'Rewrite this prompt')]), StubProvider::MODEL_ID);
    $rows = $this->rows();
    $row = end($rows);
    $this->assertInstanceOf(AiUsage::class, $row);
    $this->assertNull($row->conversation());
    $this->assertNull($row->prefixLoss());
    $this->assertFalse($row->prefixDrifted());
  }

  /**
   * The row records the runner's rebuilt tag, and nothing else sets it.
   */
  public function testRebuiltTag(): void {
    $this->leg('job-4', 'Rebuilt', '1', [UsageLedger::REBUILT_TAG]);
    $row = $this->ledger->newestLeg('job-4');
    $this->assertNotNull($row);
    $this->assertTrue($row->rebuilt(), 'The rebuilt tag sets the flag.');
    $this->assertSame('1', $row->get('rebuilt')->value);
    $this->assertFalse($row->prefixDrifted(), 'A rebuild is not drift.');

    $this->leg('job-4', 'Continued');
    $row = $this->ledger->newestLeg('job-4');
    $this->assertFalse($row->rebuilt(), 'An untagged leg is not a rebuild, whatever its conversation.');
    $this->assertSame('0', $row->get('rebuilt')->value, 'The column defaults to FALSE, never NULL.');

    $this->leg('job-5');
    $this->assertFalse($this->ledger->newestLeg('job-5')->rebuilt());
  }

  /**
   * A leg outside a conversation on an unpriced model still lands, unpriced.
   */
  public function testUntaggedUnpricedLeg(): void {
    $this->config(CostResolver::SETTINGS)->set(CostResolver::PRICES_KEY, [])->save();

    $this->provider->chat(new ChatInput([new ChatMessage('user', 'Rewrite this prompt')]), StubProvider::MODEL_ID);

    $rows = $this->rows();
    $this->assertCount(1, $rows);
    $row = reset($rows);
    $this->assertInstanceOf(AiUsage::class, $row);
    $this->assertNull($row->conversation(), 'No runner tag, no conversation.');
    $this->assertNull($row->segment());
    $this->assertSame(1500, $row->promptTokens(), 'The counts are kept whatever the price.');
    $this->assertNull($row->cost(), 'An unpriced model leaves the cost NULL, not zero.');
    $legs = $this->ledger->legsForUser($this->uid, 0);
    $this->assertCount(1, $legs);
    $this->assertNull($legs[0]['cost'], 'The caps read the unpriced leg as weighing nothing.');
    $this->assertSame($row->created(), $legs[0]['created']);
  }

  /**
   * With only the `*` row in the table, an unpriced model rides it.
   *
   * The same leg as testUntaggedUnpricedLeg() (no runner tag, the stub's
   * default counts), but the table holds one row, the fallback, so the
   * cost is the fallback price: the stub's 1000 input, 200 output and 500
   * cached reads at the same per-million figures the fixture uses
   * ($1.55) plus the row's per-call figure ($0.25), and the caps read it
   * as weighing exactly that.
   */
  public function testUntaggedLegPricedByFallbackRow(): void {
    $this->config(CostResolver::SETTINGS)->set(CostResolver::PRICES_KEY, [
      [
        'model' => CostResolver::FALLBACK_MODEL,
        'input' => 1000.0,
        'output' => 2500.0,
        'cache_read' => 100.0,
        'cache_write' => 1250.0,
        'per_call' => 0.25,
      ],
    ])->save();
    $this->assertSame(CostResolver::FALLBACK_MODEL, $this->container->get('daedalus_ai_ledger.cost_resolver')->pricedBy(StubProvider::MODEL_ID));

    $this->provider->chat(new ChatInput([new ChatMessage('user', 'Rewrite this prompt')]), StubProvider::MODEL_ID);

    $rows = $this->rows();
    $this->assertCount(1, $rows);
    $row = reset($rows);
    $this->assertInstanceOf(AiUsage::class, $row);
    $this->assertNull($row->conversation());
    $this->assertSame(StubProvider::MODEL_ID, $row->model(), 'The row names the model the leg ran on, not the row that priced it.');
    $this->assertSame(1500, $row->promptTokens());
    $this->assertEqualsWithDelta(1.80, $row->cost(), 1e-9, 'The fallback row prices the tokens and adds its per-call figure.');
    $legs = $this->ledger->legsForUser($this->uid, 0);
    $this->assertCount(1, $legs);
    $this->assertEqualsWithDelta(1.80, $legs[0]['cost'], 1e-9, 'The caps count the fallback-priced leg.');
  }

}
