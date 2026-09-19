<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Exception;

use Drupal\daedalus_blueprint\Materials\MaterialGestureException;

/**
 * A variant gesture's payload failed validation.
 *
 * Carries the standard blueprint errors ({path, code, message, hint}) so the
 * variant API controller can answer in the same error shape every other
 * Daedalus Stylish write path speaks.
 */
class VariantValidationException extends MaterialGestureException {
}
