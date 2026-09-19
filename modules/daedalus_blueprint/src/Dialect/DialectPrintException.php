<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Dialect;

/**
 * Thrown when a node tree carries something the dialect cannot yet print.
 *
 * The printer is loud, never lossy-by-silence: a tree shape outside the
 * spec v0.2 grammar (multi-region layouts, structured link values with
 * title/options, variant references with fragments, field-scoped style
 * keys) raises this instead of printing an approximation a parse could not
 * round-trip. Each gap is a deliberate follow-up, not an accident.
 */
final class DialectPrintException extends \RuntimeException {
}
