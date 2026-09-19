<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_memory\Enum;

/**
 * Represents the persistence state of an agent between turns.
 */
enum AgentState {

  // No prior state exists. Initialize the agent from scratch.
  case Fresh;

  // Prior state exists but is stale: the agent finished and no new messages
  // arrived. Discard the state and start fresh.
  case Stale;

  // The agent finished a previous turn and the user sent a new message.
  // Restore state but reset execution counters for a new cycle.
  case CrossTurn;

  // The agent is mid-execution and the frontend is polling for progress.
  // Restore state as-is to continue the current cycle.
  case SameTurn;

  /**
   * Determines the agent state from persisted data and current session.
   *
   * @param array|null $persisted
   *   The persisted state from tempstore, or NULL if none exists.
   * @param int $session_message_count
   *   The number of messages in the current session.
   *
   * @return self
   *   The determined agent state.
   */
  public static function detect(?array $persisted, int $session_message_count): self {
    if ($persisted === NULL) {
      return self::Fresh;
    }

    $was_finished = !empty($persisted['finished']);
    $processed_count = $persisted['processed_message_count'] ?? 0;
    $has_new_messages = $session_message_count > $processed_count;

    if ($was_finished && !$has_new_messages) {
      return self::Stale;
    }

    if ($was_finished && $has_new_messages) {
      return self::CrossTurn;
    }

    return self::SameTurn;
  }

}
