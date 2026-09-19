<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_stencil\Exception;

use Drupal\daedalus_blueprint\Result\BlueprintError;

/**
 * A stencil definition cannot be used.
 *
 * Same structured blueprint errors as every other blueprint path, plus the
 * stencil coordinates: which source (a module's stencil file or a stencil
 * content entity) and which stencil ID. Discovery failures are always loud:
 * a shipped stencil that violates the file grammar is a developer error in
 * the shipping module, and an ID collision anywhere in the flat namespace
 * is a conflict to resolve, never something to skip quietly.
 */
class StencilDefinitionException extends \RuntimeException {

  /**
   * Constructs a StencilDefinitionException.
   *
   * @param string|null $provider
   *   The module shipping the failing stencil file, or NULL when the failing
   *   definition is a stencil content entity saved on this site.
   * @param string $stencilId
   *   The failing stencil's ID (the filename suffix, or the content
   *   stencil's machine name).
   * @param \Drupal\daedalus_blueprint\Result\BlueprintError[] $errors
   *   The validation errors.
   */
  public function __construct(
    protected ?string $provider,
    protected string $stencilId,
    protected array $errors,
  ) {
    parent::__construct(sprintf(
      'The "%s" stencil %s is invalid: %s',
      $stencilId,
      $provider === NULL ? 'saved as content on this site' : sprintf('shipped by the "%s" module', $provider),
      $errors !== [] ? $errors[0]->getMessage() : 'The stencil definition failed validation.',
    ));
  }

  /**
   * The module shipping the failing stencil file, NULL for content stencils.
   */
  public function getProvider(): ?string {
    return $this->provider;
  }

  /**
   * The failing stencil's ID.
   */
  public function getStencilId(): string {
    return $this->stencilId;
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
