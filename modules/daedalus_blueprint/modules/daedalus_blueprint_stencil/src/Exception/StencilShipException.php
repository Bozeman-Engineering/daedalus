<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_stencil\Exception;

use Drupal\daedalus_blueprint\Materials\MaterialGestureException;

/**
 * A stencil ship was refused before anything was written.
 *
 * The ship lane's refusals (no identity to ship under, an id another
 * module already ships, an element that is not a placement) carry the same
 * structured blueprint errors every other stencil write path speaks, so
 * the drush command prints a code, a message, and a hint instead of a bare
 * string. Refusals are always whole: nothing lands on disk or in the
 * database when this is thrown.
 */
class StencilShipException extends MaterialGestureException {
}
