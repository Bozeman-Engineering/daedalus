/**
 * @file
 * The pop-out half of the keyboard wire: every pop-out document senses
 * keystrokes for the leader.
 *
 * A popped-out panel is the same app in another window, so Edit Mode's
 * keyboard (undo, panel toggles, selection verbs, traversal, and whatever
 * contrib registers) acts from here exactly as from the editing page. The
 * pop-out never resolves or acts: it runs the keyboard sensor
 * (edit_mode/hotkeys/keyboard-sensor.js, the same adapter the leader
 * runs) over its own document and forwards each stroke, pure data, to the
 * leader's dispatcher over daedalus_ui's channel envelope on prefix
 * 'daedalus_ui' with the pop-out's session scope. The leader's registry,
 * contexts and the user's remaps exist once, there; the protocol is
 * documented in the leader half (edit_mode/hotkeys/popout-relay.js).
 *
 * The one thing the pop-out decides is the synchronous swallow: a browser
 * default is prevented only for a combo in the claim set the leader
 * publishes (popout/claims.js), so a keystroke the leader would ignore
 * keeps its native meaning here. Typing is never forwarded: inside a
 * pop-out text field mod+z is the field's own undo (the sensor's typing
 * fact, from the target half of the typing rule; a pop-out hosts no
 * CKEditor, the leader's half). A bare Space or Enter on a focused
 * control is the control's, the sensor's rule in every document.
 *
 * Phases: a 'down' stroke is forwarded when claimed; an 'up' is forwarded
 * only while a 'down' this document forwarded is still pressed (a release
 * is never swallowed and the leader ignores one with no active hold, so
 * an unrelated keyup need not cross the wire); the 'blur' stroke is
 * forwarded as is (release-all on the leader: keyup events are lost when
 * this window loses focus).
 *
 * A native module script (served standalone, see the library definition)
 * so it imports the sensor, the typing rule and the channel relative to
 * its own URL. Attached to every pop-out route by
 * DaedalusUiHooks::pageAttachments, so a new pop-out gets the keyboard
 * the way it gets the chrome: by the route option line.
 */
import { openChannel } from '../broadcast/channel.js';
import { createKeyboardSensor } from '../edit_mode/hotkeys/keyboard-sensor.js';
import { isTypingTarget } from '../edit_mode/hotkeys/typing.js';
import { createClaims } from './claims.js';

// The session scope is the pop-out route contract's one input (the
// leader's ?scope, delivered as daedalusUi.popout.scope); a hand-typed URL
// without one has no leader to pair with, so no keyboard is sensed.
const { scope } = drupalSettings.daedalusUi.popout;
if (scope) {
  // This document's identity on the wire: presence is tracked per pop-out
  // (the satellite and the preview shell share the session's channel), so
  // one window's bye never marks its sibling absent. Not crypto.randomUUID:
  // an insecure origin (the FJS site) has none.
  const id = Math.random().toString(36).slice(2);
  const claims = createClaims();
  // The keys of the 'down' strokes forwarded and not yet released.
  const pressed = new Set();
  const hello = () => channel.post('hello', { id });

  const channel = openChannel('daedalus_ui', scope, {
    claims: (set) => claims.update(set),
    'leader-ready': hello,
    'leader-gone': () => {
      // No leader, nothing claimed: every key keeps its native meaning
      // until a leader-ready brings a fresh set.
      claims.clear();
      pressed.clear();
    },
  });

  createKeyboardSensor({
    document,
    window,
    claims: claims.claims,
    isTyping: isTypingTarget,
    onKey: (stroke) => {
      if (stroke.phase === 'down') {
        pressed.add(stroke.descriptor.key);
        channel.post('key', { stroke });
        return true;
      }
      if (stroke.phase === 'up') {
        if (!pressed.size) {
          return false;
        }
        pressed.delete(stroke.descriptor.key);
      } else {
        pressed.clear();
      }
      channel.post('key', { stroke });
      return false;
    },
  });

  hello();
  window.addEventListener('pagehide', () => channel.post('bye', { id }));
}
