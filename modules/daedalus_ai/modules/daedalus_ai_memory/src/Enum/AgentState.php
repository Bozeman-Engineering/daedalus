<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_memory\Enum;

/**
 * The state a conversation's persisted record is in when a request arrives.
 *
 * Detected by the identity of the session's last user message (its
 * timestamp and text), never by a message count: the user-visible chat
 * memory caps its history, so a count stops growing while the conversation
 * goes on, and a record compared by count read as stale on every later
 * turn. A record remembers which user message its last turn ran for; the
 * request's last user message either is that one (a poll of a running
 * turn, or the same message sent again) or is not (a new turn). A readable
 * record is never discarded for what the session carries: the record is
 * the agent's memory.
 */
enum AgentState {

  // No record exists. Initialize the agent from scratch.
  case Fresh;

  // The record is from before the transcript existed (no transcript key),
  // so nothing in it can be read. The runner's reader refuses it, which
  // deletes it and runs the request fresh.
  case Stale;

  // The request carries a user message the record has not run for, or the
  // same message again after the last turn finished (the user sent it
  // again; it is appended as a new turn). Restore the record and open a
  // new turn.
  case CrossTurn;

  // The record's last turn has not finished and the request carries the
  // same user message: the front end is polling it. Restore as-is.
  case SameTurn;

  /**
   * Determines the state from the record and the request's last user message.
   *
   * @param array|null $persisted
   *   The record from the tempstore, or NULL when none exists.
   * @param string|null $identity
   *   The identity of the session's last user message (self::identity()),
   *   or NULL when the session carries none.
   *
   * @return self
   *   The state.
   */
  public static function detect(?array $persisted, ?string $identity): self {
    if ($persisted === NULL) {
      return self::Fresh;
    }
    if (!isset($persisted['transcript'])) {
      return self::Stale;
    }
    if ($identity !== NULL && $identity !== ($persisted['last_user'] ?? NULL)) {
      return self::CrossTurn;
    }
    return empty($persisted['finished']) ? self::SameTurn : self::CrossTurn;
  }

  /**
   * The identity of the session's last user message.
   *
   * @param array $chat_history
   *   The session's messages as the assistant runner hands them over, each
   *   a `role`, `message` and (from the chat memory) `timestamp`.
   *
   * @return string|null
   *   A hash of the last user message's timestamp and text, or NULL when
   *   the session carries no user message.
   */
  public static function identity(array $chat_history): ?string {
    foreach (array_reverse(array_values($chat_history)) as $message) {
      if (($message['role'] ?? NULL) === 'user') {
        return hash('sha256', (string) ($message['timestamp'] ?? '') . "\n" . (string) ($message['message'] ?? ''));
      }
    }
    return NULL;
  }

}
