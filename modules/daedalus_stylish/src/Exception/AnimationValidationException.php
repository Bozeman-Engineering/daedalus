<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Exception;

/**
 * An animation gesture's payload failed validation.
 *
 * The animation gestures' error carrier: the same structured blueprint
 * errors ({path, code, message, hint}) every daedalus_stylish write path speaks.
 * Extends VariantValidationException because that class IS the
 * daedalus-stylish-wide error-carrying contract (named for its first user, see
 * ShippedSkinException): the shared controller envelope and the drush sync
 * commands catch the base and serve every gesture family.
 */
class AnimationValidationException extends VariantValidationException {
}
