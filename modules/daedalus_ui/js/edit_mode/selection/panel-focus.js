/**
 * @file
 * Releasing the page-elements panel's DOM focus.
 *
 * Tree rows are real focusable treeitems (page-elements-tree.js), so
 * focus can sit on a row while the canvas keys run at the document
 * level. Two gestures hand it back to the document: F6 toward the canvas
 * (keyboard-traversal.js) and the Selector's Escape once it clears the
 * selection (selector-events.js), which would otherwise leave the
 * browser's focus-visible ring on the row of a selection that is gone.
 * Both go through this one door.
 */

/**
 * Blurs the focused page-elements row, when focus is in the panel.
 *
 * Focus anywhere else (the canvas, the top bar, a sidebar control) is not
 * the panel's to release and is left where it is.
 *
 * @return {boolean}
 *   TRUE when focus was in the panel and has been released.
 */
export const releasePanelFocus = () => {
  const mount = document.getElementById('page-elements-tree');
  if (!mount?.contains(document.activeElement)) {
    return false;
  }
  document.activeElement.blur();
  return true;
};
