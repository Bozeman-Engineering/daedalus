import { POLICY } from './resolver';

/**
 * The selection grammar's depth policy: whether a canvas gesture resolves the
 * deepest DEPICTED thing under the pointer (the LEAF element, or a part
 * whose row the page elements panel currently draws: reach.js) or stops
 * at the boundary of the selection.
 *
 * Two sources feed the ONE policy: the platform modifier held on the
 * pointer event (Cmd on macOS, Ctrl elsewhere; the resolver reads both
 * flags so a Mac Ctrl-click deep-selects too) and the armed element
 * picker (the selection slice's pickerArmed, set by the page elements
 * panel's picker toggle or its hotkey). Either arms depth; the picker
 * is not a second resolution path, only a second way to say "deep". Every
 * reader of depth in selector-events.js (element resolution, part
 * resolution) goes through here so the two sources can never drift.
 */

/**
 * Whether the gesture is deep.
 *
 * @param {{metaKey?: boolean, ctrlKey?: boolean}} event
 *   The pointer event, or any object carrying the two modifier flags.
 * @param {boolean} pickerArmed
 *   The selection slice's pickerArmed flag.
 *
 * @return {boolean}
 */
export const isDeep = (event, pickerArmed = false) =>
  Boolean(event?.metaKey || event?.ctrlKey || pickerArmed);

/**
 * The resolver policy for the gesture: LEAF when deep, BOUNDARY otherwise.
 *
 * @param {{metaKey?: boolean, ctrlKey?: boolean}} event
 *   The pointer event.
 * @param {boolean} pickerArmed
 *   The selection slice's pickerArmed flag.
 *
 * @return {string}
 *   One of POLICY.LEAF or POLICY.BOUNDARY.
 */
export const policyFor = (event, pickerArmed = false) =>
  isDeep(event, pickerArmed) ? POLICY.LEAF : POLICY.BOUNDARY;
