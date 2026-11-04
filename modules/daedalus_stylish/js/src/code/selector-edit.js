import {
  OWNER,
  digest,
  reach,
} from '../../../../daedalus_ui/js/edit_mode/materials/selector-digest.js';
import { parseCompound } from '../../../../daedalus_ui/js/edit_mode/materials/condition-clause.js';

/**
 * The selector edit: the pure model behind a rule row's editable prelude.
 *
 * A rule's selector is chrome, never buffer text: typing `> d-text {`
 * mints the row, and the row's header reads the canonical selector back
 * (selector-chrome.js Prelude). Editing that selector after the fact is
 * the same typing surface opened in place: a double-click on the header
 * swaps the code face for a one-line editor seeded with the current
 * selector, and the designer retypes it under the mint surface's own
 * reader and gate (selector-digest.js digest, the one digester every
 * door calls). This module is the gesture's model, DOM-free: the state
 * is the selector the row carries (`from`) and the text as typed; every
 * key the editor cares about answers an outcome the chrome dispatches to
 * its host, and the typed text's reach (its structural reach, or
 * nothing) is read per keystroke so the canvas can paint it before
 * anything commits.
 *
 * The outcomes (the decisions in the edit-selector topic):
 * - COMMIT: Enter or a typed `{` (the brace is the gate that mints a row
 *   today) while the text digests to a selector with no condition that
 *   differs from the row's: the host moves the rule (`move_style` on the
 *   host map, a staged move in a document session). `to` is canonical,
 *   so a respelling of the same selector is never a move; a state on a
 *   compound before the last (`li:hover > ul`) is a plain move, and a
 *   typed list is a move to the one `:is()` rule the digester folds it
 *   into (`h1, h2` moves the row to `*:is(h1, h2)`).
 * - NOOP: Enter or `{` on text equal to the row's selector (canonically):
 *   the editor closes and nothing moves.
 * - REFUSED: Enter or `{` on text the digester refuses (a list that does
 *   not fold among them), on a wrapper alone, on the element itself
 *   (`&`), or on text that PEELS a state (`> ul` edited
 *   to `> ul:hover`): a move plus a restructure of the entry's
 *   conditions, which `move_style` does not express, so the corrective
 *   says to nest the state inside the rule as `&:hover` (the
 *   selector-digest decision). The editor stays open with the text, and
 *   the chrome names the refusal (`reason`) through the host's failure
 *   path.
 * - CANCEL: Escape, or blur. Blur cancels here although the page
 *   elements rename commits on blur: a rekey changes a rule's reach
 *   across the page, and a silent move on an accidental click-away is
 *   worse than losing a half-typed selector.
 */

export const COMMIT = 'commit';
export const NOOP = 'noop';
export const REFUSED = 'refused';
export const CANCEL = 'cancel';

/**
 * The gestures the editor reads: Enter and a typed `{` settle, Escape
 * and blur leave. Any other key edits the text and answers nothing.
 */
export const SETTLE_GESTURES = Object.freeze(['Enter', '{']);
export const LEAVE_GESTURES = Object.freeze(['Escape', 'blur']);

/**
 * Opens an edit on a row's selector.
 *
 * @param {string} selector
 *   The row's canonical selector (the chrome's `selector` prop).
 *
 * @return {{from: string, text: string}}
 *   The edit state: the selector the row carries and the text as typed,
 *   seeded with the selector itself.
 */
export const openSelectorEdit = (selector) => {
  if (typeof selector !== 'string' || selector === '') {
    throw new TypeError("A selector edit opens on a row's selector.");
  }
  return { from: selector, text: selector };
};

/**
 * The state after a keystroke: the text as it now reads.
 *
 * @param {{from: string, text: string}} state
 *   The edit state (openSelectorEdit's shape).
 * @param {string} text
 *   The text as typed; anything else reads as empty.
 *
 * @return {{from: string, text: string}}
 *   The state carrying the new text.
 */
export const typedSelectorEdit = (state, text) => ({
  ...state,
  text: typeof text === 'string' ? text : '',
});

/**
 * The reach the typed text would have: the structural reach of the
 * selector it digests to (selector-digest.js reach: a posable state
 * stripped, since it matches nothing until it holds), null while the
 * text does not digest to a rule selector (a refusal, a wrapper alone,
 * the owner itself: the paint clears). Read per keystroke; the chrome
 * hands it to the host's `preview` callback.
 *
 * @param {{from: string, text: string}} state
 *   The edit state (openSelectorEdit's shape).
 * @param {{conditions: ?Object, posable: ?string[]}} options
 *   The digester's options (the guide's condition table).
 */
export const selectorEditPreview = (state, options = {}) => {
  const answer = digest(state.text, options);
  if (
    answer.refusal !== null ||
    answer.selector === null ||
    answer.selector === OWNER
  ) {
    return null;
  }
  return reach(answer.selector, options);
};

/**
 * The outcome of a gesture on the edit, or null when the gesture is
 * ordinary typing.
 *
 * @param {{from: string, text: string}} state
 *   The edit state (openSelectorEdit's shape).
 * @param {string} gesture
 *   'Enter' or '{' (settle), 'Escape' or 'blur' (leave), anything else
 *   edits.
 * @param {{conditions: ?Object, posable: ?string[]}} options
 *   The digester's options (the guide's condition table).
 *
 * @return {?{kind: string, from: string, to?: string, text?: string, reason?: string}}
 *   COMMIT carries `from` and the canonical `to`; NOOP and CANCEL carry
 *   `from`; REFUSED carries `from`, the offending `text` and the
 *   `reason` the chrome names.
 */
export const selectorEditOutcome = (state, gesture, options = {}) => {
  if (LEAVE_GESTURES.includes(gesture)) {
    return { kind: CANCEL, from: state.from };
  }
  if (!SETTLE_GESTURES.includes(gesture)) {
    return null;
  }
  const refused = (reason) => ({
    kind: REFUSED,
    from: state.from,
    text: state.text,
    reason,
  });
  const answer = digest(state.text, options);
  if (answer.refusal !== null) {
    return refused(`${answer.refusal.message}. ${answer.refusal.hint}`.trim());
  }
  const to = answer.selector;
  if (to === null) {
    // A wrapper alone: a condition, which a row's selector never is.
    return refused(
      `"${state.text}" is a condition, not a selector; a row's selector addresses something under the element (a condition nests inside the rule as its own block).`,
    );
  }
  if (to === OWNER) {
    return refused(
      `"${state.text}" is the element itself; a row's selector addresses something under it.`,
    );
  }
  if (answer.condition !== null) {
    // The nesting the corrective names is the peeled clauses' own CSS
    // (a reference's published wrapper, a literal's text), glued to the
    // anchor as the dialect reads a nested prelude.
    const nested = parseCompound(answer.condition, options.conditions ?? {})
      .map((clause) => clause.css ?? `:${clause.text}`)
      .join('');
    return refused(
      `"${state.text}" peels "${answer.condition}" off the row into a condition; a state on the addressed element nests inside the rule as "&${nested}" (a state on a compound before it, "li:hover > ul", moves the rule).`,
    );
  }
  if (to === state.from) {
    return { kind: NOOP, from: state.from };
  }
  return { kind: COMMIT, from: state.from, to };
};
