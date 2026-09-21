<?php

declare(strict_types=1);

namespace Drupal\daedalus\Migrate;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * One phase of the site migration, run by migrate/plus_suite/translate.php.
 *
 * A phase is built from the container, runs once per invocation and answers
 * with a report; the entry prints the report and records a completed phase
 * in state under `daedalus.migrate.<phase>` unless the phase is repeatable.
 */
interface PhaseInterface {

  /**
   * Whether the phase runs again after it has been recorded as done.
   *
   * A read-only phase (the audit) is repeatable: it opens and closes the
   * migration. A phase that changes the site is not, and the entry skips it
   * once it is recorded.
   */
  public const REPEATABLE = FALSE;

  /**
   * Builds the phase from the container.
   */
  public static function create(ContainerInterface $container): static;

  /**
   * Runs the phase.
   */
  public function run(): ReportInterface;

}
