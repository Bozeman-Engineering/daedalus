<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_config\PropertyHandler;

/**
 * Result object for property handler serialize/deserialize operations.
 */
class PropertyHandlerResult {

  /**
   * Constructs a new PropertyHandlerResult.
   *
   * @param mixed $value
   *   The processed value.
   * @param \Drupal\daedalus_blueprint\Result\BlueprintError[] $errors
   *   Any errors encountered.
   * @param \Drupal\daedalus_blueprint\Result\BlueprintWarning[] $warnings
   *   Any warnings encountered.
   */
  public function __construct(
    protected readonly mixed $value = NULL,
    protected readonly array $errors = [],
    protected readonly array $warnings = [],
  ) {}

  /**
   * Creates a successful result.
   *
   * @param mixed $value
   *   The processed value.
   * @param \Drupal\daedalus_blueprint\Result\BlueprintWarning[] $warnings
   *   Any warnings.
   *
   * @return static
   *   A new successful result.
   */
  public static function success(mixed $value, array $warnings = []): static {
    return new static($value, [], $warnings);
  }

  /**
   * Creates a failed result.
   *
   * @param \Drupal\daedalus_blueprint\Result\BlueprintError[] $errors
   *   The errors.
   *
   * @return static
   *   A new failed result.
   */
  public static function failure(array $errors): static {
    return new static(NULL, $errors);
  }

  /**
   * Whether the operation succeeded.
   *
   * @return bool
   *   TRUE if no errors.
   */
  public function isSuccess(): bool {
    return empty($this->errors);
  }

  /**
   * Returns the processed value.
   *
   * @return mixed
   *   The value.
   */
  public function getValue(): mixed {
    return $this->value;
  }

  /**
   * Returns errors.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   The errors.
   */
  public function getErrors(): array {
    return $this->errors;
  }

  /**
   * Returns warnings.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintWarning[]
   *   The warnings.
   */
  public function getWarnings(): array {
    return $this->warnings;
  }

}
