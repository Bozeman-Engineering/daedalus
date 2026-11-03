<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_ledger;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * How long the rows are kept, and the purge that enforces it on cron.
 *
 * `retention_days` on the settings says how many days of rows to keep; 0
 * keeps every row. The purge never cuts under the longest span any
 * configured window can cover (Window::maxSeconds(): a rolling P1M is up
 * to 31 days, an anchored window two periods), so a retention shorter
 * than that is raised to it and a window sum is never computed over a
 * hole. Rows are deleted through the entity storage in batches, oldest
 * first, at most PURGE_LIMIT per run: a site that kept a year of legs
 * before a shorter retention was set drains over a few cron runs rather
 * than holding one for minutes, and the steady state (one day's rows a
 * day) is far under the limit.
 */
final class UsageRetention {

  /**
   * The settings key: days of rows to keep, 0 for every row.
   */
  public const RETENTION_KEY = 'retention_days';

  /**
   * The most rows one purge deletes; the rest wait for the next cron run.
   */
  public const PURGE_LIMIT = 10000;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly UsageGate $usageGate,
    private readonly UsageLedger $ledger,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * The configured retention in days; 0 (or an unset key) keeps every row.
   */
  public function configuredDays(): int {
    return max(0, (int) $this->configFactory->get(CostResolver::SETTINGS)->get(self::RETENTION_KEY));
  }

  /**
   * The longest span any configured window can cover, in seconds.
   *
   * Zero with no windows configured.
   */
  public function windowFloorSeconds(): int {
    $floor = 0;
    foreach ($this->usageGate->windows() as $window) {
      $floor = max($floor, $window->maxSeconds());
    }
    return $floor;
  }

  /**
   * The retention the purge enforces, in seconds; NULL keeps every row.
   *
   * The configured days, raised to the window floor when shorter.
   */
  public function retentionSeconds(): ?int {
    $days = $this->configuredDays();
    if ($days === 0) {
      return NULL;
    }
    return max($days * 86400, $this->windowFloorSeconds());
  }

  /**
   * Deletes the rows older than the retention, up to PURGE_LIMIT of them.
   *
   * @param int|null $now
   *   The moment to judge age at; the request time when NULL.
   *
   * @return int
   *   How many rows were deleted.
   */
  public function purge(?int $now = NULL): int {
    $seconds = $this->retentionSeconds();
    if ($seconds === NULL) {
      return 0;
    }
    $now ??= $this->time->getRequestTime();
    $deleted = $this->ledger->deleteBefore($now - $seconds, self::PURGE_LIMIT);
    if ($deleted > 0) {
      $this->logger->notice('Deleted @count AI usage rows older than @days days.', [
        '@count' => $deleted,
        '@days' => intdiv($seconds, 86400),
      ]);
    }
    return $deleted;
  }

}
