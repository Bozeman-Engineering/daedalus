<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Component\Serialization\Yaml;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_stylish\Exception\VariantValidationException;
use Drupal\workspaces\WorkspaceManagerInterface;

/**
 * Ships site entities to disk: the disk lane's other direction, per kind.
 *
 * ShippedDefinitionsImporter brings MODULE.daedalus_stylish.<kind>*.yml INTO a site
 * (sync); a shipper takes a site's entities of one kind OUT to a module
 * (ship). A ship writes one per-id file, MODULE.daedalus_stylish.<kind>.FILE_ID.yml
 * (the machine-written form the importer discovers beside the hand-authored
 * single file, so a ship never touches a human's file), and adopts every
 * shipped entity IN PLACE: the entity keeps its id and uuid (references
 * elsewhere may hold either), gains the module as its provider and the
 * written payload as its source hash, and lands published. After a ship
 * the site is already consistent with disk: a sync of the module reports
 * UNCHANGED for every shipped name, nothing to run.
 *
 * The file's grammar stays the importer's: each subclass's definition()
 * emits exactly the keys its importer reads, and every definition a caller
 * ships is checked against the importer's shape rule before it is written,
 * so a shipper never writes what the importer would refuse to read.
 * Callers add what this module cannot know: the variants file's `blueprint:`
 * key belongs to whoever owns blueprints (daedalus_blueprint_library), not here.
 *
 * Machine names are the site-wide vocabulary of a kind, so a name already
 * shipped by another module, or by another file of the target module, is
 * never re-shipped: plan() reports it SHIPPED_ELSEWHERE and ship() refuses
 * the whole run (SHIPPED_<KIND>_CONFLICT, the importer's word for the same
 * collision seen from the other direction). Likewise a definition naming
 * no entity on this site has nothing to adopt and is refused: shipping is
 * "this site's entity goes to disk", not a second way to author files.
 *
 * A subclass supplies the kind's facts: the importer it mirrors (through
 * the constructor), the entity type it adopts, the slice of a definition
 * the source hash covers, the hash function the entity stamps with, the
 * exception the importer throws for the kind, and a public definition()
 * that builds a definition from an entity in the importer's grammar.
 */
abstract class ShippedDefinitionsShipper {

  /**
   * Plan outcomes, per shipped name.
   *
   * The disk-side vocabulary: whether the per-id file gains, changes, or
   * already holds the entry. The importer's constants are reused so a
   * ship report reads like a sync report.
   */
  public const CREATED = ShippedDefinitionsImporter::CREATED;
  public const UPDATED = ShippedDefinitionsImporter::UPDATED;
  public const UNCHANGED = ShippedDefinitionsImporter::UNCHANGED;

  /**
   * The name is shipped by another module or another file of this module.
   */
  public const SHIPPED_ELSEWHERE = 'shipped_elsewhere';

  /**
   * The per-id file held the name and the new ship no longer includes it.
   *
   * The file is rewritten whole, so the entry leaves disk; the entity is
   * untouched (the disk lane never deletes) and lives on as ordinary site
   * content until something ships it again.
   */
  public const DROPPED = 'dropped';

  /**
   * Constructs the shipper.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   Resolves the target module's directory and lists the enabled modules
   *   whose files are read for collisions.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The kind's storage: shipped names are loaded and adopted in place.
   * @param \Drupal\daedalus_stylish\ShippedDefinitionsImporter $importer
   *   The other direction of the lane for this kind: owns the file names,
   *   the grammar check, and the read of what every module already ships.
   * @param \Drupal\workspaces\WorkspaceManagerInterface|null $workspaceManager
   *   Optional: with Workspaces, the lookups and the adopting saves run
   *   outside any active workspace, exactly as the importer's sync does,
   *   so shipped entities are LIVE facts, never staged invisibly.
   */
  public function __construct(
    protected ModuleHandlerInterface $moduleHandler,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ShippedDefinitionsImporter $importer,
    protected ?WorkspaceManagerInterface $workspaceManager = NULL,
  ) {}

  /**
   * The entity type this shipper adopts (its machine_name is the file key).
   */
  abstract protected function entityTypeId(): string;

  /**
   * The slice of a definition the entity's source hash covers.
   *
   * Exactly what the importer passes to setShippedSource() on sync (styles
   * for variants, {tokens, defaults} for skins, the content-addressed
   * family and faces for fonts, read from the module's assets), so the
   * hash a ship stamps equals the hash the next sync computes and the
   * sync reads UNCHANGED. Called after the file (and any assets) were
   * written, with the target module for a kind whose payload lives partly
   * beside the file.
   *
   * @param array $definition
   *   The definition as written.
   * @param string $module
   *   The target module.
   * @param string $name
   *   The entry's machine name.
   */
  abstract protected function shippedData(array $definition, string $module, string $name): array;

