<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Result;

use Drupal\Core\Entity\EntityInterface;

/**
 * Return object for all blueprint operations.
 *
 * Shared interface for both content and config blueprint results. Generic
 * dispatch code (BlueprintBackendInterface) uses this type. Backend-specific
 * code uses the concrete result class for full type safety.
 */
interface BlueprintResultInterface {

  /**
   * Returns whether the operation completed successfully.
   *
   * @return bool
   *   TRUE if the operation succeeded with no errors.
   */
  public function isSuccess(): bool;

  /**
   * Returns the phase that was reached.
   *
   * One of 'structural', 'semantic', 'hydrated', or 'validated' (dry-run).
   *
   * @return string
   *   The phase identifier.
   */
  public function getPhase(): string;

  /**
   * Returns the list of errors.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   The errors collected during the operation.
   */
  public function getErrors(): array;

  /**
   * Returns the list of warnings.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintWarning[]
   *   The warnings collected during the operation.
   */
  public function getWarnings(): array;

  /**
   * Returns the entity if the operation succeeded.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The entity, or NULL if the operation failed or was validate-only.
   */
  public function getEntity(): ?EntityInterface;

  /**
   * Serializes the result for JSON output.
   *
   * Used by API responses, Drush commands, and AI tools.
   *
   * @return array
   *   The result as an associative array.
   */
  public function toArray(): array;

}
