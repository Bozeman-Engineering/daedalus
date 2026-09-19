<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_stencil\Exception;

use Drupal\daedalus_blueprint\Materials\MaterialGestureException;

/**
 * A stencil gesture (save-as-stencil, push-update) failed.
 *
 * Carries the standard blueprint errors ({path, code, message, hint}) so the
 * stencil API controller answers in the same error shape every other
 * blueprint write path speaks (the VariantValidationException precedent).
 */
class StencilGestureException extends MaterialGestureException {
}
