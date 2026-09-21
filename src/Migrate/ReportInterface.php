<?php

declare(strict_types=1);

namespace Drupal\daedalus\Migrate;

/**
 * What a migration phase answers with.
 */
interface ReportInterface {

  /**
   * Whether the phase succeeded; a refusal or a failure is FALSE.
   */
  public function ok(): bool;

  /**
   * The one-line summary the entry prints last.
   */
  public function summary(): string;

  /**
   * The full report, newline-terminated, printed before the summary.
   */
  public function render(): string;

}
