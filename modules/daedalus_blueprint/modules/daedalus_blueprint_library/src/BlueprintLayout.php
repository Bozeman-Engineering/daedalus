<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_library;

use Drupal\daedalus_blueprint\Plugin\Block\LinkedBlueprintBlock;

/**
 * Readings of a blueprint definition's layout, the grammar read in one place.
 *
 * A served definition's `layout` is the lean node tree in the shape
 * NodeTreeBuilder::build() answers (what DialectPrinter prints and the
 * instantiator places): nodes keyed by kind, styles on the node, every
 * variant reference a map ({variant: id, fragment?, via?}). A FILE may
 * author its references as bare machine names (the sugar the instantiator
 * also reads), so discovery passes a file's tree through normalize() once
 * and every reader of a served definition walks one grammar.
 * - normalize(): bare variant ids to the reference map, recursively;
 *   nothing else changes.
 * - wears(): the variant ids a node's own styles entry wears; on a
 *   block-scope blueprint's root, the record's applies.wears.
 * - referencedVariants(): every authored wearing in a tree, for the
 *   adoption dialogs and the ship lane's variant roster.
 * - withoutUuids(), withoutProvenance(), skeleton(): a document read back
 *   as a definition (the capture's reductions).
 * - parentOf(): the container an element sits in (the capture's
 *   corrective for a block root).
 */
final class BlueprintLayout {

  /**
   * A file's tree in the served grammar.
   *
   * @param array $nodes
   *   The authored root nodes.
   *
   * @return array
   *   The same nodes with every bare variant reference expanded to the
   *   tree's reference map.
   */
  public static function normalize(array $nodes): array {
    return array_map(
      static fn ($node): mixed => is_array($node) ? self::normalizeNode($node) : $node,
      array_values($nodes),
    );
  }

  /**
   * One node with its variant references expanded, recursively.
   */
  private static function normalizeNode(array $node): array {
    if (isset($node['styles']) && is_array($node['styles'])) {
      foreach ($node['styles'] as $local => $entry) {
        if (!is_array($entry) || !isset($entry['variants']) || !is_array($entry['variants'])) {
          continue;
        }
        $node['styles'][$local]['variants'] = array_map(
          static fn ($reference): mixed => is_string($reference) ? ['variant' => $reference] : $reference,
          array_values($entry['variants']),
        );
      }
    }
    if (isset($node['children']) && is_array($node['children'])) {
      $node['children'] = self::normalize($node['children']);
    }
    return $node;
  }

  /**
   * A tree without its elements' identity, recursively.
   *
   * A content blueprint's served layout carries its saved document's uuids
   * (the instantiator maps them to fresh identity at placement); a FRAGMENT
   * of it (the roster record's tree, what the schema and page_read print)
   * is a definition, not a page, and prints id-less like a file's, so the
   * model never addresses a blueprint's own elements from a page.
   *
   * @param array $nodes
   *   Lean nodes.
   *
   * @return array
   *   The same nodes with no uuid key at any depth.
   */
  public static function withoutUuids(array $nodes): array {
    return array_map(static function ($node): mixed {
      if (!is_array($node)) {
        return $node;
      }
      unset($node['uuid']);
      if (isset($node['children']) && is_array($node['children'])) {
        $node['children'] = self::withoutUuids($node['children']);
      }
      return $node;
    }, array_values($nodes));
  }

  /**
   * The variant ids a node's own styles entry wears, definition order.
   *
   * The node's own entry ('' in the element-relative grammar) and its
   * "variants" list in the served grammar: a reference with a non-empty
   * fragment or an origin is derived data (a composition's distribution,
   * a wear with a via), not a wearing of this node, and reads as none; a
   * list that is not a list is the instantiator's to refuse at placement,
   * so it reads as no wearings here. On a block-scope blueprint's root
   * this is the record's applies.wears (MaterialRecord::APPLIES_WEARS).
   *
   * @return string[]
   *   The ids.
   */
  public static function wears(array $node): array {
    return self::authored($node['styles'][''] ?? []);
  }

