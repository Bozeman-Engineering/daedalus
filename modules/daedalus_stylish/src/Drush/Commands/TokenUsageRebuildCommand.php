<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Drush\Commands;

use Drupal\daedalus_stylish\TokenUsage;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Rebuilds the token usage table from the saved values of all three stores.
 *
 * The repair path for the tracking chokepoint's accepted staleness edges
 * (workspace publish fires no per-entity update hooks; writes bypassing the
 * entity save API are invisible to any hook). The semantics live in
 * TokenUsage::rebuild(); this is a thin CLI adapter, the swatch rebuild
 * command's twin.
 */
#[AsCommand(
  name: 'daedalus_stylish:token-usage:rebuild',
  description: 'Rebuilds the daedalus_token_usage table from the saved default-revision style maps, variant styles, and skin tokens/defaults.',
)]
final class TokenUsageRebuildCommand extends Command {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'daedalus_stylish.token_usage')]
    protected TokenUsage $usage,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $tracked = $this->usage->rebuild();
    $output->writeln(sprintf(
      'Rebuilt the token usage table: %d token-referencing source %s tracked.',
      $tracked,
      $tracked === 1 ? 'entity' : 'entities',
    ));
    return self::SUCCESS;
  }

}
