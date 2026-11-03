<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Entity\EntityDefinitionUpdateManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * The one activation lane for module-shipped daedalus_stylish definitions.
 *
 * Modules ship skins (with their tokens, animations, fonts and defaults)
 * and variants as YAML beside their code (see ShippedDefinitionsImporter);
 * this orchestrator runs the two importers over a module set in
 * dependency order and answers with one report per kind. The order is
 * the reference graph: a variant may name an animation (motion-*) or a
 * token the active skin mints (var(--space-4), var(--color-accent)), and
 * the roster's verdict (VariantConformance) withholds a variant naming a
 * material that is not published yet. Skins, then variants lets one
 * module ship a self-referencing package whose variants are offered the
 * moment the run ends; in any other order the variants land withheld on
 * their own module's materials until the next verdict.
 *
 * Five entries, all thin: hook_rebuild() (every enabled module, at the
 * end of drupal_flush_all_caches(), so `drush cr`, the UI's clear-all
 * and `drush updb` all activate shipped definitions; core's installer no
 * longer ends in a rebuild), hook_modules_installed() (the batch, or
 * everything when daedalus_stylish itself installs, because a provider
 * may have been enabled before the system that can host its files
 * existed), a design system becoming the active one, by its activation
 * or by its config landing under a pointer that already names it (every
 * enabled module, because the importers defer a package whole while its
 * system is not the active, landed one, and the system becoming active
 * is what lands it), the default theme landing, by system.theme's
 * default changing to an installed theme or by the theme the default
 * already names being installed (every enabled module, because the skins
 * kind waits while the named default theme is not installed: a skin's
 * defaults validate against the default theme's guide, which is the
 * site's only once the theme's chain layers; config import writes
 * system.theme late, so an install from exported config lands its
 * package's skin at that save), and the `daedalus_stylish:sync` drush
 * command. Rebuild, install, the activation and the theme landing log
 * their outcomes; the command prints them. A deferred package or kind is
 * silent in the log (a converged state, like unchanged) and named by the
 * command. The two law changes through config (the pointer, the default
 * theme) reach here through DesignSystemSwitchSubscriber, the one sweep
 * that resets the roster and the guide before the sync and recompiles
 * after it; the install hooks reset the guide before syncing for the
 * same reason (a sync runs under the guide of the moment).
 *
 * A definition fails the sync on structure alone (VariantImporter; a
 * shipped variant validates on the validator's landing lane): whether
 * the site's law of the day offers it is the roster's verdict, so a
 * global the current law refuses (daedalus_stylish_layouts' Columns
 * variant, gap var(--space-gutter), on a site with nothing minted) lands
 * withheld and is offered and delivered, with no re-sync, once a skin
 * mints the token or the law opens (every law change recompiles the
 * variants file; _daedalus_stylish_delivery_compile()). Hence the
 * install entry syncs every batch as it installs, inside the installer
 * or out: a fresh install's batches land their globals before the
 * recipe's activation gives the site its system (withheld until it
 * does), a batch after the activation lands under the site's law, and
 * the installer ends with no cache rebuild to wait for. Each batch logs
 * its outcomes (unchanged is silent).
 *
 * Activating on cache rebuild is a deliberate departure from core, which
 * writes content on install and in update hooks only. A module's blueprint
 * file already goes live on rebuild through discovery, so a package pulled
 * to a site whose variants file waited for a command nobody runs was
 * half-active: the blueprint placed, its variants did not exist. One package,
 * one activation rule. The costs are accepted: a rebuild is no longer
 * side-effect free (shipped content revisions can appear under an ops
 * command), validation failures surface in the log rather than a command's
 * output, and every rebuild pays a YAML parse plus a hash compare per
 * shipping module (an unchanged definition is a no-op). Nothing is
 * clobbered: a site-edited variant is KEPT by its
 * importer, and a skin's site edits live in its own site layer, which the
 * skins importer never writes (it has no KEPT: the package layer is
 * locked on a consuming site, so new defaults always land under the
 * site's layer).
 */
final class ShippedSync {

  public function __construct(
    protected SkinImporter $skins,
    protected VariantImporter $variants,
    protected EntityDefinitionUpdateManagerInterface $updateManager,
    protected LoggerChannelInterface $logger,
  ) {}

  /**
   * The importers in dependency order, keyed by kind.
   *
   * @return array<string, \Drupal\daedalus_stylish\ShippedDefinitionsImporter>
   *   Skins, variants: each kind after every kind it may reference (a
   *   variant's motion-{name} names a skin's animation and its var() a
   *   skin's token, so the skins land before the variants).
   */
  public function importers(): array {
    $importers = [];
    foreach ([$this->skins, $this->variants] as $importer) {
      $importers[$importer->kind()] = $importer;
    }
    return $importers;
  }

  /**
   * Syncs every kind a module set ships, in dependency order.
   *
   * Tolerant like each importer's sync: a definition that cannot land is
   * FAILED on its kind's report and the rest of the run proceeds, so one
   * broken variant never holds back a module's skin or another module's
   * files. Nothing the disk lane can refuse throws out of here.
   *
   * @param string[]|null $modules
   *   Modules to sync, or NULL for every enabled module. Modules shipping
   *   no file of a kind are simply not providers of it.
   *
   * @return \Drupal\daedalus_stylish\ShippedSyncReport
   *   The three kinds' reports, in sync order.
   */
  public function sync(?array $modules = NULL): ShippedSyncReport {
    $reports = [];
    foreach ($this->importers() as $kind => $importer) {
      $reports[$kind] = $importer->sync($modules);
    }
    return new ShippedSyncReport($reports);
  }

  /**
   * Reacts to modules being installed (hook_modules_installed()).
   *
   * When daedalus_stylish itself is in the batch, every enabled module syncs:
   * modules shipping definitions may have been enabled before daedalus_stylish
   * existed, and their defaults must arrive the moment the system that can
   * host them does. Otherwise only the just-installed modules sync.
   * A site install's batches sync as they install, the ones before the
   * recipe's activation included (see the class comment): a global the
   * site cannot offer yet lands withheld, nothing is refused for law, and
   * the installer ends with no rebuild to catch up on. Outcomes are
   * logged.
   *
   * @param string[] $modules
   *   The installed batch.
   */
  public function onInstall(array $modules): void {
    $this->log($this->sync(in_array('daedalus_stylish', $modules, TRUE) ? NULL : $modules));
  }

  /**
   * Reacts to a cache rebuild (hook_rebuild()): every enabled module syncs.
   *
   * Guarded: when entity definition updates are pending on a kind's own
   * entity type (new daedalus_stylish code pulled whose base fields are
   * not installed yet), saving entities against the stale schema would
   * break the rebuild itself, so the sync is skipped with one error line
   * naming the types. daedalus_stylish ships the update hooks that
   * install its own changes, so `drush updb` installs them and ends in a
   * cache rebuild, which then syncs. Pending updates on any other entity
   * type (a core or contrib type a site's stack altered without an update
   * hook) are not this lane's concern and never block it: the sync reads
   * and writes the kinds' storages alone. Outcomes are logged.
   *
   * @return bool
   *   TRUE when the sync ran, FALSE when the guard skipped it; the
   *   caller's other rebuild reactions (the defaults stylesheet compile)
   *   ride the same guard, since they read the same storages.
   */
  public function onRebuild(): bool {
    $pending = array_keys(array_intersect_key(
      $this->updateManager->getChangeList(),
      array_flip(array_map(static fn (ShippedDefinitionsImporter $importer): string => $importer->entityTypeId(), $this->importers())),
    ));
    if ($pending !== []) {
      $this->logger->error('Shipped daedalus_stylish definitions were not synced: entity definition updates are pending for @types. Run `drush updb` (daedalus_stylish ships the update hooks that install them); the cache rebuild at its end syncs them.', ['@types' => implode(', ', $pending)]);
      return FALSE;
    }
    $this->log($this->sync());
    return TRUE;
  }

  /**
   * Reacts to a design system becoming the active one: every module syncs.
   *
   * The sweep exists for the incoming package: the importers defer a
   * package whole while its system is inactive (install, the system's
   * config landing, every rebuild in between: nothing of it is read,
   * because its variants' axes and parts are the system's own stratum,
   * layered only while it is active), so the activation is what lands
   * it, under its own law, in the activation itself and with no cache
   * rebuild (a recipe's activation action lands a fresh site's package
   * this way; an install from exported config writes the pointer before
   * the system's entity imports, so there the entity's landing is the
   * activation and lands it). Nothing else needs the sweep: the globals
   * landed with their batches, and the ones the outgoing law could not offer were
   * never refused by the sync, only withheld by the roster's verdict,
   * which re-judges them under the incoming law on its own. Every enabled
   * module syncs because the package's provider is not known here; a
   * converged definition is unchanged and silent, so a later activation
   * of an already landed site logs nothing. The caller has reset the
   * declarations before this runs (DesignSystemSwitchSubscriber). A
   * site-built system packages nothing of its own; the outgoing system's
   * members are not touched (their entities stay, the roster simply
   * stops offering them). Outcomes are logged.
   */
  public function onDesignSystemActivated(): void {
    $this->log($this->sync());
  }

  /**
   * Reacts to the default theme landing: every module syncs.
   *
   * The sweep exists for the skins kind: SkinImporter defers every
   * skin-shipping module while system.theme names a default theme that is
   * not installed (its defaults validate against the default theme's
   * guide, whose chain layers only once the theme is installed), and the
   * theme landing is what lands them. Two saves land it: system.theme's
   * default changing to an installed theme (config import's order, where
   * the installer's stark stands in until the config phase imports
   * system.theme; a recipe's set-default action), and the install of the
   * theme the default already names (hook_themes_installed()). Every
   * enabled module syncs because the waiting providers are not known
   * here; a converged definition is unchanged and silent, so a default
   * theme change on a landed site logs nothing. The caller has reset the
   * guide before this runs. Outcomes are logged.
   */
  public function onDefaultThemeChanged(): void {
    $this->log($this->sync());
  }

  /**
   * Logs a run: one summary per module and kind, one error per failure.
   *
   * Unchanged definitions are silent (every rebuild syncs, and a converged
   * site must not fill the log); a module whose kind saw anything else
   * gets one info line counting the outcomes (created, updated, kept,
   * failed), and every failure one error line carrying its path, code,
   * and message.
   */
  protected function log(ShippedSyncReport $report): void {
    foreach ($report->reports() as $kind => $kind_report) {
      foreach ($kind_report->outcomes() as $module => $outcomes) {
        $counts = array_count_values($outcomes);
        $summary = [];
        foreach ([
          ShippedDefinitionsImporter::CREATED,
          ShippedDefinitionsImporter::UPDATED,
          ShippedDefinitionsImporter::KEPT,
          ShippedDefinitionsImporter::FAILED,
        ] as $outcome) {
          if (isset($counts[$outcome])) {
            $summary[] = "{$counts[$outcome]} $outcome";
          }
        }
        if ($summary === []) {
          continue;
        }
        $this->logger->info('@module: @kind sync: @summary', [
          '@module' => $module,
          '@kind' => $kind,
          '@summary' => implode(', ', $summary),
        ]);
      }
      foreach ($kind_report->failures() as $module => $failures) {
        foreach ($failures as $name => $errors) {
          $lines = [];
          foreach ($errors as $error) {
            $lines[] = sprintf('%s [%s]: %s', $error->getPath(), $error->getCode(), $error->getMessage());
          }
          $this->logger->error('@module: @kind @name failed: @errors', [
            '@module' => $module,
            '@kind' => $kind,
            '@name' => $name,
            '@errors' => implode('; ', $lines),
          ]);
        }
      }
    }
  }

}