  /**
   * Whether a per-id file's entry differs from the definition that replaces it.
   *
   * The plan's UNCHANGED-or-UPDATED question, over whole definitions by
   * default (the file holds exactly what the shipper would write). A
   * kind whose entry is not the whole story (a font's faces name files
   * whose bytes live beside the file) widens it.
   *
   * @param array $existing
   *   The entry as the file holds it.
   * @param array $definition
   *   The definition the ship would write.
   * @param string $module
   *   The target module.
   * @param \Drupal\daedalus_stylish\ShippedSourceInterface $entity
   *   The site entity the definition was built from.
   */
  protected function definitionChanged(array $existing, array $definition, string $module, ShippedSourceInterface $entity): bool {
    return $this->hash($existing) !== $this->hash($definition);
  }

  /**
   * Hashes a data map the way the kind's entity does.
   *
   * Used for both comparisons: the plan's "did the file entry change"
   * (over whole definitions) and the adoption's "is the stamp current"
   * (over shippedData()).
   */
  abstract protected function hash(array $data): string;

  /**
   * Builds the kind's shipped-definition exception, the importer's twin.
   *
   * @param string $module
   *   The target module.
   * @param string $name
   *   The machine name the exception is about.
   * @param \Drupal\daedalus_blueprint\Result\BlueprintError[] $errors
   *   The structured errors.
   */
  abstract protected function shippedException(string $module, string $name, array $errors): VariantValidationException;

  /**
   * The other direction of the lane: the importer this shipper mirrors.
   *
   * Kind-agnostic callers (the ship orchestrator) read the kind's noun,
   * its file names, and its per-id files through it.
   */
  public function importer(): ShippedDefinitionsImporter {
    return $this->importer;
  }

  /**
   * Builds an entity's shipped definition through the kind's definition().
   *
   * Each kind declares definition() on its own entity interface (a base
   * declaration would force the widened type on every subclass, since PHP
   * forbids narrowing a parameter), so kind-agnostic callers reach it
   * here with an entity this shipper's load() answered.
   */
  public function definitionOf(ShippedSourceInterface $entity): array {
    return $this->definition($entity);
  }

  /**
   * The per-id file a ship writes, relative to the Drupal root.
   *
   * MODULE.daedalus_stylish.<kind>.FILE_ID.yml at the module's root, the name built
   * through the importer (the grammar's one owner).
   *
   * @throws \Drupal\Core\Extension\Exception\UnknownExtensionException
   *   When the module is not enabled: there is no directory to ship into.
   */
  public function filePath(string $module, string $file_id): string {
    return $this->moduleHandler->getModule($module)->getPath() . "/$module." . $this->importer->perFileSuffix($file_id);
  }

  /**
   * Plans a ship without touching disk or entities: the dry run's report.
   *
   * @param string $module
   *   The target module.
   * @param string $file_id
   *   The per-id file's id (MODULE.daedalus_stylish.<kind>.FILE_ID.yml).
   * @param array $definitions
   *   Definitions keyed by machine name, each in the importer's grammar
   *   (see the subclass's definition()).
   *
   * @return array
   *   An outcome per name, the definitions' names first (CREATED, UPDATED,
   *   UNCHANGED, or SHIPPED_ELSEWHERE), then every name the file currently
   *   holds that the ship leaves out (DROPPED).
   *
   * @throws \Drupal\daedalus_stylish\Exception\VariantValidationException
   *   The kind's shipped exception, when a definition is malformed (the
   *   importer's shape rule) or names no entity on this site.
   */
  public function plan(string $module, string $file_id, array $definitions): array {
    return $this->outsideWorkspace(fn (): array => $this->doPlan($module, $file_id, $definitions));
  }

  /**
   * Ships definitions: writes the per-id file and adopts the entities.
   *
   * The file is written whole (a machine-written file has one author), then
   * each entity is stamped with the module as provider and the written
   * payload as its source hash, and published. Nothing is created or
   * deleted: every shipped name is an existing entity, adopted in place.
   *
   * @return array
   *   The plan's outcomes (see plan()).
   *
   * @throws \Drupal\daedalus_stylish\Exception\VariantValidationException
   *   The kind's shipped exception, when any name is shipped elsewhere
   *   (SHIPPED_<KIND>_CONFLICT; nothing is written), plus plan()'s own
   *   failures.
   * @throws \RuntimeException
   *   When the file cannot be written.
   */
  public function ship(string $module, string $file_id, array $definitions): array {
    return $this->outsideWorkspace(function () use ($module, $file_id, $definitions): array {
      $plan = $this->doPlan($module, $file_id, $definitions);
      $conflicts = array_keys($plan, static::SHIPPED_ELSEWHERE, TRUE);
      if ($conflicts !== []) {
        throw $this->conflictException($module, $file_id, $conflicts);
      }

      $this->writeFile($module, $file_id, $definitions);

      foreach ($definitions as $name => $definition) {
        $entity = $this->load((string) $name);
        assert($entity instanceof ShippedSourceInterface);
        $data = $this->shippedData($definition, $module, (string) $name);
        if ($entity->getProvider() === $module && $entity->getSourceHash() === $this->hash($data) && $entity->isPublished()) {
          continue;
        }
        $entity->setShippedSource($module, $data);
        $entity->setPublished();
        $entity->save();
      }
      return $plan;
    });
  }

