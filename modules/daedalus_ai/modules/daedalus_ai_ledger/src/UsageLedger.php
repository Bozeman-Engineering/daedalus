<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_ledger;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Event\AiProviderResponseBaseEvent;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\daedalus_ai\Provider\Usage;
use Drupal\daedalus_ai\Provider\UsageReader;
use Drupal\daedalus_ai_ledger\Entity\AiUsage;

/**
 * The rows: writes one per leg from a response event, reads them back.
 *
 * Three readers depend on it: the caps sum the site's rows or a user's
 * over a window (legsForSite(), legsForUser(): a plain select of created
 * and cost, since a month of legs is thousands of rows and loading
 * entities for a sum would be waste), the segment window reads a
 * conversation's newest row for the last prompt size (newestLeg()), and
 * the status report reads what became
 * of the cached prompt prefix, and how many conversations were rebuilt
 * from the visible chat, over the last REPORT_WINDOW seconds
 * (prefixReport(), one aggregate select in the same style), and which
 * models ran legs in that window with no price row of their own
 * (modelsWithoutRow(), one grouped select filtered through the resolver,
 * so the answer is what the table says today, not what it said when the
 * row was written). The retention purge deletes the rows before a moment
 * through the storage (deleteBefore()), the one road a row leaves by.
 */
final class UsageLedger {

  /**
   * How far back the status report reads, in seconds: seven days.
   *
   * Long enough that a weekend's drift is still visible on Monday, short
   * enough that a fixed break clears on its own without anyone resetting
   * anything.
   */
  public const REPORT_WINDOW = 7 * 86400;

  /**
   * The request tag that names the conversation: ai_agents' runner id.
   */
  public const RUNNER_TAG_PREFIX = 'ai_agents_runner_';

  /**
   * The request tag that names the segment the leg ran in.
   */
  public const SEGMENT_TAG_PREFIX = 'daedalus_ai_segment_';

  /**
   * The request tag the runner sets on a leg whose prompt prefix changed.
   *
   * The runner hashes the system prompt and the tool list per leg; when
   * the hash differs from the segment's previous leg, the provider cache
   * is rewritten from the top, and this tag says so on the row.
   */
  public const PREFIX_DRIFT_TAG = 'daedalus_ai_prefix_drift';

  /**
   * The request tag the runner sets on the first leg of a rebuilt record.
   *
   * A fresh start that replays earlier messages of the visible chat is a
   * conversation whose record expired (a week idle) or could not be read:
   * the model continues from the user-visible text alone, with every tool
   * exchange, print and carry-over forgotten. The row keeps it as
   * `rebuilt`, and the status report counts the conversations; it is the
   * one measurement of the "AI forgot" experience, data to watch rather
   * than a warning, since the expiry rebuild is legitimate.
   */
  public const REBUILT_TAG = 'daedalus_ai_rebuilt';

  /**
   * The prefix loss, in tokens, under which a leg counts as a full hit.
   *
   * A leg's cached read sits a few tokens under the previous leg's prompt
   * size even when the prefix held (d shows a steady 2 to 4 token gap, the
   * message framing, not a miss); a difference under this allowance is
   * recorded as zero.
   */
  public const PREFIX_JITTER = 32;

