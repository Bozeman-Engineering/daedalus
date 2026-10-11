<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\daedalus_blueprint\DesignSystem\ShipReport;

/**
 * What one sync of shipped definitions did, per module and machine name.
 *
 * ShippedDefinitionsImporter::sync() returns one, stamped with the kind it
 * synced (variant, skin, swatch, animation): every definition it read
 * gets an outcome (created, updated, unchanged, kept), and every definition
 * or file it could not take gets FAILED plus the structured errors that
 * refused it. A failure never stops the run: the next definition in the
 * same file still lands, and one broken module never blocks another. The
 * name a failure is recorded under is the machine name for a definition
 * that failed validation or collided, and the file's base name for a file
 * that could not be read (malformed YAML, a machine name shipped twice).
 *
 * A module that packages a design system is DEFERRED as a whole while its
 * system is not the active one (its definitions are not read, let alone
 * validated): they land at the system's activation, under its own law.
 * A deferral is neither an outcome nor a failure; it is recorded per
 * module with the system it waits for.
 *
 * Every consumer speaks this one report: the sync command prints it and
 * exits non-zero on failures, update hooks fail the update on failures,
 * the rebuild and install hooks log it, and the failure lines are the same
 * text wherever they surface. ShippedSync composes one per kind into a
 * ShippedSyncReport.
 */
final class SyncReport {

  /**
   * Constructs the report.
   *
   * @param string $kind
   *   The kind synced, as ShippedDefinitionsImporter::kind() names it.
   */
  public function __construct(
    protected string $kind,
  ) {}

  /**
   * The kind synced: 'variant', 'skin', 'swatch', or 'animation'.
   */
  public function kind(): string {
    return $this->kind;
  }

  /**
   * Outcomes keyed by module then name.
   *
   * @var array<string, array<string, string>>
   */
  protected array $outcomes = [];

  /**
   * The errors behind every FAILED outcome, keyed by module then name.
   *
   * @var array<string, array<string, \Drupal\daedalus_blueprint\Result\BlueprintError[]>>
   */
  protected array $errors = [];

  /**
   * The modules deferred, keyed by module: the design system each awaits.
   *
   * @var array<string, string>
   */
  protected array $deferred = [];

  /**
   * Records a module's deferral: it packages a design system not active.
   *
   * @param string $module
   *   The package module.
   * @param string $design_system
   *   The id of the design system whose activation lands its definitions.
   */
  public function defer(string $module, string $design_system): void {
    $this->deferred[$module] = $design_system;
  }

  /**
   * The deferred modules, keyed by module: the design system each awaits.
   *
   * @return array<string, string>
   *   Empty when every module in the set was read.
   */
  public function deferred(): array {
    return $this->deferred;
  }

  /**
   * Records a definition's outcome.
   *
   * @param string $module
   *   The module shipping the definition.
   * @param string $name
   *   The definition's machine name.
   * @param string $outcome
   *   One of ShippedDefinitionsImporter::CREATED, UPDATED, UNCHANGED, KEPT.
   */
  public function record(string $module, string $name, string $outcome): void {
    $this->outcomes[$module][$name] = $outcome;
  }

  /**
   * Records a failure: the definition (or file) did not land.
   *
   * @param string $module
   *   The module shipping the definition or file.
   * @param string $name
   *   The definition's machine name, or the file's base name when the file
   *   itself could not be read.
   * @param \Drupal\daedalus_blueprint\Result\BlueprintError[] $errors
   *   The structured errors that refused it.
   */
  public function fail(string $module, string $name, array $errors): void {
    $this->outcomes[$module][$name] = ShippedDefinitionsImporter::FAILED;
    $this->errors[$module][$name] = $errors;
  }

  /**
   * Every outcome, keyed by module then name.
   *
   * @return array<string, array<string, string>>
   *   The CREATED/UPDATED/UNCHANGED/KEPT/FAILED constants of
   *   ShippedDefinitionsImporter.
   */
  public function outcomes(): array {
    return $this->outcomes;
  }

  /**
   * One definition's outcome, or NULL when the sync never saw it.
   */
  public function outcome(string $module, string $name): ?string {
    return $this->outcomes[$module][$name] ?? NULL;
  }

  /**
   * The errors behind every failure, keyed by module then name.
   *
   * @return array<string, array<string, \Drupal\daedalus_blueprint\Result\BlueprintError[]>>
   *   Only the FAILED entries; empty when everything landed.
   */
  public function failures(): array {
    return $this->errors;
  }

  /**
   * The errors behind one failure, or NULL when the entry did not fail.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]|null
   *   The structured errors.
   */
  public function errors(string $module, string $name): ?array {
    return $this->errors[$module][$name] ?? NULL;
  }

  /**
   * Whether anything failed.
   */
  public function hasFailures(): bool {
    return $this->errors !== [];
  }

  /**
   * Whether the sync saw nothing at all: no file read, no module deferred.
   */
  public function isEmpty(): bool {
    return $this->outcomes === [] && $this->deferred === [];
  }

  /**
   * The failures as human-readable lines: the one text every surface prints.
   *
   * Per failure one header line (`MODULE: KIND NAME failed`), then one line per
   * error (`  PATH [CODE]: MESSAGE`) and its hint when it carries one
   * (`    Hint: ...`): ShipReport::failureLinesFor(), the one formatter both
   * directions of the disk lane print through.
   *
   * @return string[]
   *   The lines, empty when nothing failed.
   */
  public function failureLines(): array {
    $lines = [];
    foreach ($this->errors as $module => $failures) {
      foreach ($failures as $name => $errors) {
        $lines = array_merge($lines, ShipReport::failureLinesFor($module, $this->kind, (string) $name, $errors));
      }
    }
    return $lines;
  }

}
