<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Exception;

/**
 * A module-shipped animation definition cannot be imported or synced.
 *
 * Same structured blueprint errors as every other Daedalus Stylish write path, plus
 * the disk-lane coordinates: which module's MODULE.daedalus_stylish.animations.yml
 * and which animation entry. Import failures are always loud: a shipped
 * animation that cannot import is a developer error in the shipping
 * module, never something to skip quietly.
 */
class ShippedAnimationException extends AnimationValidationException {

  /**
   * Constructs the exception.
   *
   * @param string $provider
   *   The module whose animations file ships the failing definition.
   * @param string $machineName
   *   The failing entry's machine name (its key in the animations file).
   * @param \Drupal\daedalus_blueprint\Result\BlueprintError[] $errors
   *   The validation errors.
   */
  public function __construct(
    protected string $provider,
    protected string $machineName,
    array $errors,
  ) {
    parent::__construct($errors);
    $this->message = sprintf('The "%s" animation shipped by the "%s" module cannot be imported: %s', $machineName, $provider, $this->message);
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
