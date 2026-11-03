<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\Provider;

/**
 * What one LLM leg consumed, in the ledger's own vocabulary.
 *
 * Every count is NULL when no source reported it, never 0 by assumption:
 * a ledger row must be able to tell "the provider reported no cache write"
 * from "zero tokens were written". The cache write is kept both as the
 * total the provider billed and, when the provider splits it, by TTL; a
 * source that knows only the total leaves the split NULL.
 *
 * @see \Drupal\daedalus_ai\Provider\UsageReader
 */
final class Usage {

  /**
   * Constructs a usage record.
   *
   * @param int|null $input
   *   Uncached input tokens (the remainder after cache reads and writes).
   * @param int|null $cachedRead
   *   Input tokens read from the prompt cache.
   * @param int|null $cacheWrite
   *   Input tokens written to the prompt cache, all TTLs together.
   * @param int|null $cacheWrite5m
   *   The part of the write placed with a 5 minute TTL, when split.
   * @param int|null $cacheWrite1h
   *   The part of the write placed with a 1 hour TTL, when split.
   * @param int|null $output
   *   Output tokens, reasoning included where the provider counts it there.
   * @param int|null $reasoning
   *   Reasoning (thinking) tokens, when reported apart.
   * @param float|null $reportedCostUsd
   *   The charge the provider reported for the leg, when it reports one.
   */
  public function __construct(
    public readonly ?int $input = NULL,
    public readonly ?int $cachedRead = NULL,
    public readonly ?int $cacheWrite = NULL,
    public readonly ?int $cacheWrite5m = NULL,
    public readonly ?int $cacheWrite1h = NULL,
    public readonly ?int $output = NULL,
    public readonly ?int $reasoning = NULL,
    public readonly ?float $reportedCostUsd = NULL,
  ) {
  }

  /**
   * The size of the prompt the leg was billed for: input, read and written.
   *
   * This is the number a segment budget compares against: everything the
   * model saw, however it was priced. NULL when no source reported any of
   * the three.
   */
  public function promptTokens(): ?int {
    if ($this->input === NULL && $this->cachedRead === NULL && $this->cacheWrite === NULL) {
      return NULL;
    }
    return ($this->input ?? 0) + ($this->cachedRead ?? 0) + ($this->cacheWrite ?? 0);
  }

  /**
   * Whether any source reported anything at all.
   */
  public function isEmpty(): bool {
    return $this->input === NULL
      && $this->cachedRead === NULL
      && $this->cacheWrite === NULL
      && $this->cacheWrite5m === NULL
      && $this->cacheWrite1h === NULL
      && $this->output === NULL
      && $this->reasoning === NULL
      && $this->reportedCostUsd === NULL;
  }

}
