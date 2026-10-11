/**
 * @file
 * The seam between the page elements panel's variant doorways and the
 * mounted Inspector.
 *
 * The variant row-facts provider (row-facts.js) registers once, at
 * leader boot (index.js), and its doorways run from daedalus_ui's row
 * menu, outside any inspector component; the two gestures they lead to
 * are the Inspector's own state: openDocument (the editing session on a
 * variant, anchored on a wearer) and the Outline Variant toggle (the
 * `outlined` reference the canvas paint derives from). This module is
 * the one slot between them, the apply-variant hotkey's opener seam and
 * code mode's collapse seam one surface over: the mounted Inspector
 * installs its two doors on mount and clears them on unmount; a doorway
 * runs through `openDocument` or `toggleOutline` and learns whether an
 * inspector took it. With no inspector mounted (the leader while the
 * panel is popped out) the doors answer false.
 *
 * The satellite fills the same slot in its own document: a doorway run
 * on the leader while the inspector is popped out routes the GESTURE
 * over stylish's protocol (popout-leader.js `doorway`) and
 * satellite-source.js runs it here with `runDoor`, so the session opens
 * and the outline toggles where the inspector actually is. Neither
 * window needs to know which one it is: the Inspector installs its doors
 * wherever it mounts.
 */

/** @type {?{openDocument: function(string, ?string): void, toggleOutline: function(string): void}} */
let mounted = null;

/**
 * Installs the mounted Inspector's doors.
 *
 * @param {object} doors
 *   - openDocument: function(reference, anchor) entering the editing
 *     session on the variant document, anchored on the wearer key (the
 *     Inspector's openDocument with no condition).
 *   - toggleOutline: function(reference) flipping Outline Variant for
 *     the reference (the Inspector's outlineGesture toggle).
 *
 * @return {Function}
 *   Clears the slot, for the Inspector's unmount; a later mount's slot
 *   is left alone.
 */
export const mountDoors = (doors) => {
  mounted = doors;
  return () => {
    if (mounted === doors) {
      mounted = null;
    }
  };
};

/**
 * Opens a variant document in the mounted Inspector, anchored on a
 * wearer.
 *
 * @param {string} reference
 *   The variant's record id.
 * @param {?string} anchor
 *   The wearer key to anchor the session on (the row's key).
 *
 * @return {boolean}
 *   True when an inspector took it; false with none mounted here.
 */
export const openDocument = (reference, anchor = null) => {
  if (!mounted) {
    return false;
  }
  mounted.openDocument(reference, anchor);
  return true;
};

/**
 * Toggles Outline Variant for a reference in the mounted Inspector.
 *
 * @param {string} reference
 *   The variant's record id.
 *
 * @return {boolean}
 *   True when an inspector took it; false with none mounted here.
 */
export const toggleOutline = (reference) => {
  if (!mounted) {
    return false;
  }
  mounted.toggleOutline(reference);
  return true;
};

/**
 * The door names the pop-out protocol carries (popout-leader.js
 * `openDoor`, satellite-source.js `doorway`).
 */
export const DOOR_EDIT = 'edit';
export const DOOR_OUTLINE = 'outline';

/**
 * Runs one routed door over this document's mounted Inspector: the
 * satellite's half of a doorway the leader's row menu ran while the
 * inspector was popped out.
 *
 * @param {{door: string, reference: string, key: ?string}} payload
 *   The door name, the variant reference and, for the edit door, the
 *   wearer key to anchor on.
 *
 * @return {boolean}
 *   Whether an inspector took it.
 */
export const runDoor = ({ door, reference, key = null }) =>
  door === DOOR_EDIT
    ? openDocument(reference, key)
    : door === DOOR_OUTLINE && toggleOutline(reference);
