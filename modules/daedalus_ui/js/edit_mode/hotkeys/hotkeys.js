import store from '../store/store';
import {
  parseBinding,
  normalizeEvent,
  matches,
  specificity,
  releasedBy,
  eventToBindingString,
} from './binding';
import { initTypingDetection, isTypingEvent } from './typing';
import { isArmed } from '../edit_session/edit-session';
import { isLifted } from '../drag/keyboard-lift';

/**
 * The one hotkey dispatcher.
 *
 * Every keyboard shortcut in Edit Mode — tool switching, selection
 * grammar, verb keys, and anything contrib registers — lives in this
 * registry as a binding: a key combo, an action, and a `when` context that
 * says where it applies. One document-level listener dispatches; when
 * several bindings match the same combo, the most specific context wins.
 * Precedence is the ordered context list below, not listener registration
 * order. A binding may declare several contexts (`when` as an array); it
 * fires in whichever of them is live, at that context's priority.
 *
 * Contexts, highest first:
 * - typing: the user is entering text. This is primarily a guard — while
 *   typing, only bindings whose contexts include 'typing' fire (the armed
 *   session's grammar keys declare it alongside 'armed-session').
 * - lift: an element is keyboard-lifted through the insertion caret.
 *   While lifted, the caret-walking keys outrank every selection/tool
 *   grammar: a lift is a committed micro-gesture, like a drag.
 * - armed-session: a page-wide editing session is armed. Lands with the
 *   Edit verb (reserved; nothing activates it yet).
 * - selection: something is selected on the canvas (verb hotkeys).
 * - tool: the binding's tool is the current tool.
 * - global: anywhere in Edit Mode.
 *
 * The whole dispatcher is gated on the hotKey store slice — the master
 * switch Edit Mode toggles on entry/exit (window.setHotKey). Nothing else
 * touches it: editing sessions keep hotkeys live and rely on the typing
 * context instead.
 */

const CONTEXTS = {
  typing: {
    priority: 50,
    // Activity is computed per-event in dispatch (it depends on the event
    // target), not from app state.
    isActive: () => false,
  },
  lift: {
    // The circular import with keyboard-lift (it registers bindings, we
    // read its state) is safe: both sides dereference only at call time —
    // the edit-session pattern.
    priority: 45,
    isActive: () => isLifted(),
  },
  'armed-session': {
    // The circular import with edit-session (it registers bindings, we read
    // its state) is safe: both sides dereference only at call time.
    priority: 40,
    isActive: () => isArmed(),
  },
  selection: {
    priority: 30,
    isActive: () => !!store.getState().selection.selectedKey,
  },
  tool: {
    priority: 20,
    isActive: (binding) => store.getState().tool.currentTool === binding.tool,
  },
  global: {
    priority: 10,
    isActive: () => true,
  },
};

/** @type {Map<string, object>} */
const bindings = new Map();

/** Hold-kind bindings currently pressed, by id. */
const activeHolds = new Map();

/** @type {Set<Function>} */
const listeners = new Set();

const notify = () => listeners.forEach((listener) => listener());

