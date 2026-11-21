<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\daedalus_stylish\Plugin\Validation\Constraint\StoredStylesConstraint;

/**
 * Reports what the stored styles validation refuses on the hosts at rest.
 *
 * A host whose style map the stored styles constraint refuses cannot be
 * saved, and no surface shows the entry at fault. This read runs that
 * constraint, and nothing else, over the current revision of every entity
 * carrying a style map field, and says where each refusal sits: the host
 * and the path inside its map. It writes nothing. The judgement is the
 * constraint's own (StoredStylesConstraintValidator, the landing lane),
 * so a line here is exactly a refusal the next save of that host meets.
 *
 * The convergences change what they can prove is safe to change
 * (KeyGrammarConvergence drops the keys that print nothing); what is left
 * may sit on a key that does print, so it is a person's call, and this
 * report is how that person learns of it.
 */
class StoredStylesReport {

  /**
   * The sandbox entry holding the walk state.
   */
  public const SANDBOX_KEY = 'daedalus_stylish_stored_styles_report';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Validates one batch of hosts and reports their refusals.
   *
   * @param array $sandbox
   *   The batch sandbox (a post_update hook's $sandbox). The report owns
   *   the self::SANDBOX_KEY entry and sets $sandbox['#finished'] (1 when
   *   every host has been read).
   * @param int $batch_size
   *   Maximum hosts to load and validate in this call.
   *
   * @return string[]
   *   One line per refusal found in this call, each logged to the
   *   daedalus_stylish channel as a warning.
   */
  public function report(array &$sandbox, int $batch_size = 50): array {
    if (!isset($sandbox[self::SANDBOX_KEY])) {
      $sandbox[self::SANDBOX_KEY] = $this->initializeState();
    }
    $state = &$sandbox[self::SANDBOX_KEY];

    $lines = [];
    $remaining = $batch_size;
    while ($remaining > 0 && !empty($state['queue'])) {
      $store = $state['queue'][0];
      $storage = $this->entityTypeManager->getStorage($store['entity_type_id']);
      $ids = array_values($storage->getQuery()
        ->accessCheck(FALSE)
        ->condition($store['id_key'], $store['last_id'], '>')
        ->sort($store['id_key'])
        ->range(0, $remaining)
        ->execute());
      if ($ids === []) {
        array_shift($state['queue']);
        continue;
      }
      $state['queue'][0]['last_id'] = end($ids);
      foreach ($storage->loadMultiple($ids) as $host) {
        assert($host instanceof FieldableEntityInterface);
        $refusals = $this->refusals($host, $store['field_names']);
        if ($refusals !== []) {
          $state['refused_hosts']++;
          array_push($lines, ...$refusals);
        }
      }
      // Keep the static entity cache from growing across a long read.
      $storage->resetCache();
      $state['processed'] += count($ids);
      $remaining -= count($ids);
    }

    $state['refusals'] += count($lines);
    if (empty($state['queue'])) {
      $sandbox['#finished'] = 1;
    }
    else {
      $sandbox['#finished'] = $state['total'] > 0 ? min($state['processed'] / $state['total'], 0.99) : 0.99;
    }
    return $lines;
  }

  /**
   * The totals of a report run so far.
   *
   * @param array $sandbox
   *   The sandbox report() has been filling.
   *
   * @return int[]
   *   The hosts read, the hosts with at least one refusal, and the
   *   refusals, keyed "hosts", "refused_hosts" and "refusals".
   */
  public function totals(array $sandbox): array {
    $state = $sandbox[self::SANDBOX_KEY] ?? [];
    return [
      'hosts' => (int) ($state['processed'] ?? 0),
      'refused_hosts' => (int) ($state['refused_hosts'] ?? 0),
      'refusals' => (int) ($state['refusals'] ?? 0),
    ];
  }

  /**
   * Builds the initial state: one queue entry per host entity type.
   *
   * A host is an entity carrying a style map field. A variant's styles
   * and a skin's defaults are other field types and other stores.
   */
  protected function initializeState(): array {
    $queue = [];
    $total = 0;
    foreach ($this->entityFieldManager->getFieldMapByFieldType('daedalus_stylish_style_map') as $entity_type_id => $fields) {
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      $count = (int) $storage->getQuery()->accessCheck(FALSE)->count()->execute();
      if ($count === 0) {
        continue;
      }
      $total += $count;
      $queue[] = [
        'entity_type_id' => $entity_type_id,
        'field_names' => array_keys($fields),
        'id_key' => $this->entityTypeManager->getDefinition($entity_type_id)->getKey('id'),
        'last_id' => 0,
      ];
    }
    return [
      'queue' => $queue,
      'total' => $total,
      'processed' => 0,
      'refused_hosts' => 0,
      'refusals' => 0,
    ];
  }

  /**
   * The stored styles refusals on one host, logged as they are found.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $host
   *   The host, at its current revision.
   * @param string[] $field_names
   *   The style map fields its entity type carries.
   *
   * @return string[]
   *   One line per refusal: the host, then the constraint's message,
   *   which opens with the path inside the map when the refusal has one.
   */
  protected function refusals(FieldableEntityInterface $host, array $field_names): array {
    $lines = [];
    foreach ($field_names as $field_name) {
      // The map field is per bundle: a bundle without styling has none.
      if (!$host->hasField($field_name)) {
        continue;
      }
      foreach ($host->get($field_name)->validate() as $violation) {
        if (!$violation->getConstraint() instanceof StoredStylesConstraint) {
          continue;
        }
        $line = sprintf('%s:%s ("%s"), %s: %s', $host->getEntityTypeId(), $host->id(), (string) $host->label(), $field_name, PlainTextOutput::renderFromHtml($violation->getMessage()));
        $lines[] = $line;
        // The line is the message, as the convergences log theirs: it
        // holds stored keys, never a placeholder to fill.
        $this->loggerFactory->get('daedalus_stylish')->warning('The stored styles validation refuses ' . $line);
      }
    }
    return $lines;
  }

}
