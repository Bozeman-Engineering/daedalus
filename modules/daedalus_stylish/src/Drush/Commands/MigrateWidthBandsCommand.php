<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Drush\Commands;

use Drupal\daedalus_stylish\WidthBandMigration;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Runs the whole width-band style migration on demand.
 *
 * The same sequence the width_bands_000N post_update hooks run, for
 * development environments and re-imported data sets: walk every host
 * style map through the transform spine, then fold variant entities and
 * skin tokens and defaults. Every notable rewrite prints;
 * the post_update path logs the same lines to the daedalus_stylish channel instead.
 */
#[AsCommand(
  name: 'daedalus_stylish:migrate-width-bands',
  description: 'Folds retired "wide" style conditions into base (with medium spill) across every style store.',
)]
final class MigrateWidthBandsCommand extends Command {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'daedalus_stylish.width_band_migration')]
    protected WidthBandMigration $migration,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    if (\Drupal::hasService('daedalus_layout.host_entity_walker')) {
      $output->writeln('Walking host entities (all revisions and translations)...');
      $sandbox = [];
      $saved = 0;
      do {
        $saved += \Drupal::service('daedalus_layout.host_entity_walker')->walk($sandbox);
      } while (empty($sandbox['#finished']) || $sandbox['#finished'] < 1);
      $output->writeln(sprintf('  %d host revision(s) rewritten.', $saved));
    }
    else {
      $output->writeln('<comment>daedalus_layout is not installed; skipping the host style map walk.</comment>');
    }

    $output->writeln('Folding wide conditions on style variant entities...');
    foreach ($this->migration->migrateVariants() as $line) {
      $output->writeln('  ' . $line);
    }

    $output->writeln('Folding wide conditions on skins (tokens and defaults)...');
    foreach ($this->migration->migrateSkins() as $line) {
      $output->writeln('  ' . $line);
    }

    $output->writeln('<info>Width-band migration complete. Per-map host rewrites were logged to the daedalus_stylish channel.</info>');
    return self::SUCCESS;
  }

}
