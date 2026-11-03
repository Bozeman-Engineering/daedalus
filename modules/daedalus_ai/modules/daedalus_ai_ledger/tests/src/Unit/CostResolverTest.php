<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai_ledger\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\daedalus_ai\Provider\Usage;
use Drupal\daedalus_ai_ledger\CostResolver;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins how a leg is priced: the reported cost first, the table second.
 *
 * The table is the caps' one counting knob, so what matters is that each
 * bucket prices exactly its count (cache reads at their own price, never
 * as input), that a zero bucket stops counting without hiding the row,
 * that a missing bucket with usage answers NULL rather than an
 * undercount, that a model with no row of its own rides the `*` fallback
 * row and answers NULL only when that row is missing too, that the
 * per-call figure is added once whatever the counts, and that a
 * provider's own report wins.
 */
#[Group('daedalus_ai_ledger')]
class CostResolverTest extends UnitTestCase {

  /**
   * A resolver over a price table.
   *
   * @param array $prices
   *   The `prices` config rows.
   */
  private function resolver(array $prices): CostResolver {
    return new CostResolver($this->getConfigFactoryStub([
      CostResolver::SETTINGS => [CostResolver::PRICES_KEY => $prices],
    ]));
  }

  /**
   * The four buckets each price their own count, USD per million.
   */
  public function testTablePricesEachBucket(): void {
    $resolver = $this->resolver([
      ['model' => 'claude-sonnet-5-5', 'input' => 2.0, 'output' => 10.0, 'cache_read' => 0.2, 'cache_write' => 2.5],
    ]);
    $usage = new Usage(input: 1_000_000, cachedRead: 1_000_000, cacheWrite: 1_000_000, output: 1_000_000, reasoning: 500);

    $this->assertSame(14.7, $resolver->tableCost($usage, 'claude-sonnet-5-5'));
    $this->assertSame(14.7, $resolver->resolve($usage, 'claude-sonnet-5-5'), 'Without a reported cost the table answers.');
  }

  /**
   * A cached read is priced as one, an order of magnitude under input.
   */
  public function testCachedReadsAreNotInput(): void {
    $resolver = $this->resolver([
      ['model' => 'm', 'input' => 2.0, 'output' => 10.0, 'cache_read' => 0.2, 'cache_write' => 2.5],
    ]);

    $this->assertSame(0.002, $resolver->tableCost(new Usage(cachedRead: 10_000), 'm'));
    $this->assertSame(0.02, $resolver->tableCost(new Usage(input: 10_000), 'm'));
  }

  /**
   * The provider's reported cost wins over the table.
   */
  public function testReportedCostWins(): void {
    $resolver = $this->resolver([
      ['model' => 'm', 'input' => 2.0, 'output' => 10.0, 'cache_read' => 0.2, 'cache_write' => 2.5],
    ]);
    $usage = new Usage(input: 1_000_000, output: 0, reportedCostUsd: 0.0421);

    $this->assertSame(0.0421, $resolver->resolve($usage, 'm'));
    $this->assertSame(2.0, $resolver->tableCost($usage, 'm'), 'The table cost stays readable beside the report.');
    $this->assertSame(0.0421, $resolver->resolve($usage, 'unpriced'), 'A report prices an unpriced model.');
  }

  /**
   * A model with no row and no fallback row answers NULL, never zero.
   */
  public function testUnpricedModel(): void {
    $resolver = $this->resolver([
      ['model' => 'm', 'input' => 2.0, 'output' => 10.0, 'cache_read' => 0.2, 'cache_write' => 2.5],
    ]);

    $this->assertNull($resolver->tableCost(new Usage(input: 10), 'other'));
    $this->assertNull($resolver->resolve(new Usage(input: 10), 'other'));
    $this->assertNull($resolver->priceFor('other'));
    $this->assertNull($resolver->pricedBy('other'));
    $this->assertSame('m', $resolver->pricedBy('m'));
    $this->assertNull($this->resolver([])->resolve(new Usage(input: 10), 'm'));
    $this->assertNull($this->resolver([])->pricedBy('m'));
  }

  /**
   * The `*` row prices any model without a row; a model's own row wins.
   */
  public function testFallbackRow(): void {
    $own = [
      'model' => 'm',
      'input' => 2.0,
      'output' => 10.0,
      'cache_read' => 0.2,
      'cache_write' => 2.5,
      'per_call' => 0.0,
    ];
    $fallback = [
      'model' => CostResolver::FALLBACK_MODEL,
      'input' => 10.0,
      'output' => 50.0,
      'cache_read' => 0.25,
      'cache_write' => 12.5,
      'per_call' => 0.25,
    ];
    $resolver = $this->resolver([$own, $fallback]);
    $usage = new Usage(input: 1_000_000, output: 1_000_000);

    $this->assertSame(CostResolver::FALLBACK_MODEL, $resolver->pricedBy('unknown'));
    $this->assertSame([
      'input' => 10.0,
      'cache_read' => 0.25,
      'cache_write' => 12.5,
      'output' => 50.0,
      'per_call' => 0.25,
    ], $resolver->priceFor('unknown'));
    $this->assertSame(60.25, $resolver->tableCost($usage, 'unknown'), 'The fallback row prices the tokens and adds its per-call figure.');
    $this->assertSame(60.25, $resolver->resolve($usage, 'unknown'));

    $this->assertSame('m', $resolver->pricedBy('m'));
    $this->assertSame(12.0, $resolver->tableCost($usage, 'm'), 'A model with its own row never rides the fallback.');
    $this->assertSame(CostResolver::FALLBACK_MODEL, $resolver->pricedBy(CostResolver::FALLBACK_MODEL), 'The fallback row is its own row.');

    $only_fallback = $this->resolver([$fallback]);
    $this->assertSame(CostResolver::FALLBACK_MODEL, $only_fallback->pricedBy('m'), 'With no row of its own, m rides the fallback too.');
    $this->assertSame(60.25, $only_fallback->tableCost($usage, 'm'));
  }

