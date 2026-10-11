<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_api\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\Core\Entity\EntityInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Dispatched while a read mode projects the staged document's tree.
 *
 * The read endpoint's derived modes (css today) print from the lean node
 * tree the spine builds for the host (NodeTreeBuilder::build), and the
 * print reads facts the spine does not mint: an element's words come from
 * daedalus_ui's annotator (`_element`), the same annotation the mutation
 * response seam (BlueprintMutationResponseEvent) puts on every delivered
 * tree. This event is that seam's read-side twin: subscribers annotate
 * the tree before the mode prints it, so its nodes wear what the
 * delivered tree wears. The JSON modes (full, summary, element) echo the
 * serializer's blueprint and do not dispatch it.
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
   *   The read mode projecting the tree ('css').
   * @param array $tree
   *   The host's lean node tree (the ordered top-level container nodes).
   */
  public function __construct(
    public readonly Request $request,
    public readonly EntityInterface $entity,
    public readonly string $mode,
    protected array $tree,
  ) {}

  /**
   * Returns the tree.
   *
   * @return array
   *   The tree, as annotated so far.
   */
  public function getTree(): array {
    return $this->tree;
  }

  /**
   * Replaces the tree.
   *
   * @param array $tree
   *   The annotated tree.
   */
  public function setTree(array $tree): void {
    $this->tree = $tree;
  }

}
