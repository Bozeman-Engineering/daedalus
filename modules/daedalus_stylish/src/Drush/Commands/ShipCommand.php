<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Drush\Commands;

use Drupal\daedalus_stylish\Ship;
use Drupal\daedalus_stylish\ShippedDefinitionsImporter;
use Drupal\daedalus_stylish\ShipReport;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The CLI adapter of the ship lane: the site OUT to a module's package.
 *
 * `daedalus_stylish:sync MODULE` brings a module's shipped definitions onto the
 * site; this command runs the other direction (see Ship): with no names,
 * every per-id file of the module is rebuilt from the current entities;
 * with `--variant NAME` or `--skin NAME` (repeatable), the named site
 * materials are adopted into the module. One line per entry in the sync
 * command's grammar (`MODULE: KIND NAME outcome`), a hand-authored entry
 * the site edited told to be edited by hand, then every failure with its
 * path, code, message, and hint, and a non-zero exit when anything was
 * refused; everything that could ship already did. `--dry-run` prints
 * the identical lines from the shippers' plans and writes nothing.
 */
#[AsCommand(
  name: 'daedalus_stylish:ship',
  description: 'Ships the site\'s daedalus_stylish materials to a module\'s per-id files (skins, variants): rebuilds the module\'s package from the current entities, or adopts named site materials into it.',
)]
final class ShipCommand extends Command {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'daedalus_stylish.ship')]
    protected Ship $ship,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this->addArgument('module', InputArgument::REQUIRED, 'The enabled module to ship into; its per-id files are rewritten from the site.');
    foreach (array_keys($this->ship->shippers()) as $kind) {
      $this->addOption($kind, NULL, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, sprintf('A %s to adopt into MODULE by machine name (repeatable). With any name given, only the named materials ship.', $kind));
    }
    $this->addOption('dry-run', NULL, InputOption::VALUE_NONE, 'Report what the ship would do; write no file, touch no entity.');
    $this->addUsage('example_recipe');
    $this->addUsage('example_recipe --variant card_blue --skin dusk');
    $this->addUsage('example_recipe --dry-run');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $module = (string) $input->getArgument('module');
    $names = [];
    foreach (array_keys($this->ship->shippers()) as $kind) {
      $names[$kind] = $input->getOption($kind);
    }
    $dry_run = (bool) $input->getOption('dry-run');
    try {
      $report = $dry_run ? $this->ship->plan($module, $names) : $this->ship->ship($module, $names);
    }
    catch (\InvalidArgumentException $e) {
      // A usage error (the module is not enabled): the terminal is the
      // recovery, not a stack trace.
      $output->writeln('<error>' . $e->getMessage() . '</error>');
      return self::FAILURE;
    }
    // A dry run prints exactly the run's lines: the report is the same
    // report, filled from the plans instead of the ships.
    if ($report->isEmpty()) {
      $output->writeln('<comment>' . $this->nothingToShip($module) . '</comment>');
      return self::SUCCESS;
    }
    $this->printOutcomes($report, $output);
    // Failures print last, in the report's one grammar, and fail the run.
    foreach ($report->failureLines() as $line) {
      $output->writeln('<error>' . $line . '</error>');
    }
    return $report->hasFailures() ? self::FAILURE : self::SUCCESS;
  }

  /**
   * Prints one line per entry: `MODULE: KIND NAME outcome`.
   *
   * A hand-authored entry the site edited names its file, since the
   * outcome is an instruction (edit it by hand), not a state of disk.
   */
  protected function printOutcomes(ShipReport $report, OutputInterface $output): void {
    $module = $report->module();
    foreach ($report->outcomes() as $kind => $outcomes) {
      foreach ($outcomes as $name => $outcome) {
        if ($outcome === ShippedDefinitionsImporter::FAILED) {
          continue;
        }
        $output->writeln(sprintf('%s: %s %s %s', $module, $kind, $name, $outcome === Ship::MODIFIED
          ? sprintf('modified; edit by hand in %s', $report->file($kind, (string) $name))
          : $outcome));
      }
    }
  }

  /**
   * The message for a module with no per-id file and no name given.
   *
   * Names the files a rebuild would read and the options that adopt, so
   * an author who expected a file to exist sees its exact name.
   */
  protected function nothingToShip(string $module): string {
    $files = [];
    $options = [];
    foreach ($this->ship->shippers() as $kind => $shipper) {
      $files[] = "$module.{$shipper->importer()->perFileSuffix('*')}";
      $options[] = "--$kind NAME";
    }
    return sprintf('%s ships no per-id daedalus_stylish file (no %s); name a site material to adopt with %s.', $module, implode(' or ', $files), implode(' or ', $options));
  }

}
