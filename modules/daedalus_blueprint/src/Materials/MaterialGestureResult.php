<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Materials;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * The outcome of a material gesture: which material, plus capture noise.
 *
 * The written material reaches the client as a roster record through the
 * materials delta (the owning module's entity hook records the write on
 * the request's MaterialsChangeSet), so the result carries identity, not
 * the record: the kind and id every lane speaks, the entity for a route
 * that echoes its own payload beside the delta, the warnings a capture
 * raised (styling a grammar could not express was dropped, never
 * silently), for a removal the caller stages as a Batch API job, how
 * many sources that job will clean up before the entity goes, and the
 * change targets of any write the gesture made on the HOST (a blueprint
 * capture stamps its source container as the blueprint's first placement),
 * so the batch response re-renders what changed on the page exactly as it
 * does for every other operation.
 */
final class MaterialGestureResult {

  /**
   * Constructs a MaterialGestureResult.
   *
   * @param \Drupal\daedalus_blueprint\Materials\MaterialKind $kind
   *   The material's kind.
   * @param string $id
   *   The material's roster id (MaterialRecord::$id).
   * @param string[] $warnings
   *   Human-readable capture warnings.
   * @param \Drupal\Core\Entity\ContentEntityInterface|null $entity
   *   The material's entity after the gesture, when it still exists.
   * @param int $staged
   *   For a removal: the number of sources a staged cleanup batch will
   *   rewrite before deleting; 0 when the removal completed in-request.
   * @param array<int, array{uuid: string, field_name: string}|string> $targets
   *   The change targets of what the gesture wrote on the host, in the shape
   *   BlueprintResult::withTargets() takes; empty when the gesture only read
   *   the host (every mint but a blueprint capture).
   */
  public function __construct(
    public readonly MaterialKind $kind,
    public readonly string $id,
    public readonly array $warnings = [],
    public readonly ?ContentEntityInterface $entity = NULL,
    public readonly int $staged = 0,
    public readonly array $targets = [],
  ) {}

}
