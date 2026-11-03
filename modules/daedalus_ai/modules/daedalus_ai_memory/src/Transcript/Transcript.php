<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_memory\Transcript;

use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\OutputInterface;
use Drupal\daedalus_ai\Provider\OpaqueContentInterface;
use Drupal\daedalus_ai\Provider\ProviderCapabilities;

/**
 * The provider-neutral, append-only model of a conversation's history.
 *
 * The history is the model-facing one, held as segments of typed entries.
 * The transcript is what the runner persists per conversation and what the
 * renderer turns into the wrapper's ChatMessage list per leg. The runner
 * opens a segment, appends the user turn with its blocks, renders, runs the
 * leg, and absorbs what the wrapper appended to its history (the tool
 * results of the previous call, any screenshot or reminder message the
 * wrapper added, the assistant turn) together with the opaque content the
 * provider answers for the leg's output. A segment closes by opening the
 * next one with a carry-over (and, on a reframe close, the current turn
 * re-sent); the closed one is kept whole in the record. The segment policy
 * (Drupal\daedalus_ai_memory\Segment) decides the close.
 *
 * Pure PHP: no service, no container. The persisted form is toArray().
 */
final class Transcript {

  /**
   * The record format; fromArray() refuses another.
   */
  public const VERSION = 1;

  /**
   * The result appended for a call that never ran.
   *
   * A provider refuses a user or assistant message right after a tool call
   * with no result, so a call the turn abandoned (the loop limit was hit
   * before it ran, or the user sent a new message first) is answered with
   * this line before the next entry lands. Append-only: the call stays as
   * it was sent, and the answer is new.
   */
  public const UNANSWERED_CALL = 'This call was not executed: the turn ended before it ran.';

  /**
   * The segments in order; the last is the current one.
   *
   * @var \Drupal\daedalus_ai_memory\Transcript\Segment[]
   */
  private array $segments = [];

  /**
   * Opens the next segment and makes it current.
   *
   * @param string|null $carryOver
   *   What the closing segment carried over, worded for the model, or NULL
   *   for the first segment.
   * @param \Drupal\daedalus_ai_memory\Transcript\Entry[] $resend
   *   Entries of the closing segment to re-send at the head of the new one,
   *   in order: on a reframe close, the current turn (Segment::currentTurn()),
   *   so the model keeps the turn it is in the middle of. The entries keep
   *   their keys and ids; nothing is rewritten.
   */
  public function openSegment(?string $carryOver = NULL, array $resend = []): Segment {
    foreach ($resend as $entry) {
      if (!$entry instanceof Entry) {
        throw new \InvalidArgumentException('A segment re-sends Entry objects.');
      }
    }
    $segment = new Segment(count($this->segments) + 1, $carryOver);
    foreach ($resend as $entry) {
      $segment->append($entry);
    }
    $this->segments[] = $segment;
    return $segment;
  }

  /**
   * Whether any segment was opened.
   */
  public function isEmpty(): bool {
    return $this->segments === [];
  }

  /**
   * The segments in order.
   *
   * @return \Drupal\daedalus_ai_memory\Transcript\Segment[]
   *   The segments.
   */
  public function segments(): array {
    return $this->segments;
  }

  /**
   * The current segment.
   *
   * @throws \LogicException
   *   When no segment was opened.
   */
  public function currentSegment(): Segment {
    if ($this->segments === []) {
      throw new \LogicException('The transcript has no open segment; open one before appending.');
    }
    return $this->segments[array_key_last($this->segments)];
  }

  /**
   * The current segment's entries in wire order.
   *
   * @return \Drupal\daedalus_ai_memory\Transcript\Entry[]
   *   The entries.
   */
  public function entries(): array {
    return $this->currentSegment()->entries();
  }

