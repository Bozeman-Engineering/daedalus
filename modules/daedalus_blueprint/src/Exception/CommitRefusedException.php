<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Exception;

/**
 * The commit door's refusal: the working copy was not saved, and why.
 *
 * Thrown by EntityStorageHandler::commit() and by nothing else. Three
 * reasons, one shape, so a caller (the Save route, an AI tool's save) has
 * one thing to catch and one list of messages to show: the stored entity
 * moved on after the working copy was taken from it, whole-entity
 * validation found violations, or storage refused the write. The stage is
 * untouched by a refusal: the unsaved changes are still there to fix,
 * discard or retry.
 *
 * The messages are plain text for a person or a model. The exception's own
 * message is the same sentences joined, so a caller that only prints
 * getMessage() still says what happened.
 */
class CommitRefusedException extends \RuntimeException {

  /**
   * The stored entity was saved elsewhere after the working copy was taken.
   */
  public const MOVED = 'moved';

  /**
   * Whole-entity validation found violations.
   */
  public const INVALID = 'invalid';

  /**
   * Storage threw while saving.
   */
  public const STORAGE = 'storage';

  /**
   * Constructs a CommitRefusedException.
   *
   * @param string $reason
   *   Why the door refused: one of the class constants.
   * @param string[] $messages
   *   What to tell the caller, one plain-text sentence each; never empty.
   * @param \Throwable|null $previous
   *   The storage exception a STORAGE refusal stands for.
   */
  public function __construct(
    protected string $reason,
    protected array $messages,
    ?\Throwable $previous = NULL,
  ) {
    parent::__construct(implode(' ', $messages), 0, $previous);
  }

  /**
   * Why the door refused.
   *
   * @return string
   *   One of MOVED, INVALID, STORAGE.
   */
  public function reason(): string {
    return $this->reason;
  }

  /**
   * What to tell the caller.
   *
   * @return string[]
   *   Plain-text sentences.
   */
  public function messages(): array {
    return $this->messages;
  }

}
