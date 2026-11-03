<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_memory\Segment;

use Drupal\daedalus_ai_ledger\Entity\AiUsage;

/**
 * The conversation's newest ledger row, as the segment policy reads it.
 *
 * The policy measures a segment by what the provider billed for its last
 * leg (input plus cached reads plus cache writes, never an estimate from
 * characters), read from the usage ledger's newest row for the
 * conversation. The row names the segment it ran in, so a row from an
 * earlier segment (the carry-over call that closed it, or its last leg)
 * is no measurement of the current one.
 */
final class LastLeg {

  /**
   * Constructs a last leg.
   *
   * @param int|null $segment
   *   The number of the segment the leg ran in, or NULL when untagged.
   * @param int|null $promptTokens
   *   The prompt size the leg was billed for, or NULL when unreported.
   */
  public function __construct(
    public readonly ?int $segment,
    public readonly ?int $promptTokens,
  ) {
    if ($segment !== NULL && $segment < 1) {
      throw new \InvalidArgumentException(sprintf('A segment number starts at 1, got %d.', $segment));
    }
    if ($promptTokens !== NULL && $promptTokens < 0) {
      throw new \InvalidArgumentException(sprintf('A prompt size must not be negative, got %d.', $promptTokens));
    }
  }

  /**
   * The last leg a ledger row records.
   *
   * @param \Drupal\daedalus_ai_ledger\Entity\AiUsage $row
   *   The conversation's newest row (UsageLedger::newestLeg()).
   */
  public static function fromRow(AiUsage $row): self {
    $segment = $row->segment();
    if ($segment !== NULL && !ctype_digit($segment)) {
      throw new \InvalidArgumentException(sprintf('A ledger row names its segment by number, got "%s".', $segment));
    }
    return new self($segment === NULL ? NULL : (int) $segment, $row->promptTokens());
  }

  /**
   * The prompt size measured for a segment, or NULL when not of it.
   *
   * A leg from another segment is no measurement of this one.
   *
   * @param int $segmentNumber
   *   The segment being measured.
   */
  public function measures(int $segmentNumber): ?int {
    return $this->segment === $segmentNumber ? $this->promptTokens : NULL;
  }

}
