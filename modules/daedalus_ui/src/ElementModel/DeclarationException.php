<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\ElementModel;

/**
 * Thrown when the layered page builder declarations are invalid.
 *
 * Raised once per build with every problem listed, so a broken declaration
 * fails loudly at discovery instead of half-working downstream.
 */
class DeclarationException extends \RuntimeException {
}
