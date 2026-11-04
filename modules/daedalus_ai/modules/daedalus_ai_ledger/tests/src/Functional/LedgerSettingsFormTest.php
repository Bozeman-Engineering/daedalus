<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai_ledger\Functional;

use Drupal\daedalus_ai_ledger\CostResolver;
use Drupal\daedalus_ai_ledger\UsageGate;
use Drupal\daedalus_ai_ledger\UsageRetention;
use Drupal\daedalus_ai_ledger\Window;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\daedalus\Traits\DaedalusFixtureTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The price table's form: the per-call column, the fallback row, the buckets.
 *
 * The form is the one place a human edits the price table, so what it
 * accepts and refuses is the table's grammar: the four token buckets are
 * required per row (a missing one with usage would leave the leg
 * unpriced), the per-call figure is optional and blank saves as 0 (the
 * call itself is free), and `*` is a model id like any other, the
 * fallback row that prices every model without one. The site is the AI
 * umbrella recipe's product, so the shipped table (the seeded rows and
 * the `*` row at the end) is what the form shows before anyone touches
 * it. Admin by explicit exception: the form is an admin-only subject
 * (`administer ai usage ledger`).
 */
#[Group('daedalus_ai_ledger')]
#[RunTestsInSeparateProcesses]
final class LedgerSettingsFormTest extends BrowserTestBase {

  use DaedalusFixtureTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'daedalus_theme';

  /**
   * The form's path.
   */
  private const PATH = '/admin/config/ai/usage-ledger';

  /**
   * {@inheritdoc}
   */
  protected function installRecipePath(): string {
    return dirname($this->root) . '/recipes/daedalus_recipe/ai';
  }

  /**
   * A blank per-call saves 0.0, `*` is a valid model id, buckets are required.
   */
  public function testPriceTable(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer ai usage ledger']));
    $assert = $this->assertSession();

    // The shipped table: the fallback row is the last row, with the
    // per-call figure in the fifth price column.
    $this->drupalGet(self::PATH);
    $assert->statusCodeEquals(200);
    $assert->pageTextContains('Per call ($)');
    $shipped = $this->config(CostResolver::SETTINGS)->get(CostResolver::PRICES_KEY);
    $last = count($shipped) - 1;
    $this->assertSame(CostResolver::FALLBACK_MODEL, $shipped[$last]['model']);
    $assert->fieldValueEquals("prices[$last][model]", CostResolver::FALLBACK_MODEL);
    $assert->fieldValueEquals("prices[$last][per_call]", '0.25');
    $assert->fieldValueEquals("prices[0][per_call]", '0');
    $assert->fieldExists('prices[' . ($last + 1) . '][model]');

    // From an empty table: a row with the per-call figure blank saves it
    // as 0.0 beside the four buckets.
    $this->config(CostResolver::SETTINGS)->set(CostResolver::PRICES_KEY, [])->save();
    $this->drupalGet(self::PATH);
    $this->submitForm([
      'prices[0][model]' => 'claude-haiku-5-5',
      'prices[0][input]' => '0.1',
      'prices[0][cache_read]' => '0.01',
      'prices[0][cache_write]' => '0.125',
      'prices[0][output]' => '0.5',
      'prices[0][per_call]' => '',
    ], 'Save configuration');
    $assert->pageTextContains('The configuration options have been saved.');
    // Config saves a mapping in its schema's key order, so the rows are
    // compared by key, not by position.
    $this->assertEquals([
      [
        'model' => 'claude-haiku-5-5',
        'input' => 0.1,
        'cache_read' => 0.01,
        'cache_write' => 0.125,
        'output' => 0.5,
        'per_call' => 0.0,
      ],
    ], $this->config(CostResolver::SETTINGS)->get(CostResolver::PRICES_KEY));

    // A `*` row with a per-call figure saves and reads back as the
    // fallback row.
    $this->drupalGet(self::PATH);
    $this->submitForm([
      'prices[1][model]' => CostResolver::FALLBACK_MODEL,
      'prices[1][input]' => '10',
      'prices[1][cache_read]' => '0.25',
      'prices[1][cache_write]' => '12.5',
      'prices[1][output]' => '50',
      'prices[1][per_call]' => '0.5',
    ], 'Save configuration');
    $assert->pageTextContains('The configuration options have been saved.');
    $prices = $this->config(CostResolver::SETTINGS)->get(CostResolver::PRICES_KEY);
    $this->assertCount(2, $prices);
    $this->assertEquals([
      'model' => CostResolver::FALLBACK_MODEL,
      'input' => 10.0,
      'cache_read' => 0.25,
      'cache_write' => 12.5,
      'output' => 50.0,
      'per_call' => 0.5,
    ], $prices[1]);
    $this->assertSame(CostResolver::FALLBACK_MODEL, $this->container->get('daedalus_ai_ledger.cost_resolver')->pricedBy('unknown-model'));

    // A row with a token bucket empty is refused and nothing is saved.
    $this->drupalGet(self::PATH);
    $this->submitForm([
      'prices[2][model]' => 'claude-sonnet-5-5',
      'prices[2][input]' => '2',
      'prices[2][cache_read]' => '0.2',
      'prices[2][cache_write]' => '2.5',
      'prices[2][output]' => '',
      'prices[2][per_call]' => '',
    ], 'Save configuration');
    $assert->pageTextContains('Every bucket of claude-sonnet-5-5 needs a price; use 0 to stop counting one.');
    $assert->pageTextNotContains('The configuration options have been saved.');
    $this->assertCount(2, $this->config(CostResolver::SETTINGS)->get(CostResolver::PRICES_KEY));
  }

