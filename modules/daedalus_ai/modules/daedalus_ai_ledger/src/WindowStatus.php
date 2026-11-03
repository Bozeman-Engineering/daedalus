<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_ledger;

use Drupal\Core\Datetime\DateFormatterInterface;

/**
 * One window as the user sees it: how much is left, and when that changes.
 *
 * The gate's own arithmetic read for display rather than for a verdict:
 * the fraction of the cap still unspent, whether the cap is reached, and
 * the next moment the fraction changes. No dollar figure is carried: the
 * cap and the spend in USD are the administrator's, and this is what
 * leaves the server for a user's meter.
 */
final class WindowStatus {

  /**
   * Builds a status; of() computes one from a window and its spend.
   *
   * @param \Drupal\daedalus_ai_ledger\Window $window
   *   The window, for its label.
   * @param string $scope
   *   The window's scope, site or user.
   * @param string $period
   *   The period as the ISO 8601 duration it was configured with.
   * @param float $remaining
   *   The fraction of the cap left, 0 to 1: max(0, 1 - spent / usd); a cap
   *   of zero reads 0.
   * @param bool $reached
   *   Whether the cap is reached (the gate would refuse on this window).
   * @param int|null $resetAt
   *   The next moment the fraction changes: an anchored window's next
   *   boundary, reached or not (it clears whole there); a reached rolling
   *   window's clearing moment; an open rolling window's moment its oldest
   *   weighing leg ages out (the fraction next rises); NULL when nothing is
   *   inside an open rolling window, or a reached one never clears by time
   *   (a cap of zero).
   */
  public function __construct(
    public readonly Window $window,
    public readonly string $scope,
    public readonly string $period,
    public readonly float $remaining,
    public readonly bool $reached,
    public readonly ?int $resetAt,
  ) {}

  /**
   * The status of a window given what was spent in it and when it changes.
   *
   * @param \Drupal\daedalus_ai_ledger\Window $window
   *   The window.
   * @param float $spent
   *   The USD sum inside it.
   * @param int|null $reset_at
   *   The next moment the fraction changes, or NULL (see the constructor).
   */
  public static function of(Window $window, float $spent, ?int $reset_at): self {
    $remaining = $window->usd > 0 ? max(0.0, 1.0 - $spent / $window->usd) : 0.0;
    return new self($window, $window->scope, $window->periodSpec, $remaining, $window->reached($spent), $reset_at);
  }

  /**
   * The period in words, through the window.
   */
  public function label(DateFormatterInterface $dateFormatter): string {
    return $this->window->label($dateFormatter);
  }

}
