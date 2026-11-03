<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_ledger\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\daedalus_ai_ledger\UsageRetention;

/**
 * Purges the usage rows past their retention on cron.
 */
final class LedgerCron {

  public function __construct(
    private readonly UsageRetention $retention,
  ) {}

  /**
   * Implements hook_cron().
   *
   * Deletes the rows older than the retention (see UsageRetention), a
   * bounded number per run, so a long-lived ledger drains over a few runs.
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->retention->purge();
  }

}
