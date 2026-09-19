<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Result;

use Drupal\Core\Entity\EntityInterface;

/**
 * Shared machinery of the blueprint result objects.
 *
 * Carries the phase reached, the errors and warnings collected, and the
 * entity an operation produced; mints each outcome through the factories;
 * serializes the common shape. A concrete result narrows getEntity() to the
 * entity family it serves (fieldable content, config) and describes that
 * entity for toArray() through entitySummary().
 */
abstract class BlueprintResultBase implements BlueprintResultInterface {

  /**
   * Constructs a new result.
   *
   * @param string $phase
   *   The phase reached ('structural', 'semantic', 'hydrated', 'validated').
   * @param \Drupal\daedalus_blueprint\Result\BlueprintError[] $errors
   *   The errors collected during the operation.
   * @param \Drupal\daedalus_blueprint\Result\BlueprintWarning[] $warnings
   *   The warnings collected during the operation.
   * @param \Drupal\Core\Entity\EntityInterface|null $entity
   *   The entity if the operation succeeded.
   */
  public function __construct(
    protected readonly string $phase,
    protected readonly array $errors = [],
    protected readonly array $warnings = [],
    protected readonly ?EntityInterface $entity = NULL,
  ) {}

  /**
   * Creates a successful result with a hydrated entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The hydrated entity.
   * @param \Drupal\daedalus_blueprint\Result\BlueprintWarning[] $warnings
   *   Any warnings collected.
   *
   * @return static
   *   A new successful result.
   */
  public static function success(EntityInterface $entity, array $warnings = []): static {
    return new static('hydrated', [], $warnings, $entity);
  }

  /**
   * Creates a successful validate-only result.
   *
   * @param \Drupal\daedalus_blueprint\Result\BlueprintWarning[] $warnings
   *   Any warnings collected.
   *
   * @return static
   *   A new validated result.
   */
  public static function validated(array $warnings = []): static {
    return new static('validated', [], $warnings);
  }

  /**
   * Creates a failed result from structural validation.
   *
   * @param \Drupal\daedalus_blueprint\Result\BlueprintError[] $errors
   *   The structural validation errors.
   *
   * @return static
   *   A new failed result.
   */
  public static function structuralFailure(array $errors): static {
    return new static('structural', $errors);
  }

  /**
   * Creates a failed result from semantic validation.
   *
   * @param \Drupal\daedalus_blueprint\Result\BlueprintError[] $errors
   *   The semantic validation errors.
   * @param \Drupal\daedalus_blueprint\Result\BlueprintWarning[] $warnings
   *   Any warnings collected.
   *
   * @return static
   *   A new failed result.
   */
  public static function semanticFailure(array $errors, array $warnings = []): static {
    return new static('semantic', $errors, $warnings);
  }

  /**
   * {@inheritdoc}
   */
  public function isSuccess(): bool {
    return empty($this->errors);
  }

  /**
   * {@inheritdoc}
   */
  public function getPhase(): string {
    return $this->phase;
  }

  /**
   * {@inheritdoc}
   */
  public function getErrors(): array {
    return $this->errors;
  }

  /**
   * {@inheritdoc}
   */
  public function getWarnings(): array {
    return $this->warnings;
  }

  /**
   * Describes the produced entity for toArray().
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity the operation produced.
   *
   * @return array
   *   The identity keys appended to the serialized result.
   */
  abstract protected function entitySummary(EntityInterface $entity): array;

  /**
   * {@inheritdoc}
   */
  public function toArray(): array {
    $result = [
      'success' => $this->isSuccess(),
      'phase' => $this->phase,
    ];
    if (!empty($this->errors)) {
      $result['errors'] = array_map(
        fn(BlueprintError $error) => $error->toArray(),
        $this->errors,
      );
    }
    if (!empty($this->warnings)) {
      $result['warnings'] = array_map(
        fn(BlueprintWarning $warning) => $warning->toArray(),
        $this->warnings,
      );
    }
    if ($this->entity !== NULL) {
      $result += $this->entitySummary($this->entity);
    }
    return $result;
  }

}
