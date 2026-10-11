<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Drush\Commands;

use Drupal\daedalus_stylish\Exception\SkinValidationException;
use Drupal\daedalus_stylish\SkinManager;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Chooses the skin the active design system wears on this site.
 *
 * The CLI adapter of SkinManager::choose(), the choose route's twin: the
 * choice is a site setting (daedalus_stylish.settings skin_choice, keyed
 * by design system id), so a deployment script or a developer's shell
 * can set it without the canvas. One line on success naming the skin
 * and the system; on refusal the error's message and hint, and a
 * non-zero exit.
 */
#[AsCommand(
  name: 'daedalus_stylish:skin:choose',
  description: 'Chooses the skin the active design system wears on this site: a published member skin that mints the guide\'s token contract. The site setting beside the activation; the system\'s default_skin answers while nothing is chosen.',
)]
final class SkinChooseCommand extends Command {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'daedalus_stylish.skin_manager')]
    protected SkinManager $skinManager,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this->addArgument('skin', InputArgument::REQUIRED, 'The machine name of the skin to wear; a member of the active design system.');
    $this->addUsage('dusk');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $name = (string) $input->getArgument('skin');
    try {
      $system = $this->skinManager->choose($name);
    }
    catch (SkinValidationException $exception) {
      foreach ($exception->getErrorsAsArrays() as $error) {
        $output->writeln(sprintf('<error>%s: %s</error> %s', $error['code'], $error['message'], $error['hint']));
      }
      return self::FAILURE;
    }
    $output->writeln(sprintf('The site wears the "%s" skin with the "%s" design system.', $name, $system));
    return self::SUCCESS;
  }

}
