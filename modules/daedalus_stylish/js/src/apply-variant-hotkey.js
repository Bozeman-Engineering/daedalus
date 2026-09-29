/**
 * The opener seam between the apply-variant hotkey and the wear bar.
 *
 * The hotkey registers once, at leader boot (index.js), while the wear
 * bar that owns the variant picker mounts and unmounts with the selection
 * and the inspector's place. This module is the one slot between them:
 * the mounted WearBar sets its opener on mount and clears it on unmount;
 * the hotkey action calls openPicker() and reports "not handled" (false)
 * when no bar is mounted, so the keypress propagates as if nothing had
 * claimed it. The opener itself returns whether it opened the picker
 * (an apply in flight or an empty offer declines), and that verdict is
 * the action's.
 *
 * Only the leader registers the hotkey, because the leader alone
 * resolves keys: a press in the satellite window is forwarded through
 * daedalus_ui's pop-out keyboard wire and judged there. The open then
 * lands where the panel is. In-page, the action calls openPicker() in
 * the leader document; popped out, it routes the open over stylish's
 * own protocol (open-variant-picker) and satellite-source.js calls
 * openPicker() in the satellite document, where the mounted wear bar
 * filled this module's slot. Neither window needs to know which one it
 * is: the bar installs its opener wherever it mounts.
 */

/** @type {?function(): boolean} */
let opener = null;

/**
 * Installs (or, with null, clears) the mounted wear bar's opener.
 *
 * @param {?function(): boolean} fn
 *   Opens the picker for the current selection over every axis and
 *   returns true when it did.
 */
export const setOpener = (fn) => {
  opener = fn;
};

/**
 * Opens the variant picker through the mounted wear bar.
 *
 * @return {boolean}
 *   True when the picker opened; false when no wear bar is mounted or
 *   the bar declined (busy, nothing applicable). The hotkey action
 *   returns this so an unhandled press propagates.
 */
export const openPicker = () => (opener ? opener() : false);
