/**
 * @file
 * The collapse model: one posture rule for every outline the UI folds.
 *
 * An outline is any surface whose rows open and close: the page elements
 * tree, code mode's page view, the Advanced inspector's sections. Each
 * one projects its rows into the outline shape below per render, holds
 * one `folds` value as its own component state, applies the functions
 * here on its gestures (a chevron, the collapse-all and expand-all
 * controls, a hotkey, the selection rule) and reads `isCollapsed` per
 * row. The model is pure and knows nothing of the DOM; the adapters own
 * their defaults and their reset (an adapter resets by keying its
 * state-owning component on the thing that resets it, a view id or an
 * anchor, so reset is a key here, never a function).
 *
 * The outline shape, a list of nodes:
 *
 *   { id, foldable, folded, children: [...] }
 *
 * `id` is the row's key, unique within the outline. `folded` is the row's
 * DEFAULT posture, what the row shows when the user has not touched it
 * (a unit row in the tree, an empty section in the inspector). `foldable`
 * says whether a chevron is rendered and whether the all-gestures may
 * touch the row: the tree's root and a bare leaf in code mode are
 * `foldable: false`. `children` may be omitted on a leaf.
 *
 * The state, `folds`, is a plain object `{ [id]: boolean }` of explicit
 * postures (true collapsed, false expanded). Plain, not a Set or a Map,
 * so it is serializable, and only ever holding what the user or a rule
 * said: a row absent from it shows its default. The rule is one line,
 * `explicit ?? default`, the same rule the three outlines applied under
 * three names before the model existed.
 *
 * The identity rule: every function returns the SAME object when nothing
 * changes and never mutates the object it was given, so a view holding
 * `folds` as state re-renders only on a real change. A null or undefined
 * state reads as no explicit postures and is returned as is when nothing
 * changes.
 *
 * The single-row writers (toggle, expand, collapse) act on the row they
 * are given; only the all-gestures and foldableIds filter on `foldable`,
 * since an adapter renders a chevron on foldable rows alone and the
 * selection rules (reveal) must open whatever hides the target.
 */

const hasMark = (folds, id) =>
  folds !== null &&
  folds !== undefined &&
  Object.prototype.hasOwnProperty.call(folds, id);

/**
 * The state with the given postures written, or the same object when
 * every one already holds.
 */
const written = (folds, postures) => {
  const changed = Object.entries(postures).filter(
    ([id, collapsed]) => !(hasMark(folds, id) && folds[id] === collapsed),
  );
  if (!changed.length) {
    return folds;
  }
  return { ...(folds ?? {}), ...Object.fromEntries(changed) };
};

/**
 * Every node of an outline in render order (parents before children).
 */
const walk = (outline, visit) => {
  (Array.isArray(outline) ? outline : []).forEach((node) => {
    visit(node);
    walk(node.children, visit);
  });
};

/**
 * Whether a row is collapsed: its explicit posture, else its default.
 *
 * @param {?object} folds
 *   The outline's explicit postures.
 * @param {object} node
 *   The row, in the outline shape.
 *
 * @return {boolean}
 */
export const isCollapsed = (folds, node) =>
  hasMark(folds, node.id) ? Boolean(folds[node.id]) : Boolean(node.folded);

/**
 * The state with one row's posture flipped: the chevron's gesture.
 */
export const toggle = (folds, node) =>
  written(folds, { [node.id]: !isCollapsed(folds, node) });

/**
 * The state with one row expanded, the same object when it already is.
 */
export const expand = (folds, node) =>
  isCollapsed(folds, node) ? written(folds, { [node.id]: false }) : folds;

/**
 * The state with one row collapsed, the same object when it already is.
 */
export const collapse = (folds, node) =>
  isCollapsed(folds, node) ? folds : written(folds, { [node.id]: true });

/**
 * The foldable rows of an outline, in render order.
 */
const foldableNodes = (outline) => {
  const nodes = [];
  walk(outline, (node) => {
    if (node.foldable) {
      nodes.push(node);
    }
  });
  return nodes;
};

/**
 * Every foldable row's id in an outline, in render order: the rows the
 * all-gestures reach.
 *
 * @param {Array<object>} outline
 *
 * @return {Array<string>}
 */
export const foldableIds = (outline) =>
  foldableNodes(outline).map((node) => node.id);

/**
 * The state with every foldable row of the outline collapsed: the
 * collapse-all gesture. Postures of rows the outline does not hold
 * (another scope's rows, rows a view projected away) survive it.
 */
export const collapseAll = (folds, outline) =>
  written(
    folds,
    Object.fromEntries(
      foldableNodes(outline)
        .filter((node) => !isCollapsed(folds, node))
        .map((node) => [node.id, true]),
    ),
  );

/**
 * The state with every foldable row of the outline expanded: the
 * expand-all gesture.
 */
export const expandAll = (folds, outline) =>
  written(
    folds,
    Object.fromEntries(
      foldableNodes(outline)
        .filter((node) => isCollapsed(folds, node))
        .map((node) => [node.id, false]),
    ),
  );

/**
 * Whether every foldable row of the outline is collapsed: collapse-all
 * would do nothing, so its control is disabled. Vacuously true for an
 * outline without a foldable row (both controls are then disabled).
 */
export const allCollapsed = (folds, outline) =>
  foldableNodes(outline).every((node) => isCollapsed(folds, node));

/**
 * Whether every foldable row of the outline is expanded: expand-all
 * would do nothing, so its control is disabled. Vacuously true for an
 * outline without a foldable row.
 */
export const allExpanded = (folds, outline) =>
  foldableNodes(outline).every((node) => !isCollapsed(folds, node));

/**
 * The rows above `id`, from the outline's root down to its parent, as
 * nodes; [] when the outline lacks the id or the id is a root's.
 */
const ancestorNodes = (outline, id) => {
  if (id === null || id === undefined) {
    return [];
  }
  const find = (list, trail) => {
    for (const node of Array.isArray(list) ? list : []) {
      if (node.id === id) {
        return trail;
      }
      const found = find(node.children, [...trail, node]);
      if (found) {
        return found;
      }
    }
    return null;
  };
  return find(outline, []) ?? [];
};

/**
 * The ids of the rows above `id`, from the outline's root down to its
 * parent, the id itself left out. [] for a root's id and for an id the
 * outline lacks, so a caller revealing it opens nothing rather than the
 * wrong rows; a scoped outline answers from its own root.
 *
 * @param {Array<object>} outline
 * @param {?string} id
 *
 * @return {Array<string>}
 */
export const ancestorsOf = (outline, id) =>
  ancestorNodes(outline, id).map((node) => node.id);

/**
 * The state with every row above `id` expanded, so the row is visible:
 * the selection rule. The row itself keeps its posture (a unit row stays
 * a unit when selected; code mode opens the selection separately with
 * expand). The same object when the row is already visible, and no
 * change for an id the outline lacks.
 */
export const reveal = (folds, outline, id) =>
  written(
    folds,
    Object.fromEntries(
      ancestorNodes(outline, id)
        .filter((node) => isCollapsed(folds, node))
        .map((node) => [node.id, false]),
    ),
  );
