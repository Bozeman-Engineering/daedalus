<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\daedalus_blueprint\BlueprintSerializerInterface;
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
 * Serializes an entity to blueprint JSON.
 */
#[AsCommand(
  name: 'daedalus-blueprint:serialize',
  description: 'Serializes an entity to blueprint JSON.',
  aliases: ['dd-blueprint-serialize'],
)]
final class SerializeCommand extends Command {

  use AutowireTrait;

  /**
   * Constructs a SerializeCommand instance.
   *
   * @param \Drupal\daedalus_blueprint\BlueprintSerializerInterface $serializer
   *   The blueprint serializer.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(
    #[Autowire(service: 'daedalus_blueprint.serializer')]
    protected BlueprintSerializerInterface $serializer,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerInterface $logger,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this
      ->addArgument('entity_type', InputArgument::REQUIRED, 'The entity type ID.')
      ->addArgument('id', InputArgument::REQUIRED, 'The entity ID.')
      ->addOption('summary', NULL, InputOption::VALUE_NONE, 'Output structural skeleton only (no field values).')
      ->addOption('element', NULL, InputOption::VALUE_REQUIRED, 'UUID of a single element to serialize.', '')
      ->addUsage('node 1')
      ->addUsage('node 1 --summary')
      ->addUsage('node 1 --element=abc-123');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $entity_type = (string) $input->getArgument('entity_type');
    $id = (string) $input->getArgument('id');
    $element = (string) $input->getOption('element');

    $storage = $this->entityTypeManager->getStorage($entity_type);
    $entity = $storage->load($id);

    if ($entity === NULL) {
      $this->logger->error("Entity {$entity_type} {$id} not found.");
      return self::FAILURE;
    }

    if ($input->getOption('summary')) {
      $result = $this->serializer->serializeSummary($entity);
    }
    elseif ($element !== '') {
      $result = $this->serializer->serializeElement($entity, $element);
      if ($result === NULL) {
        $this->logger->error("Element {$element} not found on entity.");
        return self::FAILURE;
      }
    }
    else {
      $result = $this->serializer->serialize($entity);
    }

    $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return self::SUCCESS;
  }

}