  /**
   * Appends a user turn with its per-turn blocks to the current segment.
   *
   * @param string $text
   *   The user's words.
   * @param \Drupal\daedalus_ai\Context\Block[] $blocks
   *   The blocks the producers stated for this turn.
   */
  public function appendUserTurn(string $text, array $blocks = []): Entry {
    $segment = $this->currentSegment();
    $this->settleUnansweredCalls($segment);
    $entry = Entry::user($text, $blocks);
    $segment->append($entry);
    return $entry;
  }

  /**
   * Appends a message of the user-visible chat replayed into a fresh start.
   *
   * When no record exists for a conversation the chat already holds (the
   * record could not be read back, or expired), the earlier messages are
   * replayed as plain entries so the model reads what the user can see; a
   * replayed user message carries no blocks and a replayed assistant
   * message no calls.
   *
   * @param string $role
   *   The chat message's role: user or assistant.
   * @param string $text
   *   Its text.
   *
   * @throws \InvalidArgumentException
   *   When the role is neither.
   */
  public function appendReplayed(string $role, string $text): Entry {
    $segment = $this->currentSegment();
    $entry = match (EntryType::fromRole($role)) {
      EntryType::User => Entry::user($text),
      EntryType::Assistant => Entry::assistant($text),
      default => throw new \InvalidArgumentException(sprintf('Only user and assistant messages are replayed, not "%s".', $role)),
    };
    $segment->append($entry);
    return $entry;
  }

  /**
   * Absorbs the messages the wrapper appended to its history during a leg.
   *
   * Each message becomes an entry of its role: a tool result is keyed from
   * the call it answers (found by id in the current segment), the last
   * assistant message takes the opaque content the provider answers for the
   * output, a user or system message the wrapper added is kept as text (a
   * screenshot message's bytes are not stored; they were delivered) and a
   * user message is marked an interjection, not the user's turn. The
   * opaque content is read only when the provider declares
   * OpaqueContentInterface; a provider without it replays nothing and the
   * entry stores nothing. Content that is stored is stamped with the pair
   * that produced it (the provider's plugin id and the model the leg ran
   * on), since it is bound to that model and the renderer replays it only
   * under the same pair.
   *
   * @param \Drupal\ai\OperationType\Chat\ChatMessage[] $messages
   *   The appended messages in history order.
   * @param \Drupal\ai\OperationType\OutputInterface|null $output
   *   The leg's output, whose assistant message is the last one appended.
   * @param object|null $provider
   *   The provider plugin (or its proxy) that produced the output.
   * @param string|null $modelId
   *   The model id the leg ran on; required when the provider answers
   *   opaque content, which is bound to it.
   *
   * @return \Drupal\daedalus_ai_memory\Transcript\Entry[]
   *   The entries appended, in order.
   *
   * @throws \InvalidArgumentException
   *   When a message is not a ChatMessage or has a role the transcript does
   *   not hold.
   * @throws \LogicException
   *   When the provider answered opaque content but no assistant message
   *   was appended to carry it, or no model id was given to bind it to.
   */
  public function absorb(array $messages, ?OutputInterface $output = NULL, ?object $provider = NULL, ?string $modelId = NULL): array {
    $segment = $this->currentSegment();
    $opaque = NULL;
    $opaque_pair = NULL;
    if ($output !== NULL && $provider !== NULL) {
      $opaque = ProviderCapabilities::as($provider, OpaqueContentInterface::class)?->opaqueContentOf($output);
    }
    if ($opaque !== NULL) {
      if ($modelId === NULL) {
        throw new \LogicException('The provider answered opaque content but no model id was given to bind it to.');
      }
      $opaque_pair = Entry::pairOf((string) $provider->getPluginId(), $modelId);
    }

    $last_assistant = NULL;
    foreach ($messages as $index => $message) {
      if (!$message instanceof ChatMessage) {
        throw new \InvalidArgumentException('A transcript absorbs ChatMessage objects.');
      }
      if ($message->getRole() === EntryType::Assistant->role()) {
        $last_assistant = $index;
      }
    }
    if ($opaque !== NULL && $last_assistant === NULL) {
      throw new \LogicException('The provider answered opaque content for a leg that appended no assistant message to carry it.');
    }

    $absorbed = [];
    foreach ($messages as $index => $message) {
      $type = EntryType::fromRole($message->getRole());
      if ($type !== EntryType::ToolResult) {
        array_push($absorbed, ...$this->settleUnansweredCalls($segment));
      }
      $entry = match ($type) {
        EntryType::User => Entry::user($message->getText(), [], FALSE),
        EntryType::System => Entry::system($message->getText()),
        EntryType::Assistant => Entry::assistant(
          $message->getText(),
          array_map([ToolCall::class, 'fromFunctionOutput'], $message->getTools() ?? []),
          $index === $last_assistant ? $opaque : NULL,
          $index === $last_assistant ? $opaque_pair : NULL,
        ),
        EntryType::ToolResult => Entry::toolResult(
          $message->getText(),
          $message->getToolsId(),
          $this->keyFor($segment, $message->getToolsId()),
        ),
      };
      $segment->append($entry);
      $absorbed[] = $entry;
    }
    return $absorbed;
  }

