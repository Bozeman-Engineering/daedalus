<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Serialization\Exception\InvalidDataTypeException;
use Drupal\Core\DefaultContent\AdminAccountSwitcher;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Component\Serialization\Yaml;
use Drupal\daedalus_blueprint\BlueprintDeserializerInterface;
use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\DesignSystem\DesignSystemMemberInterface;
use Drupal\daedalus_blueprint\DesignSystem\UnknownDesignSystemException;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_stylish\Exception\VariantValidationException;
use Drupal\workspaces\WorkspaceManagerInterface;

/**
 * The disk lane's shared machinery: shipped YAML synced by hash.
 *
 * Modules ship daedalus_stylish content as YAML files at their root, keyed by
 * machine name; an importer syncs each entry with "one hash, two
 * comparisons" semantics (see ShippedSourceTrait). Variants established the
 * lane (VariantImporter) and skins reuse it (SkinImporter); this base owns
 * what is identical between them (the file discovery, the sync loop, the
 * workspace escape, and the administrative account switch) while each
 * importer owns its file grammar and entity semantics in syncDefinition().
 *
 * A module's definitions come from two file forms merged: the hand-authored
 * MODULE.{suffix} (e.g. x.daedalus_stylish.variants.yml) plus every per-id file
 * MODULE.{suffix minus .yml}.ID.yml beside it (x.daedalus_stylish.variants.card.yml).
 * The per-id form is the machine-written lane (a stencil shipped with its
 * variants lands as one such file) so writers never touch the comment-heavy
 * single file; a machine name shipped in two of one module's files is a
 * developer error (FAILED on the sync report under the file's name, an
 * exception for the loud reader). One grammar for every importer: skins,
 * swatches, and animations gain the per-id form by construction.
 *
 * Activation belongs to ShippedSync, which runs the four importers in
 * dependency order (animations, swatches, skins, variants) on every cache
 * rebuild (hook_rebuild(), so `drush cr` brings a pulled package live), on
 * module install (hook_modules_installed()), and by hand through
 * `drush daedalus_stylish:sync [MODULE]`. Each importer's sync() stays public
 * because the orchestrator composes it; nothing else should call it to
 * activate a site (one kind alone fails on references to the others).
 *
 * Membership is the spine's one disk-lane rule for every member kind
 * (ActiveDesignSystem::shippedMembership(), spoken here through
 * designSystemFor()): a definition's own `design_system` key
 * wins (`design_system: ~` opts out explicitly, a global material), and an
 * absent key means the module's shipped design system, the one whose
 * package the module is (ActiveDesignSystem::packagedSystemOf(), landed
 * or still on disk as install config); a module that packages no system
 * ships global materials. A member validates in its
 * own system's universe (the field handlers pass the membership to
 * StyleMapValidator) but under the SITE's law, and a package's law (the
 * guide stratum its files declare) layers only while its system is
 * active. So a package syncs only while its system is the active one
 * (ActiveDesignSystem::packagedSystemOf(), which knows a package from
 * the moment its module is installed, before its system's config lands);
 * inactive, it is deferred whole, and the activation lands it under its
 * own law in one run.
 */
abstract class ShippedDefinitionsImporter {

  /**
   * Sync outcomes, per shipped entry.
   *
   * The first four are what syncDefinition() answers; FAILED is the report's
   * word for an entry (or a whole file) the sync could not take, carried
   * with its structured errors on the SyncReport.
   */
  public const CREATED = 'created';
  public const UPDATED = 'updated';
  public const UNCHANGED = 'unchanged';
  public const KEPT = 'kept';
  public const FAILED = 'failed';

