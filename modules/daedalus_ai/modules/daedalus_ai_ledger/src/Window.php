<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_ledger;

use Drupal\Core\Datetime\DateFormatterInterface;

/**
 * One spend window: a scope, a period, a cap in USD and an optional anchor.
 *
 * The scope says whose legs the window sums: every priced leg on the site
 * (SCOPE_SITE) or the account's own (SCOPE_USER). The period is an ISO
 * 8601 duration (`PT5H`, `P7D`, `P1M`), kept as the string it was
 * configured with beside its parsed \DateInterval. Unanchored, the window
 * rolls: it holds the last period's legs and usage ages out continuously.
 * Anchored to a date (the site's timezone, midnight), it runs from the
 * latest boundary at or before now to the next, the boundaries stepping
 * from the anchor by the period, so a `P1M` window anchored on a billing
 * day lands on that day each month and clears whole at the next.
 *
 * Every date arithmetic goes through \DateTimeImmutable in the window's
 * timezone, so a month is a real month and a day across a DST change is
 * the day the calendar shows; a boundary is the anchor plus a whole
 * number of periods (never a running sum of single steps, which would
 * drift from the 31st into the next month), and a day past a month's end
 * overflows the way PHP's does.
 */
final class Window {

  public const SCOPE_SITE = 'site';

  public const SCOPE_USER = 'user';

  public const SCOPES = [self::SCOPE_SITE, self::SCOPE_USER];

  /**
   * The date format an anchor is configured in.
   */
  public const ANCHOR_FORMAT = 'Y-m-d';

  /**
   * The parsed period.
   */
  public readonly \DateInterval $period;

  /**
   * Builds a window, refusing a bad scope, period or cap by name.
   *
   * @param string $scope
   *   SCOPE_SITE or SCOPE_USER.
   * @param string $periodSpec
   *   The period as an ISO 8601 duration string.
   * @param float $usd
   *   The cap.
   * @param \DateTimeImmutable|null $anchor
   *   The moment the boundaries step from, or NULL for a rolling window.
   * @param \DateTimeZone $timezone
   *   The timezone the arithmetic runs in (the site's).
   */
  public function __construct(
    public readonly string $scope,
    public readonly string $periodSpec,
    public readonly float $usd,
    public readonly ?\DateTimeImmutable $anchor,
    public readonly \DateTimeZone $timezone,
  ) {
    if (!in_array($scope, self::SCOPES, TRUE)) {
      throw new \InvalidArgumentException(sprintf('A usage cap\'s scope must be "site" or "user", not "%s".', $scope));
    }
    $this->period = self::parsePeriod($periodSpec);
    if ($usd < 0) {
      throw new \InvalidArgumentException('A usage cap\'s usd cannot be negative.');
    }
  }

  /**
   * The windows the caps config describes.
   *
   * @param mixed $caps
   *   The `caps` config value: a list of {scope, period, usd, anchor}
   *   mappings.
   * @param \DateTimeZone $timezone
   *   The site's timezone.
   *
   * @return list<self>
   *   The windows, in config order.
   *
   * @throws \InvalidArgumentException
   *   Naming the field a row gets wrong.
   */
  public static function fromConfig(mixed $caps, \DateTimeZone $timezone): array {
    $windows = [];
    foreach (is_array($caps) ? $caps : [] as $cap) {
      if (!is_array($cap)) {
        throw new \InvalidArgumentException('Every usage cap is a mapping of scope, period, usd and anchor.');
      }
      $windows[] = self::fromRow($cap, $timezone);
    }
    return $windows;
  }

