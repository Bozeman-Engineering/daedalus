<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\LayoutBuilder;

/**
 * Result of layout deserialization.
 *
 * Contains errors collected during layout processing and the built Section
 * objects. Errors use paths relative to layout_builder__layout (e.g.,
 * layout_builder__layout[0].components[1].block_fields.field_body).
 */
class LayoutDeserializerResult {

  /**
   * Constructs a LayoutDeserializerResult.
   *
   * @param \Drupal\daedalus_blueprint\Result\BlueprintError[] $errors
   *   Errors collected during layout deserialization.
   * @param \Drupal\layout_builder\Section[] $sections
   *   The built Section objects.
   * @param \Drupal\daedalus_blueprint\Result\BlueprintWarning[] $warnings
   *   Warnings collected during layout deserialization.
   */
  public function __construct(
    protected readonly array $errors = [],
    protected readonly array $sections = [],
    protected readonly array $warnings = [],
  ) {}

  /**
   * Gets the errors collected during layout deserialization.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   The errors.
   */
  public function getErrors(): array {
    return $this->errors;
  }

  /**
   * Gets the built Section objects.
   *
   * @return \Drupal\layout_builder\Section[]
   *   The sections.
   */
  public function getSections(): array {
    return $this->sections;
  }

  /**
   * Gets the warnings collected during layout deserialization.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintWarning[]
   *   The warnings.
   */
  public function getWarnings(): array {
    return $this->warnings;
  }

  /**
   * Checks whether any errors were collected.
   *
   * @return bool
   *   TRUE if there are errors.
   */
  public function hasErrors(): bool {
    return !empty($this->errors);
  }

}
