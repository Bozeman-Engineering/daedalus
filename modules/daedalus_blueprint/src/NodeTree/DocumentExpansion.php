<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\NodeTree;

/**
 * A node tree expanded into spine operations with fresh identity.
 *
 * What DocumentInstantiator::expand() answers: the batch operations that
 * place the tree (structural operations first, in tree order, then every
 * style operation, so a composition variant's apply binds over a subtree
 * that already exists), the node path each operation came from (parallel
 * to the operations, for re-rooting a nested batch's errors onto the
 * tree's own coordinates), the node styles as one map keyed by the
 * absolute selectable key minted for each node (the same entries the
 * style operations were derived from, in tree order, for a consumer that
 * writes a whole map instead of running the style operations), the
 * identity map from each node's source uuid to the uuid minted for its
 * copy (only nodes that carried a source uuid appear), the minted uuids
 * of the root nodes, and the grammar errors the tree carried (an
 * expansion with errors places nothing).
 */
final class DocumentExpansion {

  /**
   * Constructs an expansion.
   *
   * @param array[] $operations
   *   The batch operations, in execution order: the structural operations
   *   first, then the style operations.
   * @param string[] $paths
   *   The node path of each operation, parallel to $operations.
   * @param int $structuralCount
   *   How many leading operations are structural; the rest are style.
   * @param array<string, array> $styles
   *   The styles keyed by absolute selectable key, in the map's canonical
   *   order: a whole document's root entries re-addressed to the host
   *   first (the styles option), then the node styles in tree order, each
   *   entry as authored: an optional "variants" list of machine names plus
   *   condition-keyed property maps.
   * @param array<string, string> $uuidMap
   *   Source uuid => minted uuid, for nodes that carried a source uuid.
   * @param string[] $roots
   *   The minted uuids of the root nodes, in order.
   * @param \Drupal\daedalus_blueprint\Result\BlueprintError[] $errors
   *   The tree's grammar errors.
   */
  public function __construct(
    public readonly array $operations,
    public readonly array $paths,
    public readonly int $structuralCount,
    public readonly array $styles,
    public readonly array $uuidMap,
    public readonly array $roots,
    public readonly array $errors,
  ) {}

  /**
   * Whether the tree expanded without grammar errors.
   */
  public function isValid(): bool {
    return $this->errors === [];
  }

  /**
   * The structural operations alone (no style operation).
   *
   * @return array[]
   *   The operations that place the tree, in execution order.
   */
  public function structuralOperations(): array {
    return array_slice($this->operations, 0, $this->structuralCount);
  }

  /**
   * The node paths of the structural operations, parallel to them.
   *
   * @return string[]
   *   The paths.
   */
  public function structuralPaths(): array {
    return array_slice($this->paths, 0, $this->structuralCount);
  }

}
