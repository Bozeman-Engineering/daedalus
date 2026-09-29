import store from '../store/store';
import { parseBinding, releasedBy, eventToBindingString } from './binding';
import { CONTEXT_PRIORITY, resolve, claimSet as deriveClaimSet } from './resolve';
import { createKeyboardSensor } from './keyboard-sensor';
import {
  initCkeditorTracking,
  isCkeditorFocused,
  isTypingTarget,
} from './typing';
import { isLifted } from '../drag/keyboard-lift';

/**
 * The one hotkey dispatcher, the leader's.
 *
 * Every keyboard shortcut in Edit Mode (the selection grammar, the open
 * edit's grammar, verb keys, and anything contrib registers) lives in this registry as a
 * binding: a key combo, an action, and a `when` context that says where
 * it applies. Three roles share the work, each once:
 *
 * - The keyboard SENSOR (keyboard-sensor.js) runs in every document of
 *   the app, the leader page and each pop-out window, and turns a key
 *   event into a stroke: pure data (the descriptor, the typing fact, the
 *   phase, the sensed facts about the focused control).
 * - RESOLUTION (resolve.js, pure) picks the winning binding from the
 *   registry, the live contexts and the stroke; when several bindings
 *   match the same combo, the most specific context wins. Precedence is
 *   the context ladder, not listener registration order. A binding may
 *   declare several contexts (`when` as an array); it fires in whichever
 *   of them is live, at that context's priority.
 * - This module owns the registry, the context readers over the store,
 *   the per-user remaps, the master gate and the ACTION: dispatch(stroke)
 *   is fed by the leader's own sensor and by strokes forwarded from
 *   pop-outs alike (popout-relay.js, the wire's leader half), and
 *   claimSet() tells a pop-out which combos the app would act on right
 *   now, so its synchronous swallow decision matches the leader's
 *   asynchronous verdict.
 *
 * The whole dispatcher is gated on the hotKey store slice, the master
 * switch Edit Mode toggles on entry/exit (window.setHotKey). Nothing else
 * touches it: editing sessions keep hotkeys live and rely on the typing
 * context instead.
 */

/**
 * The context readers, every context but 'typing' (which is a fact of
 * the stroke, not of app state). The circular import with keyboard-lift
 * (it registers bindings, we read its state) is safe: both sides
 * dereference only at call time. The editing context is the store's
 * editingKey, the one reactive fact of an open edit (the engine's
 * editChanged report is its only writer, edit-session.js).
 */
const CONTEXTS = {
  lift: () => isLifted(),
  editing: () => store.getState().selection.editingKey !== null,
  selection: () => !!store.getState().selection.selectedKey,
  global: () => true,
};

const isActive = (context, binding) => CONTEXTS[context](binding);

/** @type {Map<string, object>} */
const bindings = new Map();

/** Hold-kind bindings currently pressed, by id. */
const activeHolds = new Map();

/** @type {Set<Function>} */
const listeners = new Set();

const notify = () => listeners.forEach((listener) => listener());

/**
 * Subscribes to registry changes (for the settings tray's re-render and
 * the pop-out relay's claim broadcast).
 *
 * The registry fills across independently delivered scripts (verb hotkeys
 * arrive with other modules' bundles, and AJAX/big_pipe script delivery
 * has no ordering guarantee), so anything rendering the binding list must
 * re-render on changes rather than snapshot once.
 *
 * @return {Function}
 *   Unsubscribe.
 */
const onChange = (listener) => {
  listeners.add(listener);
  return () => listeners.delete(listener);
};

