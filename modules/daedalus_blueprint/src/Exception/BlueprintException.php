<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Exception;

/**
 * Exception for truly unexpected/unrecoverable failures.
 *
 * This should not be thrown for expected validation failures (use
 * BlueprintError instead). Reserved for situations like entity storage
 * corruption or missing field definitions after structural validation passed.
 */
class BlueprintException extends \RuntimeException {

}