  /**
   * The per-call figure is added once per leg, whatever the counts.
   */
  public function testPerCall(): void {
    $resolver = $this->resolver([
      ['model' => 'm', 'input' => 2.0, 'output' => 10.0, 'cache_read' => 0.2, 'cache_write' => 2.5, 'per_call' => 0.5],
    ]);

    $this->assertSame(0.5, $resolver->tableCost(new Usage(), 'm'), 'A leg with every count NULL costs exactly the per-call figure.');
    $this->assertSame(0.5, $resolver->tableCost(new Usage(input: 0, output: 0), 'm'), 'Zero counts are the same as none.');
    $this->assertSame(12.5, $resolver->tableCost(new Usage(input: 1_000_000, output: 1_000_000), 'm'), 'The figure is added once on top of the tokens.');
    $this->assertSame(2.5, $resolver->tableCost(new Usage(input: 1_000_000), 'm'));
    $this->assertSame(0.5, $resolver->resolve(new Usage(), 'm'));
    $this->assertSame(0.1, $resolver->resolve(new Usage(reportedCostUsd: 0.1), 'm'), 'A reported cost still wins over the table, per-call included.');

    $without = $this->resolver([
      ['model' => 'm', 'input' => 2.0, 'output' => 10.0, 'cache_read' => 0.2, 'cache_write' => 2.5],
    ]);
    $this->assertSame(0.0, $without->tableCost(new Usage(), 'm'), 'A row without the figure adds nothing: the call is free, not unknown.');
    $this->assertSame(12.0, $without->tableCost(new Usage(input: 1_000_000, output: 1_000_000), 'm'), 'Tokens are priced as before.');
    $this->assertArrayNotHasKey('per_call', $without->priceFor('m'));

    $zero = $this->resolver([
      ['model' => 'm', 'input' => 2.0, 'output' => 10.0, 'cache_read' => 0.2, 'cache_write' => 2.5, 'per_call' => 0.0],
    ]);
    $this->assertSame(0.0, $zero->tableCost(new Usage(), 'm'));
    $this->assertNull($zero->tableCost(new Usage(cachedRead: 1), 'other'), 'No row and no fallback: nothing is answered, per-call or not.');
  }

  /**
   * A zero bucket stops counting; a missing bucket with usage refuses to price.
   */
  public function testZeroAndMissingBuckets(): void {
    $zero = $this->resolver([
      ['model' => 'm', 'input' => 2.0, 'output' => 10.0, 'cache_read' => 0.0, 'cache_write' => 2.5],
    ]);
    $this->assertSame(2.0, $zero->tableCost(new Usage(input: 1_000_000, cachedRead: 5_000_000), 'm'));

    $missing = $this->resolver([
      ['model' => 'm', 'input' => 2.0, 'output' => 10.0],
    ]);
    $this->assertSame(2.0, $missing->tableCost(new Usage(input: 1_000_000), 'm'), 'A missing bucket the leg did not use is no obstacle.');
    $this->assertNull($missing->tableCost(new Usage(input: 1_000_000, cachedRead: 1), 'm'), 'A missing bucket the leg used would undercount, so nothing is answered.');
  }

  /**
   * Unreported counts weigh nothing; an empty usage on a priced model is zero.
   */
  public function testUnreportedCountsWeighNothing(): void {
    $resolver = $this->resolver([
      ['model' => 'm', 'input' => 2.0, 'output' => 10.0, 'cache_read' => 0.2, 'cache_write' => 2.5],
    ]);

    $this->assertSame(0.0, $resolver->tableCost(new Usage(), 'm'));
    $this->assertSame(10.0, $resolver->tableCost(new Usage(output: 1_000_000), 'm'));
  }

  /**
   * The seeded Gemini image row prices an image leg at the image rate.
   *
   * The row is read off the shipped install config, so the figures the
   * caps count for `models/gemini-3-pro-image` are the ones pinned here:
   * 20 input tokens at $2 and 1120 output tokens at $120, per million.
   */
  public function testSeededGeminiImageRow(): void {
    $shipped = Yaml::decode((string) file_get_contents(dirname(__DIR__, 3) . '/config/install/daedalus_ai_ledger.settings.yml'));
    $resolver = $this->resolver($shipped['prices']);

    $this->assertSame(['input' => 2.0, 'cache_read' => 2.0, 'cache_write' => 0.0, 'output' => 120.0, 'per_call' => 0.0], $resolver->priceFor('models/gemini-3-pro-image'));
    $this->assertSame(0.13444, $resolver->tableCost(new Usage(input: 20, output: 1120), 'models/gemini-3-pro-image'));
    $this->assertSame(0.13444, $resolver->resolve(new Usage(input: 20, output: 1120), 'models/gemini-3-pro-image'));
  }

  /**
   * The table's row for a model reads as floats by bucket.
   */
  public function testPriceFor(): void {
    $resolver = $this->resolver([
      ['model' => 'm', 'input' => '2', 'output' => 10, 'cache_read' => 0.2, 'cache_write' => 'x'],
    ]);

    $this->assertSame(['input' => 2.0, 'cache_read' => 0.2, 'output' => 10.0], $resolver->priceFor('m'));
  }

}
