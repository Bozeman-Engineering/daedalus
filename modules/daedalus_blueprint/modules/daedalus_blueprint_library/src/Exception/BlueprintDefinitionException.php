<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_library\Exception;

use Drupal\daedalus_blueprint\Result\BlueprintError;

/**
 * A blueprint definition cannot be used.
 *
 * Same structured blueprint errors as every other blueprint path, plus the
 * blueprint coordinates: which source (a module's blueprint file or a
 * blueprint content entity) and which blueprint ID. Discovery failures are
 * always loud: a shipped blueprint that violates the file grammar is a
 * developer error in the shipping module, and an ID collision anywhere in
 * the flat namespace is a conflict to resolve, never something to skip
 * quietly.
 */
class BlueprintDefinitionException extends \RuntimeException {

  /**
   * Constructs a BlueprintDefinitionException.
   *
   * @param string|null $provider
   *   The module shipping the failing blueprint file, or NULL when the
   *   failing definition is a blueprint content entity saved on this site.
   * @param string $blueprintId
   *   The failing blueprint's ID (the filename suffix, or the content
   *   blueprint's machine name).
   * @param \Drupal\daedalus_blueprint\Result\BlueprintError[] $errors
   *   The validation errors.
   */
  public function __construct(
    protected ?string $provider,
    protected string $blueprintId,
    protected array $errors,
  ) {
    parent::__construct(sprintf(
      'The "%s" blueprint %s is invalid: %s',
      $blueprintId,
      $provider === NULL ? 'saved as content on this site' : sprintf('shipped by the "%s" module', $provider),
      $errors !== [] ? $errors[0]->getMessage() : 'The blueprint definition failed validation.',
    ));
  }

  /**
   * The module shipping the failing file, NULL for a content blueprint.
   */
  public function getProvider(): ?string {
    return $this->provider;
  }

  /**
   * The failing blueprint's ID.
   */
  public function getBlueprintId(): string {
    return $this->blueprintId;
  }

  /**
   * The validation errors.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   The validation errors.
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
