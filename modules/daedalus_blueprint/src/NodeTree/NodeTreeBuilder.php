<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\NodeTree;

use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\LayoutBuilder\FieldBlock;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\daedalus_blueprint\Plugin\Block\LinkedBlueprintBlock;

/**
 * The lean node tree's reads: a host's tree, its style map, its nodes.
 *
 * The tree is the spine's canonical page representation: one
 * kind-discriminated tree of containers, regions and blocks with styles
 * on the node, produced by LayoutSerializer (the one producer; its
 * docblock is the shape's reference) and carried by the blueprint's
 * `layout`. It is the representation the model-facing dialect prints
 * from, the instantiator writes, and every client reader walks, so the
 * surfaces project the same nodes by construction.
 *
 * This service is what a consumer holds to read a tree without a
 * blueprint: build() for a host's tree, the style map reads for the
 * printers, and the static node helpers (find(), labelRendered()) every
 * tree walker shares.
 *
 * The flat style map (selectable keys to condition-keyed entries) is a
 * derived delivery index of the tree for the CSS printers, not the
 * canonical shape: a node's `styles` is keyed by the LOCAL remainder of
 * the selectable key after the node's own identity is stripped, and the
 * flat map is exactly reconstructible from the tree plus the node-less
 * keys (`entity:`, `field:` and their rule forms, which no layout node
 * carries). The reconstruction has ONE order, the document's
 * (styleMapOf()): the node-less keys first, then every node in document
 * order, each node's own keys as the node stores them. Storage keeps
 * whatever order the writes left (the style ops append), and nothing
 * reads that order back: the printers on both sides print the canonical
 * order, so an entity's inline tag and the client's live preview emit the
 * same bytes whatever the write history was.
 */
class NodeTreeBuilder {

  public function __construct(
    protected LayoutSerializer $layoutSerializer,
  ) {}

  /**
   * Builds the lean node tree for a host.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host (staged state included: the serializer reads whatever
   *   sections the host carries), its own style map on the nodes.
   *
   * @return array
   *   The ordered list of top-level container nodes.
   */
  public function build(HostInterface $host): array {
    return $this->layoutSerializer->serialize($host);
  }

  /**
   * Builds the lean node tree of a host's OWN document.
   *
   * What build() answers for a host that owns the whole document it
   * renders; for a host rendered inside a foreign document (a page of a
   * locked bundle) the slot sections it owns alone, as top-level
   * containers each tagged with its slot, the owner's template left out.
   * The whole-document capture reads here: a locked page saved as a
   * blueprint is its fills, a page blueprint of slot sections, exactly
   * what a locked page takes as a start.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host.
   *
   * @return array
   *   The ordered list of the host's own top-level container nodes.
   */
  public function buildOwn(HostInterface $host): array {
    return $this->layoutSerializer->serialize($host, NULL, $host->ownSections());
  }

  /**
   * Reads a host's flat style map.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host.
   *
   * @return array
   *   The decoded style map; empty when the host has no style store or no
   *   styles.
   */
  public function styleMap(HostInterface $host): array {
    return $host->styleMap();
  }

  /**
   * Orders a map addressing a host's document canonically, for delivery.
   *
   * The map given, not the host's stored one: delivery layers and
   * re-keys a map before it prints (a template render lands the
   * template's document-level entries on the entity's root), so the
   * caller hands over what it is about to print. The map is distributed
   * onto the host's document and flattened back by styleMapOf(), the one
   * ordering; a key addressing no node of the document is node-less and
   * prints first, in its stored place among the node-less keys.
   *
   * The document is read through the host's own door, which seeds an
   * empty override from the display's template on first read: a caller
   * that renders rather than authors (CssStyleBackend) hands over a host
   * over a disposable clone of the entity (daedalus_stylish's
   * RenderStyles), so the ordering walks the seeded document and the
   * request's entity stays as loaded.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host whose document the map's keys address.
   * @param array $map
   *   The flat map to order.
   *
   * @return array
   *   The same entries, in canonical order.
   */
  public function orderedStyleMap(HostInterface $host, array $map): array {
    return static::styleMapOf($this->layoutSerializer->serialize($host, $map), $map);
  }

  /**
   * The flat style map in its canonical order: the document's.
   *
   * The ONE ordering of the map, read by every printer: the server's
   * CssPrinter prints what CssStyleBackend hands it through here, and the
   * client's printMap prints what styleMapOf() in style-guide.js (this
   * function's twin, locked byte for byte by PrinterEquivalenceTest)
   * builds from the mirror's tree. Node-less keys come first (`entity:`
   * and `field:` keys and their rule forms, which no layout node carries:
   * the root and its fields render before any section), in their stored
   * order; then every node in document order (depth-first, a container
   * before its children, region nodes transparent), each node's own keys
   * in the order the node stores them. Order between different nodes
   * carries no cascade meaning (specificity, then @scope proximity
   * decide), and a node's own keys keep their stored order verbatim, so
   * the rendered result never changes by this: only which byte sequence
   * prints it, which is now the same from both printers.
   *
   * @param array $nodes
   *   The lean tree, styles on the nodes.
   * @param array $styles
   *   The flat map the node-less keys are read from. A key a tree node
   *   carries is the node's and is read from the node, so the map may be
   *   the whole stored map or the node-less remainder alone (the
   *   blueprint's top-level `styles`): either way the result holds every
   *   key exactly once.
   *
   * @return array
   *   Absolute selectable keys to their entries, in canonical order.
   */
  public static function styleMapOf(array $nodes, array $styles): array {
    $carried = [];
    self::collectStyles($nodes, $carried);
    return self::nodeless($styles, $carried) + $carried;
  }

