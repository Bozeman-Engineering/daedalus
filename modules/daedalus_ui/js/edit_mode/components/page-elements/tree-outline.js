/**
 * @file
 * The page elements tree's outline adapter: its rows in the collapse
 * model's shape (outline/collapse.js) and their default postures, pure.
 *
 * The tree (page-elements-tree.js) projects the rendered tree through
 * outlineOf per render and reads each row's posture through the model;
 * what a row DEFAULTS to is decided here, where it is tested beside the
 * model, since the tree component itself needs a browser.
 *
 * The default postures:
 * - the root row (the whole page) never folds: there is nothing outside
 *   it to reveal by collapsing it, so it is not foldable and starts open;
 * - a unit row (the node's presentation ANNOTATION, projected from the
 *   blueprint: the panel's face of the same default stop the canvas
 *   resolver honors) starts collapsed, as does a row with nothing under
 *   it (not foldable either way);
 * - rich text starts collapsed (Decisions: formatted text is noisy): a
 *   model row over instance rows (a formatted text block, or the field
 *   row carrying an open render's carriers) and an instance row with
 *   rows of its own (a list, a list item over its paragraph). While such
 *   a row is collapsed it draws its direct row count as a fact
 *   (foldedRowCount). In the Markup view a text block's shell rows sit
 *   beside its carriers; the block still starts collapsed, since the
 *   content under it is the noise the default hides;
 * - every other pseudo row (a shell chain, a generated box) starts
 *   expanded: it is structure on the way to real rows, so collapsing it
 *   is manual noise-reduction;
 * - a shown-document row (shown-rows.js: the blueprint a door shows by
 *   reference) is a leaf: nothing of the shown document is this host's,
 *   so there is nothing under it to fold.
 *
 * A posture the user sets is keyed by the row id (the model key, or the
 * owner key and part id for a pseudo row), so it survives re-projections
 * of the same rows; expand all, collapse all and the selection reveal
 * reach the rich-text rows like any foldable row. What the selection
 * reveal reveals is decided here too (revealId): the seated part's row
 * when the outline draws it, else the selected element's row, so a leaf
 * seated inside a collapsed formatted text block is the one gesture that
 * opens the block's default.
 */

import { ancestorsOf } from '../../../outline/collapse';

/**
 * Whether a rendered node is an instance row: a pseudo row the instance
 * source minted (markup-view.js toInstanceRow), identity-carrying rich
 * text, drawn in every view.
 */
const isInstanceRow = (node) =>
  node.pseudo === true && node.source === 'instance';

/**
 * Whether a rendered node is rich text with rows under it: a model row
 * over instance rows, or an instance row with rows of its own.
 *
 * @param {object} node
 *   A rendered tree node (a model node or a pseudo row).
 *
 * @return {boolean}
 */
export const richTextRow = (node) => {
  const children = Array.isArray(node.children) ? node.children : [];
  if (node.pseudo) {
    return isInstanceRow(node) && children.length > 0;
  }
  return children.some(isInstanceRow);
};

/**
 * A model row in the collapse model's shape. Keyed by the node's key (a
 * keyless row by its label, the same fallback its render key uses).
 *
 * @param {object} node
 *   A projected model node.
 * @param {boolean} isRoot
 *   Whether the node is the tree's root row.
 *
 * @return {{id: string, foldable: boolean, folded: boolean}}
 */
export const modelRow = (node, isRoot) => ({
  id: node.key ?? node.label,
  foldable: !isRoot && node.children.length > 0,
  folded:
    !isRoot &&
    (node.presentation === 'unit' ||
      node.children.length === 0 ||
      richTextRow(node)),
});

/**
 * A pseudo row in the model's shape. Pseudo siblings can repeat an id
 * under one parent row (two absorbed blocks hoisting identical shell
 * chains into one region), so the id includes the owning model key, as
 * the render key does.
 *
 * @param {object} part
 *   A pseudo row (a shell, an instance carrier, a generated box).
 *
 * @return {{id: string, foldable: boolean, folded: boolean}}
 */
export const pseudoRow = (part) => ({
  id: `pseudo:${part.parentKey}:${part.id}`,
  foldable: part.children?.length > 0,
  folded: richTextRow(part),
});

