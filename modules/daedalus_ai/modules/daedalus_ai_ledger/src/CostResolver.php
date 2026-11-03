<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_ledger;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\daedalus_ai\Provider\Usage;

/**
 * Prices one leg: the provider's reported cost first, the price table second.
 *
 * The price table (config `prices`, USD per million tokens per model in
 * four token buckets plus a per-call figure) is the one counting knob of
 * the caps: raw tokens would misprice cached reads by an order of
 * magnitude, so the caps count a price-tied unit, and tuning what counts
 * means editing a bucket (zero stops counting it). A provider that reports
 * the charge itself (a gateway's cost header, xAI's cost ticks) is believed
 * over the table.
 *
 * A model with no row of its own is priced by the fallback row, keyed
 * `*` (FALLBACK_MODEL), so a model nobody has priced yet still weighs
 * something against the caps rather than nothing; pricedBy() names which
 * row priced a model, which is how the status report tells an admin
 * which rows to add. The `per_call` bucket (PER_CALL_BUCKET) is USD added
 * once per leg on top of the token buckets: a leg always makes exactly
 * one call, so a missing or zero figure means the call itself is free,
 * not unknown, and a leg that reports no tokens at all (a provider with
 * no usage accounting) costs the row's per-call figure.
 *
 * Every cache write is priced at the cache_write bucket whatever its TTL;
 * the row keeps the 5m/1h split where the provider gave one, so a finer
 * table can be added without losing data.
 */
final class CostResolver {

  public const SETTINGS = 'daedalus_ai_ledger.settings';

  public const PRICES_KEY = 'prices';

  /**
   * The model id of the row that prices any model without a row of its own.
   */
  public const FALLBACK_MODEL = '*';

  /**
   * The bucket added once per leg, whatever the counts.
   */
  public const PER_CALL_BUCKET = 'per_call';

  /**
   * The token buckets, by the Usage field each one prices.
   */
  public const BUCKETS = [
    'input' => 'input',
    'cachedRead' => 'cache_read',
    'cacheWrite' => 'cache_write',
    'output' => 'output',
  ];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * The cost the caps count: reported when present, else from the table.
   *
   * @param \Drupal\daedalus_ai\Provider\Usage $usage
   *   The leg's usage.
   * @param string $model
   *   The model id the leg ran on.
   *
   * @return float|null
   *   USD, or NULL when the provider reported nothing and the table does
   *   not price the model.
   */
  public function resolve(Usage $usage, string $model): ?float {
    return $usage->reportedCostUsd ?? $this->tableCost($usage, $model);
  }

  /**
   * The cost the price table computes for the leg, ignoring any report.
   *
   * @param \Drupal\daedalus_ai\Provider\Usage $usage
   *   The leg's usage.
   * @param string $model
   *   The model id the leg ran on.
   *
   * @return float|null
   *   USD, or NULL when neither the model nor the fallback has a row, or a
   *   token bucket the leg used is missing from the row that prices it (an
   *   unpriced bucket with usage would silently undercount, so it answers
   *   nothing instead). The row's per-call figure is added once whatever
   *   the counts; absent, the call adds nothing.
   */
  public function tableCost(Usage $usage, string $model): ?float {
    $price = $this->priceFor($model);
    if ($price === NULL) {
      return NULL;
    }
    $usd = 0.0;
    foreach (self::BUCKETS as $field => $bucket) {
      $count = $usage->$field ?? 0;
      if ($count === 0) {
        continue;
      }
      if (!isset($price[$bucket])) {
        return NULL;
      }
      $usd += $count * (float) $price[$bucket] / 1_000_000;
    }
    $usd += $price[self::PER_CALL_BUCKET] ?? 0.0;
    return round($usd, 8);
  }

  /**
   * The model id whose row prices a model.
   *
   * @param string $model
   *   The model id a leg ran on.
   *
   * @return string|null
   *   The model itself when it has a row, FALLBACK_MODEL when only the
   *   fallback row exists, NULL when neither.
   */
  public function pricedBy(string $model): ?string {
    if ($this->rowFor($model) !== NULL) {
      return $model;
    }
    if ($this->rowFor(self::FALLBACK_MODEL) !== NULL) {
      return self::FALLBACK_MODEL;
    }
    return NULL;
  }

  /**
   * The buckets that price a model, resolved through pricedBy().
   *
   * @return array<string, float>|null
   *   The token buckets and the per-call figure of the row that prices
   *   the model (a bucket the row lacks is left out), or NULL when neither
   *   the model nor the fallback has a row.
   */
  public function priceFor(string $model): ?array {
    $priced_by = $this->pricedBy($model);
    if ($priced_by === NULL) {
      return NULL;
    }
    $row = $this->rowFor($priced_by);
    $buckets = [];
    foreach ([...self::BUCKETS, self::PER_CALL_BUCKET] as $bucket) {
      if (isset($row[$bucket]) && is_numeric($row[$bucket])) {
        $buckets[$bucket] = (float) $row[$bucket];
      }
    }
    return $buckets;
  }

  /**
   * The table's raw row for exactly this model id, or NULL when it has none.
   *
   * @return array<string, mixed>|null
   *   The config row as stored.
   */
  private function rowFor(string $model): ?array {
    foreach ($this->configFactory->get(self::SETTINGS)->get(self::PRICES_KEY) ?? [] as $row) {
      if (is_array($row) && ($row['model'] ?? NULL) === $model) {
        return $row;
      }
    }
    return NULL;
  }

}