  /**
   * How many rows one storage delete takes during a purge.
   *
   * A row is under 1 KB and carries no references, so a batch this size
   * loads and deletes in well under a second while the entity hooks still
   * fire per row.
   */
  public const PURGE_BATCH = 500;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly UsageReader $usageReader,
    private readonly CostResolver $costResolver,
    private readonly AiProviderPluginManager $providerManager,
    private readonly AccountInterface $currentUser,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Records the leg a response event describes.
   *
   * The provider is instantiated to read a reported cost off its
   * ReportedCostInterface, when it declares one; the row runs as the
   * current user, which is who the request ran as.
   *
   * @param \Drupal\ai\Event\AiProviderResponseBaseEvent $event
   *   The post-response or post-streaming event.
   *
   * @return \Drupal\daedalus_ai_ledger\Entity\AiUsage
   *   The saved row.
   */
  public function record(AiProviderResponseBaseEvent $event): AiUsage {
    $output = $event->getOutput();
    $provider_id = $event->getProviderId();
    $provider = $this->providerManager->createInstance($provider_id);
    $usage = $this->usageReader->read($output, $provider);
    $model = $event->getModelId();
    $tags = $event->getTags();
    $conversation = self::tagValue($tags, self::RUNNER_TAG_PREFIX);
    $segment = self::tagValue($tags, self::SEGMENT_TAG_PREFIX);
    // The predecessor is read before this row lands, so it is the leg
    // before this one.
    $previous = $conversation === NULL ? NULL : $this->newestLeg($conversation);

    $values = [
      'uid' => $this->currentUser->id(),
      'created' => $this->time->getRequestTime(),
      'request_id' => $event->getRequestThreadId(),
      'conversation' => $conversation,
      'segment' => $segment,
      'provider' => $provider_id,
      'model' => $model,
      'operation_type' => $event->getOperationType(),
      'reported_cost' => $usage->reportedCostUsd,
      'table_cost' => $this->costResolver->tableCost($usage, $model),
      'cost' => $this->costResolver->resolve($usage, $model),
      'prefix_drift' => in_array(self::PREFIX_DRIFT_TAG, $tags, TRUE),
      'prefix_loss' => self::prefixLoss($previous, $segment, $provider_id, $model, $usage),
      'rebuilt' => in_array(self::REBUILT_TAG, $tags, TRUE),
    ];
    foreach (AiUsage::TOKEN_COLUMNS as $field => $column) {
      $values[$column] = $usage->$field;
    }

    /** @var \Drupal\daedalus_ai_ledger\Entity\AiUsage $row */
    $row = $this->storage()->create($values);
    $row->save();
    return $row;
  }

  /**
   * A user's legs since a moment, as the window math takes them.
   *
   * @param int $uid
   *   The user.
   * @param int $since
   *   The oldest created time to include (exclusive).
   *
   * @return list<array{created: int, cost: float|null}>
   *   The legs, oldest first.
   */
  public function legsForUser(int $uid, int $since): array {
    return $this->legs($since, $uid);
  }

  /**
   * The site's legs since a moment, every user's, for the window math.
   *
   * @param int $since
   *   The oldest created time to include (exclusive).
   *
   * @return list<array{created: int, cost: float|null}>
   *   The legs, oldest first.
   */
  public function legsForSite(int $since): array {
    return $this->legs($since, NULL);
  }

  /**
   * The {created, cost} pairs since a moment, for one user or for everyone.
   *
   * @param int $since
   *   The oldest created time to include (exclusive).
   * @param int|null $uid
   *   The user, or NULL for every user's legs.
   *
   * @return list<array{created: int, cost: float|null}>
   *   The legs, oldest first.
   */
  private function legs(int $since, ?int $uid): array {
    $table = $this->entityTypeManager->getDefinition('ai_usage')->getBaseTable();
    $query = $this->database->select($table, 'u')
      ->fields('u', ['created', 'cost'])
      ->condition('created', $since, '>')
      ->orderBy('created');
    if ($uid !== NULL) {
      $query->condition('uid', $uid);
    }
    $rows = $query->execute()->fetchAll();
    $legs = [];
    foreach ($rows as $row) {
      $legs[] = [
        'created' => (int) $row->created,
        'cost' => $row->cost === NULL ? NULL : (float) $row->cost,
      ];
    }
    return $legs;
  }

