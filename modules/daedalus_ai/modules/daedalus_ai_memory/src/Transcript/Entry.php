<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_memory\Transcript;

use Drupal\daedalus_ai\Context\Block;

/**
 * One entry of a segment, typed with what its type carries.
 *
 * An entry is a user turn, an assistant turn, a tool result or a system
 * instruction. A user entry carries the user's words and the per-turn
 * blocks beside them, and says whether it is the user's own turn or a user
 * message the wrapper interjected mid-turn (a screenshot message), since a
 * segment's current turn starts at the user's; an assistant entry its
 * text, tool calls and the opaque per-provider slot (thinking blocks,
 * encrypted reasoning: the spine never reads inside it) together with the
 * pair that produced it; a tool result its text, the id of the call it
 * answers and the key of the fact it states; a system entry its text. The
 * transcript holds no file bytes: a user message's files are delivered on
 * the leg they arrive and the ATTACHMENTS block names them from then on.
 *
 * The opaque pair (`provider__model`, the provider plugin id and the model
 * id of the leg that answered the content) is kept because the content is
 * bound to the model that produced it: a thinking signature is signed by
 * its model, and replayed under another model it is dropped on Anthropic
 * (under the binding beta) and undefined elsewhere. The renderer replays
 * an entry's opaque content only when the pair equals the one the leg
 * will run on, so a user's model switch costs one cache rewrite, never a
 * degraded leg. An entry with content but no pair (a record written
 * before the pair was kept) is never replayed.
 *
 * Entries are immutable and append-only in their segment: nothing sent to
 * a provider is ever edited, stripped or reordered.
 */
final class Entry {

  /**
   * Constructs an entry; use the named constructors.
   *
   * @param \Drupal\daedalus_ai_memory\Transcript\EntryType $type
   *   The type.
   * @param string $text
   *   The text.
   * @param \Drupal\daedalus_ai\Context\Block[] $blocks
   *   The per-turn blocks (user entries only).
   * @param \Drupal\daedalus_ai_memory\Transcript\ToolCall[] $toolCalls
   *   The tool calls (assistant entries only).
   * @param array|null $opaque
   *   The opaque provider content to replay (assistant entries only).
   * @param string|null $opaquePair
   *   The `provider__model` pair that produced the opaque content
   *   (assistant entries only; NULL without content, or for content kept
   *   before the pair was recorded, which is never replayed).
   * @param string|null $toolId
   *   The id of the call answered (tool result entries only).
   * @param string|null $key
   *   The key of the fact stated (tool result entries only).
   * @param bool $turn
   *   Whether the entry is the user's own turn (user entries only; FALSE
   *   for a user message the wrapper interjected mid-turn).
   */
  private function __construct(
    public readonly EntryType $type,
    public readonly string $text,
    public readonly array $blocks = [],
    public readonly array $toolCalls = [],
    public readonly ?array $opaque = NULL,
    public readonly ?string $opaquePair = NULL,
    public readonly ?string $toolId = NULL,
    public readonly ?string $key = NULL,
    public readonly bool $turn = TRUE,
  ) {
  }

  /**
   * A user turn, or a user message the wrapper interjected mid-turn.
   *
   * @param string $text
   *   The user's words.
   * @param \Drupal\daedalus_ai\Context\Block[] $blocks
   *   The per-turn blocks stated beside them.
   * @param bool $turn
   *   TRUE for the user's own turn (the default); FALSE for a user-role
   *   message the wrapper appended inside a turn, such as the screenshots
   *   message it adds after a tool returned images.
   */
  public static function user(string $text, array $blocks = [], bool $turn = TRUE): self {
    foreach ($blocks as $block) {
      if (!$block instanceof Block) {
        throw new \InvalidArgumentException('A user entry takes Block objects.');
      }
    }
    return new self(EntryType::User, $text, blocks: array_values($blocks), turn: $turn);
  }

  /**
   * An assistant turn.
   *
   * @param string $text
   *   The reply text.
   * @param \Drupal\daedalus_ai_memory\Transcript\ToolCall[] $toolCalls
   *   The calls the turn made.
   * @param array|null $opaque
   *   The provider-native content to replay with the turn, or NULL.
   * @param string|null $opaquePair
   *   The pair that produced the content, as pairOf() spells it, or NULL
   *   (content with no pair is kept but never replayed).
   *
   * @throws \InvalidArgumentException
   *   When a pair is given without content, or the pair is empty.
   */
  public static function assistant(string $text, array $toolCalls = [], ?array $opaque = NULL, ?string $opaquePair = NULL): self {
    foreach ($toolCalls as $call) {
      if (!$call instanceof ToolCall) {
        throw new \InvalidArgumentException('An assistant entry takes ToolCall objects.');
      }
    }
    if ($opaquePair !== NULL && $opaque === NULL) {
      throw new \InvalidArgumentException('An opaque pair names the content it binds; an assistant entry without opaque content takes none.');
    }
    if ($opaquePair === '') {
      throw new \InvalidArgumentException('An opaque pair must be a non-empty string or NULL.');
    }
    return new self(EntryType::Assistant, $text, toolCalls: array_values($toolCalls), opaque: $opaque, opaquePair: $opaquePair);
  }

