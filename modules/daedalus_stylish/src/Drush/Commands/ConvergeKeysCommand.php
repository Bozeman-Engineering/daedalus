<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Drush\Commands;

use Drupal\daedalus_layout\ComponentTransform\HostEntityWalker;
use Drupal\daedalus_stylish\ShippedSync;
use Drupal\daedalus_stylish\StoredStylesReport;
use Drupal\daedalus_stylish\StyleStoreConvergenceInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Runs the whole key grammar convergence on demand.
 *
 * The key_grammar_000N post_update sequence (ConvergenceCommandBase) over
 * KeyGrammarConvergence, closing as that sequence does with the report of
 * what the stored styles validation still refuses on a host. For a site
 * whose other convergences have run: the variant and skin passes drop a
 * retired spelling an earlier convergence would have rewritten.
 */
#[AsCommand(
  name: 'daedalus_stylish:converge-keys',
  description: 'Drops every stored style key its store\'s key grammar rejects (such a key prints nothing) across every style store, then reports what the stored styles validation still refuses on a host.',
)]
final class ConvergeKeysCommand extends ConvergenceCommandBase {

  public function __construct(
    #[Autowire(service: 'daedalus_stylish.shipped_sync')]
    protected ShippedSync $shippedSync,
    #[Autowire(service: 'daedalus_stylish.key_grammar_convergence')]
    protected StyleStoreConvergenceInterface $convergence,
    #[Autowire(service: 'daedalus_stylish.stored_styles_report')]
    protected StoredStylesReport $storedStylesReport,
    #[Autowire(service: 'daedalus_layout.host_entity_walker')]
    protected ?HostEntityWalker $hostEntityWalker = NULL,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function convergenceLabel(): string {
    return 'Key grammar convergence';
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $status = parent::execute($input, $output);
    if ($status !== self::SUCCESS) {
      return $status;
    }

    $output->writeln('Validating the stored styles of every host (current revisions, nothing is written)...');
    $sandbox = [];
    do {
      foreach ($this->storedStylesReport->report($sandbox) as $line) {
        $output->writeln('  ' . $line);
      }
    } while (empty($sandbox['#finished']) || $sandbox['#finished'] < 1);
    $totals = $this->storedStylesReport->totals($sandbox);
    $output->writeln($totals['refusals'] === 0
      ? sprintf('<info>The stored styles validation accepts the style map of every host (%d read).</info>', $totals['hosts'])
      : sprintf('<comment>The stored styles validation refuses %d style map entries on %d of %d hosts. Those hosts cannot be saved until the entries are repaired.</comment>', $totals['refusals'], $totals['refused_hosts'], $totals['hosts']));
    return self::SUCCESS;
  }

}