  /**
   * What became of the cached prompt prefix on the legs since a moment.
   *
   * One aggregate select over the rows (never loaded entities: a week of
   * legs is thousands of rows): legs measured (a non-NULL prefix loss),
   * legs the runner tagged as drifted, distinct drifted conversations,
   * tokens re-sent on drifted legs, tokens re-sent on undrifted legs, and
   * distinct conversations with a leg the runner tagged as rebuilt.
   * A second, grouped select names up to PrefixReport::CONVERSATION_SAMPLE
   * of the drifted conversations, newest drift first, and runs only when
   * there is drift to name.
   *
   * @param int $since
   *   The oldest created time to include (exclusive).
   */
  public function prefixReport(int $since): PrefixReport {
    $table = $this->entityTypeManager->getDefinition('ai_usage')->getBaseTable();
    $query = $this->database->select($table, 'u');
    $query->addExpression('COUNT(u.prefix_loss)', 'measured');
    $query->addExpression('SUM(CASE WHEN u.prefix_drift = 1 THEN 1 ELSE 0 END)', 'drifted');
    $query->addExpression('COUNT(DISTINCT CASE WHEN u.prefix_drift = 1 THEN u.conversation END)', 'conversations');
    $query->addExpression('SUM(CASE WHEN u.prefix_drift = 1 THEN u.prefix_loss ELSE 0 END)', 'drifted_tokens');
    $query->addExpression('SUM(CASE WHEN u.prefix_drift = 1 THEN 0 ELSE u.prefix_loss END)', 'expiry_tokens');
    $query->addExpression('COUNT(DISTINCT CASE WHEN u.rebuilt = 1 THEN u.conversation END)', 'rebuilt_conversations');
    $query->condition('u.created', $since, '>');
    $totals = $query->execute()->fetchAssoc();

    $ids = [];
    if ((int) $totals['drifted'] > 0) {
      $sample = $this->database->select($table, 'u')
        ->fields('u', ['conversation'])
        ->condition('u.created', $since, '>')
        ->condition('u.prefix_drift', 1)
        ->isNotNull('u.conversation')
        ->groupBy('u.conversation')
        ->range(0, PrefixReport::CONVERSATION_SAMPLE);
      $sample->addExpression('MAX(u.created)', 'newest');
      $sample->addExpression('MAX(u.id)', 'newest_id');
      $sample->orderBy('newest', 'DESC');
      $sample->orderBy('newest_id', 'DESC');
      $ids = array_values(array_map('strval', $sample->execute()->fetchCol()));
    }

    return new PrefixReport(
      measuredLegs: (int) $totals['measured'],
      driftedLegs: (int) $totals['drifted'],
      driftedConversations: (int) $totals['conversations'],
      driftedConversationIds: $ids,
      driftedTokens: (int) $totals['drifted_tokens'],
      expiryTokens: (int) $totals['expiry_tokens'],
      rebuiltConversations: (int) $totals['rebuilt_conversations'],
    );
  }

  /**
   * How many legs landed since a moment, whatever their model or price.
   *
   * @param int $since
   *   The oldest created time to include (exclusive).
   */
  public function legCount(int $since): int {
    $table = $this->entityTypeManager->getDefinition('ai_usage')->getBaseTable();
    $query = $this->database->select($table, 'u');
    $query->addExpression('COUNT(u.id)', 'legs');
    $query->condition('u.created', $since, '>');
    return (int) $query->execute()->fetchField();
  }

  /**
   * The models that ran legs since a moment with no price row of their own.
   *
   * One grouped select over the rows (model, legs, newest leg), filtered
   * through CostResolver::pricedBy() to the models it does not answer with
   * their own id: those priced by the fallback row and those priced by
   * nothing. The filter runs against the table as it is now, so a model
   * drops off this list the moment its row is added; a flag stamped on
   * the row at write time would keep saying fallback after that.
   *
   * @param int $since
   *   The oldest created time to include (exclusive).
   *
   * @return array<string, int>
   *   Legs per model id, the model seen most recently first.
   */
  public function modelsWithoutRow(int $since): array {
    $table = $this->entityTypeManager->getDefinition('ai_usage')->getBaseTable();
    $query = $this->database->select($table, 'u')
      ->fields('u', ['model'])
      ->condition('u.created', $since, '>')
      ->groupBy('u.model');
    $query->addExpression('COUNT(u.id)', 'legs');
    $query->addExpression('MAX(u.created)', 'newest');
    $query->addExpression('MAX(u.id)', 'newest_id');
    $query->orderBy('newest', 'DESC');
    $query->orderBy('newest_id', 'DESC');
    $models = [];
    foreach ($query->execute()->fetchAll() as $row) {
      $model = (string) $row->model;
      if ($this->costResolver->pricedBy($model) !== $model) {
        $models[$model] = (int) $row->legs;
      }
    }
    return $models;
  }