  /**
   * The window one config row describes.
   *
   * @param array<string, mixed> $row
   *   The row: scope, period and usd are required, anchor may be absent or
   *   empty for a rolling window.
   * @param \DateTimeZone $timezone
   *   The site's timezone.
   *
   * @throws \InvalidArgumentException
   *   Naming the field the row gets wrong.
   */
  public static function fromRow(array $row, \DateTimeZone $timezone): self {
    foreach (['scope', 'period', 'usd'] as $key) {
      if (!isset($row[$key]) || $row[$key] === '') {
        throw new \InvalidArgumentException(sprintf('Every usage cap needs a %s.', $key));
      }
    }
    if (!is_numeric($row['usd'])) {
      throw new \InvalidArgumentException(sprintf('A usage cap\'s usd must be a number, not "%s".', (string) $row['usd']));
    }
    $anchor = $row['anchor'] ?? '';
    if (!is_string($anchor)) {
      throw new \InvalidArgumentException('A usage cap\'s anchor must be a date string or empty.');
    }
    return new self(
      (string) $row['scope'],
      (string) $row['period'],
      (float) $row['usd'],
      $anchor === '' ? NULL : self::parseAnchor($anchor, $timezone),
      $timezone,
    );
  }

  /**
   * Parses a period, refusing a malformed or zero-length one.
   *
   * @param string $spec
   *   An ISO 8601 duration string.
   *
   * @throws \InvalidArgumentException
   *   When the string is not a duration, or the duration is zero.
   */
  public static function parsePeriod(string $spec): \DateInterval {
    try {
      $period = new \DateInterval($spec);
    }
    catch (\Exception $e) {
      throw new \InvalidArgumentException(sprintf('A usage cap\'s period must be an ISO 8601 duration such as PT5H, P7D or P1M, not "%s".', $spec), 0, $e);
    }
    if (self::longestSeconds($period) === 0) {
      throw new \InvalidArgumentException(sprintf('A usage cap\'s period cannot be zero ("%s").', $spec));
    }
    return $period;
  }

  /**
   * Parses an anchor date at midnight in the timezone.
   *
   * @param string $date
   *   A date in ANCHOR_FORMAT.
   * @param \DateTimeZone $timezone
   *   The site's timezone.
   *
   * @throws \InvalidArgumentException
   *   When the string is not a real date in that format.
   */
  public static function parseAnchor(string $date, \DateTimeZone $timezone): \DateTimeImmutable {
    $anchor = \DateTimeImmutable::createFromFormat('!' . self::ANCHOR_FORMAT, $date, $timezone);
    // createFromFormat() accepts an overflowing day (2026-02-30 lands in
    // March), so the round trip is what proves the date is real.
    if ($anchor === FALSE || $anchor->format(self::ANCHOR_FORMAT) !== $date) {
      throw new \InvalidArgumentException(sprintf('A usage cap\'s anchor must be a date as %s, not "%s".', self::ANCHOR_FORMAT, $date));
    }
    return $anchor;
  }

  /**
   * Whether the window rolls (no anchor) rather than resets on a boundary.
   */
  public function rolling(): bool {
    return $this->anchor === NULL;
  }

  /**
   * Whether a sum has reached the cap.
   */
  public function reached(float $spent): bool {
    return $spent >= $this->usd;
  }

  /**
   * The moment the window opens, judged at a moment.
   *
   * Rolling: now less the period. Anchored: the latest boundary at or
   * before now; an anchor in the future is that boundary itself, so
   * nothing is inside yet.
   *
   * @param int $now
   *   The moment the window ends.
   */
  public function since(int $now): int {
    if ($this->anchor === NULL) {
      return $this->at($now)->sub($this->period)->getTimestamp();
    }
    return $this->boundary($this->latestStep($now))->getTimestamp();
  }

  /**
   * The next boundary after a moment, or NULL for a rolling window.
   *
   * An anchor in the future is the next boundary.
   *
   * @param int $now
   *   The moment to look from.
   */
  public function nextBoundary(int $now): ?int {
    if ($this->anchor === NULL) {
      return NULL;
    }
    if ($this->anchor->getTimestamp() > $now) {
      return $this->anchor->getTimestamp();
    }
    return $this->boundary($this->latestStep($now) + 1)->getTimestamp();
  }