  /**
   * The keys of the facts loaded in the current segment, in load order.
   *
   * @return string[]
   *   The keys.
   */
  public function loadedKeys(): array {
    return array_keys($this->currentSegment()->resultsByKey());
  }

  /**
   * The transcript as a serializable array; fromArray() reads it back.
   *
   * @return array{version: int, segments: array[]}
   *   The record.
   */
  public function toArray(): array {
    return [
      'version' => self::VERSION,
      'segments' => array_map(static fn (Segment $segment): array => $segment->toArray(), $this->segments),
    ];
  }

  /**
   * A transcript from its persisted record.
   *
   * @param array $values
   *   What toArray() produced.
   *
   * @throws \InvalidArgumentException
   *   When the version is another or a segment is malformed, or the
   *   segments are not numbered 1..n in order.
   */
  public static function fromArray(array $values): self {
    if (($values['version'] ?? NULL) !== self::VERSION) {
      throw new \InvalidArgumentException(sprintf('Unknown transcript record version %s; this code reads version %d.', is_scalar($values['version'] ?? NULL) ? (string) $values['version'] : gettype($values['version'] ?? NULL), self::VERSION));
    }
    if (!is_array($values['segments'] ?? NULL)) {
      throw new \InvalidArgumentException('A transcript record needs a segments array.');
    }
    $transcript = new self();
    foreach (array_values($values['segments']) as $position => $segment) {
      if (!is_array($segment)) {
        throw new \InvalidArgumentException('A transcript segment must be an array.');
      }
      $segment = Segment::fromArray($segment);
      if ($segment->number() !== $position + 1) {
        throw new \InvalidArgumentException(sprintf('Transcript segment %d sits at position %d; segments are numbered in order from 1.', $segment->number(), $position + 1));
      }
      $transcript->segments[] = $segment;
    }
    return $transcript;
  }

  /**
   * Answers the segment's open calls with the unanswered line.
   *
   * @return \Drupal\daedalus_ai_memory\Transcript\Entry[]
   *   The result entries appended, none when no call was open.
   */
  private function settleUnansweredCalls(Segment $segment): array {
    $settled = [];
    foreach ($segment->unansweredCalls() as $call) {
      $entry = Entry::toolResult(self::UNANSWERED_CALL, $call->id);
      $segment->append($entry);
      $settled[] = $entry;
    }
    return $settled;
  }

  /**
   * The key of the fact a tool result states, from the call it answers.
   */
  private function keyFor(Segment $segment, ?string $toolId): ?string {
    if ($toolId === NULL) {
      return NULL;
    }
    $call = $segment->toolCall($toolId);
    return $call === NULL ? NULL : ResultKey::fromCall($call);
  }

}