/**
 * A shown-document row in the model's shape: keyed by the door it sits
 * under (one shown document per door), a leaf (not foldable, reading
 * folded like a leafless model row).
 *
 * @param {object} row
 *   A shown-document row (shown-rows.js).
 *
 * @return {{id: string, foldable: boolean, folded: boolean}}
 */
export const shownRow = (row) => ({
  id: `shown:${row.parentKey}`,
  foldable: false,
  folded: true,
});

/**
 * The count a collapsed rich-text row shows as a fact: its direct rows.
 * Null for an open row and for every row that is not rich text (a unit
 * row or a section collapsed by hand says nothing).
 *
 * @param {object} node
 *   A rendered tree node.
 * @param {boolean} collapsed
 *   The row's posture, as the model reads it.
 *
 * @return {?number}
 */
export const foldedRowCount = (node, collapsed) =>
  collapsed && richTextRow(node) ? node.children.length : null;

/**
 * The rendered tree in the collapse model's shape: one root node whose
 * subtree mirrors the projection, model rows, pseudo rows and shown rows
 * alike.
 *
 * @param {object} tree
 *   The rendered tree's root node.
 *
 * @return {Array<object>}
 */
export const outlineOf = (tree) => {
  const rowOfNode = (node, isRoot) => {
    if (node.shown) {
      return shownRow(node);
    }
    return node.pseudo ? pseudoRow(node) : modelRow(node, isRoot);
  };
  const project = (node, isRoot) => ({
    ...rowOfNode(node, isRoot),
    children: (node.children ?? []).map((child) => project(child, false)),
  });
  return [project(tree, true)];
};

/**
 * The outline's node for a row id, or null when the outline lacks it.
 */
export const rowOf = (outline, id) => {
  for (const node of outline) {
    if (node.id === id) {
      return node;
    }
    const found = rowOf(node.children, id);
    if (found) {
      return found;
    }
  }
  return null;
};

/**
 * The rows from the outline's root down to a key, as one string: the
 * dependency of the tree's two posture rules (the selection rule and the
 * drop rule). It changes when the key changes and when a re-projection
 * moves the key under other rows, and holds through everything else, so
 * a manual re-collapse of a row that still holds the selection sticks
 * (the rule is keyed to the CHANGE, as the per-row rule it replaced was).
 */
export const pathTo = (outline, key) =>
  key ? [...ancestorsOf(outline, key), key].join('\n') : '';

/**
 * The row id the selection rule reveals: the seated part's row when the
 * outline draws it, else the selected element's key, null with nothing
 * selected.
 *
 * A part seat (a canvas Cmd-click on a paragraph, a part row's click,
 * arrow traversal) is a TARGET under the selected key, never a key of
 * its own, so the selection rule read on the key alone opened the path to
 * the block and stopped at its fold (Decisions: formatted text defaults
 * to closed; a seated leaf opens the path to itself). The id is the one
 * pseudoRow mints, so pathTo and reveal reach the part row like any row,
 * and reveal leaves the row's own posture alone (a seated list item with
 * rows of its own stays folded: the leaf is shown, its rows are not).
 * A target whose row the outline does not draw (a tag tier, a chain, a
 * part under a key the tree is not rendering, a shell the view is not
 * drawing) falls back to the key, so the selected element is revealed
 * rather than nothing. The write address (the slice's `address`) is not
 * read: it seats no row.
 *
 * @param {Array<object>} outline
 *   The outline, from outlineOf.
 * @param {object} selection
 *   The selection slice's state (selectedKey and target).
 * @param {?string} selection.selectedKey
 *   The primary selection.
 * @param {?{key: string, selector: string}} selection.target
 *   The styling target seated under it, if any.
 *
 * @return {?string}
 *   The row id to reveal, or null.
 */
export const revealId = (outline, { selectedKey, target }) => {
  if (!selectedKey) {
    return null;
  }
  if (target?.key && target.selector) {
    const { id } = pseudoRow({ parentKey: target.key, id: target.selector });
    if (rowOf(outline, id)) {
      return id;
    }
  }
  return selectedKey;
};
