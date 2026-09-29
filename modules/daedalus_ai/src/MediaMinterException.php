<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai;

/**
 * A file no media type's source field accepts.
 *
 * Its own class so a surface that mints from a file the model named can
 * turn the refusal into an error naming that file without swallowing any
 * other failure of the mint.
 */
class MediaMinterException extends \RuntimeException {
}