  /**
   * The `provider__model` pair an assistant entry's opaque content is bound to.
   *
   * @param string $providerId
   *   The provider plugin id the leg ran on.
   * @param string $modelId
   *   The model id the leg ran on.
   */
  public static function pairOf(string $providerId, string $modelId): string {
    return $providerId . '__' . $modelId;
  }

  /**
   * A tool result.
   *
   * @param string $text
   *   The result text.
   * @param string|null $toolId
   *   The id of the call it answers, or NULL for a result the wrapper
   *   appended without one (a tool that returns directly).
   * @param string|null $key
   *   The key of the fact it states, or NULL.
   */
  public static function toolResult(string $text, ?string $toolId, ?string $key = NULL): self {
    return new self(EntryType::ToolResult, $text, toolId: $toolId, key: $key);
  }

  /**
   * A system instruction appended mid-conversation.
   */
  public static function system(string $text): self {
    return new self(EntryType::System, $text);
  }

  /**
   * The tool call with an id, or NULL when this entry made none such.
   */
  public function toolCall(string $id): ?ToolCall {
    foreach ($this->toolCalls as $call) {
      if ($call->id === $id) {
        return $call;
      }
    }
    return NULL;
  }

  /**
   * The entry as a serializable array; fromArray() reads it back.
   *
   * Only the keys the type carries are present.
   *
   * @return array<string, mixed>
   *   The entry.
   */
  public function toArray(): array {
    $values = ['type' => $this->type->value, 'text' => $this->text];
    switch ($this->type) {
      case EntryType::User:
        $values['blocks'] = array_map(static fn (Block $block): array => $block->toArray(), $this->blocks);
        $values['turn'] = $this->turn;
        break;

      case EntryType::Assistant:
        $values['tool_calls'] = array_map(static fn (ToolCall $call): array => $call->toArray(), $this->toolCalls);
        $values['opaque'] = $this->opaque;
        $values['opaque_pair'] = $this->opaquePair;
        break;

      case EntryType::ToolResult:
        $values['tool_id'] = $this->toolId;
        $values['key'] = $this->key;
        break;

      case EntryType::System:
        break;
    }
    return $values;
  }

  /**
   * An entry from its array form.
   *
   * @param array $values
   *   What toArray() produced.
   *
   * @throws \InvalidArgumentException
   *   When the type is unknown or a carried value is malformed.
   */
  public static function fromArray(array $values): self {
    $type = EntryType::tryFrom(is_string($values['type'] ?? NULL) ? $values['type'] : '');
    if ($type === NULL) {
      throw new \InvalidArgumentException(sprintf('Unknown transcript entry type "%s".', is_scalar($values['type'] ?? NULL) ? (string) $values['type'] : gettype($values['type'] ?? NULL)));
    }
    if (!is_string($values['text'] ?? NULL)) {
      throw new \InvalidArgumentException('A transcript entry text must be a string.');
    }
    $text = $values['text'];
    switch ($type) {
      case EntryType::User:
        $turn = $values['turn'] ?? TRUE;
        if (!is_bool($turn)) {
          throw new \InvalidArgumentException('A transcript user entry turn flag must be a boolean.');
        }
        return self::user($text, array_map(
          static fn (mixed $block): Block => Block::fromArray(self::array($block, 'block')),
          self::array($values['blocks'] ?? [], 'blocks'),
        ), $turn);

      case EntryType::Assistant:
        $opaque = $values['opaque'] ?? NULL;
        if ($opaque !== NULL && !is_array($opaque)) {
          throw new \InvalidArgumentException('Transcript opaque content must be an array or NULL.');
        }
        return self::assistant($text, array_map(
          static fn (mixed $call): ToolCall => ToolCall::fromArray(self::array($call, 'tool call')),
          self::array($values['tool_calls'] ?? [], 'tool_calls'),
        ), $opaque, self::nullableString($values['opaque_pair'] ?? NULL, 'opaque_pair'));

      case EntryType::ToolResult:
        return self::toolResult($text, self::nullableString($values['tool_id'] ?? NULL, 'tool_id'), self::nullableString($values['key'] ?? NULL, 'key'));

      case EntryType::System:
        return self::system($text);
    }
  }

  /**
   * Reads an array from the array form.
   */
  private static function array(mixed $value, string $what): array {
    if (!is_array($value)) {
      throw new \InvalidArgumentException(sprintf('A transcript entry %s must be an array.', $what));
    }
    return $value;
  }

  /**
   * Reads an optional string from the array form.
   */
  private static function nullableString(mixed $value, string $key): ?string {
    if ($value !== NULL && !is_string($value)) {
      throw new \InvalidArgumentException(sprintf('A transcript entry %s must be a string or NULL.', $key));
    }
    return $value;
  }

}
