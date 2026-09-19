<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\OperationHandler;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_blueprint\Result\BlueprintResult;

/**
 * Handles module-provided batch operations.
 *
 * BlueprintOperations dispatches its built-in operations directly; any
 * operation name it does not recognize falls through to the registered
 * operation handlers before failing with INVALID_OPERATION. Register a
 * handler as a service tagged with `daedalus_blueprint.operation_handler`
 * to add operations to the batch grammar.
 *
 * Handlers participate fully in batchOperations() semantics: return a
 * structural failure to collect errors across operations, a semantic
 * failure to trigger rollback, or success with targets and warnings like
 * any built-in operation. Never save the entity — mutate it and let the
 * caller decide persistence.
 */
interface OperationHandlerInterface {

  /**
   * Gets the operation names this handler provides.
   *
   * @return string[]
   *   Operation names (the batch operation 'op' values).
   */
  public function getOperations(): array;

  /**
   * Applies one operation to the entity.
   *
   * String-encoded JSON in the standard parameter keys (fields, data,
   * structure, location, properties, regions) is already decoded by the
   * dispatcher before this is called.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to operate on. Mutate in place; never save.
   * @param array $operation
   *   The full operation definition including the 'op' key.
   * @param array $options
   *   The request-scoped options batchOperations() received, forwarded as-is
   *   — the same lane field handlers read (e.g. 'decode_html_entities'), so
   *   an option means the same thing on every write path.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResult
   *   The operation result.
   */
  public function apply(FieldableEntityInterface $entity, array $operation, array $options = []): BlueprintResult;

  /**
   * Gets the LLM-facing router line for each provided operation.
   *
   * Guidance is always-on context: it rides the batch tool's operations
   * parameter on every request, so it is a router line, not doctrine.
   * Roughly 25 words naming what the op does, its parameter keys, and,
   * when the op has doctrine, the owning module's on-demand skill that
   * carries it (invoked via eb_skill), e.g. for an op 'update_style':
   * 'with "key" selectable key, "properties" map; doctrine in the styling
   * skill'. Placement grammar, identity minting, and batch semantics are
   * stated once on the batch tool itself; never restate them here.
   *
   * @return array<string, string>
   *   One router line keyed by operation name.
   */
  public function getGuidance(): array;

}