  /**
   * Constructs the importer.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler: the shipping modules and their paths.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, for the imported entity type's storage.
   * @param \Drupal\daedalus_blueprint\BlueprintDeserializerInterface $deserializer
   *   The blueprint deserializer that lands each definition on the spine.
   * @param \Drupal\Core\DefaultContent\AdminAccountSwitcher $accountSwitcher
   *   Core's default-content admin switcher. Shipped defaults are code, not
   *   user input: importing them under whichever user happens to trigger the
   *   install (drush is anonymous) would fail the blueprint access checks,
   *   so the import runs as an administrative account, the same precedent
   *   core's DefaultContent Importer sets for shipped content.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service, for the shipped-source hash stamp.
   * @param \Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem $activeDesignSystem
   *   The design-system unit: the module default for a member's
   *   membership (which system, if any, the shipping module packages).
   * @param \Drupal\workspaces\WorkspaceManagerInterface|null $workspaceManager
   *   Optional: without Workspaces there is no active-workspace context to
   *   escape. With it, the whole sync executes outside any active workspace
   *   so shipped defaults land LIVE, never staged invisibly.
   */
  public function __construct(
    protected ModuleHandlerInterface $moduleHandler,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected BlueprintDeserializerInterface $deserializer,
    protected AdminAccountSwitcher $accountSwitcher,
    protected TimeInterface $time,
    protected ActiveDesignSystem $activeDesignSystem,
    protected ?WorkspaceManagerInterface $workspaceManager = NULL,
  ) {}

  /**
   * The kind's noun: 'variant', 'skin', 'swatch', or 'animation'.
   *
   * The word every report, log line, and command line names a definition
   * by (`MODULE: KIND NAME outcome`), and the key ShippedSync files the
   * kind's report under. Singular, because it labels one definition.
   */
  abstract public function kind(): string;

  /**
   * The shipped file's suffix, e.g. 'daedalus_stylish.variants.yml'.
   *
   * A module's file is MODULE.{suffix} at the module root, next to its
   * style guide YAML. Public because the sync command base names the file
   * in its messages; the importer stays the one owner of the string.
   */
  abstract public function fileSuffix(): string;

  /**
   * The per-id shipped file's suffix, e.g. 'daedalus_stylish.variants.card.yml'.
   *
   * A module's per-id files are MODULE.{perFileSuffix(ID)} beside its
   * single file; '*' names the whole family (discovery's glob, the sync
   * command's messages). Writers of the machine lane build their file name
   * here so the grammar has one owner.
   */
  public function perFileSuffix(string $id): string {
    return substr($this->fileSuffix(), 0, -strlen('.yml')) . ".$id.yml";
  }

  /**
   * A module's per-id shipped files, keyed by file id, in name order.
   *
   * The machine-written half of a module's package: every
   * MODULE.{perFileSuffix(ID)} beside the single file, as discovery reads
   * them and as the ship lane rewrites them. The one place the family is
   * globbed, so the id a file carries is read back by the grammar's owner.
   *
   * @return array<string, string>
   *   Absolute paths keyed by file id; empty when the module is not enabled
   *   or ships no per-id file.
   */
  public function perIdFiles(string $module): array {
    $extensions = $this->moduleHandler->getModuleList();
    if (!isset($extensions[$module])) {
      return [];
    }
    $path = $extensions[$module]->getPath();
    $prefix = "$module." . substr($this->fileSuffix(), 0, -strlen('.yml')) . '.';
    $files = [];
    foreach (glob("$path/$module.{$this->perFileSuffix('*')}") ?: [] as $file) {
      $files[substr(basename($file), strlen($prefix), -strlen('.yml'))] = $file;
    }
    return $files;
  }

  /**
   * The importer's exception for a shipped definition that cannot import.
   *
   * The base discovers files and detects duplicate machine names across
   * them, but every failure must speak the importer's own exception family
   * (ShippedVariantException, ShippedSkinException, ...) so callers catch
   * one type per lane whether the fault is in the file set or the entry.
   *
   * @param string $module
   *   The module shipping the failing definition.
   * @param string $name
   *   The failing entry's machine name.
   * @param \Drupal\daedalus_blueprint\Result\BlueprintError[] $errors
   *   The structured errors.
   */
  abstract protected function shippedException(string $module, string $name, array $errors): VariantValidationException;

  /**
   * Enforces the kind's file grammar: the keys and their PHP types.
   *
   * Public because the grammar has one owner: the ship lane
   * (ShippedDefinitionsShipper) checks every definition here before
   * writing it, so disk never holds what the importer would refuse to read
   * back.
   *
   * @throws \Drupal\daedalus_stylish\Exception\VariantValidationException
   *   The kind's shipped exception, naming the malformed entry.
   */
  abstract public function checkDefinitionShape(string $module, string $name, mixed $definition): void;

