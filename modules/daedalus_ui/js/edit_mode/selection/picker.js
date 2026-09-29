import store from '../store/store';
import { setPickerArmed } from '../store/slices/selection-slice';

/**
 * The element picker's toggle: the one model behind the page elements
 * panel's picker button and the `selector.picker` hotkey.
 *
 * Armed, the selection grammar's depth policy (depth-policy.js) resolves
 * canvas hover and click as if the platform modifier were held, and the
 * first canvas click the grammar handles disarms it (selector-events.js
 * owns that, with the Escape rung and the drop on leaving Edit Mode). A
 * second press disarms. An open edit is no obstacle: the armed click
 * lands through the open edit's press decision like any canvas press
 * (commit, then the deep selection), so the picker followed by a
 * double-click edits a nested leaf in two gestures.
 */

/**
 * Whether the picker is armed.
 *
 * @return {boolean}
 */
export const isPickerArmed = () => store.getState().selection.pickerArmed;

/**
 * Arms the picker, or disarms it when armed.
 */
export const togglePicker = () => {
  const { selection } = store.getState();
  store.dispatch(setPickerArmed(!selection.pickerArmed));
};
