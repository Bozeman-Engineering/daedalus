<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

/**
 * What one orchestrated sync did: the four kinds' reports, in sync order.
 *
 * ShippedSync::sync() returns one. Each kind keeps its own SyncReport (a
 * machine name is a vocabulary per kind, so the reports never merge); this
 * wrapper answers the questions every consumer asks of the whole run: did
 * anything fail, did anything ship at all, and the failure lines in the
 * one grammar the command prints and the update hooks throw.
 */
final class ShippedSyncReport {

  /**
   * Constructs the report.
   *
   * @param array<string, \Drupal\daedalus_stylish\SyncReport> $reports
   *   The per-kind reports keyed by kind, in the order they ran.
   */
  public function __construct(
    protected array $reports,
  ) {}

  /**
   * The per-kind reports keyed by kind, in the order they ran.
   *
   * @return array<string, \Drupal\daedalus_stylish\SyncReport>
   *   The reports.
   */
  public function reports(): array {
    return $this->reports;
  }

  /**
   * One kind's report.
   *
   * @param string $kind
   *   The kind, as ShippedDefinitionsImporter::kind() names it.
   */
  public function report(string $kind): SyncReport {
    return $this->reports[$kind];
  }

  /**
   * Whether anything of any kind failed.
   */
  public function hasFailures(): bool {
    foreach ($this->reports as $report) {
      if ($report->hasFailures()) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether the run saw nothing at all (no module shipped a file of any kind).
   */
  public function isEmpty(): bool {
    foreach ($this->reports as $report) {
      if (!$report->isEmpty()) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * The failures of every kind as human-readable lines, in sync order.
   *
   * @return string[]
   *   The lines (SyncReport::failureLines() per kind), empty when nothing
   *   failed.
   */
  public function failureLines(): array {
    $lines = [];
    foreach ($this->reports as $report) {
      $lines = array_merge($lines, $report->failureLines());
    }
    return $lines;
  }

}