  /**
   * Syncs one shipped definition; the importer's own grammar and semantics.
   *
   * @return string
   *   One of the CREATED/UPDATED/UNCHANGED/KEPT constants.
   */
  abstract protected function syncDefinition(string $module, string $name, mixed $definition, int $uid): string;

  /**
   * The design system a shipped definition is a member of, or NULL.
   *
   * The spine's one membership rule (ActiveDesignSystem::shippedMembership(),
   * see the class doc) in this lane's error grammar: an authored id must
   * name a design system that is landed on this site or shipped as install
   * config by an installed module (a package's files are read in the
   * window before its own config lands, so a pending system is a valid
   * home; an id nothing lands and nothing ships is a developer error in
   * the shipping module, refused loudly); the shape of the key was checked
   * by checkDesignSystemShape() before this is asked. A package's own
   * members never reach this in the window: sync() defers the package
   * whole while its system is not active.
   *
   * @throws \Drupal\daedalus_stylish\Exception\VariantValidationException
   *   The importer's concrete exception (SHIPPED_DESIGN_SYSTEM_UNKNOWN).
   */
  protected function designSystemFor(string $module, string $name, array $definition): ?string {
    try {
      return $this->activeDesignSystem->shippedMembership($module, $definition);
    }
    catch (UnknownDesignSystemException $exception) {
      throw $this->shippedException($module, $name, [
        new BlueprintError($name, 'SHIPPED_DESIGN_SYSTEM_UNKNOWN', sprintf('The "%s" entry names the design system "%s", which is neither landed on this site nor shipped by an installed module.', $name, $exception->designSystem), ['design_systems' => $exception->known], $exception->known === [] ? 'The site has no design systems, landed or shipped; ship the design system as install config (daedalus_blueprint.design_system.ID.yml) beside the definition, or drop the key.' : sprintf('The design systems landed or shipped here: %s. Name one of these, ship the missing one as install config beside the definition, or drop the key (the module\'s own shipped design system applies) or set it to ~ (global).', implode(', ', $exception->known))),
      ]);
    }
  }

  /**
   * Enforces the `design_system` key's shape: absent, null, or an id.
   *
   * The importers' checkDefinitionShape() call this for the key they
   * share; the code is the importer's own (INVALID_SHIPPED_VARIANT, ...)
   * so a shape error reads like every other shape error of that file.
   *
   * @throws \Drupal\daedalus_stylish\Exception\VariantValidationException
   */
  protected function checkDesignSystemShape(string $module, string $name, array $definition, string $code): void {
    if (!array_key_exists('design_system', $definition)) {
      return;
    }
    $design_system = $definition['design_system'];
    if ($design_system !== NULL && (!is_string($design_system) || trim($design_system) === '')) {
      throw $this->shippedException($module, $name, [
        new BlueprintError($name, $code, sprintf('The "%s" entry has the wrong shape.', $name), [], 'design_system, when given, is a design system id, or ~ (null) for a global material offered under every design system; omit it to join the design system the shipping module packages.'),
      ]);
    }
  }

  /**
   * Syncs a definition's membership onto an existing entity.
   *
   * Part of the shipped face, synced independently of the payload hash
   * (a design_system key added later, or the module gaining a shipped
   * design system, must reach sites without a payload change): disk is
   * the author of a shipped material's membership. The caller decides
   * when the assignment saves.
   *
   * @return bool
   *   Whether the membership changed.
   */
  protected function syncMembership(DesignSystemMemberInterface $entity, ?string $design_system): bool {
    if ($entity->getDesignSystem() === $design_system) {
      return FALSE;
    }
    $entity->setDesignSystem($design_system);
    return TRUE;
  }

  /**
   * Enforces the `ai` key's shape: absent, or false.
   *
   * Offered is the default every material has, so a file says `ai: false`
   * only to opt a material out (design-system materials never ship the
   * key); the grammar refuses anything else rather than letting a
   * redundant `ai: true` grow a second spelling of the default.
   */
  protected function checkOfferedShape(string $module, string $name, array $definition, string $code): void {
    if (array_key_exists('ai', $definition) && $definition['ai'] !== FALSE) {
      throw $this->shippedException($module, $name, [
        new BlueprintError($name, $code, sprintf('The "%s" entry has the wrong shape.', $name), [], 'ai, when given, is false: the material is kept out of the AI\'s roster (it still applies everywhere); omit the key to offer it, the default.'),
      ]);
    }
  }