  /**
   * A conversation's newest row, or NULL before its first leg.
   *
   * @param string $conversation
   *   The agent runner id.
   */
  public function newestLeg(string $conversation): ?AiUsage {
    $ids = $this->storage()->getQuery()
      ->accessCheck(FALSE)
      ->condition('conversation', $conversation)
      ->sort('created', 'DESC')
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      return NULL;
    }
    /** @var \Drupal\daedalus_ai_ledger\Entity\AiUsage $row */
    $row = $this->storage()->load(reset($ids));
    return $row;
  }

  /**
   * Deletes the rows recorded before a moment, oldest first.
   *
   * Through the entity storage, PURGE_BATCH rows at a time, so the entity
   * hooks fire for every row and no single delete loads more than a batch;
   * the query rides the created index. Stops at the limit, so a caller
   * running on cron bounds one run's work and takes the rest next time.
   *
   * @param int $cutoff
   *   The moment; a row created before it (exclusive) goes.
   * @param int $limit
   *   The most rows to delete in this call.
   *
   * @return int
   *   How many rows were deleted.
   */
  public function deleteBefore(int $cutoff, int $limit): int {
    $storage = $this->storage();
    $deleted = 0;
    while ($deleted < $limit) {
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('created', $cutoff, '<')
        ->sort('created')
        ->sort('id')
        ->range(0, min(self::PURGE_BATCH, $limit - $deleted))
        ->execute();
      if ($ids === []) {
        break;
      }
      $storage->delete($storage->loadMultiple($ids));
      $deleted += count($ids);
    }
    return $deleted;
  }

  /**
   * How many cached tokens the previous leg held that this leg re-sent.
   *
   * The previous leg's billed prompt size (input plus cached plus written)
   * is what the provider cached for it; a leg that follows it in the same
   * segment reads that much from cache when the prefix held, so the
   * difference is what the cache lost, whatever the cause (drift, which
   * the tag names, or the TTL running out between turns). A difference
   * under the jitter allowance is zero. NULL when nothing can be measured:
   * no predecessor in the same segment, a count unreported on either
   * side, or a predecessor on another provider or model (a model switch
   * re-writes the conversation's cache once, which is neither drift nor
   * expiry; the provider caches are per model).
   *
   * @param \Drupal\daedalus_ai_ledger\Entity\AiUsage|null $previous
   *   The conversation's newest row before this leg, or NULL.
   * @param string|null $segment
   *   The segment this leg ran in, or NULL when untagged.
   * @param string $provider_id
   *   The provider this leg ran on.
   * @param string $model
   *   The model this leg ran on.
   * @param \Drupal\daedalus_ai\Provider\Usage $usage
   *   This leg's usage.
   */
  private static function prefixLoss(?AiUsage $previous, ?string $segment, string $provider_id, string $model, Usage $usage): ?int {
    if ($previous === NULL || $segment === NULL || $previous->segment() !== $segment) {
      return NULL;
    }
    if ($previous->provider() !== $provider_id || $previous->model() !== $model) {
      return NULL;
    }
    $cached = $previous->promptTokens();
    if ($cached === NULL || $usage->cachedRead === NULL) {
      return NULL;
    }
    $loss = $cached - $usage->cachedRead;
    return $loss < self::PREFIX_JITTER ? 0 : $loss;
  }

  /**
   * The value a prefixed request tag carries, or NULL when absent.
   *
   * @param string[] $tags
   *   The request tags.
   * @param string $prefix
   *   The tag prefix.
   */
  private static function tagValue(array $tags, string $prefix): ?string {
    foreach ($tags as $tag) {
      if (str_starts_with($tag, $prefix) && strlen($tag) > strlen($prefix)) {
        return substr($tag, strlen($prefix));
      }
    }
    return NULL;
  }

  /**
   * The ai_usage storage.
   */
  private function storage() {
    return $this->entityTypeManager->getStorage('ai_usage');
  }

}
