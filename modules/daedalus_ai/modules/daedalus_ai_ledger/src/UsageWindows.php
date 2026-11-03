<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_ledger;

/**
 * The window math over a set of legs: what a window holds, when it clears.
 *
 * Pure: the legs come in as {created, cost} pairs (what UsageLedger reads
 * for the site or for a user back to the earliest window's start) and the
 * sums come out, so the gate's arithmetic is pinned without a database.
 * The window says where it opens (Window::since()): a rolling window holds
 * the legs younger than its period (a leg exactly as old as the period has
 * aged out), an anchored one the legs since its latest boundary, that
 * moment included. An unpriced leg (cost NULL) weighs nothing.
 */
final class UsageWindows {

  /**
   * What was spent inside one window.
   *
   * @param list<array{created: int, cost: float|null}> $legs
   *   The legs, any order.
   * @param \Drupal\daedalus_ai_ledger\Window $window
   *   The window.
   * @param int $now
   *   The moment the window ends.
   *
   * @return float
   *   The USD sum of the legs inside the window.
   */
  public static function spent(array $legs, Window $window, int $now): float {
    $sum = 0.0;
    foreach (self::inside($legs, $window, $now) as $leg) {
      $sum += $leg['cost'] ?? 0.0;
    }
    return $sum;
  }

  /**
   * When a window that has reached its cap clears again.
   *
   * An anchored window clears whole at its next boundary. A rolling
   * window's sum only falls as legs age out, oldest first, so its reset
   * is the moment the oldest legs whose removal brings the sum under the
   * cap have all left the window: the youngest of them plus the period.
   *
   * @param list<array{created: int, cost: float|null}> $legs
   *   The legs, any order.
   * @param \Drupal\daedalus_ai_ledger\Window $window
   *   The window.
   * @param int $now
   *   The moment the window ends.
   *
   * @return int|null
   *   The timestamp the window clears, or NULL when it has not reached its
   *   cap, or never clears by time alone (a cap of zero).
   */
  public static function resetAt(array $legs, Window $window, int $now): ?int {
    $inside = self::inside($legs, $window, $now);
    $remaining = 0.0;
    foreach ($inside as $leg) {
      $remaining += $leg['cost'] ?? 0.0;
    }
    if (!$window->reached($remaining)) {
      return NULL;
    }
    if (!$window->rolling()) {
      return $window->reached(0.0) ? NULL : $window->nextBoundary($now);
    }
    usort($inside, static fn (array $a, array $b): int => $a['created'] <=> $b['created']);
    foreach ($inside as $leg) {
      $remaining -= $leg['cost'] ?? 0.0;
      if (!$window->reached($remaining)) {
        return $window->agedOutAt($leg['created']);
      }
    }
    return NULL;
  }

  /**
   * When the oldest leg that weighs something inside the window was made.
   *
   * The meter's next rise on an open rolling window: its sum falls the
   * moment that leg ages out (Window::agedOutAt()). A leg that weighs
   * nothing (unpriced, or priced at zero) changes no sum when it leaves,
   * so it is passed over.
   *
   * @param list<array{created: int, cost: float|null}> $legs
   *   The legs, any order.
   * @param \Drupal\daedalus_ai_ledger\Window $window
   *   The window.
   * @param int $now
   *   The moment the window ends.
   *
   * @return int|null
   *   The created time of the oldest weighing leg inside the window, or
   *   NULL when none is.
   */
  public static function oldestInside(array $legs, Window $window, int $now): ?int {
    $oldest = NULL;
    foreach (self::inside($legs, $window, $now) as $leg) {
      if (($leg['cost'] ?? 0.0) <= 0.0) {
        continue;
      }
      if ($oldest === NULL || $leg['created'] < $oldest) {
        $oldest = $leg['created'];
      }
    }
    return $oldest;
  }

  /**
   * The legs inside the window.
   *
   * @param list<array{created: int, cost: float|null}> $legs
   *   The legs.
   * @param \Drupal\daedalus_ai_ledger\Window $window
   *   The window.
   * @param int $now
   *   The moment the window ends.
   *
   * @return list<array{created: int, cost: float|null}>
   *   The legs created after the window opened (at or after, for an
   *   anchored window: the boundary moment belongs to the window it
   *   opens).
   */
  private static function inside(array $legs, Window $window, int $now): array {
    $since = $window->since($now);
    $keep = $window->rolling()
      ? static fn (array $leg): bool => $leg['created'] > $since
      : static fn (array $leg): bool => $leg['created'] >= $since;
    return array_values(array_filter($legs, $keep));
  }

}