  /**
   * The exception ship() throws for names a plan reported SHIPPED_ELSEWHERE.
   *
   * Public so a dry run can report exactly what the ship would refuse: the
   * plan names the conflicts, this names their shippers (the other module,
   * or the target module's own single file) in one error per name.
   *
   * @param string $module
   *   The target module.
   * @param string $file_id
   *   The per-id file the ship would have written.
   * @param string[] $conflicts
   *   The machine names planned SHIPPED_ELSEWHERE.
   */
  public function conflictException(string $module, string $file_id, array $conflicts): VariantValidationException {
    $shipped = $this->outsideWorkspace(fn (): array => $this->shippedOutside($module, $file_id));
    $errors = [];
    foreach ($conflicts as $name) {
      $errors[] = new BlueprintError($name, $this->errorCode('CONFLICT'), sprintf('The machine name "%s" is already shipped by %s.', $name, $shipped[$name] ?? sprintf('the "%s" module', $this->load($name)?->getProvider())), [], sprintf('Machine names are the site-wide %s vocabulary; a name ships from exactly one file of one module. Leave it to its shipper, or rename the %s.', $this->importer->kind(), $this->importer->kind()));
    }
    return $this->shippedException($module, (string) reset($conflicts), $errors);
  }

  /**
   * The exception plan() throws for a name with no entity on this site.
   *
   * Public so a caller resolving names before it can build a definition
   * (the ship orchestrator's named adoption) refuses in the same words.
   */
  public function absentException(string $module, string $name): VariantValidationException {
    return $this->shippedException($module, $name, [
      new BlueprintError($name, $this->errorCode('ABSENT'), sprintf('No %s named "%s" exists on this site, so there is nothing to ship.', $this->importer->kind(), $name), [], sprintf('Shipping takes an existing %s to disk and adopts it in place; to bring a definition onto the site, put it in the file and sync.', $this->importer->kind())),
    ]);
  }

  /**
   * Plans within the workspace escape; see plan().
   */
  protected function doPlan(string $module, string $file_id, array $definitions): array {
    $path = $this->filePath($module, $file_id);
    $existing = $this->readFile($path);
    $shipped = $this->shippedOutside($module, $file_id);
    $plan = [];
    foreach ($definitions as $name => $definition) {
      $name = (string) $name;
      $this->importer->checkDefinitionShape($module, $name, $definition);
      $entity = $this->load($name);
      $provider = $entity?->getProvider();
      if (isset($shipped[$name]) || ($provider !== NULL && $provider !== $module)) {
        $plan[$name] = static::SHIPPED_ELSEWHERE;
        continue;
      }
      if ($entity === NULL) {
        throw $this->absentException($module, $name);
      }
      if (!array_key_exists($name, $existing)) {
        $plan[$name] = static::CREATED;
      }
      elseif (!$this->definitionChanged(is_array($existing[$name]) ? $existing[$name] : [], $definition, $module, $entity)) {
        $plan[$name] = static::UNCHANGED;
      }
      else {
        $plan[$name] = static::UPDATED;
      }
    }
    foreach (array_keys($existing) as $name) {
      if (!array_key_exists($name, $plan)) {
        $plan[(string) $name] = static::DROPPED;
      }
    }
    return $plan;
  }

  /**
   * Every machine name shipped on disk outside the target per-id file.
   *
   * Reads what each enabled module ships right now through the importer
   * (its per-module read, which also refuses a module shipping one name in
   * two files), then subtracts the target file's own entries: those are
   * the ship's to rewrite.
   *
   * @return array<string, string>
   *   Keyed by machine name; the value names the shipper for messages
   *   ('the "x" module' or, for the target module's other files, its
   *   single-file name).
   */
  protected function shippedOutside(string $module, string $file_id): array {
    $own = array_keys($this->readFile($this->filePath($module, $file_id)));
    $shipped = [];
    foreach (array_keys($this->moduleHandler->getModuleList()) as $provider) {
      foreach (array_keys($this->importer->getShippedDefinitions($provider)) as $name) {
        $name = (string) $name;
        if ($provider === $module) {
          if (!in_array($name, $own, TRUE)) {
            $shipped[$name] = sprintf('another file of the "%s" module (%s.%s)', $module, $module, $this->importer->fileSuffix());
          }
          continue;
        }
        $shipped[$name] = sprintf('the "%s" module', $provider);
      }
    }
    return $shipped;
  }

