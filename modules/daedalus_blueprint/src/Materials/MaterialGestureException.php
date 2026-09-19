<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Materials;

use Drupal\daedalus_blueprint\Result\BlueprintError;

/**
 * A material gesture refused: the errors in the gesture's parameter space.
 *
 * The one exception the material gesture family speaks (save_material,
 * update_material, remove_material on the op spine; the admin routes as
 * adapters over the same provider methods): a provider's own gesture
 * exceptions extend it, so the op handler and every route translate one
 * type into the blueprint error shape, whichever kind refused.
 */
class MaterialGestureException extends \RuntimeException {

  /**
   * Constructs a MaterialGestureException.
   *
   * @param \Drupal\daedalus_blueprint\Result\BlueprintError[] $errors
   *   The errors, with paths in the gesture's parameter space.
   */
  public function __construct(
    protected array $errors,
  ) {
    parent::__construct($errors !== [] ? $errors[0]->getMessage() : 'The material gesture failed.');
  }

  /**
   * The errors.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Errors with paths in the gesture's parameter space.
   */
  public function getErrors(): array {
    return $this->errors;
  }

  /**
   * The errors as plain arrays, for JSON responses.
   */
  public function getErrorsAsArrays(): array {
    return array_map(static fn (BlueprintError $error) => $error->toArray(), $this->errors);
  }

}
