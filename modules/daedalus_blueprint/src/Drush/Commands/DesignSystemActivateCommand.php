<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Drush\Commands;

use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Activates a design system: the CLI adapter over ActiveDesignSystem.
 */
#[AsCommand(
  name: 'daedalus-blueprint:design-system:activate',
  description: 'Activates a design system by id (its materials, skin and law come into force site-wide).',
  aliases: ['dd-blueprint-activate'],
)]
final class DesignSystemActivateCommand extends Command {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: ActiveDesignSystem::SERVICE_ID)]
    protected ActiveDesignSystem $activeDesignSystem,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this
      ->addArgument('id', InputArgument::REQUIRED, 'The design system id (daedalus_blueprint.design_system.ID).')
      ->addUsage('olivero');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $id = (string) $input->getArgument('id');
    if ($this->activeDesignSystem->activeId() === $id) {
      $output->writeln(sprintf('The design system "%s" is already active.', $id));
      return self::SUCCESS;
    }
    try {
      $this->activeDesignSystem->activate($id);
    }
    catch (\InvalidArgumentException $e) {
      $output->writeln('<error>' . $e->getMessage() . '</error>');
      return self::FAILURE;
    }
    $output->writeln(sprintf('Activated the design system "%s".', $id));
    return self::SUCCESS;
  }

}
