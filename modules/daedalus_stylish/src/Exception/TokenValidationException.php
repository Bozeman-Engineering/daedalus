<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Exception;

/**
 * A token gesture's payload failed validation.
 *
 * The token gestures' error carrier: the same structured blueprint errors
 * ({path, code, message, hint}) every daedalus_stylish write path speaks.
 * Extends VariantValidationException because that class IS the
 * daedalus-stylish-wide error-carrying contract (named for its first
 * user, see ShippedSkinException), so the shared controller envelope and
 * the op spine serve every gesture family through one catch.
 */
class TokenValidationException extends VariantValidationException {
}
