/**
 * The tree rows' rename grammar — pure decisions, no DOM, no store.
 *
 * Rename is one affordance over ONE op: `update_element`. A SECTION row
 * writes its custom label through `update_element {label}`
 * (layout_settings.label server-side); an empty commit CLEARS the custom
 * label, falling back to the layout-derived name. The ROOT row is the
 * entity's label field presenting AS the entity row (the annotation's
 * derived edit_target), so its commit is `update_element` on the root
 * element with properties keyed by the edit target — AI parity, tempstore,
 * undo/redo and mirror re-projection all come free — and an empty or
 * unchanged commit CANCELS: the label field is required, there is no
 * fallback name to clear to. The Preact tree row
 * (page-elements-tree.js) renders these decisions and dispatches the op.
 */

/**
 * Whether a row offers the rename affordance.
 *
 * Sections (containers) at any depth, plus the root row when the blueprint
 * annotation routes an edit target to it. Inline entity rows (paragraphs)
 * share the root's kind but carry no edit_target route, so they stay
 * read-only; a keyless row has no identity to commit against.
 *
 * @param {Object} node
 *   The projected tree node.
 * @param {boolean} isRoot
 *   Whether this is the tree's root row (depth 0).
 * @param {?string} editTarget
 *   The root annotation's derived edit target (null off the root).
 *
 * @return {boolean}
 */
export const rowRenamable = (node, isRoot, editTarget) =>
  !!node.key && (node.kind === 'container' || (isRoot && !!editTarget));

/**
 * The ONE operation a rename commit dispatches, or null for a no-op.
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