  /**
   * A site cap with a period and an anchor saves; a bad period is refused.
   *
   * The AI recipe points the chat default at claude-opus-5-5, whose
   * shipped row prices uncached input at 4 USD per million, so a 20 USD
   * cap reads as about 5 million tokens under the table.
   */
  public function testCaps(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer ai usage ledger']));
    $assert = $this->assertSession();

    $this->drupalGet(self::PATH);
    $assert->pageTextContains('Spend windows');
    $assert->fieldExists('caps[0][scope]');
    $assert->fieldExists('caps[0][period]');
    $assert->fieldExists('caps[0][usd]');
    $assert->fieldExists('caps[0][anchor]');
    // No window, no token line.
    $assert->pageTextNotContains('uncached input tokens');

    $this->submitForm([
      'caps[0][scope]' => Window::SCOPE_SITE,
      'caps[0][period]' => 'P1M',
      'caps[0][usd]' => '20',
      'caps[0][anchor]' => '2026-01-01',
    ], 'Save configuration');
    $assert->pageTextContains('The configuration options have been saved.');
    $this->assertEquals([
      ['scope' => 'site', 'period' => 'P1M', 'usd' => 20.0, 'anchor' => '2026-01-01'],
    ], $this->config(CostResolver::SETTINGS)->get(UsageGate::CAPS_KEY));
    $assert->fieldValueEquals('caps[0][scope]', Window::SCOPE_SITE);
    $assert->fieldValueEquals('caps[0][period]', 'P1M');
    $assert->fieldValueEquals('caps[0][anchor]', '2026-01-01');
    // A blank row follows the saved one.
    $assert->fieldExists('caps[1][period]');
    $default = $this->container->get('ai.provider')->getDefaultProviderForOperationType('chat');
    $this->assertSame('claude-opus-5-5', $default['model_id'], 'The recipe\'s chat default is the model the token line is computed for.');
    $assert->pageTextContains('20 USD is about 5 million uncached input tokens of claude-opus-5-5');

    // A period that is not a duration is refused and nothing changes.
    $this->submitForm([
      'caps[1][scope]' => Window::SCOPE_USER,
      'caps[1][period]' => '5 hours',
      'caps[1][usd]' => '2',
    ], 'Save configuration');
    $assert->pageTextContains('A usage cap\'s period must be an ISO 8601 duration such as PT5H, P7D or P1M, not "5 hours".');
    $assert->pageTextNotContains('The configuration options have been saved.');
    $this->assertCount(1, $this->config(CostResolver::SETTINGS)->get(UsageGate::CAPS_KEY));

    // A zero period and an overflowing anchor are refused too.
    $this->drupalGet(self::PATH);
    $this->submitForm([
      'caps[1][scope]' => Window::SCOPE_USER,
      'caps[1][period]' => 'PT0S',
      'caps[1][usd]' => '2',
      'caps[1][anchor]' => '2026-02-30',
    ], 'Save configuration');
    $assert->pageTextContains('A usage cap\'s period cannot be zero ("PT0S").');
    $assert->pageTextContains('A usage cap\'s anchor must be a date as Y-m-d, not "2026-02-30".');
    $this->assertCount(1, $this->config(CostResolver::SETTINGS)->get(UsageGate::CAPS_KEY));

    // A rolling user window beside the site one, and the site one dropped
    // by clearing its period.
    $this->drupalGet(self::PATH);
    $this->submitForm([
      'caps[0][period]' => '',
      'caps[1][scope]' => Window::SCOPE_USER,
      'caps[1][period]' => 'PT5H',
      'caps[1][usd]' => '2.5',
      'caps[1][anchor]' => '',
    ], 'Save configuration');
    $assert->pageTextContains('The configuration options have been saved.');
    $this->assertEquals([
      ['scope' => 'user', 'period' => 'PT5H', 'usd' => 2.5, 'anchor' => ''],
    ], $this->config(CostResolver::SETTINGS)->get(UsageGate::CAPS_KEY));
    $assert->pageTextContains('2.5 USD is about 0.6 million uncached input tokens of claude-opus-5-5');
  }

  /**
   * The retention shows the shipped year, saves a number, states the floor.
   */
  public function testRetention(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer ai usage ledger']));
    $assert = $this->assertSession();

    $this->drupalGet(self::PATH);
    $assert->fieldValueEquals('retention_days', '365');
    $assert->pageTextContains('Never shorter than the longest configured window');

    $this->submitForm(['retention_days' => '30'], 'Save configuration');
    $assert->pageTextContains('The configuration options have been saved.');
    $this->assertSame(30, $this->config(CostResolver::SETTINGS)->get(UsageRetention::RETENTION_KEY));
    $assert->fieldValueEquals('retention_days', '30');

    // With an anchored monthly window the floor is two periods of 31 days,
    // and the description says so.
    $this->submitForm([
      'caps[0][scope]' => Window::SCOPE_SITE,
      'caps[0][period]' => 'P1M',
      'caps[0][usd]' => '20',
      'caps[0][anchor]' => '2026-01-01',
      'retention_days' => '0',
    ], 'Save configuration');
    $assert->pageTextContains('The windows above need 62 days of rows, so a smaller value is raised to that.');
    $this->assertSame(0, $this->config(CostResolver::SETTINGS)->get(UsageRetention::RETENTION_KEY));

    // A blank is refused: the field is required.
    $this->submitForm(['retention_days' => ''], 'Save configuration');
    $assert->pageTextContains('Retention (days) field is required.');
    $this->assertSame(0, $this->config(CostResolver::SETTINGS)->get(UsageRetention::RETENTION_KEY));
  }

}
