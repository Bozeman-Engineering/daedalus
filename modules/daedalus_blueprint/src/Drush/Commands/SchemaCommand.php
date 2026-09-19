<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\daedalus_blueprint\BlueprintSchemaBuilderInterface;
use Drupal\daedalus_blueprint\Exception\BlueprintException;
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
 * Outputs the descriptive schema for an entity type and bundle.
 */
#[AsCommand(
  name: 'daedalus-blueprint:schema',
  description: 'Outputs the descriptive schema for an entity type and bundle.',
  aliases: ['dd-blueprint-schema'],
)]
final class SchemaCommand extends Command {

  use AutowireTrait;
  use RunsAsSelectedUserTrait;

  /**
   * Constructs a SchemaCommand instance.
   *
   * @param \Drupal\daedalus_blueprint\BlueprintSchemaBuilderInterface $schemaBuilder
   *   The blueprint schema builder.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\Core\Session\AccountSwitcherInterface $accountSwitcher
   *   Switches to the --user account for the build (used by the trait).
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Loads the --user account (used by the trait).
   */
  public function __construct(
    #[Autowire(service: 'daedalus_blueprint.schema_builder')]
    protected BlueprintSchemaBuilderInterface $schemaBuilder,
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
      ->addArgument('entity_type', InputArgument::REQUIRED, 'The entity type ID.')
      ->addArgument('bundle', InputArgument::REQUIRED, 'The bundle machine name.')
      ->addOption('view-mode', NULL, InputOption::VALUE_REQUIRED, 'View mode to filter available block types by layout_builder_restrictions.')
      ->addOption('user', NULL, InputOption::VALUE_REQUIRED, 'User ID to build the schema as; the schema is access-filtered for this user. Drush runs as anonymous by default, which cannot build schemas.', '1')
      ->addUsage('node page')
      ->addUsage('node page --view-mode=default')
      ->addUsage('node page --user=42');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $entity_type = (string) $input->getArgument('entity_type');
    $bundle = (string) $input->getArgument('bundle');
    $view_mode = $input->getOption('view-mode') ?? 'full';
    $uid = (int) $input->getOption('user');

    // The schema is access-filtered against the current user (CP5) and gates on
    // create access. Drush bootstraps as anonymous, so build as the --user
    // account (default uid 1).
    try {
      $schema = $this->runAsUser(
        $uid,
        fn() => $this->schemaBuilder->getSchema($entity_type, $bundle, $view_mode),
      );
    }
    catch (BlueprintException $e) {
      $this->logger->error($e->getMessage());
      return self::FAILURE;
    }

    $output->writeln(json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return self::SUCCESS;
  }

}
