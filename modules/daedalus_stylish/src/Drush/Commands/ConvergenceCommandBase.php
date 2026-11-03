<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Drush\Commands;

use Drupal\daedalus_stylish\ShippedSync;
use Drupal\daedalus_stylish\StyleStoreConvergenceInterface;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs one whole style-store convergence on demand.
 *
 * The same sequence the convergence's post_update hooks run, for
 * development environments and re-imported data sets: sync the shipped
 * definitions (their disk sources speak the current grammar, and the sync
 * lane must own shipped-unmodified entities before any in-place rewrite),
 * walk every host style map through the transform spine (the convergence's
 * sweep is registered there permanently), then converge variant entities
 * and skin defaults. Every notable rewrite prints; the post_update path
 * logs the same lines to the daedalus_stylish channel. A concrete command
 * names its convergence and the words for it.
 */
abstract class ConvergenceCommandBase extends Command {

  use AutowireTrait;

  public function __construct(
    protected ShippedSync $shippedSync,
    protected StyleStoreConvergenceInterface $convergence,
  ) {
    parent::__construct();
  }

  /**
   * The convergence's name, for the closing line.
   */
  abstract protected function convergenceLabel(): string;

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $output->writeln('Syncing the shipped definitions...');
    $report = $this->shippedSync->sync();
    if ($report->hasFailures()) {
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
    }
    else {
      $output->writeln('<comment>daedalus_layout is not installed; skipping the host style map walk.</comment>');
    }

    $output->writeln('Converging style variant entities...');
    foreach ($this->convergence->convergeVariants() as $line) {
      $output->writeln('  ' . $line);
    }

    $output->writeln('Converging skin defaults...');
    foreach ($this->convergence->convergeSkins() as $line) {
      $output->writeln('  ' . $line);
    }

    $output->writeln(sprintf('<info>%s complete. Per-map host rewrites were logged to the daedalus_stylish channel.</info>', $this->convergenceLabel()));
    return self::SUCCESS;
  }

}