  /**
   * Syncs the AI opt-out from disk: `ai: false` withholds, absent offers.
   *
   * Disk is the one author of a shipped material's face (the distro lock
   * refuses the flag's UI edit on shipped materials), so unlike the
   * description an absent key is not "leave it alone" but the default:
   * dropping `ai: false` from the file re-offers the material on the next
   * sync. Independent of the payload hash, like the description.
   *
   * @return bool
   *   Whether the flag changed.
   */
  protected function syncOffered(DaedalusStylishVariantInterface|DaedalusStylishSwatchInterface|DaedalusStylishAnimationInterface $entity, array $definition): bool {
    $offered = ($definition['ai'] ?? TRUE) !== FALSE;
    if ($entity->isOffered() === $offered) {
      return FALSE;
    }
    $entity->setOffered($offered);
    return TRUE;
  }

  /**
   * Reads a module's shipped definitions from disk, loudly.
   *
   * The reader for callers that need the whole set or nothing (the revert
   * source, the shipper's roster): the first file-level failure throws.
   * The sync loop reads through readShippedFiles() instead and records
   * such failures on its report.
   *
   * @return array
   *   Definitions keyed by machine name, exactly as authored. Empty when the
   *   module is not enabled or ships no file.
   *
   * @throws \Drupal\daedalus_stylish\Exception\VariantValidationException
   *   The importer's concrete exception when a file is malformed or one
   *   machine name appears in two of the module's files; see
   *   readShippedFiles() for the errors.
   */
  public function getShippedDefinitions(string $module): array {
    [$definitions, $failures] = $this->readShippedFiles($module);
    foreach ($failures as $errors) {
      throw $this->shippedException($module, $errors[0]->getPath(), $errors);
    }
    return $definitions;
  }

  /**
   * Reads a module's shipped files, collecting file-level failures.
   *
   * The single file MODULE.{suffix} first, then every per-id file
   * MODULE.{suffix minus .yml}.ID.yml in name order, merged into one map.
   * A file that cannot be parsed (SHIPPED_FILE_MALFORMED) contributes
   * nothing; a machine name shipped in two of the module's files
   * (SHIPPED_DEFINITION_DUPLICATE) lands from neither, because which copy
   * wins is not a question a shipped file gets to leave open. Both are
   * developer errors in the shipping module, reported under the file's
   * base name, and neither stops the module's other definitions.
   *
   * @return array{0: array, 1: array<string, \Drupal\daedalus_blueprint\Result\BlueprintError[]>}
   *   The definitions keyed by machine name, exactly as authored (empty when
   *   the module is not enabled or ships no file), and the failures keyed
   *   by the failing file's base name.
   */
  protected function readShippedFiles(string $module): array {
    $extensions = $this->moduleHandler->getModuleList();
    if (!isset($extensions[$module])) {
      return [[], []];
    }
    $path = $extensions[$module]->getPath();
    $files = array_values($this->perIdFiles($module));
    array_unshift($files, "$path/$module.{$this->fileSuffix()}");
    $definitions = [];
    $sources = [];
    $failures = [];
    foreach ($files as $file) {
      if (!is_file($file)) {
        continue;
      }
      $basename = basename($file);
      try {
        $decoded = Yaml::decode(file_get_contents($file)) ?? [];
      }
      catch (InvalidDataTypeException $e) {
        // Meaningful recovery: the file is reported under its own name and
        // the module's other files still land.
        $failures[$basename][] = new BlueprintError($basename, 'SHIPPED_FILE_MALFORMED', sprintf('The "%s" module\'s %s cannot be parsed: %s', $module, $basename, $e->getMessage()), [], 'Fix the YAML; nothing in this file syncs until it parses.');
        continue;
      }
      if (!is_array($decoded)) {
        $failures[$basename][] = new BlueprintError($basename, 'SHIPPED_FILE_MALFORMED', sprintf('The "%s" module\'s %s must be a map of definitions keyed by machine name, %s given.', $module, $basename, get_debug_type($decoded)), [], 'Author the file as `machine_name:` entries at the top level.');
        continue;
      }
      foreach ($decoded as $name => $definition) {
        $name = (string) $name;
        if (isset($sources[$name])) {
          $failures[$basename][] = new BlueprintError($name, 'SHIPPED_DEFINITION_DUPLICATE', sprintf('The machine name "%s" is shipped twice by the "%s" module: in %s and %s.', $name, $module, basename($sources[$name]), $basename), [], sprintf('A module ships each machine name in exactly one of its %s.%s or %s.%s files.', $module, $this->fileSuffix(), $module, $this->perFileSuffix('ID')));
          unset($definitions[$name]);
          continue;
        }
        $sources[$name] = $file;
        $definitions[$name] = $definition;
      }
    }
    return [$definitions, $failures];
  }

