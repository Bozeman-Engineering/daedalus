<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai\AiLogTail;

/**
 * Remembers each thread's last row so the next one prints only what is new.
 *
 * Every chat row's prompt is the whole conversation so far, so a thread's
 * rows normally extend one another and only the extension is worth
 * printing. The tracker keys threads on LogEntry::threadKey() (the runner
 * id) and diffs each row's transcript against the thread's previous one,
 * message by message; a row whose transcript does not extend the previous
 * one is reported as diverged and handed back whole, never guessed at.
 */
final class ThreadTracker {

  /**
   * The last row of each thread, keyed by thread key.
   *
   * @var array<string, array{entry: \Drupal\daedalus_blueprint_ai\AiLogTail\LogEntry, transcript: \Drupal\daedalus_blueprint_ai\AiLogTail\Transcript}>
   */
  private array $threads = [];

  /**
   * Records a row and answers what it adds to its thread.
   *
   * @param \Drupal\daedalus_blueprint_ai\AiLogTail\LogEntry $entry
   *   The row.
   * @param \Drupal\daedalus_blueprint_ai\AiLogTail\Transcript $transcript
   *   The row's parsed prompt (parsed once by the caller, shared with the
   *   formatter).
   *
   * @return \Drupal\daedalus_blueprint_ai\AiLogTail\TranscriptDelta|null
   *   The delta, or NULL for a standalone row (no runner tag), which the
   *   tracker neither diffs nor remembers.
   */
  public function advance(LogEntry $entry, Transcript $transcript): ?TranscriptDelta {
    $key = $entry->threadKey();
    if ($key === NULL) {
      return NULL;
    }

    $previous = $this->threads[$key] ?? NULL;
    $this->threads[$key] = ['entry' => $entry, 'transcript' => $transcript];
    if ($previous === NULL) {
      return new TranscriptDelta($transcript, 0, NULL, FALSE, FALSE, FALSE, FALSE);
    }

    $previousEntry = $previous['entry'];
    $previousTranscript = $previous['transcript'];
    $systemPromptChanged = $entry->systemPrompt() !== $previousEntry->systemPrompt();
    $toolsChanged = $entry->chatTools() !== $previousEntry->chatTools();

    if (!self::extends($previousTranscript, $transcript)) {
      return new TranscriptDelta($transcript, 0, $previousEntry->id, TRUE, FALSE, $systemPromptChanged, $toolsChanged);
    }

    $hidden = $previousTranscript->count();
    $added = array_slice($transcript->messages, $hidden);
    $echoDropped = FALSE;
    if ($added !== [] && $added[0]->role === Transcript::ASSISTANT && self::echoes($added[0]->text, $previousEntry)) {
      array_shift($added);
      $hidden++;
      $echoDropped = TRUE;
    }

    return new TranscriptDelta(Transcript::fromMessages($added), $hidden, $previousEntry->id, FALSE, $echoDropped, $systemPromptChanged, $toolsChanged);
  }

  /**
   * Whether a transcript begins with every message of an earlier one.
   *
   * @param \Drupal\daedalus_blueprint_ai\AiLogTail\Transcript $previous
   *   The thread's previous transcript.
   * @param \Drupal\daedalus_blueprint_ai\AiLogTail\Transcript $current
   *   The row's transcript.
   *
   * @return bool
   *   TRUE when $current is $previous plus zero or more messages.
   */
  private static function extends(Transcript $previous, Transcript $current): bool {
    if ($current->count() < $previous->count()) {
      return FALSE;
    }
    foreach ($previous->messages as $index => $message) {
      $candidate = $current->messages[$index];
      if ($candidate->role !== $message->role || $candidate->text !== $message->text) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Whether an assistant message is the previous row's response, echoed.
   *
   * The ai_agents runner appends the normalized response to the history
   * verbatim, but ai_logging stores only the provider's raw output. The
   * normalized text is the raw output's text fragments joined in order
   * (Anthropic joins its text blocks, a streamed row stores its chunks),
   * so the message echoes the response when its text is one string leaf of
   * the decoded output or the in-order concatenation of several. An empty
   * message echoes a tool-calls-only response, which has no text to
   * compare.
   *
   * @param string $text
   *   The assistant message's text.
   * @param \Drupal\daedalus_blueprint_ai\AiLogTail\LogEntry $previous
   *   The thread's previous row, whose output the message would echo.
   *
   * @return bool
   *   TRUE when the message says nothing the previous response did not.
   */
  private static function echoes(string $text, LogEntry $previous): bool {
    if ($text === '') {
      return TRUE;
    }
    $leaves = self::stringLeaves($previous->decodedOutput());
    if (in_array($text, $leaves, TRUE)) {
      return TRUE;
    }
    $cursor = 0;
    foreach ($leaves as $leaf) {
      if ($leaf !== '' && str_starts_with(substr($text, $cursor), $leaf)) {
        $cursor += strlen($leaf);
        if ($cursor === strlen($text)) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * Collects every string in a decoded output, in document order.
   *
   * @param mixed $value
   *   A decoded output: an array, a scalar, or NULL.
   *
   * @return string[]
   *   The strings.
   */
  private static function stringLeaves(mixed $value): array {
    if (is_string($value)) {
      return [$value];
    }
    if (!is_array($value)) {
      return [];
    }
    $leaves = [];
    foreach ($value as $item) {
      foreach (self::stringLeaves($item) as $leaf) {
        $leaves[] = $leaf;
      }
    }
    return $leaves;
  }

}
