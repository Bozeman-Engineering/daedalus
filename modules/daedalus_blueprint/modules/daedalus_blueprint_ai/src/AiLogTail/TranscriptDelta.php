<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai\AiLogTail;

/**
 * What one row adds to its thread's transcript, as the tracker read it.
 *
 * The formatter prints $transcript and reads the flags for its notes; a
 * NULL delta (a standalone row, or full mode) means "print the whole prompt".
 */
final readonly class TranscriptDelta {

  /**
   * Constructs a TranscriptDelta.
   *
   * @param \Drupal\daedalus_blueprint_ai\AiLogTail\Transcript $transcript
   *   The messages to print: the extension over the thread's previous row,
   *   or the whole transcript on the thread's first row and on divergence.
   * @param int $hiddenCount
   *   How many of the row's messages are not in $transcript: the shared
   *   prefix already printed with earlier rows, plus a dropped echo.
   * @param int|null $sinceId
   *   The id of the thread's previous row, NULL on the thread's first row.
   * @param bool $diverged
   *   TRUE when the row's transcript does not extend the previous one
   *   (history rewritten or compacted); $transcript is then the whole row.
   * @param bool $echoDropped
   *   TRUE when the extension opened with the previous row's response
   *   echoed as an assistant message and that message was dropped.
   * @param bool $systemPromptChanged
   *   TRUE when the system prompt differs from the thread's previous row.
   * @param bool $toolsChanged
   *   TRUE when the tool definitions differ from the thread's previous row.
   */
  public function __construct(
    public Transcript $transcript,
    public int $hiddenCount,
    public ?int $sinceId,
    public bool $diverged,
    public bool $echoDropped,
    public bool $systemPromptChanged,
    public bool $toolsChanged,
  ) {}

  /**
   * Whether this is the thread's first row, printed whole.
   *
   * @return bool
   *   TRUE when nothing preceded the row in its thread.
   */
  public function isFirst(): bool {
    return $this->sinceId === NULL;
  }

}
