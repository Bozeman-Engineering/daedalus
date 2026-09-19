<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai\AiLogTail;

/**
 * The parsed form of an ai_log prompt column.
 *
 * The prompt column stores a chat conversation as a flat string built by
 * ChatInput::toString(): each message rendered as its role on one line
 * followed by its (possibly multi-line) text and a newline. No structured
 * message record exists anywhere else, so message boundaries are detected
 * heuristically, and this class is the ONE place that heuristic lives: any
 * line that is exactly one of self::ROLES starts a message. A prompt with no
 * such line is not a chat and parses as a single free-text message.
 */
final class Transcript {

  /**
   * The role lines that open a chat message.
   */
  public const ROLES = ['user', self::ASSISTANT, 'tool'];

  /**
   * The role of the model's own turns, which the tracker recognizes as echoes.
   */
  public const ASSISTANT = 'assistant';

  /**
   * Constructs a Transcript.
   *
   * @param \Drupal\daedalus_blueprint_ai\AiLogTail\Message[] $messages
   *   The messages, oldest first.
   */
  private function __construct(
    public readonly array $messages,
  ) {}

  /**
   * Parses a prompt column into messages.
   *
   * @param string $prompt
   *   The raw prompt string.
   *
   * @return self
   *   The parsed transcript.
   */
  public static function parse(string $prompt): self {
    if ($prompt === '') {
      return new self([]);
    }

    $lines = explode("\n", $prompt);
    // A trailing newline (ChatInput always ends a message with one) leaves an
    // empty last element; drop it so the text round-trips without growing.
    if (end($lines) === '') {
      array_pop($lines);
    }

    $messages = [];
    $role = NULL;
    $buffer = [];
    $open = FALSE;
    foreach ($lines as $line) {
      if (in_array($line, self::ROLES, TRUE)) {
        if ($open) {
          $messages[] = new Message($role, implode("\n", $buffer));
        }
        $role = $line;
        $buffer = [];
        $open = TRUE;
        continue;
      }
      $buffer[] = $line;
      $open = TRUE;
    }
    if ($open) {
      $messages[] = new Message($role, implode("\n", $buffer));
    }

    return new self($messages);
  }

  /**
   * Builds a transcript from already-parsed messages.
   *
   * @param \Drupal\daedalus_blueprint_ai\AiLogTail\Message[] $messages
   *   The messages, oldest first.
   *
   * @return self
   *   The transcript.
   */
  public static function fromMessages(array $messages): self {
    return new self(array_values($messages));
  }

  /**
   * Whether the prompt carried chat structure.
   *
   * @return bool
   *   TRUE when at least one message opened with a role line.
   */
  public function isChat(): bool {
    foreach ($this->messages as $message) {
      if ($message->role !== NULL) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * The number of messages.
   *
   * @return int
   *   The count.
   */
  public function count(): int {
    return count($this->messages);
  }

  /**
   * Keeps only the last N messages.
   *
   * Non-chat transcripts are never sliced: their single free-text message is
   * the whole prompt and hiding it would hide everything.
   *
   * @param int $lastN
   *   How many trailing messages to keep; 0 keeps them all.
   *
   * @return self
   *   The same transcript when nothing is hidden, else a shorter one.
   */
  public function slice(int $lastN): self {
    if ($lastN <= 0 || $lastN >= $this->count() || !$this->isChat()) {
      return $this;
    }
    return new self(array_slice($this->messages, -$lastN));
  }

  /**
   * Renders the transcript back into the prompt column's shape.
   *
   * @return string
   *   The prompt string; parse() of a ChatInput prompt round-trips exactly.
   */
  public function toString(): string {
    return implode('', array_map(fn(Message $message): string => $message->toString(), $this->messages));
  }

}
