<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Drush\Commands;

use Drupal\daedalus_stylish\AnimationUsage;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Rebuilds the animation usage table from the saved values of the stores.
 *
 * The repair path for the tracking chokepoint's accepted staleness edges
 * (workspace publish fires no per-entity update hooks; writes bypassing the
 * entity save API are invisible to any hook). The semantics live in
 * AnimationUsage::rebuild(); this is a thin CLI adapter, the swatch rebuild
 * command's twin.
 */
#[AsCommand(
  name: 'daedalus_stylish:animation-usage:rebuild',
  description: 'Rebuilds the daedalus_animation_usage table from the saved default-revision style maps, variant styles, and skin defaults.',
)]
final class AnimationUsageRebuildCommand extends Command {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'daedalus_stylish.animation_usage')]
    protected AnimationUsage $usage,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $tracked = $this->usage->rebuild();
    $output->writeln(sprintf(
      'Rebuilt the animation usage table: %d animation-wearing source %s tracked.',
      $tracked,
      $tracked === 1 ? 'entity' : 'entities',
    ));
    return self::SUCCESS;
  }

}