  /**
   * When a leg inside a rolling window ages out of it.
   *
   * @param int $created
   *   The leg's time.
   *
   * @throws \LogicException
   *   On an anchored window, whose legs leave it at the next boundary.
   */
  public function agedOutAt(int $created): int {
    if ($this->anchor !== NULL) {
      throw new \LogicException('An anchored window clears at its next boundary; its legs never age out one by one.');
    }
    return $this->at($created)->add($this->period)->getTimestamp();
  }

  /**
   * The longest span the window can cover, in seconds.
   *
   * A rolling period's longest realization (a month as 31 days, a year as
   * 366, a day as 86400); an anchored window two periods, the current one
   * and the one before it. Retention never cuts under this, so a window
   * sum is never computed over a hole.
   */
  public function maxSeconds(): int {
    $one = self::longestSeconds($this->period);
    return $this->anchor === NULL ? $one : 2 * $one;
  }

  /**
   * The period in words.
   *
   * A whole number of months reads as the formatter's month unit alone
   * ("1 month", "3 months"); any other period reads as the formatter's
   * interval over its fixed realization at two units ("5 hours", "1 day",
   * "1 week" for seven days, "1 day 12 hours").
   *
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter.
   */
  public function label(DateFormatterInterface $dateFormatter): string {
    $p = $this->period;
    if ($p->m > 0 && $p->y === 0 && $p->d === 0 && $p->h === 0 && $p->i === 0 && $p->s === 0) {
      return (string) $dateFormatter->formatInterval($p->m * 2592000, 1);
    }
    $seconds = $p->y * 31536000 + $p->m * 2592000 + $p->d * 86400 + $p->h * 3600 + $p->i * 60 + $p->s;
    return (string) $dateFormatter->formatInterval($seconds, 2);
  }

  /**
   * The config row this window came from.
   *
   * @return array{scope: string, period: string, usd: float, anchor: string}
   *   The row.
   */
  public function toArray(): array {
    return [
      'scope' => $this->scope,
      'period' => $this->periodSpec,
      'usd' => $this->usd,
      'anchor' => $this->anchor?->format(self::ANCHOR_FORMAT) ?? '',
    ];
  }

  /**
   * A timestamp as a moment in the window's timezone.
   */
  private function at(int $timestamp): \DateTimeImmutable {
    return (new \DateTimeImmutable('@' . $timestamp))->setTimezone($this->timezone);
  }

  /**
   * The k-th boundary: the anchor plus k whole periods.
   *
   * @param int $k
   *   How many periods past the anchor; zero is the anchor.
   */
  private function boundary(int $k): \DateTimeImmutable {
    if ($k === 0) {
      return $this->anchor;
    }
    return $this->anchor->add(self::scaled($this->period, $k));
  }

  /**
   * The largest k whose boundary is at or before now; zero for a future anchor.
   *
   * Estimated from the period's longest realization, then settled by
   * stepping either way, so neither a short month nor a DST day misplaces
   * it.
   */
  private function latestStep(int $now): int {
    $anchor = $this->anchor->getTimestamp();
    if ($anchor >= $now) {
      return 0;
    }
    $k = intdiv($now - $anchor, self::longestSeconds($this->period));
    while ($k > 0 && $this->boundary($k)->getTimestamp() > $now) {
      $k--;
    }
    while ($this->boundary($k + 1)->getTimestamp() <= $now) {
      $k++;
    }
    return $k;
  }

  /**
   * A period multiplied by a whole number.
   */
  private static function scaled(\DateInterval $period, int $k): \DateInterval {
    return new \DateInterval(sprintf('P%dY%dM%dDT%dH%dM%dS', $period->y * $k, $period->m * $k, $period->d * $k, $period->h * $k, $period->i * $k, $period->s * $k));
  }

  /**
   * The most seconds one period can span.
   */
  private static function longestSeconds(\DateInterval $period): int {
    return $period->y * 366 * 86400 + $period->m * 31 * 86400 + $period->d * 86400 + $period->h * 3600 + $period->i * 60 + $period->s;
  }

}
