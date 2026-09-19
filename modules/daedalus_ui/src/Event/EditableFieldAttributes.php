<?php

namespace Drupal\daedalus_ui\Event;

use Drupal\Component\EventDispatcher\Event;

/**
 * Editable field attributes.
 *
 * Lets modules add attributes when Fields are wrapped in order to make the
 * editing components aware of the field.
 */
class EditableFieldAttributes extends Event {

  public function __construct(
    protected array &$variables,
  ) {}

  /**
   * Gets the template variables by reference so subscribers can alter them.
   */
  public function &getVariables(): array {
    return $this->variables;
  }

}
