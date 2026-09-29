<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Dialect;

/**
 * The result of parsing a dialect document or fragment.
 *
 * The tree is the lean node tree (NodeTreeBuilder's shape): what parses
 * cleanly is exactly what the spine's blueprint machinery consumes, so the
 * dialect adds no second validation path. The tree is best-effort when
 * errors are present (built from what did parse); callers act on it only
 * when isValid().
 */
final class DialectParseResult {

  /**
   * Constructs a parse result.
   *
   * @param array $tree
   *   The parsed lean node tree: an ordered list of top-level nodes. For a
   *   document these are container nodes; for a fragment, any nodes legal
   *   at the insertion point.
   * @param \Drupal\daedalus_blueprint\Dialect\DialectError[] $errors
   *   Structural errors, each with a line number where one applies.
   * @param list<array{name: string, type: string, content: string, line: int}> $files
   *   The document's <pb-file> declarations (bytes the author wrote, to be
   *   saved through the file lane before the tree lands), in document
   *   order. Always empty for a fragment: a declaration is a document's.
   */
  public function __construct(
    public readonly array $tree,
    public readonly array $errors,
    public readonly array $files = [],
  ) {}

  /**
   * Whether the document parsed without a structural error.
   */
  public function isValid(): bool {
    return $this->errors === [];
  }

}
