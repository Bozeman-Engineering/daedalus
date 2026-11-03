<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_memory\Transcript;

/**
 * One segment of the transcript: an append-only run of entries.
 *
 * The entries of a segment share a stable prefix, and the segment opens
 * with what the previous one carried over. A segment boundary is where the
 * model's working history resets to the prefix plus the carry-over; the
 * user-visible chat scrolls on. Inside a segment nothing is ever edited,
 * stripped or reordered. The prefix hash (tools plus system prompt) is
 * noted per leg so that drift, which breaks every provider's cache, is
 * logged rather than guessed at.
 */
final class Segment {

  /**
   * Constructs a segment; the transcript opens them.
   *
   * @param int $number
   *   The segment's number in the transcript, from 1.
   * @param string|null $carryOver
   *   What the previous segment carried over, worded for the model, or
   *   NULL for the first segment.
   * @param string[] $prefixHashes
   *   The distinct prefix hashes noted, in the order first seen.
   * @param \Drupal\daedalus_ai_memory\Transcript\Entry[] $entries
   *   The entries in wire order.
   * @param string|null $prefixHash
   *   The prefix hash the latest leg was sent with, one of $prefixHashes;
   *   NULL reads the last distinct hash (a record written before the
   *   latest hash was kept apart, where the two were the same thing until
   *   a prefix returned to an earlier value).
   */
  public function __construct(
    private readonly int $number,
    private readonly ?string $carryOver = NULL,
    private array $prefixHashes = [],
    private array $entries = [],
    private ?string $prefixHash = NULL,
  ) {
    if ($number < 1) {
      throw new \InvalidArgumentException(sprintf('A segment number starts at 1, got %d.', $number));
    }
    foreach ($entries as $entry) {
      if (!$entry instanceof Entry) {
        throw new \InvalidArgumentException('A segment takes Entry objects.');
      }
    }
    foreach ($prefixHashes as $hash) {
      if (!is_string($hash) || $hash === '') {
        throw new \InvalidArgumentException('A segment prefix hash must be a non-empty string.');
      }
    }
    $this->entries = array_values($entries);
    $this->prefixHashes = array_values(array_unique($prefixHashes));
    if ($prefixHash === NULL) {
      $this->prefixHash = $this->prefixHashes === [] ? NULL : $this->prefixHashes[array_key_last($this->prefixHashes)];
    }
    elseif (!in_array($prefixHash, $this->prefixHashes, TRUE)) {
      throw new \InvalidArgumentException('A segment\'s latest prefix hash must be one of its noted hashes.');
    }
  }

  /**
   * The segment's number in the transcript, from 1.
   */
  public function number(): int {
    return $this->number;
  }

  /**
   * What the previous segment carried over, or NULL for the first.
   */
  public function carryOver(): ?string {
    return $this->carryOver;
  }

  /**
   * Appends an entry; the one way a segment changes.
   */
  public function append(Entry $entry): void {
    $this->entries[] = $entry;
  }

  /**
   * The entries in wire order.
   *
   * @return \Drupal\daedalus_ai_memory\Transcript\Entry[]
   *   The entries.
   */
  public function entries(): array {
    return $this->entries;
  }

  /**
   * Whether the segment holds no entry yet.
   */
  public function isEmpty(): bool {
    return $this->entries === [];
  }

  /**
   * The last entry, or NULL while the segment is empty.
   */
  public function last(): ?Entry {
    return $this->entries === [] ? NULL : $this->entries[array_key_last($this->entries)];
  }

  /**
   * Notes the prefix a leg was sent with.
   *
   * @param string $hash
   *   The hash as Prefix::hash() minted it.
   *
   * @return bool
   *   TRUE when the prefix differs from the one the previous leg was sent
   *   with (drift: the caller logs it), FALSE on the first leg and while it
   *   holds. A return to a prefix the segment has seen before is drift
   *   too: the provider cached the legs since under the other prefix, and
   *   a feeder that varies the prompt on some requests and not others
   *   (the case this exists to catch) rewrites the cache on every flip,
   *   not on the first alone. The distinct list does not grow on it.
   */
  public function notePrefix(string $hash): bool {
    if ($hash === '') {
      throw new \InvalidArgumentException('A prefix hash must not be empty.');
    }
    $previous = $this->prefixHash;
    if (!in_array($hash, $this->prefixHashes, TRUE)) {
      $this->prefixHashes[] = $hash;
    }
    $this->prefixHash = $hash;
    return $previous !== NULL && $previous !== $hash;
  }

  /**
   * The prefix hash of the latest leg, or NULL before any leg.
   */
  public function prefixHash(): ?string {
    return $this->prefixHash;
  }

  /**
   * The distinct prefix hashes noted, in the order first seen.
   *
   * @return string[]
   *   The hashes; more than one means the prefix drifted in this segment.
   */
  public function prefixHashes(): array {
    return $this->prefixHashes;
  }

  /**
   * Whether more than one prefix was sent in this segment.
   */
  public function prefixDrifted(): bool {
    return count($this->prefixHashes) > 1;
  }

