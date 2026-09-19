<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Dialect;

use Drupal\daedalus_blueprint\Result\BlueprintError;

/**
 * One structural error from the dialect parser.
 *
 * Errors are corrective by design: the message names the dialect form the
 * author should have written, because the corrective channel is the part of
 * the authoring loop that measurably teaches (see dialect spec v0.2). The
 * line number is the 1-based source line where the offending construct
 * sits, so a model (or person) can find it in the document it just wrote.
 *
 * Structured like the spine's BlueprintError, which every dialect error
 * becomes at the op door: `context` carries the data a repair needs (the
 * legal roster, the candidates a did-you-mean ranked, the reference as
 * written) and `hint` the corrective action, so the two doors answer with
 * one shape and a client can render the roster instead of parsing prose.
 */
final class DialectError {

  public function __construct(
    public readonly string $code,
    public readonly string $message,
    public readonly ?int $line = NULL,
    public readonly array $context = [],
    public readonly string $hint = '',
  ) {}

  /**
   * The error as the spine's shape, on a payload path.
   *
   * The two doors answer with one shape: the context and hint ride
   * through, and the source line joins the message (the one fact a
   * payload path cannot carry).
   *
   * @param string $path
   *   The payload path the dialect text arrived on ('html', 'css',
   *   'layout').
   */
  public function toBlueprintError(string $path): BlueprintError {
    return new BlueprintError(
      $path,
      $this->code,
      $this->message . ($this->line !== NULL ? sprintf(' (line %d)', $this->line) : ''),
      $this->context,
      $this->hint,
    );
  }

  /**
   * The error as one line: code, source line, message and hint.
   */
  public function __toString(): string {
    $where = $this->line === NULL ? '' : sprintf(' line %d', $this->line);
    $hint = $this->hint === '' ? '' : ' ' . $this->hint;
    return sprintf('%s%s: %s%s', $this->code, $where, $this->message, $hint);
  }

}
