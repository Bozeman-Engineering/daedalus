<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Dialect;

/**
 * A file declaration's bytes were refused.
 *
 * Thrown by a FileDeclarationHandlerInterface implementer with one message
 * per refusal reason (the lane's own words); DomOperationHandler turns it
 * into the dialect error naming the declaration.
 */
final class FileDeclarationException extends \RuntimeException {

  /**
   * Constructs the exception from the refusal reasons.
   *
   * @param list<string> $messages
   *   One message per refused reason.
   * @param \Throwable|null $previous
   *   The refusal as the lane raised it, when there is one.
   */
  public function __construct(
    public readonly array $messages,
    ?\Throwable $previous = NULL,
  ) {
    parent::__construct(implode(' ', $messages), 0, $previous);
  }

}