  /**
   * The variant references a tree's styles carry, authored wearings only.
   *
   * One walk over every node's styles entries (its own, its fields', its
   * parts'), reading each entry as wears() reads the root's: derived
   * references (a fragment, a via) are a composition's distribution and
   * never list. The adoption dialogs offer this set and the ship lane's
   * variant roster is built from it.
   *
   * @param array $nodes
   *   Lean nodes (a tree, or any node list).
   *
   * @return string[]
   *   The unique references, in first-seen order.
   */
  public static function referencedVariants(array $nodes): array {
    $references = [];
    $walk = static function (array $list) use (&$walk, &$references): void {
      foreach ($list as $node) {
        if (!is_array($node)) {
          continue;
        }
        foreach (is_array($node['styles'] ?? NULL) ? $node['styles'] : [] as $entry) {
          foreach (self::authored(is_array($entry) ? $entry : []) as $id) {
            if (!in_array($id, $references, TRUE)) {
              $references[] = $id;
            }
          }
        }
        $walk(is_array($node['children'] ?? NULL) ? $node['children'] : []);
      }
    };
    $walk($nodes);
    return $references;
  }

  /**
   * A tree without placement provenance, recursively.
   *
   * A captured subtree may be a placement of another blueprint (its root
   * stamped `blueprint`); a blueprint is not a placement, so the stamp
   * never travels into a saved document. A linked node inside it keeps
   * its `blueprint`: that key names what the node shows, not where the
   * node came from.
   *
   * @param array $nodes
   *   Lean nodes.
   *
   * @return array
   *   The same nodes with no blueprint key at any depth.
   */
  public static function withoutProvenance(array $nodes): array {
    return array_map(static function ($node): mixed {
      if (!is_array($node)) {
        return $node;
      }
      // A linked node's `blueprint` is the blueprint it shows, its
      // identity, never a stamp: a captured linked placement stays linked.
      if (($node['kind'] ?? NULL) !== LinkedBlueprintBlock::KIND) {
        unset($node['blueprint']);
      }
      if (isset($node['children']) && is_array($node['children'])) {
        $node['children'] = self::withoutProvenance($node['children']);
      }
      return $node;
    }, array_values($nodes));
  }

  /**
   * A tree skeletonized: every inline block's pinned content dropped.
   *
   * The capture option `strip_values`: an inline block keeps its type,
   * its managed configuration and its styles, and loses its block_fields
   * and display label, so every field sample-populates at placement.
   * Plugin and field blocks are structure, not content, and stay whole;
   * containers keep their labels and layout settings.
   *
   * @param array $nodes
   *   Lean nodes.
   *
   * @return array
   *   The skeleton.
   */
  public static function skeleton(array $nodes): array {
    return array_map(static function ($node): mixed {
      if (!is_array($node)) {
        return $node;
      }
      if (($node['kind'] ?? NULL) === 'block' && isset($node['block_type'])) {
        unset($node['block_fields'], $node['label'], $node['label_rendered']);
      }
      if (isset($node['children']) && is_array($node['children'])) {
        $node['children'] = self::skeleton($node['children']);
      }
      return $node;
    }, array_values($nodes));
  }

  /**
   * The uuid of the container an element sits in, or NULL at the root.
   *
   * Region nodes are transparent: a block in a multi-region layout's slot
   * belongs to the container owning the slot.
   *
   * @param array $nodes
   *   Lean nodes (a tree, or any node list).
   * @param string $uuid
   *   The element uuid.
   *
   * @return string|null
   *   The parent container's uuid, or NULL when the element is a root or
   *   is not in the tree.
   */
  public static function parentOf(array $nodes, string $uuid, ?string $parent = NULL): ?string {
    foreach ($nodes as $node) {
      if (!is_array($node)) {
        continue;
      }
      if (($node['kind'] ?? '') !== 'region' && (string) ($node['uuid'] ?? '') === $uuid) {
        return $parent;
      }
      $next = ($node['kind'] ?? '') === 'container' ? (string) ($node['uuid'] ?? '') : $parent;
      $found = self::parentOf($node['children'] ?? [], $uuid, $next === '' ? NULL : $next);
      if ($found !== NULL) {
        return $found;
      }
    }
    return NULL;
  }

  /**
   * The authored variant ids of one styles entry, in order.
   *
   * A string reference, or a map with a variant and neither a fragment
   * nor a via; anything else is derived or malformed and reads as none.
   *
   * @return string[]
   *   The ids.
   */
  private static function authored(array $entry): array {
    $variants = $entry['variants'] ?? [];
    if (!is_array($variants) || !array_is_list($variants)) {
      return [];
    }
    $ids = [];
    foreach ($variants as $reference) {
      if (is_string($reference) && $reference !== '') {
        $ids[] = $reference;
        continue;
      }
      if (!is_array($reference) || !is_string($reference['variant'] ?? NULL) || $reference['variant'] === '') {
        continue;
      }
      if (($reference['fragment'] ?? '') !== '' || (is_string($reference['via'] ?? NULL) && $reference['via'] !== '')) {
        continue;
      }
      $ids[] = $reference['variant'];
    }
    return $ids;
  }

}
