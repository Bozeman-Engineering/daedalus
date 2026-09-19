<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Drush\Commands;

use Drupal\daedalus_stylish\ShippedDefinitionsImporter;
use Drupal\daedalus_stylish\ShippedSync;
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
 * Every enabled module's shipped animations, swatches, skins, and variants
 * already activate on a cache rebuild and on install (see ShippedSync);
 * this command runs the same orchestrated sync by hand, so an operator can
 * activate one module's package and read every outcome on the terminal
 * instead of in the log. One line per definition in sync order
 * (`MODULE: KIND NAME outcome`), one line per deferred package (a module
 * packaging a design system that is not the active one: its definitions
 * land at the activation, under the system's own law), then every
 * failure with its path, code, message, and hint, and a non-zero exit
 * when anything failed; everything that could land already did.
 */
#[AsCommand(
  name: 'daedalus_stylish:sync',
  description: 'Activates module-shipped daedalus_stylish definitions (animations, swatches, skins, variants) and reports every outcome; the same sync a cache rebuild runs.',
)]
final class SyncCommand extends Command {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'daedalus_stylish.shipped_sync')]
    protected ShippedSync $sync,
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
    foreach ($report->reports() as $kind => $kind_report) {
      foreach ($kind_report->outcomes() as $provider => $outcomes) {
        foreach ($outcomes as $name => $outcome) {
          if ($outcome === ShippedDefinitionsImporter::FAILED) {
            continue;
          }
          $output->writeln(sprintf('%s: %s %s %s', $provider, $kind, $name, $outcome === ShippedDefinitionsImporter::KEPT ? 'kept (site-edited; new defaults on disk)' : $outcome));
        }
      }
      $deferred += $kind_report->deferred();
    }
    // A package waits as a whole, so it is one line, not one per kind.
    foreach ($deferred as $provider => $design_system) {
      $output->writeln(sprintf('<comment>%s: deferred; it packages the "%s" design system, which is not active. Its definitions land when the system is activated.</comment>', $provider, $design_system));
    }
    // Failures print last, in the report's one grammar, and fail the run.
    foreach ($report->failureLines() as $line) {
      $output->writeln('<error>' . $line . '</error>');
    }
    return $report->hasFailures() ? self::FAILURE : self::SUCCESS;
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
