<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_api\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\Core\Entity\EntityInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Dispatched while a read mode projects the staged blueprint.
 *
 * The read endpoint's derived modes (css today) print from the lean node
 * tree built over the serialized blueprint's sections, and the print
 * reads facts the serializer does not mint: an element's words come from
 * daedalus_ui's annotator (`_element`), the same annotation the mutation
 * response seam (BlueprintMutationResponseEvent) puts on every delivered
 * blueprint. This event is that seam's read-side twin: subscribers
 * annotate the blueprint before the tree is built over it, so the tree's
 * nodes wear what the delivered tree wears. The JSON modes (full,
 * summary, element) echo the serializer's blueprint and do not dispatch
 * it.
 */
final class BlueprintReadEvent extends Event {

  /**
   * Constructs a BlueprintReadEvent.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The read request.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being read, in its staged state.
   * @param string $mode
   *   The read mode projecting the blueprint ('css').
   * @param array $blueprint
   *   The serialized blueprint (the backend's serialize()).
   */
  public function __construct(
    public readonly Request $request,
    public readonly EntityInterface $entity,
    public readonly string $mode,
    protected array $blueprint,
  ) {}

  /**
   * Returns the blueprint.
   *
   * @return array
   *   The blueprint, as annotated so far.
   */
  public function getBlueprint(): array {
    return $this->blueprint;
  }

  /**
   * Replaces the blueprint.
   *
   * @param array $blueprint
   *   The annotated blueprint.
   */
  public function setBlueprint(array $blueprint): void {
    $this->blueprint = $blueprint;
  }

}
