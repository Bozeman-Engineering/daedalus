<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_ledger;

/**
 * The gate's answer: allow the turn, or refuse it naming why.
 *
 * A refusal carries the window that is full and its scope (the site's
 * budget or the user's), what was spent in it, when it clears (NULL when
 * it never clears by time alone) and a sentence saying so, worded for the
 * chat bubble the runner answers through.
 */
final class GateVerdict {

  private function __construct(
    public readonly bool $allowed,
    public readonly ?string $scope = NULL,
    public readonly ?Window $window = NULL,
    public readonly ?float $spentUsd = NULL,
    public readonly ?int $resetAt = NULL,
    public readonly string $message = '',
  ) {}

  /**
   * The turn may run.
   */
  public static function allow(): self {
    return new self(TRUE);
  }

  /**
   * The turn is refused: a window has reached its cap.
   *
   * @param \Drupal\daedalus_ai_ledger\Window $window
   *   The full window; its scope is the verdict's.
   * @param float $spent_usd
   *   What was spent inside it.
   * @param int|null $reset_at
   *   When it clears, or NULL when it never does by time alone.
   * @param string $message
   *   The sentence naming the window and its reset time.
   */
  public static function refuse(Window $window, float $spent_usd, ?int $reset_at, string $message): self {
    return new self(FALSE, $window->scope, $window, $spent_usd, $reset_at, $message);
  }

}
