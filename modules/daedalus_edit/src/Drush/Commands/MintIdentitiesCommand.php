<?php

declare(strict_types=1);

namespace Drupal\daedalus_edit\Drush\Commands;

use Drupal\daedalus_edit\IdentityBackfill;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Backfills identity classes onto existing formatted text content.
 */
#[AsCommand(
  name: 'daedalus_edit:mint-identities',
  description: 'Mints missing s-* identity classes onto existing formatted text content.',
  aliases: ['dd-edit-mint'],
)]
final class MintIdentitiesCommand extends Command {

  use AutowireTrait;

  /**
   * Constructs a MintIdentitiesCommand instance.
   *
   * @param \Drupal\daedalus_edit\IdentityBackfill $backfill
   *   The identity backfill service.
   */
  public function __construct(
    #[Autowire(service: 'daedalus_edit.identity_backfill')]
    protected IdentityBackfill $backfill,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this
      ->addOption('entity-type', NULL, InputOption::VALUE_REQUIRED, 'Backfill only this entity type id.')
      ->addOption('chunk', NULL, InputOption::VALUE_REQUIRED, 'Entities loaded per chunk.', '50')
      ->addUsage('')
      ->addUsage('--entity-type=node');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $entity_type = $input->getOption('entity-type');
    $types = $entity_type !== NULL ? [$entity_type] : $this->backfill->entityTypeIds();
    $chunk = max(1, (int) $input->getOption('chunk'));

    $total_processed = 0;
    $total_saved = 0;
    foreach ($types as $type) {
      $after = NULL;
      $processed = 0;
      $saved = 0;
      do {
        $result = $this->backfill->processChunk($type, $after, $chunk);
        $processed += $result['processed'];
        $saved += $result['saved'];
        $after = $result['last_id'];
      } while ($result['processed'] === $chunk);
      $output->writeln(sprintf('%s: %d checked, %d minted and saved.', $type, $processed, $saved));
      $total_processed += $processed;
      $total_saved += $saved;
    }

    $output->writeln(sprintf('Done: %d entities checked, %d minted and saved.', $total_processed, $total_saved));
    return Command::SUCCESS;
  }

}
