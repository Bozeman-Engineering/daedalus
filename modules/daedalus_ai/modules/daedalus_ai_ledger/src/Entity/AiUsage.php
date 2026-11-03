<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_ledger\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_ai\Provider\Usage;
use Drupal\daedalus_ai_ledger\AiUsageStorageSchema;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;
use Drupal\views\EntityViewsData;

/**
 * One LLM leg as the ledger counts it.
 *
 * A row per provider call: who ran it (uid), which conversation (the agent
 * runner id from the request tags) and segment, the provider and model,
 * every token count the UsageReader could read (NULL where no source
 * reported one, never 0 by assumption), the cost the provider reported
 * when it reports one, the cost the price table computes, and the cost the
 * caps count (reported first, table second), and what became of the cached
 * prompt prefix: whether the runner saw it drift on this leg, and how many
 * tokens the previous leg of the same segment had cached that this leg
 * re-sent; and whether the leg was the first of a record rebuilt from the
 * visible chat (the agent's record had expired or could not be read).
 * Nothing here is estimated: the counts are the provider's own accounting,
 * and the loss is arithmetic over them.
 */
#[ContentEntityType(
  id: 'ai_usage',
  label: new TranslatableMarkup('AI usage'),
  label_collection: new TranslatableMarkup('AI usage'),
  label_singular: new TranslatableMarkup('AI usage row'),
  label_plural: new TranslatableMarkup('AI usage rows'),
  render_cache: FALSE,
  persistent_cache: FALSE,
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'owner' => 'uid',
  ],
  handlers: [
    'storage_schema' => AiUsageStorageSchema::class,
    'views_data' => EntityViewsData::class,
  ],
  admin_permission: 'administer ai usage ledger',
  base_table: 'ai_usage',
  internal: TRUE,
)]
final class AiUsage extends ContentEntityBase implements EntityOwnerInterface {

  use EntityOwnerTrait;

