<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\daedalus_stylish\Exception\VariantValidationException;

/**
 * The one ship lane for a module's package: the site OUT to its per-id files.
 *
 * ShippedSync brings a module's MODULE.daedalus_stylish.<kind>*.yml INTO the site;
 * this orchestrator runs the kind shippers (ShippedDefinitionsShipper's
 * subclasses, one per kind that has a site-to-disk lane: skins and
 * variants today, swatches and animations when they gain a shipper) over
 * ONE module and answers with a ShipReport. It is the lane the package
 * author uses from a development site: change a shipped material on the
 * site (the distro lock lifted by the daedalus_blueprint developer gate),
 * then ship the module, and disk follows the site.
 *
 * With no names, every per-id file of the module is rebuilt whole from
 * the current entities: each entry's definition comes from the entity
 * (the shipper's definition()), keys the shipper does not emit are carried
 * from the existing entry (the variants file's `stencil:` key belongs to
 * whoever owns stencils; this module never learns what it means), and an
 * entry whose entity no longer exists is dropped from the file. Entries
 * of the hand-authored single file (MODULE.daedalus_stylish.<kind>.yml) are read,
 * never written: one the site edited is reported MODIFIED so the author
 * edits the file by hand; a machine never touches a human's file.
 *
 * With names (per kind), the named site materials are adopted into the
 * module: a name already in one of the module's per-id files rebuilds that
 * file (a per-id file is always written whole, so the rule is one rule);
 * a new name lands in MODULE.daedalus_stylish.<kind>.NAME.yml, joining that file's
 * entries if it exists. A name with no entity, shipped by another module,
 * or living in the module's single file is refused with the shipper's
 * own error (SHIPPED_<KIND>_ABSENT, SHIPPED_<KIND>_CONFLICT), and the
 * refusal fails the file it would have written while the rest of the run
 * proceeds. Nothing the lane can refuse throws out of here; the command
 * prints the report and exits non-zero on failures.
 *
 * After a ship the site is consistent with disk: every shipped entity is
 * re-stamped with the module as provider and the written payload as its
 * source hash, and published, so a sync of the module reports UNCHANGED.
 * plan() is the dry run: the same report from the shippers' plans, with
 * no file written and no entity touched.
 */
final class Ship {

  /**
   * A hand-authored entry the site edited: reported, never written.
   */
  public const MODIFIED = 'modified';

  /**
   * Constructs the orchestrator.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   Answers whether the target module is enabled and where it lives.
   * @param \Drupal\daedalus_stylish\SkinShipper $skins
   *   The skins lane's site-to-disk direction.
   * @param \Drupal\daedalus_stylish\VariantShipper $variants
   *   The variants lane's site-to-disk direction.
   */
  public function __construct(
    protected ModuleHandlerInterface $moduleHandler,
    protected SkinShipper $skins,
    protected VariantShipper $variants,
  ) {}

  /**
   * The shippers keyed by kind, in the sync's order (skins, variants).
   *
   * The order is the sync's so a ship report reads like a sync report;
   * nothing on disk depends on it (each file is one kind).
   *
   * @return array<string, \Drupal\daedalus_stylish\ShippedDefinitionsShipper>
   *   Keyed by the kind's noun, as the importer's kind() names it.
   */
  public function shippers(): array {
    $shippers = [];
    foreach ([$this->skins, $this->variants] as $shipper) {
      $shippers[$shipper->importer()->kind()] = $shipper;
    }
    return $shippers;
  }

  /**
   * Plans a ship without touching disk or entities: the dry run's report.
   *
   * @param string $module
   *   The target module.
   * @param array<string, string[]> $names
   *   Machine names to adopt, keyed by kind; empty for a module-wide
   *   rebuild of every per-id file.
   *
   * @throws \InvalidArgumentException
   *   When the module is not enabled, or a kind has no shipper.
   */
  public function plan(string $module, array $names = []): ShipReport {
    return $this->run($module, $names, FALSE);
  }

  /**
   * Ships: rewrites the per-id files and adopts the entities.
   *
   * @param string $module
   *   The target module.
   * @param array<string, string[]> $names
   *   Machine names to adopt, keyed by kind; empty for a module-wide
   *   rebuild of every per-id file.
   *
   * @throws \InvalidArgumentException
   *   When the module is not enabled, or a kind has no shipper.
   * @throws \RuntimeException
   *   When a file cannot be written.
   */
  public function ship(string $module, array $names = []): ShipReport {
    return $this->run($module, $names, TRUE);
  }

  /**
   * Runs the shippers over the module; see plan() and ship().
   */
  protected function run(string $module, array $names, bool $write): ShipReport {
    if (!$this->moduleHandler->moduleExists($module)) {
      throw new \InvalidArgumentException(sprintf('The "%s" module is not enabled, so there is no directory to ship into.', $module));
    }
    $shippers = $this->shippers();
    $names = array_filter(array_map('array_values', $names));
    if (($unknown = array_diff_key($names, $shippers)) !== []) {
      throw new \InvalidArgumentException(sprintf('No shipper for the kind(s) %s; the kinds that ship are %s.', implode(', ', array_keys($unknown)), implode(', ', array_keys($shippers))));
    }
    $report = new ShipReport($module);
    $module_wide = $names === [];
    foreach ($shippers as $kind => $shipper) {
      $files = [];
      foreach ($shipper->importer()->perIdFiles($module) as $file_id => $path) {
        $files[$file_id] = $shipper->readFile($path);
      }
      if ($module_wide) {
        $this->reportHandAuthored($report, $module, $kind, $shipper);
        $rebuild = array_fill_keys(array_keys($files), []);
      }
      else {
        $rebuild = [];
        foreach (array_unique($names[$kind] ?? []) as $name) {
          $file_id = $this->fileHolding($files, $name);
          if ($file_id !== NULL) {
            $rebuild[$file_id] ??= [];
          }
          else {
            $rebuild[$name][] = $name;
          }
        }
      }
      foreach ($rebuild as $file_id => $adopt) {
        $this->shipFile($report, $module, $kind, $shipper, (string) $file_id, $files[$file_id] ?? [], $adopt, $write);
      }
    }
    return $report;
  }

