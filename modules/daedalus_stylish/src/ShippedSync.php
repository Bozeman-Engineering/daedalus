<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Entity\EntityDefinitionUpdateManagerInterface;
use Drupal\Core\Installer\InstallerKernel;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;

/**
 * The one activation lane for module-shipped daedalus_stylish definitions.
 *
 * Modules ship animations, swatches, skins, and variants as YAML beside
 * their code (see ShippedDefinitionsImporter); this orchestrator runs the
 * four importers over a module set in dependency order and answers with
 * one report per kind. The order is the reference graph: a variant may
 * name an animation (motion-*), a swatch (--swatch-*), or a token the
 * active skin mints (var(--space-4)), and a strict site refuses a
 * reference to a material that is not published yet. Animations, then
 * swatches, then skins, then variants lets one module ship a
 * self-referencing package that lands clean in a single run; in any other
 * order the variants fail on their own module's materials (one site's did).
 *
 * Four entries, all thin: hook_rebuild() (every enabled module, at the
 * end of drupal_flush_all_caches(), so `drush cr`, the UI's clear-all,
 * `drush updb`, and site install all activate shipped definitions),
 * hook_modules_installed() (the batch, or everything when daedalus_stylish itself
 * installs, because a provider may have been enabled before the system
 * that can host its files existed), a design system being activated
 * (every enabled module, because the site's law is the active system's
 * and an activation is when a definition the previous law refused can
 * land: the incoming package, which the importers defer whole while its
 * system is inactive, and everything a site install's batch left
 * waiting), and the `daedalus_stylish:sync` drush command. Rebuild, install and
 * the activation log their outcomes; the command prints them. A deferred
 * package is silent in the log (a converged state, like unchanged) and
 * named by the command.
 *
 * The install entry waits while the site itself is being installed
 * (InstallerKernel::installationAttempted()) and has no design system
 * yet: the installer enables the modules before the recipe's config and
 * actions land, so the batch runs
 * on a site with no design system and no skin, where a global naming a
 * bound token (daedalus_stylish_layouts' Columns variant, gap var(--space-gutter))
 * is refused with nothing minted, and the recipe's activation action
 * lands it moments later. A refusal the same install resolves is not a
 * failure `drush si` should report, so the batch syncs nothing, silently
 * (a recipe installs its ingredients in many batches and the entry fires
 * for each; a line per batch is a wall of them, and nothing in it is
 * actionable mid-install), and the activation lands every enabled
 * module's definitions whole. Once the activation has given the site its
 * law, a later ingredient's batch (the recipe installs its block types
 * after the styling ingredient, each shipping variants scoped to its own
 * type) syncs at install like a batch outside the installer: nothing the
 * activation could resolve is pending any more, and the installer ends
 * with no cache rebuild, so a batch that kept waiting would land only at
 * the site's first `drush cr`. An install that activates no system (a
 * recipe or profile listing daedalus_stylish alone) lands its globals at the
 * site's first cache rebuild, whose log carries whatever that sync
 * refuses. Outside the installer (`drush en`,
 * a recipe applied to an existing site) the batch syncs as before: the
 * site has its law already, and a recipe's activation on such a site
 * still re-syncs what its batch refused.
 *
 * Activating on cache rebuild is a deliberate departure from core, which
 * writes content on install and in update hooks only. A module's stencil
 * file already goes live on rebuild through discovery, so a package pulled
 * to a site whose variants file waited for a command nobody runs was
 * half-active: the stencil placed, its variants did not exist. One package,
 * one activation rule. The costs are accepted: a rebuild is no longer
 * side-effect free (shipped content revisions can appear under an ops
 * command), validation failures surface in the log rather than a command's
 * output, and every rebuild pays a YAML parse plus a hash compare per
 * shipping module (an unchanged definition is a no-op). Nothing is
 * clobbered: a site-edited definition is KEPT by every importer.
 */
final class ShippedSync {

