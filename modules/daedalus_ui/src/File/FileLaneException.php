<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\File;

/**
 * A file the lane refused: validator violations or a declared-type mismatch.
 *
 * The message is the violations joined for a human; adapters translate it to
 * their own surface (the upload controller to an HTTP 400, a dialect
 * declaration to a dialect error naming the declaration).
 */
final class FileLaneException extends \RuntimeException {

  /**
   * Constructs the exception from the refusal reasons.
   *
   * @param list<string> $messages
   *   One message per refused reason.
   */
  public function __construct(
    public readonly array $messages,
  ) {
    parent::__construct(implode(' ', $messages));
  }

}
