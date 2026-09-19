import { isSectionNode, sectionChildNodes } from '../style-guide.js';
import {
  entryRefs,
  hasCustomStyles,
  findVariant,
  holdsFragments,
} from '../variant-scope.js';

/**
 * The page-tree code view model (pure; the view renders it).
 *
 * The page tree is the whole-layout surface of the islands architecture:
 * one chrome row per selectable element in LAYOUT ORDER — the root entity,
 * then containers, their blocks, nested containers recursively — each row
 * hosting the element's islands. Structure comes from the staged layout tree the source
 * serves (style-guide.js layoutTree(), broadcast to the satellite), never
 * from the style map: the map only says which of the structure's elements
 * carry styles. Everything here is data-in/data-out so the same logic
 * serves both hosts and gets node-test backpressure.
 */

/**
 * The ordered element tree the page view renders.
 *
 * Walks the pared layout tree into nodes of {key, kind, depth, children}
 * plus a kind-specific discriminator (layoutId / blockType). A container's
 * children land directly under its row — regions have no keys (nothing
 * could select or style them), so a multi-region section's children
 * flatten under the section row in stored region order. Nested containers
 * recurse in place of components. Nodes without a uuid are skipped.
 * With a root uuid the whole layout nests under one entity node (the
 * "Page" row), so the tree reads Page{container{block}}.
 *
 * @param {Array} layout
 *   The staged layout tree (source.getLayout()).
 * @param {?string} rootUuid
 *   The root entity's uuid (source.getRootUuid()), or null to render the
 *   sections bare.
 *
 * @return {Array<object>}
 *   The node tree, layout order preserved at every depth.
 */
export const pageNodes = (layout, rootUuid = null) => {
  const componentNode = (node, depth) =>
    node?.uuid
      ? {
          key: `block:${node.uuid}`,
          kind: 'block',
          blockType: node.block_type ?? null,
          depth,
          children: [],
        }
      : null;
  const sectionNode = (section, depth) => {
    if (!section?.uuid) {
      return null;
    }
    const children = sectionChildNodes(section)
      .map((node) =>
        isSectionNode(node)
          ? sectionNode(node, depth + 1)
          : componentNode(node, depth + 1),
      )
      .filter(Boolean);
    return {
      key: `container:${section.uuid}`,
      kind: 'container',
      layoutId: section.layout_id ?? null,
      depth,
      children,
    };
  };
  const depth = rootUuid ? 1 : 0;
  const sections = (Array.isArray(layout) ? layout : [])
    .map((section) => sectionNode(section, depth))
    .filter(Boolean);
  if (!rootUuid) {
    return sections;
  }
  return [
    { key: `entity:${rootUuid}`, kind: 'entity', depth: 0, children: sections },
  ];
};

/**
 * The node carrying a key, anywhere in a pageNodes() tree, or null.
 *
 * Depth-first in layout order, so the first (only) carrier wins. The
 * draft-session subtree rule uses it to ask whether a fresh canvas
 * selection still lives INSIDE the capture's subtree (outside means the
 * save session leaves).
 */
export const findNode = (nodes, key) => {
  for (const node of Array.isArray(nodes) ? nodes : []) {
    if (node.key === key) {
      return node;
    }
    const found = findNode(node.children ?? [], key);
    if (found) {
      return found;
    }
  }
  return null;
};

/**
 * One anchor's subtree: the draft-session subtree rule's projection.
 *
 * The anchor's own node with its descendants intact, wherever it sits in
 * the layout walk — the page tree pruned to one element and everything
 * under it. An anchor the layout tree does not carry (a field key, an
 * element the walk skips) still answers: a synthetic childless node, so
 * membership is decidable for every selectable key.
 *
 * @param {Array<object>} nodes
 *   pageNodes()' tree.
 * @param {?string} anchorKey
 *   The subtree root's selectable key.
 *
 * @return {Array<object>}
 *   A one-node tree ([] only without an anchor).
 */