  /**
   * The keys of a flat map that no node of a tree carries, in stored order.
   *
   * The blueprint's top-level `styles`: what is left of the map once the
   * nodes own theirs. A stored key addressing a node the document no
   * longer has (a stale key) is node-less by this reading and stays in the
   * map, so nothing is dropped and the map stays exactly reconstructible.
   *
   * @param array $nodes
   *   The lean tree, styles on the nodes.
   * @param array $styles
   *   The flat map.
   *
   * @return array
   *   The entries of $styles no node carries.
   */
  public static function nodelessStyles(array $nodes, array $styles): array {
    $carried = [];
    self::collectStyles($nodes, $carried);
    return self::nodeless($styles, $carried);
  }

  /**
   * The entries of a map whose keys are not among the carried keys.
   */
  private static function nodeless(array $styles, array $carried): array {
    $map = [];
    foreach ($styles as $key => $entry) {
      $key = (string) $key;
      if (!array_key_exists($key, $carried)) {
        $map[$key] = $entry;
      }
    }
    return $map;
  }

  /**
   * Collects the tree's node styles under their absolute keys, in order.
   *
   * @param array $nodes
   *   Lean nodes (a tree, or any node list).
   * @param array &$carried
   *   Absolute keys to entries, appended in document order.
   */
  protected static function collectStyles(array $nodes, array &$carried): void {
    foreach ($nodes as $node) {
      if (!is_array($node)) {
        continue;
      }
      $kind = $node['kind'] ?? NULL;
      // A linked node's identity is its block component's: block-keyed.
      if ($kind === LinkedBlueprintBlock::KIND) {
        $kind = 'block';
      }
      if (($kind === 'container' || $kind === 'block') && isset($node['uuid'])) {
        $prefix = $kind . ':' . $node['uuid'];
        foreach (is_array($node['styles'] ?? NULL) ? $node['styles'] : [] as $local => $entry) {
          $carried[$prefix . $local] = $entry;
        }
      }
      self::collectStyles(is_array($node['children'] ?? NULL) ? $node['children'] : [], $carried);
    }
  }

  /**
   * Whether a block's label shows: the one rule behind `label_rendered`.
   *
   * A label renders when it is non-empty and core's label_display is
   * 'visible' (the absent-key default, core's block plugin default): core's
   * block template prints no label element for an empty label, so an
   * empty label with display on shows nothing. Every surface that asks
   * the fact of storage asks here (the tree's `label_rendered`, the
   * blueprint export, daedalus_ui's canvas attribution), so the tree and the
   * canvas agree by construction.
   *
   * @param array $component
   *   A component's configuration, or anything carrying `label` and
   *   `label_display` in core's spelling.
   *
   * @return bool
   *   TRUE when the label renders as page content.
   */
  public static function labelRendered(array $component): bool {
    $label = $component['label'] ?? '';
    return is_string($label) && $label !== '' && ($component['label_display'] ?? 'visible') === 'visible';
  }

  /**
   * Finds the node an element uuid identifies, at any depth.
   *
   * Region nodes are transparent. The node comes back with its subtree
   * (children included); a consumer wanting the element alone prunes it.
   *
   * @param array $nodes
   *   Lean nodes (a tree, or any node list).
   * @param string $uuid
   *   The element uuid.
   *
   * @return array|null
   *   The node, or NULL when no node carries the uuid.
   */
  public static function find(array $nodes, string $uuid): ?array {
    foreach ($nodes as $node) {
      if (!is_array($node)) {
        continue;
      }
      if (($node['kind'] ?? '') !== 'region' && (string) ($node['uuid'] ?? '') === $uuid) {
        return $node;
      }
      $found = self::find($node['children'] ?? [], $uuid);
      if ($found !== NULL) {
        return $found;
      }
    }
    return NULL;
  }

  /**
   * The base block plugin a block node is an instance of, or NULL.
   *
   * The one derivation every styling and dialect surface shares (the
   * server's SelectorMatcher, the dialect's axis and class views, the
   * clients' twins): a plugin block's plugin id with any derivative
   * stripped (derivatives share one template, so `system_menu_block:main`
   * styles as `system_menu_block`), `field_block` for a node that names a
   * field (the node spells no derivative, and no reader needs the subject's
   * bundle to address it), NULL for an inline block (addressed by its
   * block_type) and for any node that is not a block.
   *
   * @param array $node
   *   A lean node.
   *
   * @return string|null
   *   The base plugin id, or NULL.
   */
  public static function pluginBase(array $node): ?string {
    if (is_string($node['field'] ?? NULL) && $node['field'] !== '') {
      return FieldBlock::PLUGIN;
    }
    // A linked node spells no plugin id; its kind is the linked block's.
    if (($node['kind'] ?? NULL) === LinkedBlueprintBlock::KIND) {
      return LinkedBlueprintBlock::PLUGIN_ID;
    }
    $plugin_id = $node['plugin_id'] ?? NULL;
    if (!is_string($plugin_id) || $plugin_id === '') {
      return NULL;
    }
    return explode(':', $plugin_id, 2)[0];
  }

}
