<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Drush\Commands;

use Drupal\daedalus_stylish\RelativeSelectorConvergence;
use Drupal\daedalus_stylish\ShippedSync;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Runs the whole relative-selector convergence on demand.
 *
 * The same sequence the relative_selector_000N post_update hooks run, for
 * development environments and re-imported data sets: sync the shipped
 * definitions (their disk sources speak the grammar), walk every host
 * style map through the transform spine, then converge variant entities
 * and skin defaults. Every notable rewrite prints; the
 * post_update path logs the same lines to the daedalus_stylish channel.
 */
#[AsCommand(
  name: 'daedalus_stylish:converge-selectors',
  description: 'Rewrites the retired "::>" children spelling to the relative selector grammar ("::> *") across every style store.',
)]
final class ConvergeSelectorsCommand extends Command {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'daedalus_stylish.shipped_sync')]
    protected ShippedSync $shippedSync,
    #[Autowire(service: 'daedalus_stylish.relative_selector_convergence')]
    protected RelativeSelectorConvergence $convergence,
  ) {
    parent::__construct();
  }

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

    $output->writeln('<info>Relative selector convergence complete. Per-map host rewrites were logged to the daedalus_stylish channel.</info>');
    return self::SUCCESS;
  }

}
