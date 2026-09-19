<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_test;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_blueprint\OperationHandler\OperationHandlerInterface;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_blueprint\Result\BlueprintResult;

/**
 * Registers a test_set_name batch operation for registry tests.
 *
 * Sets the entity's name from properties.name. The magic value 'forbidden'
 * returns a semantic failure so tests can exercise batch rollback through
 * a registered handler.
 */
class TestOperationHandler implements OperationHandlerInterface {

  /**
   * {@inheritdoc}
   */
  public function getOperations(): array {
    return ['test_set_name'];
  }

  /**
   * {@inheritdoc}
   */
  public function apply(FieldableEntityInterface $entity, array $operation, array $options = []): BlueprintResult {
    $name = $operation['properties']['name'] ?? NULL;

    if (!is_string($name) || $name === '') {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'properties.name',
          code: 'MISSING_NAME',
          message: 'A non-empty properties.name is required.',
        ),
      ]);
    }

    if ($name === 'forbidden') {
      return BlueprintResult::semanticFailure([
        new BlueprintError(
          path: 'properties.name',
          code: 'FORBIDDEN_NAME',
          message: "The name 'forbidden' is not allowed.",
        ),
      ]);
    }

    $entity->set('name', $name);

    return BlueprintResult::success($entity)
      ->withTargetsFrom($entity->uuid(), ['name']);
  }

  /**
   * {@inheritdoc}
   */
  public function getGuidance(): array {
    return [
      'test_set_name' => 'with "properties" {name}; sets the entity name',
    ];
  }

}
