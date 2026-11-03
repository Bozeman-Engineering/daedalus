<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Materials;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;

/**
 * One reading of the AI context budget: what the site shares, vs the ceiling.
 *
 * The sum of every weight source (MaterialsBudget::reading()) beside the
 * site's budget: characters by kind and for the system, the total, the
 * estimated tokens at the site's ratio, the budget, the fraction used and
 * whether the site is full. One shape for every consumer (the usage
 * dialog's bar and legend, the JSON reading, the refusal's context), so
 * the display and the refusal state the same numbers.
 *
 * Tokens here are an ESTIMATE (characters at a fixed ratio, never a
 * provider count); a surface says so.
 */
final class BudgetReading implements CacheableDependencyInterface {

  /**
   * Characters by kind (its shared plus its records'), every kind present.
   *
   * @var array<string, int>
   */
  public readonly array $characters;

  /**
   * Characters by record: MaterialKind value => record id => characters.
   *
   * Every kind present, [] when nothing renders a record of it; a record
   * that is not offered renders nowhere, so it has no entry.
   *
   * @var array<string, array<string, int>>
   */
  public readonly array $records;

  /**
   * A kind's characters no one record owns, keyed by MaterialKind value.
   *
   * The headings and punctuation over several records of the kind, shown
   * once beside the kind's records; every kind present, 0 when none.
   *
   * @var array<string, int>
   */
  public readonly array $shared;

  /**
   * The characters no material toggle shrinks.
   */
  public readonly int $system;

  /**
   * Every kind's characters plus the system figure.
   */
  public readonly int $total;

  /**
   * The total as tokens at the ratio, rounded up.
   */
  public readonly int $estimatedTokens;

  /**
   * The share of the budget the estimate takes (1.0 is exactly full).
   */
  public readonly float $fraction;

  /**
   * Whether the site is full: the estimate at or past the budget.
   */
  public readonly bool $over;

  /**
   * Constructs a reading.
   *
   * @param \Drupal\daedalus_blueprint\Materials\MaterialsWeights $weights
   *   Every source's weights, summed.
   * @param int $budgetTokens
   *   The site's budget, in estimated tokens (the config).
   * @param int $charactersPerToken
   *   The ratio the estimate divides by (the config).
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   What the reading derived from: the sources' metadata plus the
   *   config's.
   */
  public function __construct(
    MaterialsWeights $weights,
    public readonly int $budgetTokens,
    public readonly int $charactersPerToken,
    private readonly CacheableMetadata $cacheability,
  ) {
    $this->characters = $weights->characters;
    $this->records = $weights->records;
    $this->shared = $weights->shared;
    $this->system = $weights->system;
    $this->total = $weights->total();
    $this->estimatedTokens = (int) ceil($this->total / $this->charactersPerToken);
    $this->fraction = $this->estimatedTokens / $this->budgetTokens;
    $this->over = $this->estimatedTokens >= $this->budgetTokens;
  }

  /**
   * The characters of one kind: its shared plus its records'.
   */
  public function of(MaterialKind $kind): int {
    return $this->characters[$kind->value];
  }

  /**
   * The characters of one record; 0 when nothing renders it.
   */
  public function record(MaterialKind $kind, string $id): int {
    return $this->records[$kind->value][$id] ?? 0;
  }

  /**
   * The reading as plain data: the JSON route's body and the refusal's context.
   *
   * @return array{characters: array<string, int>, records: array<string, array<string, int>>, shared: array<string, int>, system: int, total: int, estimated_tokens: int, budget_tokens: int, characters_per_token: int, fraction: float, over: bool}
   *   The reading.
   */
  public function toArray(): array {
    return [
      'characters' => $this->characters,
      'records' => $this->records,
      'shared' => $this->shared,
      'system' => $this->system,
      'total' => $this->total,
      'estimated_tokens' => $this->estimatedTokens,
      'budget_tokens' => $this->budgetTokens,
      'characters_per_token' => $this->charactersPerToken,
      'fraction' => $this->fraction,
      'over' => $this->over,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return $this->cacheability->getCacheContexts();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    return $this->cacheability->getCacheTags();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return $this->cacheability->getCacheMaxAge();
  }

}