/**
 * Registers a binding.
 *
 * @param {object} spec
 *   - id: unique id; also the per-user remap storage key.
 *   - key: default binding string ('v', 'mod+d', 'alt'). May be empty:
 *     the binding shows in the settings tray but cannot fire until bound.
 *   - when: context name or array of context names (default 'global').
 *     A multi-context binding fires in whichever of its contexts is live,
 *     so one binding (and one remap) covers e.g. an open edit and the
 *     typing state inside its widget.
 *   - kind: 'press' (default) or 'hold'. Hold actions are called with
 *     (stroke, true) on press and (stroke|null, false) on release: the
 *     releasing 'up' stroke, or null when the window blurred or the
 *     binding was unregistered or rebound.
 *   - action: function(stroke[, pressed]). The stroke is the sensed
 *     keystroke as pure data (keyboard-sensor.js: descriptor, typing,
 *     phase, consumed, control), never the event: it may have been
 *     pressed in another window. Return false to signal "did not handle";
 *     the browser default then propagates normally.
 *   - label: settings-tray label. Bindings without one are not listed,
 *     the escape hatch for true internals; anything a user could press
 *     deliberately should carry a label so the tray documents it.
 *   - section: settings-tray group label (e.g. 'Selection'). Unsectioned
 *     labeled bindings land in the tray's catch-all group.
 *   - remappable: whether the settings tray offers rebinding. Bindings
 *     that aren't are still listed, locked, for discoverability.
 */
const register = (spec) => {
  // `when` is normalized to an array internally; getBindings() exposes it
  // that way too.
  const when = Array.isArray(spec.when) ? spec.when : [spec.when ?? 'global'];
  if (
    !spec.id ||
    !when.length ||
    when.some((context) => !(context in CONTEXT_PRIORITY))
  ) {
    throw new Error(`Invalid hotkey binding: ${JSON.stringify(spec)}`);
  }
  // A per-user remap saved for this id wins over the code default.
  const override = drupalSettings?.daedalusUi?.hotKeys?.[spec.id]?.key;
  const key = override ?? spec.key ?? '';
  bindings.set(spec.id, {
    kind: 'press',
    remappable: false,
    label: null,
    section: null,
    ...spec,
    when,
    key,
    parsed: parseBinding(key),
  });
  notify();
};

const unregister = (id) => {
  releaseHold(id, null);
  if (bindings.delete(id)) {
    notify();
  }
};

/**
 * Rebinds a binding client-side. Persistence is the settings tray's job.
 */
const rebind = (id, key) => {
  const binding = bindings.get(id);
  if (!binding) {
    return;
  }
  releaseHold(id, null);
  binding.key = key;
  binding.parsed = parseBinding(key);
  notify();
};

const getBindings = () => {
  ensureServerBindings();
  return Array.from(
    bindings.values(),
    ({ id, key, when, kind, label, section, remappable }) => ({
      id,
      key,
      when,
      kind,
      label,
      section,
      remappable,
    }),
  );
};

const releaseHold = (id, stroke) => {
  const held = activeHolds.get(id);
  if (held) {
    activeHolds.delete(id);
    held.action(stroke, false);
  }
};

const releaseAll = () => {
  for (const id of Array.from(activeHolds.keys())) {
    releaseHold(id, null);
  }
};

/**
 * Dispatches a stroke: resolves it against the registry and the live
 * contexts and runs the winning action. Fed by the leader's own sensor
 * and by strokes a pop-out forwarded, judged identically: a keystroke
 * pressed in a pop-out acts on the app exactly as if pressed here, the
 * pop-out contributing only its local sensed facts.
 *
 * @param {object} stroke
 *   A keyboard-sensor.js stroke.
 *
 * @return {boolean}
 *   Whether the app handled it: false when the gate is off, nothing
 *   matched, or the action declined, so the sensing document lets the
 *   browser default through.
 */
export const dispatch = (stroke) => {
  if (stroke.phase === 'blur') {
    // Leaving the window loses keyup events, so it releases every hold.
    releaseAll();
    return false;
  }
  if (stroke.phase === 'up') {
    for (const [id, binding] of activeHolds) {
      if (releasedBy(binding.parsed, stroke.descriptor.key)) {
        releaseHold(id, stroke);
      }
    }
    return false;
  }
  if (!store.getState().hotKey.enabled) {
    return false;
  }
  ensureServerBindings();
  const best = resolve(
    bindings.values(),
    stroke.descriptor,
    stroke.typing,
    isActive,
  );
  if (!best) {
    return false;
  }
  if (best.kind === 'hold') {
    if (activeHolds.has(best.id)) {
      // Key repeat on a held binding.
      return true;
    }
    activeHolds.set(best.id, best);
    return best.action(stroke, true) !== false;
  }
  return best.action(stroke) !== false;
};

