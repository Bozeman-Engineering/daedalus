<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai\Drush\Commands;

use Drupal\Core\Database\Connection;
use Drupal\daedalus_blueprint_ai\AiLogTail\EntryFormatter;
use Drupal\daedalus_blueprint_ai\AiLogTail\FormatOptions;
use Drupal\daedalus_blueprint_ai\AiLogTail\LogEntry;
use Drupal\daedalus_blueprint_ai\AiLogTail\ThreadTracker;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Tails AI log entries in real-time.
 *
 * By default a row prints only what it adds to its conversation (each
 * ai_agents runner is one thread; see ThreadTracker) with every base64 blob
 * collapsed to one line. --full prints the raw dump: the whole prompt as
 * the model received it, every byte.
 */
#[AsCommand(
  name: 'daedalus-blueprint:ai-tail',
  description: 'Tails AI log entries in real-time.',
  aliases: ['dd-blueprint-tail'],
)]
final class AiTailCommand extends Command {

  use AutowireTrait;

  /**
   * The columns every print needs.
   */
  private const FIELDS = [
    'id', 'created', 'provider', 'model',
    'operation_type', 'prompt', 'output_text',
  ];

  /**
   * The columns only the optional sections need.
   */
  private const EXTRA_FIELDS = ['configuration', 'extra_data'];

  /**
   * The Symfony console style helper.
   *
   * @var \Symfony\Component\Console\Style\SymfonyStyle
   */
  protected SymfonyStyle $io;

  /**
   * Constructs an AiTailCommand instance.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(
    #[Autowire(service: 'database')]
    protected Connection $database,
  ) {
    parent::__construct();
  }

  /**
   * Builds the IO style helper.
   *
   * Replaces Drush's IoTrait, which only exists in Drush 14; this keeps the
   * command working on the stable Drush 13.7 line.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   The command input.
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The command output.
   *
   * @return \Symfony\Component\Console\Style\SymfonyStyle
   *   The IO style helper.
   */
  protected function setIo(InputInterface $input, OutputInterface $output): SymfonyStyle {
    $this->io = new SymfonyStyle($input, $output);
    return $this->io;
  }

  /**
   * Returns the IO style helper.
   *
   * @return \Symfony\Component\Console\Style\SymfonyStyle
   *   The IO style helper.
   */
  protected function io(): SymfonyStyle {
    return $this->io;
  }

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this
      ->addOption('limit', NULL, InputOption::VALUE_REQUIRED, 'Number of recent entries to show initially.', '5')
      ->addOption('interval', NULL, InputOption::VALUE_REQUIRED, 'Polling interval in seconds.', '2')
      ->addOption('no-follow', NULL, InputOption::VALUE_NONE, 'Show recent entries and exit (no polling).')
      ->addOption('tag', NULL, InputOption::VALUE_REQUIRED, 'Filter by tag value.', '')
      ->addOption('full', NULL, InputOption::VALUE_NONE, 'Print the raw dump: the whole prompt of every row (no per-thread dedupe) and every base64 blob in full.')
      ->addOption('truncate', NULL, InputOption::VALUE_REQUIRED, 'Truncate prompts longer than this many bytes (0 = no truncation).', '0')
      ->addOption('messages', 'm', InputOption::VALUE_OPTIONAL, 'Show only the last N messages of what each row prints. Use --messages / -m alone for the last 3, or --messages=5 / -m5 for a specific count (no space after -m). Omit to show them all; non-chat prompts are always shown in full.', FALSE)
      ->addOption('system-prompt', NULL, InputOption::VALUE_NONE, 'Include the system prompt in output.')
      ->addOption('configuration', NULL, InputOption::VALUE_NONE, 'Include provider configuration in output.')
      ->addOption('tools', NULL, InputOption::VALUE_NONE, 'Include tool definitions in output.')
      ->addOption('extra-data', NULL, InputOption::VALUE_NONE, 'Include the full extra data (debug) in output.')
      ->addOption('all', NULL, InputOption::VALUE_NONE, 'Include all optional fields in output.')
      ->addUsage('--no-follow --limit=10')
      ->addUsage('--full')
      ->addUsage('--tag=ai_agents')
      ->addUsage('--system-prompt')
      ->addUsage('--messages=3')
      ->addUsage('--all');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $this->setIo($input, $output);

    if (!$this->database->schema()->tableExists('ai_log')) {
      $this->io()->error('The ai_log table does not exist. Is ai_logging module enabled?');
      return self::FAILURE;
    }

    $limit = (int) $input->getOption('limit');
    $interval = (int) $input->getOption('interval');
    $tag = (string) $input->getOption('tag');
    $options = $this->formatOptions($input);
    $tracker = new ThreadTracker();
    $formatter = new EntryFormatter($output, $options);

    $entries = $this->fetch($tag, $options->needsExtras(), limit: $limit);
    $lastId = 0;
    if ($entries !== []) {
      $this->print($entries, $tracker, $formatter, $options);
      $lastId = end($entries)->id;
    }
    else {
      $this->io()->text('<comment>No log entries found.</comment>');
    }

    if ((bool) $input->getOption('no-follow')) {
      return self::SUCCESS;
    }

    $this->io()->text('<info>Following AI log... Press Ctrl+C to stop.</info>');

