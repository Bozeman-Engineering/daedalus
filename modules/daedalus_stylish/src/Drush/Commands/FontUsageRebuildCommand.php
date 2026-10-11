<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Drush\Commands;

use Drupal\daedalus_stylish\FontUsage;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Rebuilds the font usage table from the saved values of the stores.
 *
 * The repair path for the tracking chokepoint's accepted staleness edges
 * (workspace publish fires no per-entity update hooks; writes bypassing
 * the entity save API are invisible to any hook; a guide rebinding
 * font-family to another scale changes which tokens are a source). The
 * semantics live in FontUsage::rebuild(); this is a thin CLI adapter, the
 * animation rebuild command's twin.
 */
#[AsCommand(
  name: 'daedalus_stylish:font-usage:rebuild',
  description: 'Rebuilds the daedalus_font_usage table from the saved default-revision style maps, variant styles, skin defaults and font-bound skin tokens.',
)]
final class FontUsageRebuildCommand extends Command {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'daedalus_stylish.font_usage')]
    protected FontUsage $usage,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $tracked = $this->usage->rebuild();
    $output->writeln(sprintf(
      'Rebuilt the font usage table: %d family-naming source %s tracked.',
      $tracked,
      $tracked === 1 ? 'entity' : 'entities',
    ));
    return self::SUCCESS;
  }

}
