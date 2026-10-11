<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_library\Exception;

use Drupal\daedalus_blueprint\Materials\MaterialGestureException;

/**
 * A blueprint ship was refused before anything was written.
 *
 * The ship lane's refusals (a module that is not enabled, a machine name
 * that is not a file id, a file already under the id, an empty document,
 * an empty face) carry the same structured blueprint errors every other
 * blueprint write path speaks, so the drush command prints a code, a
 * message, and a hint instead of a bare string. Refusals are always whole:
 * nothing lands on disk or in the database when this is thrown.
 */
class BlueprintShipException extends MaterialGestureException {
}
