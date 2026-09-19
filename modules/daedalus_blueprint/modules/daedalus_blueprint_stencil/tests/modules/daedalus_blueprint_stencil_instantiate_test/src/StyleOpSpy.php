<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_stencil_instantiate_test;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\State\StateInterface;
use Drupal\daedalus_blueprint\OperationHandler\OperationHandlerInterface;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_blueprint\Result\BlueprintResult;

/**
 * Records style operations instead of writing styles.
 *
 * Stands in for daedalus_stylish's operation handler so instantiate tests can assert
 * the exact operations (and translated selectable keys) the stencil handler
 * emits, without pulling the styling stack into the kernel test. The
 * "explode" variant fails semantically on demand to prove nested-batch
 * rollback.
 */
class StyleOpSpy implements OperationHandlerInterface {

  public const STATE_KEY = 'daedalus_blueprint_stencil_instantiate_test.style_ops';

  public function __construct(protected StateInterface $state) {}

  /**
   * {@inheritdoc}
   */
  public function getOperations(): array {
    return ['update_style', 'apply_variant'];
  }

  /**
   * {@inheritdoc}
   */
  public function apply(FieldableEntityInterface $entity, array $operation, array $options = []): BlueprintResult {
    if (($operation['variant'] ?? NULL) === 'explode') {
      return BlueprintResult::semanticFailure([
        new BlueprintError('variant', 'STYLE_VARIANT_NOT_FOUND', 'The spy was asked to explode.'),
      ]);
    }
    $ops = $this->state->get(self::STATE_KEY, []);
    $ops[] = $operation;
    $this->state->set(self::STATE_KEY, $ops);
    return BlueprintResult::success($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function getGuidance(): array {
    return [];
  }

}