  /**
   * The current turn: the entries from the latest user turn on.
   *
   * A reframe close re-sends these after the carry-over so the model keeps
   * the turn it is in the middle of (the user's words with their blocks,
   * the calls it made, the results it got, a screenshots message the
   * wrapper interjected). Empty before any user turn.
   *
   * @return \Drupal\daedalus_ai_memory\Transcript\Entry[]
   *   The entries, the user entry first.
   */
  public function currentTurn(): array {
    for ($i = count($this->entries) - 1; $i >= 0; $i--) {
      if ($this->entries[$i]->type === EntryType::User && $this->entries[$i]->turn) {
        return array_slice($this->entries, $i);
      }
    }
    return [];
  }

  /**
   * Whether the user's own turn has been appended to this segment.
   *
   * A segment with none yet is one the next user turn opens (the first
   * segment before any turn, or one a budget close opened for the next
   * turn), which is when the once-per-segment blocks are asked for.
   */
  public function hasUserTurn(): bool {
    foreach ($this->entries as $entry) {
      if ($entry->type === EntryType::User && $entry->turn) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * The latest assistant entry's calls that no tool result has answered.
   *
   * Only the entries after that assistant entry count as its answers, and
   * only while they are all tool results: once anything else follows, the
   * calls were settled when it was appended. Empty when no call is open.
   *
   * @return \Drupal\daedalus_ai_memory\Transcript\ToolCall[]
   *   The unanswered calls, in call order.
   */
  public function unansweredCalls(): array {
    $answered = [];
    for ($i = count($this->entries) - 1; $i >= 0; $i--) {
      $entry = $this->entries[$i];
      if ($entry->type === EntryType::Assistant) {
        return array_values(array_filter(
          $entry->toolCalls,
          static fn (ToolCall $call): bool => !in_array($call->id, $answered, TRUE),
        ));
      }
      if ($entry->type !== EntryType::ToolResult) {
        return [];
      }
      if ($entry->toolId !== NULL) {
        $answered[] = $entry->toolId;
      }
    }
    return [];
  }

  /**
   * The tool call with an id, searched from the latest assistant entry back.
   */
  public function toolCall(string $id): ?ToolCall {
    for ($i = count($this->entries) - 1; $i >= 0; $i--) {
      $entry = $this->entries[$i];
      if ($entry->type === EntryType::Assistant && ($call = $entry->toolCall($id)) !== NULL) {
        return $call;
      }
    }
    return NULL;
  }

  /**
   * The keyed tool results, the latest copy per key.
   *
   * Keys come in the order they were first loaded.
   *
   * @return array<string, \Drupal\daedalus_ai_memory\Transcript\Entry>
   *   The latest result by key.
   */
  public function resultsByKey(): array {
    $results = [];
    foreach ($this->entries as $entry) {
      if ($entry->type === EntryType::ToolResult && $entry->key !== NULL) {
        $results[$entry->key] = $entry;
      }
    }
    return $results;
  }

  /**
   * The segment as a serializable array; fromArray() reads it back.
   *
   * @return array{number: int, carry_over: string|null, prefix_hashes: string[], prefix_hash: string|null, entries: array[]}
   *   The segment.
   */
  public function toArray(): array {
    return [
      'number' => $this->number,
      'carry_over' => $this->carryOver,
      'prefix_hashes' => $this->prefixHashes,
      'prefix_hash' => $this->prefixHash,
      'entries' => array_map(static fn (Entry $entry): array => $entry->toArray(), $this->entries),
    ];
  }

  /**
   * A segment from its array form.
   *
   * @param array $values
   *   What toArray() produced.
   *
   * @throws \InvalidArgumentException
   *   When a value is malformed.
   */
  public static function fromArray(array $values): self {
    if (!is_int($values['number'] ?? NULL)) {
      throw new \InvalidArgumentException('A transcript segment number must be an integer.');
    }
    $carry_over = $values['carry_over'] ?? NULL;
    if ($carry_over !== NULL && !is_string($carry_over)) {
      throw new \InvalidArgumentException('A transcript segment carry-over must be a string or NULL.');
    }
    $hashes = $values['prefix_hashes'] ?? [];
    $entries = $values['entries'] ?? [];
    if (!is_array($hashes) || !is_array($entries)) {
      throw new \InvalidArgumentException('A transcript segment needs prefix_hashes and entries arrays.');
    }
    $prefix_hash = $values['prefix_hash'] ?? NULL;
    if ($prefix_hash !== NULL && !is_string($prefix_hash)) {
      throw new \InvalidArgumentException('A transcript segment prefix_hash must be a string or NULL.');
    }
    return new self($values['number'], $carry_over, $hashes, array_map(
      static function (mixed $entry): Entry {
        if (!is_array($entry)) {
          throw new \InvalidArgumentException('A transcript segment entry must be an array.');
        }
        return Entry::fromArray($entry);
      },
      $entries,
    ), $prefix_hash);
  }

}
