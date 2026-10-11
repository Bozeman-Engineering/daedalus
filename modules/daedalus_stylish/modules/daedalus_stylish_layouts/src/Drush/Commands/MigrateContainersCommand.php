<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish_layouts\Drush\Commands;

use Drupal\daedalus_stylish\ShippedSync;
use Drupal\daedalus_stylish_layouts\ContainerMigration;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Runs the whole container-model data migration on demand.
 *
 * The same sequence the migrate_000N post_update hooks run, for
 * development environments and re-imported data sets: sync the generic
 * variant families, walk every layout store (hosts, then display
 * defaults) through the transform spine, then migrate variant entities.
 * Every notable rewrite prints; the post_update path
 * logs the same lines to the daedalus_stylish_layouts channel instead.
 */
#[AsCommand(
  name: 'daedalus_stylish_layouts:migrate-containers',
  description: 'Converts multi-region sections, style maps and variants to the container model.',
)]
final class MigrateContainersCommand extends Command {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'daedalus_stylish.shipped_sync')]
    protected ShippedSync $shippedSync,
    #[Autowire(service: 'daedalus_stylish_layouts.container_migration')]
    protected ContainerMigration $migration,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $output->writeln('Syncing the shipped daedalus_stylish_layouts definitions...');
    $report = $this->shippedSync->sync(['daedalus_stylish_layouts']);
    if ($report->hasFailures()) {
      // The migration re-points references onto these families: without
      // every one of them in place it must not run.
      foreach ($report->failureLines() as $line) {
        $output->writeln('<error>' . $line . '</error>');
      }
      return self::FAILURE;
    }

    if (\Drupal::hasService('daedalus_layout.host_entity_walker')) {
      $output->writeln('Walking host entities (all revisions and translations)...');
      $sandbox = [];
      $saved = 0;
      do {
        $saved += \Drupal::service('daedalus_layout.host_entity_walker')->walk($sandbox);
      } while (empty($sandbox['#finished']) || $sandbox['#finished'] < 1);
      $output->writeln(sprintf('  %d host revision(s) rewritten.', $saved));

      $output->writeln('Walking entity view display defaults...');
      $sandbox = [];
      $saved = 0;
      do {
        $saved += \Drupal::service('daedalus_layout.entity_view_display_walker')->walk($sandbox);
      } while (empty($sandbox['#finished']) || $sandbox['#finished'] < 1);
      $output->writeln(sprintf('  %d display(s) rewritten.', $saved));
    }
    else {
      $output->writeln('<comment>daedalus_layout is not installed; skipping the section store walks.</comment>');
    }

    $output->writeln('Migrating style variant entities...');
    foreach ($this->migration->migrateVariants() as $line) {
      $output->writeln('  ' . $line);
    }

    $output->writeln('Retiring the shipped layout-exact variants...');
    foreach ($this->migration->retireShippedLayoutVariants() as $line) {
      $output->writeln('  ' . $line);
    }

    $output->writeln('Retiring the shipped split proportions variants...');
    foreach ($this->migration->retireShippedSplitVariants() as $line) {
      $output->writeln('  ' . $line);
    }

    $output->writeln('<info>Container migration complete. Per-map host rewrites were logged to the daedalus_stylish_layouts channel.</info>');
    return self::SUCCESS;
  }

}