  /**
   * The token count columns, by Usage field name.
   */
  public const TOKEN_COLUMNS = [
    'input' => 'input',
    'cachedRead' => 'cached_read',
    'cacheWrite' => 'cache_write',
    'cacheWrite5m' => 'cache_write_5m',
    'cacheWrite1h' => 'cache_write_1h',
    'output' => 'output',
    'reasoning' => 'reasoning',
  ];

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);
    $uid = $fields['uid'];
    assert($uid instanceof BaseFieldDefinition);
    $uid->setLabel(new TranslatableMarkup('User'))
      ->setDescription(new TranslatableMarkup('The user the leg ran as.'));

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Recorded'))
      ->setDescription(new TranslatableMarkup('When the leg finished.'));

    $fields['request_id'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Request id'))
      ->setDescription(new TranslatableMarkup('The request thread id drupal/ai gave the leg.'))
      ->setSetting('max_length', 128);

    $fields['conversation'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Conversation'))
      ->setDescription(new TranslatableMarkup('The agent runner id, from the request tags; empty for a call outside a conversation.'))
      ->setSetting('max_length', 255);

    $fields['segment'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Segment'))
      ->setDescription(new TranslatableMarkup('The conversation segment the leg ran in, from the request tags.'))
      ->setSetting('max_length', 64);

    $fields['provider'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Provider'))
      ->setSetting('max_length', 128)
      ->setRequired(TRUE);

    $fields['model'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Model'))
      ->setSetting('max_length', 255)
      ->setRequired(TRUE);

    $fields['operation_type'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Operation type'))
      ->setSetting('max_length', 64)
      ->setRequired(TRUE);

    $labels = [
      'input' => new TranslatableMarkup('Input tokens'),
      'cached_read' => new TranslatableMarkup('Cache read tokens'),
      'cache_write' => new TranslatableMarkup('Cache write tokens'),
      'cache_write_5m' => new TranslatableMarkup('Cache write tokens, 5 minute TTL'),
      'cache_write_1h' => new TranslatableMarkup('Cache write tokens, 1 hour TTL'),
      'output' => new TranslatableMarkup('Output tokens'),
      'reasoning' => new TranslatableMarkup('Reasoning tokens'),
    ];
    foreach ($labels as $column => $label) {
      $fields[$column] = BaseFieldDefinition::create('integer')
        ->setLabel($label)
        ->setSetting('unsigned', TRUE)
        ->setSetting('size', 'big');
    }

    $fields['reported_cost'] = BaseFieldDefinition::create('float')
      ->setLabel(new TranslatableMarkup('Reported cost (USD)'))
      ->setDescription(new TranslatableMarkup('The charge the provider reported for the leg, when it reports one.'));

    $fields['table_cost'] = BaseFieldDefinition::create('float')
      ->setLabel(new TranslatableMarkup('Table cost (USD)'))
      ->setDescription(new TranslatableMarkup('The cost the price table computes from the tokens; empty when the model is unpriced.'));

    $fields['cost'] = BaseFieldDefinition::create('float')
      ->setLabel(new TranslatableMarkup('Cost (USD)'))
      ->setDescription(new TranslatableMarkup('What the caps count: the reported cost, else the table cost.'));

    $fields += static::prefixFieldDefinitions();
    $fields['rebuilt'] = static::rebuiltFieldDefinition();

    return $fields;
  }

  /**
   * The two columns that record what happened to the cached prompt prefix.
   *
   * Kept apart from the rest so the update hook that adds them to a site
   * installed before them installs the same definitions the entity
   * declares; nothing here is a second copy.
   *
   * @return \Drupal\Core\Field\BaseFieldDefinition[]
   *   The definitions, by field name.
   */
  public static function prefixFieldDefinitions(): array {
    $fields = [];
    $fields['prefix_drift'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Prefix drift'))
      ->setDescription(new TranslatableMarkup('Whether the system prompt or the tool list changed between this leg and the one before it in the same segment, as the agent runner tagged it.'))
      ->setDefaultValue(FALSE);

    $fields['prefix_loss'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Prefix loss'))
      ->setDescription(new TranslatableMarkup('How many tokens the previous leg of the same segment had cached that this leg did not read from cache; empty when unmeasured.'))
      ->setSetting('unsigned', TRUE)
      ->setSetting('size', 'big');

    return $fields;
  }

  /**
   * The column that records a leg as the first of a rebuilt record.
   *
   * Kept apart for the same reason as prefixFieldDefinitions(): the update
   * hook that adds it to an older site installs this definition, not a
   * second copy.
   */
  public static function rebuiltFieldDefinition(): BaseFieldDefinition {
    return BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Rebuilt'))
      ->setDescription(new TranslatableMarkup("Whether this leg was the first of a conversation rebuilt from the visible chat, as the agent runner tagged it: the agent's record had expired or could not be read, and earlier messages were replayed."))
      ->setDefaultValue(FALSE);
  }

  /**
   * The usage the row records, in the reader's vocabulary.
   */
  public function usage(): Usage {
    $counts = [];
    foreach (self::TOKEN_COLUMNS as $field => $column) {
      $counts[$field] = $this->intOrNull($column);
    }
    return new Usage(
      input: $counts['input'],
      cachedRead: $counts['cachedRead'],
      cacheWrite: $counts['cacheWrite'],
      cacheWrite5m: $counts['cacheWrite5m'],
      cacheWrite1h: $counts['cacheWrite1h'],
      output: $counts['output'],
      reasoning: $counts['reasoning'],
      reportedCostUsd: $this->floatOrNull('reported_cost'),
    );
  }

  /**
   * The size of the prompt the leg was billed for, or NULL when unreported.
   */
  public function promptTokens(): ?int {
    return $this->usage()->promptTokens();
  }

  /**
   * The cost the caps count, or NULL when nothing priced the leg.
   */
  public function cost(): ?float {
    return $this->floatOrNull('cost');
  }

  /**
   * The agent runner id the leg belongs to, or NULL outside a conversation.
   */
  public function conversation(): ?string {
    return $this->stringOrNull('conversation');
  }

  /**
   * The segment the leg ran in, or NULL when untagged.
   */
  public function segment(): ?string {
    return $this->stringOrNull('segment');
  }

  /**
   * The provider plugin id the leg ran on.
   */
  public function provider(): string {
    return (string) $this->get('provider')->value;
  }

  /**
   * The model id the leg ran on.
   */
  public function model(): string {
    return (string) $this->get('model')->value;
  }

  /**
   * Whether the prompt prefix changed between this leg and its predecessor.
   */
  public function prefixDrifted(): bool {
    return (bool) $this->get('prefix_drift')->value;
  }

  /**
   * The tokens the leg re-sent that its predecessor had cached.
   *
   * NULL when unmeasured: no predecessor in the same segment, a count
   * unreported on either side, or a predecessor on another provider or
   * model.
   */
  public function prefixLoss(): ?int {
    return $this->intOrNull('prefix_loss');
  }

  /**
   * Whether the leg opened a record rebuilt from the visible chat.
   */
  public function rebuilt(): bool {
    return (bool) $this->get('rebuilt')->value;
  }

  /**
   * When the leg was recorded.
   */
  public function created(): int {
    return (int) $this->get('created')->value;
  }

  /**
   * A string column, or NULL when empty.
   */
  private function stringOrNull(string $column): ?string {
    $value = $this->get($column)->value;
    return $value === NULL || $value === '' ? NULL : (string) $value;
  }

  /**
   * An integer column, or NULL when unset.
   */
  private function intOrNull(string $column): ?int {
    $value = $this->get($column)->value;
    return $value === NULL || $value === '' ? NULL : (int) $value;
  }

  /**
   * A float column, or NULL when unset.
   */
  private function floatOrNull(string $column): ?float {
    $value = $this->get($column)->value;
    return $value === NULL || $value === '' ? NULL : (float) $value;
  }

}
