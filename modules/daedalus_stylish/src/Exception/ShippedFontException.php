<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Exception;

/**
 * A module-shipped font definition cannot be imported or synced.
 *
 * Same structured blueprint errors as every other Daedalus Stylish write
 * path, plus the disk-lane coordinates: which module's
 * MODULE.daedalus_stylish.fonts.yml and which font entry. Import failures
 * are always loud: a shipped font that cannot import (a malformed entry,
 * a face naming a file assets/fonts/ does not hold) is a developer error
 * in the shipping module, never something to skip quietly.
 */
class ShippedFontException extends FontValidationException {

  /**
   * Constructs the exception.
   *
   * @param string $provider
   *   The module whose fonts file ships the failing definition.
   * @param string $machineName
   *   The failing entry's machine name (its key in the fonts file).
   * @param \Drupal\daedalus_blueprint\Result\BlueprintError[] $errors
   *   The validation errors.
   */
  public function __construct(
    protected string $provider,
    protected string $machineName,
    array $errors,
  ) {
    parent::__construct($errors);
    $this->message = sprintf('The "%s" font shipped by the "%s" module cannot be imported: %s', $machineName, $provider, $this->message);
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
