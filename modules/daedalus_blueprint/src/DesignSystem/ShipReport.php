<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\DesignSystem;

use Drupal\daedalus_blueprint\Result\BlueprintError;

/**
 * What one ship into a module's package did, per kind and machine name.
 *
 * The one report every site-to-disk lane fills: DesignSystemShip threads
 * one through each tagged package shipper (the blueprint lane, then
 * daedalus_stylish's materials lane), and daedalus_stylish's Ship fills one
 * of its own for a named adoption. Every entry a lane writes or reads
 * gets an outcome in the disk lane's vocabulary (created, updated,
 * unchanged, dropped, the lanes' own words), a hand-authored entry the
 * site edited is reported MODIFIED (never written), and every file or
 * name a lane refused gets FAILED plus the structured errors that
 * refused it. A failure never stops a run: the next file of the same
 * kind, the other kinds and the other lanes still ship. The name a
 * failure is recorded under is the machine name for an entry with
 * nothing to ship, and a file's base name when the whole file was
 * refused (a name shipped elsewhere refuses the file, since a per-id
 * file is written whole).
 *
 * The grammar is the sync report's read from the other direction: the
 * commands print `MODULE: KIND NAME outcome` per entry and the failure
 * lines the sync report prints (failureLinesFor(), the one formatter
 * both directions share), so a developer reads one vocabulary whether
 * disk moved to the site or the site to disk. A dry run fills the same
 * report from the lanes' plans, so its output is the real run's.
 *
 * Two lanes may touch one file: the blueprint lane writes a variants
 * per-id file and the materials lane's rebuild then reads it back
 * unchanged. The first outcome recorded for a name stands against a
 * later UNCHANGED, so the entry reads as the lane that wrote it saw it.
 */
final class ShipReport {

  /**
   * The entry was refused; errors() carries why.
   */
  public const FAILED = 'failed';

  /**
   * A hand-authored entry the site edited: reported, never written.
   */
  public const MODIFIED = 'modified';

  /**
   * The entry already matches disk; the null outcome.
   */
  public const UNCHANGED = 'unchanged';

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
   * Everything a lane said out loud beside its outcomes.
   *
   * @var string[]
   */
  protected array $notes = [];

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
   *   The kind: a material noun as its lane names it (font, animation,
   *   swatch, skin, variant, blueprint, icon), or the package's own parts
   *   (module, design_system).
   * @param string $name
   *   The entry's machine name.
   * @param string $outcome
   *   The lane's word for what happened (created, updated, unchanged,
   *   dropped, copied, kept, installed), or MODIFIED for a hand-authored
   *   entry the site edited. An UNCHANGED over an outcome another lane
   *   already recorded is dropped (see the class docblock).
   * @param string $file
   *   The base name of the file the entry lives in.
   */
  public function record(string $kind, string $name, string $outcome, string $file): void {
    if ($outcome === self::UNCHANGED && isset($this->outcomes[$kind][$name])) {
      return;
    }
    $this->outcomes[$kind][$name] = $outcome;
    $this->files[$kind][$name] = $file;
  }

  /**
   * Records a failure: the file, or the named entry, did not ship.
   *
   * @param string $kind
   *   The kind.
   * @param string $name
   *   The machine name of an entry with nothing to ship, or the base name
   *   of the file the lane refused whole.
   * @param \Drupal\daedalus_blueprint\Result\BlueprintError[] $errors
   *   The structured errors that refused it.
   */
  public function fail(string $kind, string $name, array $errors): void {
    $this->outcomes[$kind][$name] = self::FAILED;
    $this->errors[$kind][$name] = $errors;
  }

  /**
   * Records something a lane says beside its outcomes.
   *
   * A skipped variant with its reason, a content blueprint that graduates,
   * a capture warning: facts the terminal should show that are neither an
   * outcome nor a refusal.
   */
  public function note(string $note): void {
    $this->notes[] = $note;
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
   * The notes, in the order the lanes said them.
   *
   * @return string[]
   *   The notes.
   */
  public function notes(): array {
    return $this->notes;
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
   * Whether the ship saw nothing: no file, no name to adopt.
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
        $lines = array_merge($lines, self::failureLinesFor($this->module, $kind, (string) $name, $errors));
      }
    }
    return $lines;
  }

  /**
   * One failure as terminal lines: the header, then each error and hint.
   *
   * The one formatter of a refused entry for both directions of the disk
   * lane (daedalus_stylish's SyncReport prints its failures through it),
   * so a refusal reads the same whether disk moved to the site or the
   * site to disk.
   *
   * @param string $module
   *   The module.
   * @param string $kind
   *   The kind.
   * @param string $name
   *   The entry or file name the failure is recorded under.
   * @param \Drupal\daedalus_blueprint\Result\BlueprintError[] $errors
   *   The structured errors.
   *
   * @return string[]
   *   The header line, then one line per error and its hint when present.
   */
  public static function failureLinesFor(string $module, string $kind, string $name, array $errors): array {
    $lines = [sprintf('%s: %s %s failed', $module, $kind, $name)];
    foreach ($errors as $error) {
      assert($error instanceof BlueprintError);
      $lines[] = sprintf('  %s [%s]: %s', $error->getPath(), $error->getCode(), $error->getMessage());
      if ($error->getHint() !== '') {
        $lines[] = sprintf('    Hint: %s', $error->getHint());
      }
    }
    return $lines;
  }

}
