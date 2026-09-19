<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\FieldHandler;

/**
 * Return object for FieldHandlerInterface::deserialize().
 *
 * Contains the value to set on the entity field plus any errors or warnings
 * collected during processing.
 */
class FieldHandlerResult {

  /**
   * Constructs a new FieldHandlerResult.
   *
   * @param mixed $value
   *   The value to set on the entity field via $entity->set().
   * @param \Drupal\daedalus_blueprint\Result\BlueprintError[] $errors
   *   Errors collected during processing.
   * @param \Drupal\daedalus_blueprint\Result\BlueprintWarning[] $warnings
   *   Warnings collected during processing.
   */
  public function __construct(
    protected readonly mixed $value,
    protected readonly array $errors = [],
    protected readonly array $warnings = [],
  ) {}

  /**
   * Gets the value to set on the entity field.
   *
   * @return mixed
   *   The processed field value, or NULL on error.
   */
  public function getValue(): mixed {
    return $this->value;
  }

  /**
   * Gets the errors collected during processing.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   The errors.
   */
  public function getErrors(): array {
    return $this->errors;
  }

  /**
   * Gets the warnings collected during processing.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintWarning[]
   *   The warnings.
   */
  public function getWarnings(): array {
    return $this->warnings;
  }

  /**
   * Returns whether any errors were collected.
   *
   * @return bool
   *   TRUE if there are errors.
   */
  public function hasErrors(): bool {
    return !empty($this->errors);
  }

}
