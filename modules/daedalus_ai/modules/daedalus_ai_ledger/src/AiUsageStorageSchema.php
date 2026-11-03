<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_ledger;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Indexes the usage table for its reads.
 *
 * The caps sum a user's rows over a window (uid, created) and the segment
 * window reads a conversation's newest row (conversation, created); both
 * run before every turn, so both get an index. The site windows, the
 * status report and the retention purge read by created alone (a site
 * window sums every user's rows since a moment; the purge deletes every
 * row before one), which neither composite index serves, so created has
 * its own.
 */
final class AiUsageStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE): array {
    $schema = parent::getEntitySchema($entity_type, $reset);
    $table = $entity_type->getBaseTable();
    $schema[$table]['indexes'] += [
      'ai_usage__user_window' => ['uid', 'created'],
      'ai_usage__conversation' => ['conversation', 'created'],
      'ai_usage__created' => ['created'],
    ];
    return $schema;
  }

}
