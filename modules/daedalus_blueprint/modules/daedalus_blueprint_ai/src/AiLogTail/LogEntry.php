<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai\AiLogTail;

/**
 * One ai_log row with its tags, as the tail reads it.
 */
final readonly class LogEntry {

  /**
   * The tag prefix ai_agents stamps with the agent runner id.
   *
   * The runner id is the transcript identity: a nested sub-agent shares the
   * ai_agents_thread_ tag with its caller but runs its own transcript, so
   * the thread tag would diverge on every row.
   */
  public const RUNNER_TAG_PREFIX = 'ai_agents_runner_';

  /**
   * Constructs a LogEntry.
   *
   * @param int $id
   *   The row id.
   * @param int $created
   *   The creation timestamp.
   * @param string $provider
   *   The provider id ('' when unrecorded).
   * @param string $model
   *   The model id ('' when unrecorded).
   * @param string $operationType
   *   The operation type ('' when unrecorded).
   * @param string $prompt
   *   The raw prompt column.
   * @param string|null $outputText
   *   The raw output_text column (JSON of the raw output, usually).
   * @param array $configuration
   *   The decoded configuration column; empty when unrecorded or not fetched.
   * @param array $extraData
   *   The decoded extra_data column; empty when unrecorded or not fetched.
   * @param string[] $tags
   *   The row's tags in delta order.
   */
  public function __construct(
    public int $id,
    public int $created,
    public string $provider,
    public string $model,
    public string $operationType,
    public string $prompt,
    public ?string $outputText,
    public array $configuration,
    public array $extraData,
    public array $tags,
  ) {}

  /**
   * Builds an entry from a database row and its tags.
   *
   * @param object $row
   *   A row from the ai_log table; configuration and extra_data are optional
   *   because the command only fetches them when a section needs them.
   * @param string[] $tags
   *   The row's tags.
   *
   * @return self
   *   The entry.
   */
  public static function fromRow(object $row, array $tags): self {
    return new self(
      id: (int) $row->id,
      created: (int) $row->created,
      provider: (string) ($row->provider ?? ''),
      model: (string) ($row->model ?? ''),
      operationType: (string) ($row->operation_type ?? ''),
      prompt: (string) ($row->prompt ?? ''),
      outputText: isset($row->output_text) ? (string) $row->output_text : NULL,
      configuration: self::decodeJsonColumn($row->configuration ?? NULL),
      extraData: self::decodeJsonColumn($row->extra_data ?? NULL),
      tags: array_values($tags),
    );
  }

  /**
   * The identity of the conversation this row belongs to.
   *
   * @return string|null
   *   The runner id from the ai_agents_runner_ tag, or NULL for a standalone
   *   row (text_to_image from a deferred processor, an ad-hoc chat).
   */
  public function threadKey(): ?string {
    foreach ($this->tags as $tag) {
      if (str_starts_with($tag, self::RUNNER_TAG_PREFIX) && strlen($tag) > strlen(self::RUNNER_TAG_PREFIX)) {
        return substr($tag, strlen(self::RUNNER_TAG_PREFIX));
      }
    }
    return NULL;
  }

  /**
   * The parsed prompt.
   *
   * @return \Drupal\daedalus_blueprint_ai\AiLogTail\Transcript
   *   The transcript.
   */
  public function transcript(): Transcript {
    return Transcript::parse($this->prompt);
  }

  /**
   * The output column as the tail reads it.
   *
   * The ai_logging writer stores json_encode() of the provider's raw
   * output, so the column is usually a JSON document; a plain string is
   * kept as it is.
   *
   * @return mixed
   *   The decoded document (an array, usually), the raw string when the
   *   column is not JSON, or NULL when nothing was recorded.
   */
  public function decodedOutput(): mixed {
    if ($this->outputText === NULL || $this->outputText === '') {
      return NULL;
    }
    return json_decode($this->outputText, TRUE) ?? $this->outputText;
  }

  /**
   * The system prompt the row recorded in its debug data.
   *
   * @return string
   *   The system prompt, '' when none was recorded or extras were not fetched.
   */
  public function systemPrompt(): string {
    $value = $this->extraData['system_prompt'] ?? '';
    return is_string($value) ? $value : '';
  }

  /**
   * The tool definitions the row recorded in its debug data.
   *
   * @return array
   *   The chat_tools array, empty when none were recorded.
   */
  public function chatTools(): array {
    $value = $this->extraData['chat_tools'] ?? [];
    return is_array($value) ? $value : [];
  }

  /**
   * Decodes a JSON column that ai_logging wrote with json_encode().
   *
   * @param string|null $value
   *   The column value.
   *
   * @return array
   *   The decoded array; empty for NULL, '' or a non-array document.
   */
  private static function decodeJsonColumn(?string $value): array {
    if ($value === NULL || $value === '') {
      return [];
    }
    $decoded = json_decode($value, TRUE, 512, JSON_THROW_ON_ERROR);
    return is_array($decoded) ? $decoded : [];
  }

}