export const subtreeNodes = (nodes, anchorKey) => {
  if (!anchorKey) {
    return [];
  }
  const found = findNode(nodes, anchorKey);
  if (found) {
    return [found];
  }
  return [
    {
      key: anchorKey,
      kind: anchorKey.split('::')[0].split(':')[0],
      depth: 0,
      children: [],
    },
  ];
};

/**
 * The tree the code view's scope projects, before the styled filter.
 *
 * One pure projection for the three scopes (code-scope.js): `page` is
 * the whole page tree; `subtree` is subtreeNodes' rule (the selection
 * with its descendants, a synthetic childless node when the walk lacks
 * it); `element` is that anchor alone, its children dropped, so the tree
 * prints one row. Element and subtree without a selection project
 * nothing: the view renders the empty state there, never the page
 * (falling through to the whole page would make the scope lie).
 * visibleTree runs over the result as over the page tree.
 *
 * @param {Array<object>} nodes
 *   pageNodes()' tree.
 * @param {string} scope
 *   'element' | 'subtree' | 'page' (normalized by the caller).
 * @param {?string} selected
 *   The selected element's key, or null.
 *
 * @return {Array<object>}
 *   The scoped tree, layout order preserved.
 */
export const scopeNodes = (nodes, scope, selected) => {
  const tree = Array.isArray(nodes) ? nodes : [];
  if (scope === 'page') {
    return tree;
  }
  const subtree = subtreeNodes(tree, selected);
  if (scope === 'subtree') {
    return subtree;
  }
  return subtree.map((node) => ({ ...node, children: [] }));
};

/**
 * Whether an element carries any styling of its own.
 *
 * Styled = own override layers (conditions, parts —
 * hasCustomStyles' read) OR worn references (a pure-reference wearing is
 * styling: the element follows a variant, distributed fragment refs
 * included). This is visibleTree's filter predicate and the
 * islands-vs-bare-row fork: an unstyled element has nothing an island
 * could print, so its row renders bare, no editor beneath.
 */
export const isStyledElement = (map, key) =>
  hasCustomStyles(map, key) || entryRefs(map?.[key]).length > 0;

/**
 * The render tree: nodes annotated with their styled flag, filtered to
 * styled elements.
 *
 * The tree scopes are styled-only, always: the unfiltered layout is the
 * page elements panel's job, and the way IN for an unstyled element is
 * selecting it (canvas or panel), never browsing here. A node survives
 * when it is styled itself, when a DESCENDANT survives (chrome ancestors
 * stay so the surviving rows keep their structural context), or when its
 * key is in `keep` — the view passes the selection so the filter never
 * hides the row the user is working in (canvas selection sync must land
 * somewhere visible).
 *
 * @param {Array<object>} nodes
 *   pageNodes()' tree.
 * @param {object} map
 *   The style map (mirror view).
 * @param {object} options
 *   - keep: keys that survive the filter regardless.
 *
 * @return {Array<object>}
 *   The annotated, pruned tree, same node shape plus {styled: boolean}.
 */
export const visibleTree = (nodes, map, { keep = [] } = {}) => {
  const keepSet = new Set(keep);
  const walk = (list) =>
    list
      .map((node) => {
        const children = walk(node.children ?? []);
        const styled = isStyledElement(map, node.key);
        if (!styled && !children.length && !keepSet.has(node.key)) {
          return null;
        }
        return { ...node, styled, children };
      })
      .filter(Boolean);
  return walk(Array.isArray(nodes) ? nodes : []);
};

/**
 * The reference the Re-apply gesture targets, or null.
 *
 * Re-apply exists only on a worn ROOT ('') reference to a composition
 * variant (one holding slot-path fragments): apply_variant again is a
 * fresh resolution over the current structure, dressing newcomers where
 * fragments are unbound (bind-once's explicit counterpart). A distributed
 * fragment reference never offers it — re-application is the composition
 * root's gesture — and a root reference to a fragment-less variant has
 * nothing to distribute.
 *
 * @return {?{variant: string, fragment: string}}
 */
export const reapplyRef = (map, key, variants) =>
  entryRefs(map?.[key]).find(
    (ref) =>
      ref.fragment === '' && holdsFragments(findVariant(variants, ref.variant)),
  ) ?? null;
