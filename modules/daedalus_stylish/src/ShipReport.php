<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

/**
 * What one ship of a module's package did, per kind and machine name.
 *
 * Ship::ship() and Ship::plan() return one, stamped with the module shipped
 * into: every entry a per-id file holds gets an outcome (created, updated,
 * unchanged, dropped), every entry of the hand-authored single file the
 * site edited is reported modified (never written), and every file or name
 * the lane refused gets FAILED plus the structured errors that refused it.
 * A failure never stops the run: the next file of the same kind and the
 * other kinds still ship. The name a failure is recorded under is the
 * machine name for a named adoption with no entity behind it, and the
 * per-id file's base name when the whole file was refused (a name shipped
 * elsewhere refuses the file, since a per-id file is written whole).
 *
 * The grammar is the sync report's read from the other direction: the ship
 * command prints `MODULE: KIND NAME outcome` per entry and the failure
 * lines SyncReport prints, so a developer reads one vocabulary whether
 * disk moved to the site or the site to disk. A dry run fills the same
 * report from the shippers' plans, so its output is the real run's.
 */
final class ShipReport {

  /**
   * Outcomes keyed by kind then name.
   *
   * @var array<string, array<string, string>>
   */
  protected array $outcomes = [];

  /**
   * The file each outcome concerns (base name), keyed by kind then name.
   *
   * @var array<string, array<string, string>>
   */
  protected array $files = [];

  /**
   * The errors behind every FAILED outcome, keyed by kind then name.
   *
   * @var array<string, array<string, \Drupal\daedalus_blueprint\Result\BlueprintError[]>>
   */
  protected array $errors = [];

  /**
   * Constructs the report.
   *
   * @param string $module
   *   The module shipped into.
   */
  public function __construct(
    protected string $module,
  ) {}

  /**
   * The module shipped into.
   */
  public function module(): string {
    return $this->module;
  }

  /**
   * Records an entry's outcome.
   *
   * @param string $kind
   *   The kind, as ShippedDefinitionsImporter::kind() names it.
   * @param string $name
   *   The entry's machine name.
   * @param string $outcome
   *   One of the shipper's CREATED, UPDATED, UNCHANGED, DROPPED, or
   *   Ship::MODIFIED for a hand-authored entry the site edited.
   * @param string $file
   *   The base name of the file the entry lives in.
   */
  public function record(string $kind, string $name, string $outcome, string $file): void {
    $this->outcomes[$kind][$name] = $outcome;
    $this->files[$kind][$name] = $file;
  }

  /**
   * Records a failure: the file, or the named entry, did not ship.
   *
   * @param string $kind
   *   The kind.
   * @param string $name
   *   The machine name of a named adoption with nothing to adopt, or the
   *   base name of the per-id file the lane refused whole.
   * @param \Drupal\daedalus_blueprint\Result\BlueprintError[] $errors
   *   The structured errors that refused it.
   */
  public function fail(string $kind, string $name, array $errors): void {
    $this->outcomes[$kind][$name] = ShippedDefinitionsImporter::FAILED;
    $this->errors[$kind][$name] = $errors;
  }

  /**
   * Every outcome, keyed by kind then name, in the order the kinds ran.
   *
   * @return array<string, array<string, string>>
   *   The outcomes.
   */
  public function outcomes(): array {
    return $this->outcomes;
  }

  /**
   * One entry's outcome, or NULL when the ship never saw it.
   */
  public function outcome(string $kind, string $name): ?string {
    return $this->outcomes[$kind][$name] ?? NULL;
  }

  /**
   * The base name of the file an entry's outcome concerns, or NULL.
   */
  public function file(string $kind, string $name): ?string {
    return $this->files[$kind][$name] ?? NULL;
  }

  /**
   * The errors behind every failure, keyed by kind then name.
   *
   * @return array<string, array<string, \Drupal\daedalus_blueprint\Result\BlueprintError[]>>
   *   Only the FAILED entries; empty when everything shipped.
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
  public function errors(string $kind, string $name): ?array {
    return $this->errors[$kind][$name] ?? NULL;
  }

  /**
   * Whether anything failed.
   */
  public function hasFailures(): bool {
    return $this->errors !== [];
  }

  /**
   * Whether the ship saw nothing: no per-id file, no name to adopt.
   */
  public function isEmpty(): bool {
    return $this->outcomes === [];
  }

  /**
   * The failures as human-readable lines, in the sync report's grammar.
   *
   * @return string[]
   *   The lines, empty when nothing failed.
   */
  public function failureLines(): array {
    $lines = [];
    foreach ($this->errors as $kind => $failures) {
      foreach ($failures as $name => $errors) {
        $lines = array_merge($lines, SyncReport::failureLinesFor($this->module, $kind, (string) $name, $errors));
      }
    }
    return $lines;
  }

}
