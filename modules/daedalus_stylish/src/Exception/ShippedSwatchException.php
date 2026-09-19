<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Exception;

/**
 * A module-shipped swatch definition cannot be imported or synced.
 *
 * Same structured blueprint errors as every other Daedalus Stylish write path, plus
 * the disk-lane coordinates: which module's MODULE.daedalus_stylish.swatches.yml and
 * which swatch entry. Import failures are always loud: a shipped swatch
 * that cannot import is a developer error in the shipping module, never
 * something to skip quietly.
 */
class ShippedSwatchException extends SwatchValidationException {

  /**
   * Constructs the exception.
   *
   * @param string $provider
   *   The module whose swatches file ships the failing definition.
   * @param string $machineName
   *   The failing entry's machine name (its key in the swatches file).
   * @param \Drupal\daedalus_blueprint\Result\BlueprintError[] $errors
   *   The validation errors.
   */
  public function __construct(
    protected string $provider,
    protected string $machineName,
    array $errors,
  ) {
    parent::__construct($errors);
    $this->message = sprintf('The "%s" swatch shipped by the "%s" module cannot be imported: %s', $machineName, $provider, $this->message);
  }

  /**
   * The module shipping the failing definition.
   */
  public function getProvider(): string {
    return $this->provider;
  }

  /**
   * The failing entry's machine name.
   */
  public function getMachineName(): string {
    return $this->machineName;
  }

}