  /**
   * Imports new and syncs changed shipped definitions.
   *
   * Tolerant and complete: every definition of every module in the set is
   * attempted, and a definition that cannot import (malformed, failing
   * spine validation, colliding with an entity its module does not ship)
   * is recorded FAILED with its errors while the rest of the file lands.
   * The one module the run does not read is a design-system package whose
   * system is not the active one: it is DEFERRED on the report (its
   * definitions validate only under the law its own system layers) and
   * lands at the system's activation.
   * Nothing the disk lane can refuse throws out of here; callers read the
   * report, and the sync command, the update hooks and the rebuild log
   * each surface its failures in their own medium. ShippedSync runs the
   * four kinds in dependency order; call one kind's sync() directly only
   * when the others cannot matter.
   *
   * @param string[]|null $modules
   *   Modules to sync, or NULL for every enabled module. Modules shipping no
   *   file are simply not providers, not an error.
   *
   * @return \Drupal\daedalus_stylish\SyncReport
   *   The outcome per module and machine name, plus the errors behind
   *   every failure. Empty when no module in the set ships a file.
   */
  public function sync(?array $modules = NULL): SyncReport {
    $report = new SyncReport($this->kind());
    $queue = [];
    $active = $this->activeDesignSystem->activeId();
    foreach ($modules ?? array_keys($this->moduleHandler->getModuleList()) as $module) {
      // A package's definitions sync only under its own law: the guide's
      // design-system stratum (axes, scales, bindings) layers only while
      // the system is active, and its skin mints the tokens its variants
      // name, so a member read while the system is inactive fails on its
      // own package. Deferred whole; the activation lands it.
      $system = $this->activeDesignSystem->packagedSystemOf($module);
      if ($system !== NULL && $system !== $active) {
        $report->defer($module, $system);
        continue;
      }
      [$definitions, $failures] = $this->readShippedFiles($module);
      foreach ($failures as $file => $errors) {
        $report->fail($module, $file, $errors);
      }
      if ($definitions !== []) {
        $queue[$module] = $definitions;
      }
    }
    if ($queue === []) {
      return $report;
    }
    // Shipped defaults land LIVE: escape any active workspace for the whole
    // run so both the duplicate lookups and the saves see (and write) the
    // live default revisions.
    $run = function () use ($queue, $report): void {
      $this->doSync($queue, $report);
    };
    $this->workspaceManager !== NULL
      ? $this->workspaceManager->executeOutsideWorkspace($run)
      : $run();
    return $report;
  }

  /**
   * Runs the sync as an administrative account, recording every outcome.
   *
   * Only the importer's own exception family is an outcome: it carries the
   * structured errors the report speaks. Anything else (a storage fault, a
   * broken style guide build) is not a definition's failure and propagates.
   */
  protected function doSync(array $queue, SyncReport $report): void {
    $account = $this->accountSwitcher->switchToAdministrator();
    try {
      foreach ($queue as $module => $definitions) {
        foreach ($definitions as $name => $definition) {
          $name = (string) $name;
          try {
            $report->record($module, $name, $this->syncDefinition($module, $name, $definition, (int) $account->id()));
          }
          catch (VariantValidationException $e) {
            // Meaningful recovery: the entry's errors ride the report and
            // the next definition in the same file still lands.
            $report->fail($module, $name, $e->getErrors());
          }
        }
      }
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
  }

}