  public function __construct(
    protected AnimationImporter $animations,
    protected SwatchImporter $swatches,
    protected SkinImporter $skins,
    protected VariantImporter $variants,
    protected ActiveDesignSystem $activeDesignSystem,
    protected EntityDefinitionUpdateManagerInterface $updateManager,
    protected LoggerChannelInterface $logger,
  ) {}

  /**
   * The importers in dependency order, keyed by kind.
   *
   * @return array<string, \Drupal\daedalus_stylish\ShippedDefinitionsImporter>
   *   Animations, swatches, skins, variants: each kind after every kind it
   *   may reference.
   */
  public function importers(): array {
    $importers = [];
    foreach ([$this->animations, $this->swatches, $this->skins, $this->variants] as $importer) {
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
   *   The four kinds' reports, in sync order.
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
   * During a site install a batch that arrives before the site has a
   * design system waits silently instead (see the class comment): the
   * recipe's activation, or failing that the site's first cache rebuild,
   * syncs it. A batch after the activation syncs at install: the site
   * has its law, and the installer ends with no rebuild to catch up on.
   * Outcomes are logged.
   *
   * @param string[] $modules
   *   The installed batch.
   */
  public function onInstall(array $modules): void {
    if (InstallerKernel::installationAttempted() && $this->activeDesignSystem->load() === NULL) {
      return;
    }
    $this->log($this->sync(in_array('daedalus_stylish', $modules, TRUE) ? NULL : $modules));
  }

  /**
   * Reacts to a cache rebuild (hook_rebuild()): every enabled module syncs.
   *
   * Guarded: when entity definition updates are pending (new code pulled
   * whose base fields or entity types are not installed yet), saving
   * entities against the stale schema would break the rebuild itself, so
   * the sync is skipped with one error line. `drush updb` installs the
   * updates and ends in a cache rebuild, which then syncs. Outcomes are
   * logged.
   *
   * @return bool
   *   TRUE when the sync ran, FALSE when the guard skipped it; the
   *   caller's other rebuild reactions (the defaults stylesheet compile)
   *   ride the same guard, since they read the same storages.
   */
  public function onRebuild(): bool {
    if ($this->updateManager->needsUpdates()) {
      $this->logger->error('Shipped daedalus_stylish definitions were not synced: entity definition updates are pending. Run `drush updb`; the cache rebuild at its end syncs them.');
      return FALSE;
    }
    $this->log($this->sync());
    return TRUE;
  }

  /**
   * Reacts to a design system becoming the active one: every module syncs.
   *
   * A member validates against its own system's tokens and materials,
   * but against the SITE's law, and the site's law is the active
   * system's: its enforcement, the guide's design-system stratum (axes,
   * scales, bindings) its package layers through daedalus_ui, and
   * the tokens its default skin mints. An activation changes all of it
   * at once, so it is the moment a definition the previous law refused
   * can land, and every enabled module syncs. Two cases meet here. The
   * incoming package: the importers defer a package whole while its
   * system is inactive (install, the system's config landing, every
   * rebuild in between: nothing of it is read), so the activation is
   * what lands it, under its own law, in the activation itself. The
   * globals: a fresh install's batch waits for the installer
   * (onInstall()), so nothing has landed when the recipe's activation
   * action gives the site its system, and the sweep here lands every
   * enabled module's definitions, a fresh site whole without a cache
   * rebuild; on an existing site a recipe's batch syncs under the law
   * the site had, and the sweep lands what that law refused (a global
   * naming a token on a scale the suite binds, with nothing minted).
   * A converged definition is unchanged and silent, so a later
   * activation of an already landed site logs nothing. The caller has
   * reset the declarations before this runs
   * (DesignSystemSwitchSubscriber). A site-built system packages nothing
   * of its own, and the globals sync under its law like any other
   * activation; the outgoing system's members are not touched (their
   * entities stay, the roster simply stops offering them). Outcomes are
   * logged.
   */
  public function onDesignSystemActivated(): void {
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
