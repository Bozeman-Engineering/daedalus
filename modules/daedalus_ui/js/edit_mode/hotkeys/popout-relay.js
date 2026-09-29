import store from '../store/store';
import { openChannel } from '../../broadcast/channel.js';
import { dispatch, claimSet, onChange } from './hotkeys';

/**
 * The leader half of the pop-out keyboard wire.
 *
 * A popped-out panel is the same app in another window: every keystroke
 * pressed in a pop-out document is judged by this page's dispatcher
 * against this page's state exactly as if pressed here (hotkeys.js
 * dispatch), the pop-out contributing only its locally sensed facts. The
 * registry, the contexts and the user's remaps exist once, here; a
 * pop-out resolves nothing and acts on nothing. The one decision a
 * pop-out must take synchronously, whether to swallow the browser default
 * at its keydown, it takes from the claim set this relay publishes
 * (hotkeys.js claimSet(): the combos the app would act on right now,
 * partitioned by typing state), because its verdict arrives
 * asynchronously.
 *
 * The wire is daedalus_ui's channel envelope under the 'daedalus_ui'
 * prefix on the edit session's scope (daedalusUi.editSession.scope, the
 * Edit mode's one delivery; a pop-out reads the same value as
 * daedalusUi.popout.scope). Every pop-out document runs the other half
 * (js/popout/keyboard.js, attached by the pop-out route contract). The
 * protocol (all messages `{ type, ...payload }`):
 * - pop-out → 'hello'        `{ id }` I exist; send me the claim set. Sent
 *                            at boot and in reply to leader-ready, so both
 *                            sides of a refresh re-pair.
 * - leader  → 'claims'       `{ free, typing }` the combos claimed right
 *                            now: in reply to hello, and whenever the set
 *                            changes while a pop-out is present.
 * - pop-out → 'key'          `{ stroke }` a sensed keystroke
 *                            (keyboard-sensor.js: descriptor, typing,
 *                            phase, consumed, control); fed to dispatch().
 * - pop-out → 'bye'          `{ id }` window closing.
 * - leader  → 'leader-ready' I (re)booted; any pop-out should hello.
 * - leader  → 'leader-gone'  I'm unloading; a pop-out forgets its claim
 *                            set until a leader-ready. A refresh follows
 *                            with leader-ready.
 *
 * Presence is tracked by handshake per pop-out id (BroadcastChannel has
 * no connection state, and the satellite and the preview shell share the
 * session's channel, so one window's bye must not mark its sibling
 * absent). Claims are derived only while a pop-out is present: a leader
 * with no pop-out does no extra work on selection changes.
 *
 * The set is re-derived on every registry change (a binding registered,
 * rebound or unregistered) and on every store change, and posted only
 * when it differs from the last post. The whole store, not a selector
 * per context input, because the lift is not store state: keyboard-lift
 * marks itself lifted after the drag slice's dispatch it rides
 * (beginExternalDrag) and before the next one (the current drop), so
 * only a recomputation on that next dispatch sees isLifted() true. The
 * derivation is a walk over the registry, cheap enough per dispatch, and
 * the value diff keeps hover traffic off the wire.
 */
export const initPopoutRelay = () => {
  // The edit session's identity rides along only for an edit session with
  // a main entity; without a scope there is no channel a pop-out could be
  // listening on.
  const scope = drupalSettings.daedalusUi?.editSession?.scope;
  if (!scope) {
    return;
  }

  /** @type {Set<string>} */
  const present = new Set();
  let published = null;

  const publish = (force = false) => {
    if (!present.size) {
      return;
    }
    const set = claimSet();
    const serialized = JSON.stringify(set);
    if (force || serialized !== published) {
      published = serialized;
      channel.post('claims', set);
    }
  };

  const channel = openChannel('daedalus_ui', scope, {
    hello: ({ id }) => {
      present.add(id);
      // A pop-out that just said hello has no set, whatever this page
      // last posted.
      publish(true);
    },
    key: ({ stroke }) => {
      dispatch(stroke);
    },
    bye: ({ id }) => {
      present.delete(id);
      if (!present.size) {
        published = null;
      }
    },
  });

  onChange(() => publish());
  store.subscribe(() => publish());

  // Recover a pop-out orphaned by a leader refresh: it re-hellos on
  // leader-ready. The unload signal lets it drop its claim set while no
  // leader would act on a forwarded key.
  channel.post('leader-ready');
  window.addEventListener('pagehide', () => channel.post('leader-gone'));
};