    // Register SIGINT handler for graceful exit.
    $running = TRUE;
    if (function_exists('pcntl_signal')) {
      pcntl_signal(SIGINT, function () use (&$running): void {
        $running = FALSE;
      });
    }

    while ($running) {
      sleep($interval);

      if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
      }

      if (!$running) {
        break;
      }

      $entries = $this->fetch($tag, $options->needsExtras(), afterId: $lastId);
      if ($entries !== []) {
        $this->print($entries, $tracker, $formatter, $options);
        $lastId = end($entries)->id;
      }
    }

    $this->io()->newLine();
    $this->io()->text('<info>Stopped.</info>');

    return self::SUCCESS;
  }

  /**
   * Resolves the print options from the command input.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   The command input.
   *
   * @return \Drupal\daedalus_blueprint_ai\AiLogTail\FormatOptions
   *   The options the formatter prints with.
   */
  private function formatOptions(InputInterface $input): FormatOptions {
    // --messages: FALSE means the flag was omitted (print everything); NULL
    // means it was passed without a value (last 3); otherwise it is an
    // explicit count. 0 disables slicing, same as omitting the flag.
    $messages = $input->getOption('messages');
    $messageLimit = match (TRUE) {
      $messages === FALSE => 0,
      $messages === NULL => 3,
      default => max(0, (int) $messages),
    };
    $all = (bool) $input->getOption('all');

    return new FormatOptions(
      full: (bool) $input->getOption('full'),
      truncate: (int) $input->getOption('truncate'),
      messageLimit: $messageLimit,
      systemPrompt: $all || (bool) $input->getOption('system-prompt'),
      configuration: $all || (bool) $input->getOption('configuration'),
      tools: $all || (bool) $input->getOption('tools'),
      extraData: $all || (bool) $input->getOption('extra-data'),
    );
  }

  /**
   * Prints rows in order, each as what it adds to its thread.
   *
   * @param \Drupal\daedalus_blueprint_ai\AiLogTail\LogEntry[] $entries
   *   The rows, oldest first.
   * @param \Drupal\daedalus_blueprint_ai\AiLogTail\ThreadTracker $tracker
   *   The per-thread memory, shared across the whole run so a follow-on
   *   row found by polling diffs against the row printed before it.
   * @param \Drupal\daedalus_blueprint_ai\AiLogTail\EntryFormatter $formatter
   *   The row printer.
   * @param \Drupal\daedalus_blueprint_ai\AiLogTail\FormatOptions $options
   *   The print options; in full mode the tracker is not consulted.
   */
  private function print(array $entries, ThreadTracker $tracker, EntryFormatter $formatter, FormatOptions $options): void {
    foreach ($entries as $entry) {
      $transcript = $entry->transcript();
      $delta = $options->full ? NULL : $tracker->advance($entry, $transcript);
      $formatter->format($entry, $transcript, $delta);
    }
  }

  /**
   * Fetches log rows with their tags, oldest first.
   *
   * With $afterId the rows are every row past that id (the follow loop);
   * without it they are the $limit most recent rows (the initial print).
   *
   * @param string $tag
   *   A tag value to filter by, '' for none.
   * @param bool $extras
   *   Whether to fetch the configuration and extra_data columns.
   * @param int|null $afterId
   *   The last printed row id, or NULL for the initial fetch.
   * @param int $limit
   *   How many recent rows the initial fetch returns; ignored with $afterId.
   *
   * @return \Drupal\daedalus_blueprint_ai\AiLogTail\LogEntry[]
   *   The rows, oldest first.
   */
  private function fetch(string $tag, bool $extras, ?int $afterId = NULL, int $limit = 0): array {
    $query = $this->database->select('ai_log', 'l');
    $query->fields('l', $extras ? [...self::FIELDS, ...self::EXTRA_FIELDS] : self::FIELDS);
    if ($tag !== '') {
      $query->join('ai_log__tags', 't', 'l.id = t.entity_id');
      $query->condition('t.tags_value', $tag);
    }
    if ($afterId === NULL) {
      $query->orderBy('l.id', 'DESC');
      $query->range(0, $limit);
    }
    else {
      $query->condition('l.id', $afterId, '>');
      $query->orderBy('l.id', 'ASC');
    }

    $rows = $query->execute()->fetchAll();
    if ($afterId === NULL) {
      $rows = array_reverse($rows);
    }
    if ($rows === []) {
      return [];
    }

    $tags = $this->fetchTags(array_map(fn(object $row): int => (int) $row->id, $rows));
    return array_map(fn(object $row): LogEntry => LogEntry::fromRow($row, $tags[(int) $row->id] ?? []), $rows);
  }

  /**
   * Fetches the tags of a set of log rows.
   *
   * @param int[] $ids
   *   The row ids.
   *
   * @return array<int, string[]>
   *   The tags in delta order, keyed by row id.
   */
  private function fetchTags(array $ids): array {
    $results = $this->database->select('ai_log__tags', 't')
      ->fields('t', ['entity_id', 'tags_value'])
      ->condition('t.entity_id', $ids, 'IN')
      ->orderBy('t.entity_id')
      ->orderBy('t.delta')
      ->execute()
      ->fetchAll();

    $tags = [];
    foreach ($results as $row) {
      $tags[(int) $row->entity_id][] = $row->tags_value;
    }
    return $tags;
  }

}
