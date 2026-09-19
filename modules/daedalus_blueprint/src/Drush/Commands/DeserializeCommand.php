<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\daedalus_blueprint\BlueprintDeserializerInterface;
use Drush\Commands\AutowireTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Deserializes blueprint JSON into an entity.
 */
#[AsCommand(
  name: 'daedalus-blueprint:deserialize',
  description: 'Deserializes blueprint JSON into an entity.',
  aliases: ['dd-blueprint-deserialize'],
)]
final class DeserializeCommand extends Command {

  use AutowireTrait;
  use RunsAsSelectedUserTrait;

  /**
   * Constructs a DeserializeCommand instance.
   *
   * @param \Drupal\daedalus_blueprint\BlueprintDeserializerInterface $deserializer
   *   The blueprint deserializer.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\Core\Session\AccountSwitcherInterface $accountSwitcher
   *   Switches to the --user account for deserialize + save (used by the
   *   trait).
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Loads the --user account (used by the trait).
   */
  public function __construct(
    #[Autowire(service: 'daedalus_blueprint.deserializer')]
    protected BlueprintDeserializerInterface $deserializer,
    protected LoggerInterface $logger,
    protected AccountSwitcherInterface $accountSwitcher,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this
      ->addArgument('source', InputArgument::REQUIRED, 'Path to JSON file, or "-" for stdin.')
      ->addOption('dry-run', NULL, InputOption::VALUE_NONE, 'Structural validation only (Phase A), do not hydrate.')
      ->addOption('no-save', NULL, InputOption::VALUE_NONE, 'Hydrate and validate but do not save.')
      ->addOption('user', NULL, InputOption::VALUE_REQUIRED, 'User ID to deserialize and save as; create/edit access is enforced for this user. Drush runs as anonymous by default, which cannot create most entities.', '1')
      ->addUsage('blueprint.json')
      ->addUsage('cat blueprint.json | drush daedalus-blueprint:deserialize -')
      ->addUsage('blueprint.json --user=42');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $source = (string) $input->getArgument('source');
    $dry_run = (bool) $input->getOption('dry-run');
    $no_save = (bool) $input->getOption('no-save');
    $uid = (int) $input->getOption('user');

    $json = $this->readSource($source);
    if ($json === NULL) {
      return self::FAILURE;
    }

    $data = json_decode($json, TRUE);
    if ($data === NULL) {
      $this->logger->error('Invalid JSON input.');
      return self::FAILURE;
    }

    $deserialize_options = [];
    if ($dry_run) {
      $deserialize_options['validate_only'] = TRUE;
    }

    // Deserialization enforces create/field-edit access and the save persists
    // as the current user. Drush bootstraps as anonymous, so run as the --user
    // account (default uid 1).
    $result = $this->runAsUser($uid, function () use ($data, $deserialize_options, $dry_run, $no_save) {
      $result = $this->deserializer->deserialize($data, $deserialize_options);
      if ($result->isSuccess() && !$dry_run && !$no_save && $result->getEntity()) {
        $result->getEntity()->save();
      }
      return $result;
    });

    $output->writeln(json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return self::SUCCESS;
  }

  /**
   * Reads JSON content from a file path or stdin.
   *
   * @param string $source
   *   File path or "-" for stdin.
   *
   * @return string|null
   *   The file contents, or NULL on error.
   */
  protected function readSource(string $source): ?string {
    if ($source === '-') {
      $content = file_get_contents('php://stdin');
      if ($content === FALSE || $content === '') {
        $this->logger->error('No input received from stdin.');
        return NULL;
      }
      return $content;
    }

    if (!file_exists($source)) {
      $this->logger->error("File not found: {$source}");
      return NULL;
    }

    $content = file_get_contents($source);
    if ($content === FALSE) {
      $this->logger->error("Could not read file: {$source}");
      return NULL;
    }

    return $content;
  }

}
