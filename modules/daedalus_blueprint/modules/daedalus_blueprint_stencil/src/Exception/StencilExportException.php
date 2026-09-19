<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_stencil\Exception;

/**
 * A placed component cannot be exported as a stencil.
 *
 * Export failures are always loud: an exported file that silently dropped
 * part of the design would ship a stencil that stamps something other than
 * what the author built. Losses the grammar genuinely cannot express are
 * surfaced as warnings on the successful export instead (see
 * StencilExport::$warnings); this exception is for designs the exporter
 * cannot represent at all.
 */
class StencilExportException extends \RuntimeException {
}
