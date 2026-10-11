<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Exception;

/**
 * A skin gesture's payload failed validation.
 *
 * The skin lifecycle gestures' error carrier (choose, and the card's
 * gestures after it): the same structured blueprint errors ({path, code,
 * message, hint}) every daedalus_stylish write path speaks. Extends
 * VariantValidationException because that class IS the
 * daedalus-stylish-wide error-carrying contract (named for its first
 * user, see ShippedSkinException), so the shared controller envelope
 * serves every gesture family through one catch.
 */
class SkinValidationException extends VariantValidationException {
}
