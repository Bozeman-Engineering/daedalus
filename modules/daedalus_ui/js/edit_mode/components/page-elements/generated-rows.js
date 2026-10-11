/**
 * Generated-box rows: the pseudo rows for the generated boxes (`&::before`,
 * `&::after`) an element's cascade materializes.
 *
 * A generated box is a styling address with no node: it exists iff the
 * registered part behavior's cascade delivers `content` at it
 * (view-registry.js, the behavior contract's generated(key)), so its row
 * comes from the behavior, never from anatomy or the mirror's markup
 * annotation. The rows are appended under the owning element's row after
 * everything the view lists there, and the selection index
 * (projection-index.js) composes them the same way, so a box is the same
 * `key::&::before` address wherever it is read. A box is a cascade fact,
 * markup anatomy rather than content, so its `source` is the Markup
 * view's id (markup-view.js MARKUP_VIEW_ID) exactly like a shell row's:
 * it exists in the index in every view, and the tree draws it and an
 * entry road reaches it (reach.js depictedRows, reachable) only while
 * the Markup view is on. Clicking one seats {parentKey: key, id:
 * '&::before'} as the styling target exactly as a part row seats its
 * part (page-elements-tree.js PartRow); the canvas paints the box through
 * the behavior's selectorFor.
 *
 * The behavior's answer is memoized here on what it reads: the blueprint
 * mirror (the style map and the tree ride it), the materials record set
 * (a worn look's box fragment can flip a box) and the behavior itself (a
 * registration after boot changes the answer). generated(key) builds the
 * box's full style stack twice per call, so the tree and the index, which
 * both ask for every keyed row on every mirror replacement, read one
 * answer per key per source, never one per render.
 */

import store from '../../store/store';
import { getPartBehavior } from './view-registry';
import { MARKUP_VIEW_ID } from './markup-view';
import { boxGlyph } from './row-glyphs';

let source = null;
let cache = new Map();

/**
 * The generated boxes a keyed element owns, from the registered part
 * behavior, memoized on the mirror, the materials and the behavior.
 *
 * @param {string} key
 *   A selectable element key.
 *
 * @return {Array<{id: string, pseudo: string, label: string}>}
 *   The behavior's generated(key) answer in grammar order; [] with no
 *   behavior registered, or one without generated().
 */
export const generatedRows = (key) => {
  const behavior = getPartBehavior();
  if (typeof behavior?.generated !== 'function') {
    return [];
  }
  const state = store.getState();
  const mirror = state.blueprint.blueprint;
  const records = state.materials.records;
  if (
    source?.mirror !== mirror ||
    source?.records !== records ||
    source?.behavior !== behavior
  ) {
    source = { mirror, records, behavior };
    cache = new Map();
  }
  if (!cache.has(key)) {
    cache.set(key, behavior.generated(key));
  }
  return cache.get(key);
};

/**
 * A generated box as a seam pseudo row under its owner: id the box's
 * selector (the row id the click commits as the target), label its words,
 * icon the box glyph (row-glyphs.js: a box has no tag of its own), source
 * the Markup view (the origin reach.js gates on).
 */
const toGeneratedRow = (box, ownerKey) => ({
  pseudo: true,
  id: box.id,
  parentKey: ownerKey,
  label: box.label,
  icon: boxGlyph(),
  source: MARKUP_VIEW_ID,
  children: [],
});

/**
 * A view tree with the generated-box rows appended under every keyed
 * model row, at any depth, after the rows the view already lists there
 * (shells, children, instance rows: a box is the cascade's row and comes
 * last). An absorbed row (its identity merged into the row presenting the
 * content) owns nothing: its key has left the universe, so no row could
 * seat a target on it, and its cascade is not asked.
 *
 * @param {object} node
 *   A view tree node (projection.js projectTree output, or a view
 *   provider's tree in the seam's vocabulary).
 * @param {function} generated
 *   The box source (generatedRows above), keyed by model key.
 *
 * @return {object}
 *   The node with box pseudo rows appended under every keyed row.
 */
export const withGeneratedRows = (node, generated) => {
  const children = node.children.map((child) =>
    withGeneratedRows(child, generated),
  );
  if (node.key && !node.absorbed) {
    children.push(
      ...generated(node.key).map((box) => toGeneratedRow(box, node.key)),
    );
  }
  return { ...node, children };
};