  /**
   * Reads a shipped file as authored, or [] when it does not exist.
   *
   * Public because the ship orchestrator rebuilds a per-id file from the
   * entries it holds (their unknown keys carried) and reads the single
   * file for what the site edited by hand.
   */
  public function readFile(string $path): array {
    if (!is_file($path)) {
      return [];
    }
    return Yaml::decode(file_get_contents($path)) ?? [];
  }

  /**
   * Writes the per-id file whole, or removes it when nothing ships.
   *
   * A header names the writer so a reader knows the file is regenerated on
   * the next ship and that hand-authored definitions belong in the single
   * file beside it.
   */
  protected function writeFile(string $module, string $file_id, array $definitions): void {
    $path = $this->filePath($module, $file_id);
    if ($definitions === []) {
      if (is_file($path) && !unlink($path)) {
        throw new \RuntimeException(sprintf('The shipped %s file %s could not be removed.', $this->importer->kind(), $path));
      }
      return;
    }
    $header = sprintf("# Machine-written by daedalus_stylish (%s): the \"%s\" package of the\n# \"%s\" module. Rewritten whole on the next ship; hand-authored %ss\n# belong in %s.%s beside this file.\n", (new \ReflectionClass($this))->getShortName(), $file_id, $module, $this->importer->kind(), $module, $this->importer->fileSuffix());
    if (file_put_contents($path, $header . Yaml::encode($definitions)) === FALSE) {
      throw new \RuntimeException(sprintf('The shipped %s file %s could not be written.', $this->importer->kind(), $path));
    }
  }

  /**
   * Loads an entity of the kind by machine name, uncached, or NULL.
   *
   * Public because the ship orchestrator rebuilds definitions from the
   * entities a per-id file names, through the same uncached read.
   */
  public function load(string $name): ?ShippedSourceInterface {
    $storage = $this->entityTypeManager->getStorage($this->entityTypeId());
    $storage->resetCache();
    $entities = $storage->loadByProperties(['machine_name' => $name]);
    $entity = reset($entities) ?: NULL;
    assert($entity === NULL || $entity instanceof ShippedSourceInterface);
    return $entity;
  }

  /**
   * The machine names of a design system's members nothing ships yet.
   *
   * The package ship's adoption set for this kind: every entity of the
   * kind whose membership is the system and whose provider is empty (the
   * site's own; a member another module ships is that module's, and one
   * the target module ships is already in a file). In machine-name
   * order, published or not: a ship publishes what it adopts, and the
   * package is the developer's whole system.
   *
   * @param string $design_system
   *   The design system id.
   *
   * @return string[]
   *   The machine names.
   */
  public function unshippedMembers(string $design_system): array {
    return $this->outsideWorkspace(function () use ($design_system): array {
      $storage = $this->entityTypeManager->getStorage($this->entityTypeId());
      $storage->resetCache();
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('design_system', $design_system)
        ->notExists('provider')
        ->sort('machine_name')
        ->execute();
      $names = [];
      foreach ($storage->loadMultiple($ids) as $entity) {
        if ($entity instanceof ShippedSourceInterface && $entity instanceof FieldableEntityInterface && $this->shipsWithPackage($entity)) {
          $names[] = (string) $entity->get('machine_name')->value;
        }
      }
      return $names;
    });
  }

  /**
   * Whether an unshipped member of the kind rides the package ship.
   *
   * TRUE for every kind but the one with a reason to hold back (a
   * variant a content blueprint outside the system owns rides that
   * blueprint's ship, VariantShipper).
   */
  protected function shipsWithPackage(ShippedSourceInterface $entity): bool {
    return TRUE;
  }

  /**
   * Runs a callable outside any active workspace (the importer's rule).
   */
  protected function outsideWorkspace(callable $run): array {
    return $this->workspaceManager !== NULL
      ? $this->workspaceManager->executeOutsideWorkspace($run)
      : $run();
  }

  /**
   * A structured error code in the importer's spelling: SHIPPED_<KIND>_X.
   *
   * The importers spell the same collision SHIPPED_VARIANT_CONFLICT and
   * SHIPPED_SKIN_CONFLICT; the shipper's codes follow the kind the same
   * way so a caller matching on codes sees one vocabulary per kind.
   */
  protected function errorCode(string $suffix): string {
    return 'SHIPPED_' . strtoupper($this->importer->kind()) . '_' . $suffix;
  }

}
