<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Drush\Commands;

use Drupal\daedalus_blueprint\Materials\MaterialsRosterBuilder;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_stylish\ShippedDefinitionsImporter;
use Drupal\daedalus_stylish\ShippedSync;
use Drupal\daedalus_stylish\StyleGuide;
use Drupal\daedalus_stylish\VariantConformance;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The CLI adapter of the shipped-definitions activation lane.
 *
 * Every enabled module's shipped skins (with their tokens, animations and fonts) and
 * variants already activate on a cache rebuild and on install (see ShippedSync);
 * this command runs the same orchestrated sync by hand, so an operator can
 * activate one module's package and read every outcome on the terminal
 * instead of in the log. One line per definition in sync order
 * (`MODULE: KIND NAME outcome`), one line per variant this run created
 * or updated that the roster's verdict withholds (a sync fails a
 * definition on structure alone, so a variant the site's current law
 * refuses lands and VariantConformance withholds it: the line names the
 * enforcement and the law's refusals, the way a site author would read
 * them at the save gate), one line per deferred package (a module
 * packaging a design system that is not the active one: its definitions
 * land at the activation, under the system's own law), one line per
 * module whose skins wait for the default theme (system.theme names a
 * theme that is not installed, the installer's window: a skin's defaults
 * validate against the default theme's guide, so they land when the
 * theme does), then every
 * failure with its path, code, message, and hint, and a non-zero exit
 * when anything failed; everything that could land already did. The
 * log stays silent about a withheld landing (a converged state, like
 * the deferred package); the terminal is where an operator reads why a
 * variant that just landed is not on offer.
 */
#[AsCommand(
  name: 'daedalus_stylish:sync',
  description: 'Activates module-shipped daedalus_stylish definitions (skins with their tokens, animations and fonts, then variants) and reports every outcome; the same sync a cache rebuild runs.',
)]
final class SyncCommand extends Command {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'daedalus_stylish.shipped_sync')]
    protected ShippedSync $sync,
    #[Autowire(service: 'daedalus_stylish.variant_conformance')]
    protected VariantConformance $conformance,
    #[Autowire(service: 'daedalus_stylish.style_guide')]
    protected StyleGuide $styleGuide,
    #[Autowire(service: 'daedalus_blueprint.materials_roster_builder')]
    protected MaterialsRosterBuilder $rosterBuilder,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this->addArgument('module', InputArgument::OPTIONAL, 'Sync only this module\'s shipped definitions. Defaults to every enabled module.');
    $this->addUsage('example_recipe');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $module = $input->getArgument('module');
    $report = $this->sync->sync($module !== NULL ? [(string) $module] : NULL);
    if ($report->isEmpty()) {
      $output->writeln('<comment>' . $this->nothingShipped($module === NULL ? NULL : (string) $module) . '</comment>');
      return self::SUCCESS;
    }
    $deferred = [];
    $awaiting_theme = [];
    $landed = [];
    foreach ($report->reports() as $kind => $kind_report) {
      foreach ($kind_report->outcomes() as $provider => $outcomes) {
        foreach ($outcomes as $name => $outcome) {
          if ($outcome === ShippedDefinitionsImporter::FAILED) {
            continue;
          }
          $output->writeln(sprintf('%s: %s %s %s', $provider, $kind, $name, $outcome === ShippedDefinitionsImporter::KEPT ? 'kept (site-edited; new defaults on disk)' : $outcome));
          if ($kind === 'variant' && ($outcome === ShippedDefinitionsImporter::CREATED || $outcome === ShippedDefinitionsImporter::UPDATED)) {
            $landed[] = [$provider, (string) $name];
          }
        }
      }
      $deferred += $kind_report->deferred();
      foreach ($kind_report->deferredForTheme() as $provider => $theme) {
        $awaiting_theme[$provider][$kind] = $theme;
      }
    }
    foreach ($this->withheldLines($landed) as $line) {
      $output->writeln('<comment>' . $line . '</comment>');
    }
    // A package waits as a whole, so it is one line, not one per kind.
    foreach ($deferred as $provider => $design_system) {
      $output->writeln(sprintf('<comment>%s: deferred; it packages the "%s" design system, which is not the active one. Its definitions land when the system becomes active.</comment>', $provider, $design_system));
    }
    // A kind waits for the default theme; one line per module and kind.
    foreach ($awaiting_theme as $provider => $kinds) {
      foreach ($kinds as $kind => $theme) {
        $output->writeln(sprintf('<comment>%s: %ss deferred; the default theme "%s" is not installed. They land when it is.</comment>', $provider, $kind, $theme));
      }
    }
    // Failures print last, in the report's one grammar, and fail the run.
    foreach ($report->failureLines() as $line) {
      $output->writeln('<error>' . $line . '</error>');
    }
    return $report->hasFailures() ? self::FAILURE : self::SUCCESS;
  }

  /**
   * The lines naming the variants this run landed that the verdict withholds.
   *
   * Only what this run created or updated is named: an unchanged variant
   * the law withholds was announced when it landed, and the sync
   * converged on it. The verdict is asked once, after the run, so the
   * judgement is the one the first page request would pay for anyway.
   * The roster is reset first because the run just moved the universe
   * under the same law key (VariantConformance memoizes per law key for
   * the request): the roster's reset cascades to the providers, and
   * daedalus_stylish's forgets the verdict there; this command never
   * resets the verdict on its own.
   *
   * @param array<int, array{0: string, 1: string}> $landed
   *   The [module, machine name] pairs created or updated by this run.
   *
   * @return string[]
   *   One line per withheld pair, in sync order:
   *   `MODULE: variant NAME landed, withheld under the current law
   *   (ENFORCEMENT): path [CODE]: message; ...`.
   */
  protected function withheldLines(array $landed): array {
    if ($landed === []) {
      return [];
    }
    $this->rosterBuilder->reset();
    $withheld = $this->conformance->withheld();
    $enforcement = $this->styleGuide->enforcement();
    $lines = [];
    foreach ($landed as [$provider, $name]) {
      if (!isset($withheld[$name])) {
        continue;
      }
      $refusals = array_map(static function (BlueprintError $error): string {
        return sprintf('%s [%s]: %s', $error->getPath(), $error->getCode(), $error->getMessage());
      }, $withheld[$name]);
      $lines[] = sprintf('%s: variant %s landed, withheld under the current law (%s): %s', $provider, $name, $enforcement, implode('; ', $refusals));
    }
    return $lines;
  }

  /**
   * The message for a run that saw no shipped file of any kind.
   *
   * Names the file forms the module (or any module) could ship, so a typo
   * in a file name is answered on the spot.
   *
   * @param string|null $module
   *   The module asked for, or NULL when every enabled module was swept.
   */
  protected function nothingShipped(?string $module): string {
    $prefix = $module ?? 'MODULE';
    $files = [];
    $per_id = '';
    foreach ($this->sync->importers() as $importer) {
      $files[] = "$prefix.{$importer->fileSuffix()}";
      $per_id = "$prefix.{$importer->perFileSuffix('*')}";
    }
    $forms = implode(', ', $files) . " or a per-id file beside one (e.g. $per_id)";
    return $module !== NULL
      ? "$module is not enabled or ships no daedalus_stylish definitions: no $forms."
      : "No enabled module ships daedalus_stylish definitions: no $forms.";
  }

}