/**
 * Subscribes to registry changes (for the settings tray's re-render).
 *
 * The registry fills across independently delivered scripts — verb hotkeys
 * arrive with other modules' bundles, and AJAX/big_pipe script delivery has
 * no ordering guarantee — so anything rendering the binding list must
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
 *   - key: default binding string ('v', 'mod+d', 'alt'). May be empty —
 *     the binding shows in the settings tray but cannot fire until bound.
 *   - when: context name or array of context names (default 'global').
 *     A multi-context binding fires in whichever of its contexts is live,
 *     so one binding (and one remap) covers e.g. an armed session and the
 *     typing state inside its widget.
 *   - tool: required when `when` includes 'tool'.
 *   - kind: 'press' (default) or 'hold'. Hold actions are called with
 *     (event, true) on press and (event, false) on release/blur.
 *   - action: function(event[, pressed]). Return false to signal "did not
 *     handle" — the event then propagates normally.
 *   - label: settings-tray label. Bindings without one are not listed —
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
  if (!spec.id || !when.length || when.some((context) => !CONTEXTS[context])) {
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
    ({ id, key, when, tool, kind, label, section, remappable }) => ({
      id,
      key,
      when,
      tool,
      kind,
      label,
      section,
      remappable,
    }),
  );
};

const releaseHold = (id, event) => {
  const held = activeHolds.get(id);
  if (held) {
    activeHolds.delete(id);
    held.action(event, false);
  }
};

const onKeyDown = (event) => {
  if (!store.getState().hotKey.enabled) {
    return;
  }
  ensureServerBindings();
  const desc = normalizeEvent(event);
  const typing = isTypingEvent(event);

  let best = null;
  let bestPriority = 0;
  for (const binding of bindings.values()) {
    if (!matches(binding.parsed, desc)) {
      continue;
    }
    // While typing, only the 'typing' context applies — and vice versa. A
    // multi-context binding fires at the highest priority among its live
    // contexts.
    const active = binding.when.filter((context) =>
      context === 'typing'
        ? typing
        : !typing && CONTEXTS[context].isActive(binding),
    );
    if (!active.length) {
      continue;
    }
    const priority = Math.max(
      ...active.map((context) => CONTEXTS[context].priority),
    );
    if (
      best === null ||
      priority > bestPriority ||
      (priority === bestPriority &&
        specificity(binding.parsed) >= specificity(best.parsed))
    ) {
      // On a full tie (same context, same specificity) the LATER
      // registration wins: a binding registered dynamically when a session
      // arms (the palette's insert keys) must beat a boot-time static
      // binding on the same key; dynamic registration is the coexistence
      // mechanism, and registration order is the only signal of intent.
      best = binding;
      bestPriority = priority;
    }
  }
  if (!best) {
    return;
  }

  let handled;
  if (best.kind === 'hold') {
    if (activeHolds.has(best.id)) {
      // Key repeat on a held binding.
      event.preventDefault();
      return;
    }
    activeHolds.set(best.id, best);
    handled = best.action(event, true);
  } else {
    handled = best.action(event);
  }
  if (handled !== false) {
    event.preventDefault();
    event.stopPropagation();
  }
};

const onKeyUp = (event) => {
  if (!activeHolds.size) {
    return;
  }
  const desc = normalizeEvent(event);
  for (const [id, binding] of activeHolds) {
    if (releasedBy(binding.parsed, desc.key)) {
      releaseHold(id, event);
    }
  }
};

// Leaving the window loses keyup events, so it releases every hold.
const onWindowBlur = () => {
  for (const id of Array.from(activeHolds.keys())) {
    releaseHold(id, null);
  }
};

/**
 * Registers the server-declared bindings: one tool-switch binding per
 * toolbar tool and one panel-toggle binding per sidebar plugin. The map
 * arrives merged with the user's remaps (HotkeySettings), so def.key is
 * already the effective key. Other entries in the map are bare user
 * remaps for bindings that register from JS (verbs); register() picks
 * those up as overrides.
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
    if (def.tool) {
      register({
        id,
        key: def.key,
        label: def.label,
        section: Drupal.t('Tools'),
        remappable: true,
        action: () =>
          Drupal.Daedalus.ModeManager.getPlugin('edit').changeTool(id),
      });
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
        // No button on this page — the panel doesn't apply here — means
        // the key falls through to other bindings.
        action: () => {
          const button = document.querySelector(
            `[data-sidebar-button-for="#${def.sidebar}"]`,
          );
          if (!button) {
            return false;
          }
          button.click();
        },
      });
    }
  });
};

/**
 * Boots the dispatcher. Called once by the app entry, before anything
 * that registers bindings.
 */
export const initHotkeys = () => {
  initTypingDetection();
  document.addEventListener('keydown', onKeyDown);
  document.addEventListener('keyup', onKeyUp);
  window.addEventListener('blur', onWindowBlur);
  ensureServerBindings();

  // The public face for non-app consumers (tool JS, the settings tray,
  // contrib). App code imports register() directly.
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
