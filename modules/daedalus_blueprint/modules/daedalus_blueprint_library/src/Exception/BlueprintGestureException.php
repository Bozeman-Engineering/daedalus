<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_library\Exception;

use Drupal\daedalus_blueprint\Materials\MaterialGestureException;

/**
 * A blueprint gesture (save-as-blueprint, push-update) failed.
 *
 * Carries the standard blueprint errors ({path, code, message, hint}) so the
 * blueprint API controller answers in the same error shape every other
 * blueprint write path speaks (the VariantValidationException precedent).
 */
class BlueprintGestureException extends MaterialGestureException {
}
