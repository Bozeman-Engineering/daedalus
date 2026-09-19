<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_stencil;

/**
 * Projections of a stencil definition's element tree.
 *
 * A definition's `element` is the lean node tree minus per-placement facts
 * (StencilExporter's grammar: no uuids, variant references as bare ids,
 * region nodes only under a multi-region layout). Consumers read it
 * without placing it, and the projections live here so the grammar is
 * read in one place:
 * - dialectNodes(): the tree in the shape DialectPrinter prints, the
 *   definition as a document without ids. The roster record carries it
 *   (MaterialRecord::tree) so the schema prints every stencil as a
 *   page-language fragment, and page_read prints the same tree on
 *   demand.
 * - wears(): the variant ids a node's own styles entry wears; on the
 *   root, the record's applies.wears.
 */
final class StencilElementTree {

  /**
   * The tree in the shape DialectPrinter prints.
   *
   * Every variant reference becomes the printer's reference map (the
   * shape the style map stores and NodeTreeBuilder carries); nothing else
   * changes: the tree stays id-less, so the print carries no id attribute
   * and no <style> block, and region nodes stay region nodes (the printer
   * refuses them for a definition exactly as it does for a page).
   *
   * @param array $element
   *   The definition's element tree.
   *
   * @return array
   *   The printer's tree: a list holding the one root node.
   */
  public static function dialectNodes(array $element): array {
    return [self::dialectNode($element)];
  }

  /**
   * One node with its variant references expanded, recursively.
   */
  private static function dialectNode(array $node): array {
    if (isset($node['styles']) && is_array($node['styles'])) {
      foreach ($node['styles'] as $local => $entry) {
        if (!is_array($entry) || !isset($entry['variants']) || !is_array($entry['variants'])) {
          continue;
        }
        $node['styles'][$local]['variants'] = array_map(
          static fn ($id): array => ['variant' => (string) $id],
          array_values($entry['variants']),
        );
      }
    }
    if (isset($node['children']) && is_array($node['children'])) {
      $node['children'] = array_map(
        static fn ($child): mixed => is_array($child) ? self::dialectNode($child) : $child,
        array_values($node['children']),
      );
    }
    return $node;
  }

  /**
   * The variant ids a node's own styles entry wears, definition order.
   *
   * The node's own entry ('' in the element-relative grammar) and its
   * "variants" list, as the exporter writes them and the instantiate
   * handler expands them (StencilOperationHandler::expandStyles()); a
   * list that is not a list of names is the handler's to refuse at
   * placement, so it reads as no wearings here. On the root this is the
   * record's applies.wears (MaterialRecord::APPLIES_WEARS).
   *
   * @return string[]
   *   The ids.
   */
  public static function wears(array $node): array {
    $variants = $node['styles']['']['variants'] ?? [];
    if (!is_array($variants) || !array_is_list($variants)) {
      return [];
    }
    return array_values(array_filter($variants, static fn ($id): bool => is_string($id) && $id !== ''));
  }

}
