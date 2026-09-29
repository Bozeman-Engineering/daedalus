import {
  collapseAll,
  expandAll,
} from '../../../../daedalus_ui/js/outline/collapse.js';

/**
 * @file
 * The seam between code mode's collapse hotkeys and the mounted page view.
 *
 * The page view (page-view.js) owns its outline's posture as component
 * state, per window, and mounts only while the inspector stands in code
 * mode; the collapse-all and expand-all hotkeys register once, at leader
 * boot (index.js), through daedalus_ui's collapse registrar, because the
 * leader alone resolves keys and its own view is UNMOUNTED exactly when
 * the pop-out branch matters (the host shows a placeholder while the
 * panel is popped out). This module is the one slot between them, the
 * apply-variant hotkey's opener seam one surface over: the mounted view
 * installs its reader and setter on mount and clears them on unmount;
 * a hotkey action reads the posture through `readOutline` and lands the
 * next one through `applyOutline`. With no view mounted (another mode,
 * or the leader while popped out) the reader answers an empty outline,
 * so the model's gesture hands back the same posture and the setter is
 * a no-op, and the stroke is handled like any bound key.
 *
 * The satellite fills the same slot in its own document: a hotkey
 * resolved on the leader while the inspector is popped out routes the
 * GESTURE over stylish's protocol (popout-leader.js `collapse-outline`)
 * and satellite-source.js runs it here with `runCollapseGesture`, over
 * the satellite's own posture, since a collapse is a reading posture of
 * one window and the leader's outline is displaced then. Neither window
 * needs to know which one it is: the view installs itself wherever it
 * mounts.
 */

/**
 * The outline's id in the hotkey registry: the bindings are
 * `daedalus_stylish.code.collapse_all` and `.expand_all` (listed in the
 * tray's Panels section, unbound by default, since the page elements
 * outline holds 3 and 4 and both outlines can be open at once), and the
 * view's controls name their key through the same id.
 */
export const OUTLINE_ID = 'daedalus_stylish.code';

/** @type {?{read: function(): {folds: ?object, outline: Array<object>}, apply: function(?object): void}} */
let mounted = null;

/**
 * Installs the mounted page view's posture reader and setter.
 *
 * @param {object} view
 *   - read: function() returning `{ folds, outline }`, the view's
 *     current posture and outline (the latest render's, read at press
 *     time).
 *   - apply: function(next) landing the next posture in the view's state.
 *
 * @return {Function}
 *   Clears the slot, for the view's unmount; a later mount's slot is
 *   left alone.
 */
export const mountOutline = (view) => {
  mounted = view;
  return () => {
    if (mounted === view) {
      mounted = null;
    }
  };
};

/**
 * The mounted view's posture and outline, or an empty outline with no
 * posture when no page view is mounted in this document.
 *
 * @return {{folds: ?object, outline: Array<object>}}
 */
export const readOutline = () =>
  mounted ? mounted.read() : { folds: null, outline: [] };

/**
 * Lands a posture in the mounted view; nothing to land on when no page
 * view is mounted.
 *
 * @param {?object} next
 *   The model's next posture.
 */
export const applyOutline = (next) => {
  mounted?.apply(next);
};

/**
 * Runs one all-gesture over the mounted view's own posture: the
 * satellite's half of a hotkey the leader resolved while the inspector
 * was popped out.
 *
 * @param {string} gesture
 *   'collapse_all' or 'expand_all', the registrar's gesture names.
 */
export const runCollapseGesture = (gesture) => {
  const { folds, outline } = readOutline();
  const run = gesture === 'collapse_all' ? collapseAll : expandAll;
  applyOutline(run(folds, outline));
};
