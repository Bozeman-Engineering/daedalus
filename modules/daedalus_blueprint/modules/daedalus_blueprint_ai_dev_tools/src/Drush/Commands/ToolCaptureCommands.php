<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai_dev_tools\Drush\Commands;

use Drupal\Core\State\StateInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\daedalus_blueprint_ai_dev_tools\EventSubscriber\ToolInputCaptureSubscriber;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Arms and disarms the page-tool input capture.
 *
 * While armed, ToolInputCaptureSubscriber writes every page tool's raw
 * input (and, once parsed, the parse verdict) to
 * private://ai-tool-capture/<conversation>/<time>-<tool>.json. The flag is
 * state, not config: a diagnostic switch for one machine, never deployed,
 * never on by default.
 */
#[AsCommand(
  name: 'daedalus-blueprint:tool-capture',
  description: 'Arms or disarms the page-tool input capture (raw tool inputs written to private://ai-tool-capture).',
  aliases: ['dd-blueprint-capture'],
)]
final class ToolCaptureCommands extends Command {

  use AutowireTrait;

  public function __construct(
    protected StateInterface $state,
    protected StreamWrapperManagerInterface $streamWrapperManager,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this
      ->addArgument('action', InputArgument::OPTIONAL, 'One of: arm, disarm, status.', 'status')
      ->addUsage('arm')
      ->addUsage('disarm');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $action = (string) $input->getArgument('action');
    switch ($action) {
      case 'arm':
        // The subscriber throws mid-turn when private:// is missing; catch
        // the misconfiguration here, where it can be fixed calmly.
        if (!$this->streamWrapperManager->isValidScheme('private')) {
          $output->writeln("<error>The private filesystem is not configured: set \$settings['file_private_path'] first. Not armed.</error>");
          return Command::FAILURE;
        }
        $this->state->set(ToolInputCaptureSubscriber::STATE_KEY, TRUE);
        $output->writeln(sprintf('Tool-input capture ARMED. Page tool inputs will be written under %s/.', ToolInputCaptureSubscriber::DIRECTORY));
        return Command::SUCCESS;

      case 'disarm':
        $this->state->delete(ToolInputCaptureSubscriber::STATE_KEY);
        $output->writeln('Tool-input capture disarmed.');
        return Command::SUCCESS;

      case 'status':
        $armed = (bool) $this->state->get(ToolInputCaptureSubscriber::STATE_KEY, FALSE);
        $output->writeln($armed ? 'Tool-input capture is ARMED.' : 'Tool-input capture is disarmed.');
        return Command::SUCCESS;
    }
    $output->writeln(sprintf('<error>Unknown action "%s". Use arm, disarm, or status.</error>', $action));
    return Command::FAILURE;
  }

}
