/**
 * The tree rows' rename grammar — pure decisions, no DOM, no store.
 *
 * Rename is one affordance over ONE op: `update_element`. A SECTION row
 * and a BLOCK row write their configured label through `update_element
 * {label}` (layout_settings.label for a section, the component
 * configuration's label for a block, server-side); an empty commit CLEARS
 * the label, and the row falls back to the name the annotator mints
 * without one (a section's type word, "Section" or "Container"; a block's
 * excerpt or type word). A block's label is offered on every block, and
 * where the label renders as page content (heading-style blocks) the
 * rename changes the page; the row's name follows the annotator's one
 * rule, so a merged block keeps reading its content after a rename while
 * its configured label changed underneath (the input seeds from that
 * label, never from the shown name). The ROOT row is the entity's label
 * field presenting AS the entity row (the annotation's derived
 * edit_target), so its commit is `update_element` on the root element
 * with properties keyed by the edit target (AI parity, tempstore,
 * undo/redo and mirror re-projection all come free), and an empty or
 * unchanged commit CANCELS: the label field is required, there is no
 * fallback name to clear to. The Preact tree row (page-elements-tree.js)
 * renders these decisions and dispatches the op.
 */

/**
 * Whether a row offers the rename affordance.
 *
 * Sections (containers) and blocks at any depth, plus the root row when the
 * blueprint annotation routes an edit target to it. Inline entity rows
 * (paragraphs) share the root's kind but carry no edit_target route, so
 * they stay read-only; a keyless row has no identity to commit against;
 * an inert row (tree-row-model.js rowInert: an element the actor can do
 * nothing to) takes no gesture, rename included. Whether this actor MAY
 * rename what CAN be renamed is the capability gate's (selection/capable.js),
 * asked by the row beside this grammar.
 *
 * @param {Object} node
 *   The projected tree node.
 * @param {boolean} isRoot
 *   Whether this is the tree's root row (depth 0).
 * @param {?string} editTarget
 *   The root annotation's derived edit target (null off the root).
 * @param {boolean} inert
 *   Whether the row is inert (keyless or an element the actor can do
 *   nothing to).
 *
 * @return {boolean}
 */
export const rowRenamable = (node, isRoot, editTarget, inert = false) =>
  !inert &&
  !!node.key &&
  (node.kind === 'container' ||
    node.kind === 'block' ||
    (isRoot && !!editTarget));

/**
 * The ONE operation a rename commit dispatches, or null for a no-op.
 *
 * A section and a block share the label path: the anchor for the
 * unchanged no-op is the node's raw stored label (`customLabel`, the
 * projection's copy of the lean node's `label`), never the displayed
 * name, so typing a merged block's shown excerpt into its input IS a
 * rename (it stores the excerpt as the label).
 *
 * @param {Object} node
 *   The projected tree node being renamed.
 * @param {string} value
 *   The rename input's raw value.
 * @param {Object} context
 *   { isRoot, editTarget, currentTitle }: the root row's gate and its live
 *   title (the unchanged-commit anchor); all irrelevant off the root.
 *
 * @return {?Object}
 *   A blueprint operation, or null when the commit cancels.
 */
export const renameOperation = (
  node,
  value,
  { isRoot = false, editTarget = null, currentTitle = null } = {},
) => {
  const label = value.trim();
  if (isRoot) {
    // Empty cancels (the label field is required); unchanged is a no-op.
    if (!editTarget || label === '' || label === currentTitle) {
      return null;
    }
    return {
      op: 'update_element',
      element: node.key.split(':')[1],
      properties: { [editTarget]: label },
    };
  }
  if (label === (node.customLabel ?? '')) {
    return null;
  }
  return {
    op: 'update_element',
    element: node.key.split(':')[1],
    properties: { label },
  };
};
