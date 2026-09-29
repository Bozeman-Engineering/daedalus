import { isSectionNode, sectionChildNodes } from '../style-guide.js';
import {
  elementEntries,
  entryRefs,
  hasCustomStyles,
  findVariant,
  holdsFragments,
} from '../variant-scope.js';
import { variantLayerId } from './layers.js';

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
 * Walks the pared layout tree into nodes of {key, kind, type, name,
 * depth, children}; `type` and `name` are the element-naming spine's
 * two words the pared layout carries (style-guide.js layoutTree, from
 * the annotator's `_element.type` / `_element.name`: a page band
 * "Section" named by its label, a nested one "Container", a block its
 * type's label named by its content excerpt; an unrenamed element's name
 * is its type word), the page view's row title. No machine id rides a
 * node: the layout id and block type are the guide's business, never a
 * row's word. A container's
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
          type: node.type ?? null,
          name: node.name ?? null,
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
      type: section.type ?? null,
      name: section.name ?? null,
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
 * A row's two words: the element's type word and, beside it, its name.
 *
 * The one spelling of "what this row is" for every chrome row over an
 * element (the page tree's rows, the variant document's slot headers,
 * both hosts): the minted type word first ("Section", "Text"), the
 * minted name after it ("Welcome", "Welcome!") ONLY when it says more
 * than the type does. The annotator names an unrenamed element with its
 * type word, so an equal name is no name: the row reads "Section", never
 * "Section Section". A node without a type word falls back to the
 * caller's word (the row must say something); a node without a name
 * carries none.
 *
 * @param {?{type: ?string, name: ?string}} node
 *   Anything carrying the spine's two words (a pageNodes() node, the
 *   matcher's facts).
 * @param {string} fallback
 *   The word for a node the spine never named.
 *
 * @return {{word: string, name: ?string}}
 */
export const rowWords = (node, fallback = '') => {
  const word = typeof node?.type === 'string' && node.type !== '' ? node.type : fallback;
  const name = typeof node?.name === 'string' ? node.name.trim() : '';
  return { word, name: name !== '' && name !== word ? name : null };
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
 * The tree the code view's scope projects, before visibleTree annotates it.
 *
 * One pure projection for the three scopes (code-scope.js): `page` is
 * the whole page tree; `subtree` is subtreeNodes' rule (the selection
 * with its descendants, a synthetic childless node when the walk lacks
 * it); `element` is that anchor alone, its children dropped, so the tree
 * prints one row. Element and subtree without a selection project
 * nothing: the view renders the empty state there, never the page
 * (falling through to the whole page would make the scope lie).
 * visibleTree runs over the result as over the page tree; the scope is
 * the only thing that ever drops a row.
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
 * included). This is visibleTree's annotation and the islands-vs-bare-row
 * fork: an unstyled element has nothing an island could print, so its
 * row renders bare, no editor beneath, until it is selected.
 */
export const isStyledElement = (map, key) =>
  hasCustomStyles(map, key) || entryRefs(map?.[key]).length > 0;

/**
 * The render tree: the scoped tree's nodes annotated with their styled
 * flag, nothing dropped.
 *
 * Every element of the scoped tree is a row, styled or not (the styled
 * only rule of 2026-08-06 is retired: the outline is a structure view,
 * and a child that only appears once selected reads as missing). What
 * the flag decides is the row's body, never its presence: a styled row
 * prints its islands, an unstyled row is bare (a header alone, no editor
 * beneath, the honesty rule) and selecting it mounts the real islands.
 * The scope (scopeNodes) is the only thing that drops a row, so a
 * selection is always in the tree it projects and needs no keep list.
 *
 * @param {Array<object>} nodes
 *   pageNodes()' tree, or scopeNodes()' projection of it.
 * @param {object} map
 *   The style map (mirror view).
 *
 * @return {Array<object>}
 *   The annotated tree, same node shape plus {styled: boolean}.
 */
export const visibleTree = (nodes, map) => {
  const walk = (list) =>
    list.map((node) => ({
      ...node,
      styled: isStyledElement(map, node.key),
      children: walk(node.children ?? []),
    }));
  return walk(Array.isArray(nodes) ? nodes : []);
};

/**
 * Whether a rendered row has a body a collapse could hide.
 *
 * A styled row mounts its islands, a row with children holds them, and
 * the selected row always mounts the full islands body (gestures and
 * placeholder islands included), styled or not. A bare leaf (an unstyled,
 * unselected element without children) has none: its row is the header
 * alone, so it carries no chevron, only the chevron's footprint.
 */
export const hasBody = (node, selected = null) =>
  Boolean(node.styled) ||
  (node.children?.length ?? 0) > 0 ||
  node.key === selected;

/**
 * A rendered tree in the collapse model's outline shape
 * (daedalus_ui/js/outline/collapse.js): one row per rendered node keyed
 * by the element key, foldable when hasBody says a chevron reaches it,
 * every row open by default (today's reading: nothing is hidden until
 * the user collapses it), children nested. The view projects it per
 * render and holds its posture (the model's `folds`) as its own state;
 * the controls, the hotkeys and the selection rule all run the model
 * over this projection, so a bare leaf never enters an all-gesture and
 * a row the scope projected away keeps whatever posture it had.
 *
 * @param {Array<object>} tree
 *   visibleTree()'s rendered tree.
 * @param {?string} selected
 *   The selected element key (the selected bare leaf is foldable).
 *
 * @return {Array<object>}
 */
export const outlineOf = (tree, selected = null) =>
  (Array.isArray(tree) ? tree : []).map((node) => ({
    id: node.key,
    foldable: hasBody(node, selected),
    folded: false,
    children: outlineOf(node.children, selected),
  }));

/**
 * A folded row's one-line summary: what the fold hides of the element.
 *
 * `count` is the element's own declarations in the map, every condition
 * of its root entry and of its part and tag entries beneath it
 * (elementEntries' overrides-only read: worn references are not
 * declarations, and a variant's lines are counted at the variant, not
 * here). `worn` is the layer id of each variant the element wears
 * (variantLayerId, the identity surfaces' hue key), in the entry's
 * order, each once: the wearing story as dots. The summary is a pure
 * read of the map, so the satellite prints it off the broadcast alone.
 *
 * @param {object} map
 *   The style map (mirror view).
 * @param {string} key
 *   The row's element key.
 *
 * @return {{count: number, worn: Array<string>}}
 */
export const foldSummary = (map, key) => {
  const count = Object.values(elementEntries(map, key)).reduce(
    (sum, entry) =>
      sum +
      Object.values(entry).reduce(
        (lines, properties) =>
          lines +
          (properties && typeof properties === 'object'
            ? Object.keys(properties).length
            : 0),
        0,
      ),
    0,
  );
  const worn = [];
  entryRefs(map?.[key]).forEach((ref) => {
    const id = variantLayerId(ref.variant);
    if (!worn.includes(id)) {
      worn.push(id);
    }
  });
  return { count, worn };
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
