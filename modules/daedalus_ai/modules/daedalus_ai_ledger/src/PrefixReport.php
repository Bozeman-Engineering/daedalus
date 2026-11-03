<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_ledger;

/**
 * What became of the cached prompt prefix over a window of legs.
 *
 * The numbers the status report states, read off the rows by one
 * aggregate select (UsageLedger::prefixReport()): how many legs were
 * measured at all (a row with a prefix loss, NULL being unmeasured), how
 * many of them the runner tagged as drifted, in how many distinct
 * conversations (with a sample of their ids for the message), how many
 * tokens the drifted legs re-sent, and how many tokens the undrifted
 * legs re-sent (the cache's TTL ran out between turns: the expiry number,
 * data for the TTL question rather than a defect), and in how many
 * distinct conversations the agent's record was rebuilt from the visible
 * chat (the record had expired or could not be read: the one measurement
 * of the "AI forgot" experience, counted rather than warned about, since
 * the expiry rebuild is legitimate).
 */
final class PrefixReport {

  /**
   * How many drifted conversation ids a report names.
   */
  public const CONVERSATION_SAMPLE = 5;

  /**
   * Builds a report; the ledger is the one caller.
   *
   * @param int $measuredLegs
   *   Legs with a measured prefix loss, drifted or not.
   * @param int $driftedLegs
   *   Legs the runner tagged as having re-sent a rewritten prefix.
   * @param int $driftedConversations
   *   Distinct conversations with at least one drifted leg.
   * @param list<string> $driftedConversationIds
   *   Up to CONVERSATION_SAMPLE of those conversations' ids, the one with
   *   the newest drifted leg first.
   * @param int $driftedTokens
   *   Tokens re-sent on the drifted legs.
   * @param int $expiryTokens
   *   Tokens re-sent on the undrifted legs.
   * @param int $rebuiltConversations
   *   Distinct conversations with a leg the runner tagged as rebuilt.
   */
  public function __construct(
    public readonly int $measuredLegs,
    public readonly int $driftedLegs,
    public readonly int $driftedConversations,
    public readonly array $driftedConversationIds,
    public readonly int $driftedTokens,
    public readonly int $expiryTokens,
    public readonly int $rebuiltConversations,
  ) {
    if (count($driftedConversationIds) > self::CONVERSATION_SAMPLE) {
      throw new \InvalidArgumentException(sprintf('A prefix report names at most %d conversations.', self::CONVERSATION_SAMPLE));
    }
  }

  /**
   * Whether any leg in the window measured its prefix at all.
   */
  public function measured(): bool {
    return $this->measuredLegs > 0;
  }

  /**
   * Whether any leg in the window re-sent a rewritten prefix.
   */
  public function drifted(): bool {
    return $this->driftedLegs > 0;
  }

  /**
   * Whether any conversation in the window was rebuilt from the visible chat.
   */
  public function rebuilt(): bool {
    return $this->rebuiltConversations > 0;
  }

}
