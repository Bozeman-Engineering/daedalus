<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Dialect;

/**
 * Thrown when a node tree carries a node kind the printer does not know.
 *
 * A programming error, never a data gap. The printer is total over data
 * (DialectPrinter): whatever a page holds that the authored grammar
 * cannot spell prints in the read-only register and is noted on the
 * DialectPrint, not thrown. Only a node kind no serializer produces
 * raises this, and nothing catches it on purpose.
 */
final class DialectPrintException extends \RuntimeException {
}