/**
 * The combos the app claims right now, for the pop-out relay: empty while
 * the gate is off, else resolve.js's partition over the registry and the
 * live contexts.
 *
 * @return {{free: string[], typing: string[]}}
 */
export const claimSet = () => {
  if (!store.getState().hotKey.enabled) {
    return { free: [], typing: [] };
  }
  ensureServerBindings();
  return deriveClaimSet(bindings.values(), isActive);
};

/**
 * Registers the server-declared bindings: one panel-toggle binding per
 * sidebar plugin. The map arrives merged with the user's remaps
 * (HotkeySettings), so def.key is already the effective key. Other
 * entries in the map are bare user remaps for bindings that register
 * from JS (verbs); register() picks those up as overrides.
 *
 * Idempotent and re-invoked from dispatch and getBindings(): the map rides
 * the Edit Mode chrome, which usually lands via AJAX merge AFTER this
 * bundle initialized, so init-time registration alone would miss it.
 */
const ensureServerBindings = () => {
  const map = drupalSettings?.daedalusUi?.hotKeys ?? {};
  Object.entries(map).forEach(([id, def]) => {
    // An empty key still registers: the binding shows in the settings tray
    // as unbound, so the user can assign one.
    if (bindings.has(id) || !def) {
      return;
    }
    if (def.sidebar) {
      register({
        id,
        key: def.key,
        label: def.label,
        section: Drupal.t('Panels'),
        remappable: true,
        // Clicking the panel's declared toggle keeps the hotkey on the
        // exact pointer path (open/close policy, cookies, close guards).
        // A panel without a button (daedalus_explode opens from the Page
        // elements panel's Show menu) toggles through the sidebar manager
        // by its DOM open state, the same open/close policy the button's
        // own click handler applies (sidebar-buttons.js). No panel element
        // on this page, the panel doesn't apply here, means the key falls
        // through to other bindings.
        action: () => {
          const button = document.querySelector(
            `[data-sidebar-button-for="#${def.sidebar}"]`,
          );
          if (button) {
            button.click();
            return;
          }
          const panel = document.getElementById(def.sidebar);
          if (!panel) {
            return false;
          }
          const manager = Drupal.Daedalus.SidebarManager;
          const toggled = panel.classList.contains('daedalus-ui-hidden')
            ? manager.openSidebar(def.sidebar)
            : manager.closeSidebar(def.sidebar);
          toggled.catch((error) => {
            // A refused close (an invalid daedalus_edit form) is expected
            // and already shown to the user; anything else is a bug.
            if (error !== Drupal.Daedalus.SidebarPluginBase.CLOSE_BLOCKED) {
              console.error('Cannot toggle sidebar:', error);
            }
          });
        },
      });
    }
  });
};

/**
 * Boots the dispatcher and the leader's own sensor. Called once by the
 * app entry, before anything that registers bindings.
 *
 * The leader's sensor claims every combo and lets the action's verdict
 * decide the swallow, exactly the pre-split behavior: a declined action
 * (a panel key with no panel, Tab with no edit open) still propagates. Its typing fact is the target half plus CKEditor focus,
 * leader state a pop-out never has. It listens on document, where the
 * FJS trait's synthetic presses land (EditModeTrait::pressHotkey).
 */
export const initHotkeys = () => {
  initCkeditorTracking();
  createKeyboardSensor({
    document,
    window,
    claims: () => true,
    isTyping: (target) => isCkeditorFocused() || isTypingTarget(target),
    onKey: dispatch,
  });
  ensureServerBindings();

  // The public face for non-app consumers (contrib editors, the settings
  // tray) and for the shared outline modules other bundles compile in
  // (js/outline/, which must not import this module: a second bundle's
  // copy would be a second registry over a second store). App code
  // imports register() directly.
  Drupal.Daedalus = Drupal.Daedalus || {};
  Drupal.Daedalus.Hotkeys = {
    register,
    unregister,
    rebind,
    getBindings,
    onChange,
    eventToBindingString,
  };
};

export { register, unregister, rebind, getBindings, onChange };
