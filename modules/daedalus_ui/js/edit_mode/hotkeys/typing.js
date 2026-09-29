/**
 * The typing fact: whether a keystroke belongs to text entry, not to
 * hotkeys. It has two halves because they live in two different places.
 *
 * The target half is pure and belongs to whichever document sensed the
 * keystroke: the target tells whether it is an input, a contenteditable,
 * or the deep-chat panel (a shadow DOM web component; composed keyboard
 * events retarget to its host element, so a tag check covers everything
 * inside it). Stateless beats focus/blur listener pairs because
 * dynamically added inputs are covered with no behavior re-attach. The
 * keyboard sensor computes it in the leader page and in every pop-out
 * document alike.
 *
 * CKEditor is the half that needs state, and that state is the leader's:
 * its balloons, dropdowns and toolbars take focus on elements that are
 * not inputs, so a target check misses them. editor.ui.focusTracker is
 * the editor's own notion of "the user is in me", tracked across every
 * editor on the page; the dispatcher ORs it into the leader's own typing
 * input, never a pop-out's (a pop-out hosts no editor).
 */

/**
 * True when a keystroke's target belongs to text entry.
 *
 * @param {EventTarget|null} target
 *   The keystroke's target: an Element, or document for a synthetic press
 *   dispatched there (never typing).
 *
 * @return {boolean}
 */
export const isTypingTarget = (target) => {
  if (!target || typeof target.closest !== 'function') {
    return false;
  }
  return (
    target.isContentEditable ||
    ['INPUT', 'TEXTAREA', 'SELECT', 'DEEP-CHAT'].includes(target.tagName) ||
    target.closest('[contenteditable="true"]') !== null ||
    target.closest('deep-chat') !== null
  );
};

const ckeditorFocusStates = new Map();
let ckeditorFocused = false;

/**
 * Wires CKEditor focus tracking on the leader page. Called once at app
 * init.
 */
export const initCkeditorTracking = () => {
  document.addEventListener('editor:attached', (event) => {
    const editor = event.detail[0];
    const editorId = editor.sourceElement.id || `editor_${Date.now()}`;

    editor.ui.focusTracker.on('change:isFocused', (evt, name, isFocused) => {
      ckeditorFocusStates.set(editorId, isFocused);
      ckeditorFocused = Array.from(ckeditorFocusStates.values()).some(Boolean);
    });

    ckeditorFocusStates.set(editorId, editor.ui.focusTracker.isFocused);
  });
};

/**
 * Whether any CKEditor on the leader page holds focus right now.
 */
export const isCkeditorFocused = () => ckeditorFocused;
