// With the extension: a pop-out document imports this file as a native
// module (js/popout/keyboard.js), where the browser resolves nothing.
import { normalizeEvent } from './binding.js';

const CONTROL = 'button, a[href], select, summary';

const NATIVE_KEYS = ['space', 'enter'];

const isElement = (target) => !!target && typeof target.closest === 'function';

const unmodified = (desc) =>
  !desc.modifiers.meta &&
  !desc.modifiers.ctrl &&
  !desc.modifiers.alt &&
  !desc.modifiers.shift;

/**
 * Whether a keystroke's target sits on a native control outside a tree
 * widget: the stroke's `control` fact.
 */
export const isControlTarget = (target) =>
  isElement(target) &&
  target.closest(CONTROL) !== null &&
  target.closest('[role="tree"]') === null;

/**
 * The keyboard sensor: the DOM half of the hotkey machinery, run in every
 * document of the app, the leader page and each pop-out window alike.
 *
 * A keystroke fuses three concerns that only coincide when the app has
 * one document: SENSING (the keydown, the typing fact, the focused
 * control, the browser default), RESOLUTION (bindings x live contexts x
 * remaps) and ACTION. Resolution and action read app state, so they live
 * with it, on the leader (hotkeys.js dispatch). Sensing lives in whichever
 * document has focus and must decide preventDefault synchronously, so this
 * adapter turns each key event into a STROKE, pure data that travels a
 * BroadcastChannel unchanged:
 *
 *   {descriptor, typing, phase, consumed, control}
 *
 * - descriptor: binding.js normalizeEvent(event), {key, modifiers}.
 * - typing: the typing fact, from the `isTyping(target)` input (the
 *   target half in every document; the leader ORs CKEditor focus in).
 * - phase: 'down', 'up', or 'blur' (the window lost focus: keyup events
 *   are lost, so every hold releases).
 * - consumed: a listener earlier in this document already claimed the key
 *   (event.defaultPrevented: CKEditor indenting a list on Tab, an
 *   autocomplete navigating its options). Read by the grammars that yield
 *   to a widget.
 * - control: focus sits on a native interactive control (a button, a
 *   link, a select, a summary) that is not part of a tree widget
 *   (role="tree": a composite whose arrow grammar is the app's own, the
 *   page elements tree). Read by grammars a focused control declines
 *   (the arrow traversal off a verb button or a toolbar link).
 *
 * The focused-control rule: a bare Space or Enter on a focused button,
 * link, select or summary is the control's own activation, in every
 * document, so the sensor yields it, never forwarding or swallowing.
 *
 * The swallow: the sensor asks `claims(descriptor, typing)` synchronously
 * whether the app claims the combo. The leader answers true for
 * everything and lets the action's verdict decide (onKey returns false
 * for "did not handle", and the default propagates exactly as before the
 * split); a pop-out answers from the claim set the leader publishes,
 * because its verdict arrives asynchronously. An 'up' stroke bypasses
 * `claims` and is never swallowed (a release cannot be, and the
 * dispatcher ignores one with no active hold).
 *
 * @param {object} options
 *   - document: the document to listen on (keydown/keyup at bubble phase:
 *     a surface that owns a key, the add panel, the popover, takes it at
 *     capture and stops it before the sensor sees it).
 *   - window: the window whose blur releases holds.
 *   - claims: (descriptor, typing) => boolean, synchronous.
 *   - isTyping: (target) => boolean, the typing fact for a target.
 *   - onKey: (stroke) => the verdict; false means "did not handle".
 *
 * @return {Function}
 *   Stops sensing.
 */
export const createKeyboardSensor = ({
  document,
  window,
  claims,
  isTyping,
  onKey,
}) => {
  const stroke = (event, phase) => ({
    descriptor: normalizeEvent(event),
    typing: isTyping(event.target),
    phase,
    consumed: event.defaultPrevented,
    control: isControlTarget(event.target),
  });

  const onKeyDown = (event) => {
    const down = stroke(event, 'down');
    if (
      NATIVE_KEYS.includes(down.descriptor.key) &&
      unmodified(down.descriptor) &&
      isElement(event.target) &&
      event.target.closest(CONTROL) !== null
    ) {
      return;
    }
    if (!claims(down.descriptor, down.typing)) {
      return;
    }
    if (onKey(down) !== false) {
      event.preventDefault();
      event.stopPropagation();
    }
  };

  const onKeyUp = (event) => {
    onKey(stroke(event, 'up'));
  };

  const onBlur = () => {
    onKey({
      descriptor: null,
      typing: false,
      phase: 'blur',
      consumed: false,
      control: false,
    });
  };

  document.addEventListener('keydown', onKeyDown);
  document.addEventListener('keyup', onKeyUp);
  window.addEventListener('blur', onBlur);

  return () => {
    document.removeEventListener('keydown', onKeyDown);
    document.removeEventListener('keyup', onKeyUp);
    window.removeEventListener('blur', onBlur);
  };
};