  /**
   * Rebuilds one per-id file from its entities and the names it adopts.
   *
   * @param \Drupal\daedalus_stylish\ShipReport $report
   *   The run's report, every entry of the file recorded on it.
   * @param string $module
   *   The target module.
   * @param string $kind
   *   The kind, as the shipper's importer names it.
   * @param \Drupal\daedalus_stylish\ShippedDefinitionsShipper $shipper
   *   The kind's shipper.
   * @param string $file_id
   *   The per-id file's id (MODULE.daedalus_stylish.<kind>.FILE_ID.yml).
   * @param array $existing
   *   The file's entries as authored (empty for a new file).
   * @param string[] $adopt
   *   Names to add to the file (the named adoption).
   * @param bool $write
   *   TRUE ships (writes and adopts), FALSE plans (the dry run).
   */
  protected function shipFile(ShipReport $report, string $module, string $kind, ShippedDefinitionsShipper $shipper, string $file_id, array $existing, array $adopt, bool $write): void {
    $file = basename($shipper->filePath($module, $file_id));
    $definitions = [];
    foreach ($existing as $name => $entry) {
      $entity = $shipper->load((string) $name);
      if ($entity === NULL) {
        // Left out of the rewrite: the plan reports it DROPPED.
        continue;
      }
      $definitions[$name] = $this->carry(is_array($entry) ? $entry : [], $shipper->definitionOf($entity));
    }
    foreach ($adopt as $name) {
      if (array_key_exists($name, $definitions)) {
        continue;
      }
      $entity = $shipper->load($name);
      if ($entity === NULL) {
        $report->fail($kind, $name, $shipper->absentException($module, $name)->getErrors());
        return;
      }
      $definitions[$name] = $shipper->definitionOf($entity);
    }
    try {
      $outcomes = $write
        ? $shipper->ship($module, $file_id, $definitions)
        : $shipper->plan($module, $file_id, $definitions);
      $conflicts = array_keys($outcomes, ShippedDefinitionsShipper::SHIPPED_ELSEWHERE, TRUE);
      if ($conflicts !== []) {
        // ship() threw before writing; the plan reports the same refusal
        // so a dry run reads exactly as the run would.
        throw $shipper->conflictException($module, $file_id, $conflicts);
      }
    }
    catch (VariantValidationException $e) {
      // Meaningful recovery: the file is refused whole and reported; the
      // module's other files and kinds still ship.
      $report->fail($kind, $file, $e->getErrors());
      return;
    }
    foreach ($outcomes as $name => $outcome) {
      $report->record($kind, (string) $name, $outcome, $file);
    }
  }

  /**
   * Reports the single file's entries: never written, modified ones named.
   *
   * An entry with no entity on the site, or whose entity another module
   * shipped, is not the site's to report (the next sync creates it, or
   * refuses the collision); every other entry reads UNCHANGED, or
   * MODIFIED when the entity's shipped data moved since its last sync.
   */
  protected function reportHandAuthored(ShipReport $report, string $module, string $kind, ShippedDefinitionsShipper $shipper): void {
    $path = $this->moduleHandler->getModule($module)->getPath() . "/$module." . $shipper->importer()->fileSuffix();
    foreach ($shipper->readFile($path) as $name => $entry) {
      $entity = $shipper->load((string) $name);
      if ($entity === NULL || $entity->getProvider() !== $module) {
        continue;
      }
      $report->record($kind, (string) $name, $entity->isModified() ? self::MODIFIED : ShippedDefinitionsShipper::UNCHANGED, basename($path));
    }
  }

  /**
   * The id of the per-id file holding a name, or NULL when none does.
   *
   * @param array<string, array> $files
   *   The module's per-id files' entries, keyed by file id.
   * @param string $name
   *   The machine name looked for.
   */
  protected function fileHolding(array $files, string $name): ?string {
    foreach ($files as $file_id => $entries) {
      if (array_key_exists($name, $entries)) {
        return (string) $file_id;
      }
    }
    return NULL;
  }

  /**
   * Merges a rebuilt definition over the existing entry, carrying its keys.
   *
   * The entity wins every key the shipper emits; keys it does not emit
   * survive from the entry, in the entry's order (a `stencil:` key stays
   * where its owner put it), and keys the entry never had append.
   */
  protected function carry(array $entry, array $definition): array {
    $merged = [];
    foreach ($entry as $key => $value) {
      $merged[$key] = array_key_exists($key, $definition) ? $definition[$key] : $value;
    }
    return $merged + $definition;
  }

}
