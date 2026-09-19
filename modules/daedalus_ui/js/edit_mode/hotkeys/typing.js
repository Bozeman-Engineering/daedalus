/**
 * Typing detection — the guard that keeps hotkeys from firing while the
 * user is entering text.
 *
 * Most of it is stateless: the event target tells us whether the keystroke
 * belongs to an input, a contenteditable, or the deep-chat panel (a shadow
 * DOM web component; composed keyboard events retarget to its host
 * element, so a tag check covers everything inside it). Stateless beats
 * the old focus/blur listener pairs because dynamically added inputs are
 * covered with no behavior re-attach.
 *
 * CKEditor is the exception that needs state: its balloons, dropdowns, and
 * toolbars take focus on elements that are not inputs, so a target check
 * misses them. editor.ui.focusTracker is the editor's own notion of "the
 * user is in me", tracked across every editor on the page.
 */

const ckeditorFocusStates = new Map();
let ckeditorFocused = false;

/**
 * Wires CKEditor focus tracking. Called once at app init.
 */
export const initTypingDetection = () => {
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
 * True when the keystroke belongs to text entry, not to hotkeys.
 */
export const isTypingEvent = (event) => {
  if (ckeditorFocused) {
    return true;
  }
  const target = event.target;
  if (!(target instanceof Element)) {
    return false;
  }
  return (
    target.isContentEditable ||
    ['INPUT', 'TEXTAREA', 'SELECT', 'DEEP-CHAT'].includes(target.tagName) ||
    target.closest('[contenteditable="true"]') !== null ||
    target.closest('deep-chat') !== null
  );
};
