/**
 * The exploded view's settings store: the controls' values, clamped,
 * persisted client-side.
 *
 * Tilt, turn, scale (the panel's Zoom), gap, depth cap, labels and
 * isolate are visual toggles, client state and not editor
 * preferences the server could read, so they live in one localStorage
 * key (logging.js's rule for client-only toggles: never EditorSettings).
 * The defaults are the spike's constants, the values that proved the
 * form.
 *
 * Every value is normalized on the way in and on the way out of storage:
 * a number is clamped to its range and a non-number falls back to the
 * default, a toggle is a real boolean or the default, the depth cap is
 * an integer of at least one or null (All). Garbage in storage (a hand
 * edit, an older shape) therefore degrades field by field to the
 * defaults instead of taking the view down. Storage failures (private
 * mode quirks, a full quota) degrade to session state: the settings work,
 * they just do not survive a reload.
 *
 * The store is built over a Storage-shaped backend so the node suite can
 * drive it with a fake; the browser singleton reads window.localStorage.
 */

export const STORAGE_KEY = 'daedalusExplode';

/**
 * The defaults: the spike's stage constants, every plane labeled,
 * isolate off, no depth cap. Which entries lift is not a setting here:
 * the part tier follows daedalus_ui's Markup view (plane-model.js).
 */
export const DEFAULTS = Object.freeze({
  tilt: 38,
  turn: -22,
  scale: 0.62,
  gap: 90,
  depthCap: null,
  labels: true,
  isolate: false,
});

/**
 * The numeric controls' ranges, inclusive: degrees, degrees, a factor,
 * pixels.
 */
export const RANGES = Object.freeze({
  tilt: Object.freeze([0, 70]),
  turn: Object.freeze([-60, 60]),
  scale: Object.freeze([0.1, 2]),
  gap: Object.freeze([0, 200]),
});

const TOGGLES = ['labels', 'isolate'];

/**
 * A number held to its range, or the default for anything not a finite
 * number.
 */
const clampNumber = (name, value) => {
  if (typeof value !== 'number' || !Number.isFinite(value)) {
    return DEFAULTS[name];
  }
  const [min, max] = RANGES[name];
  return Math.min(max, Math.max(min, value));
};

/**
 * The depth cap the model reads: an integer of at least one, else All.
 */
const normalizeCap = (value) =>
  Number.isInteger(value) && value >= 1 ? value : null;

/**
 * Collapses any object (a stored one, a patch over the current values,
 * null) onto the settings' value space, field by field.
 *
 * @param {*} raw
 *   Whatever storage or a caller handed over.
 *
 * @return {object}
 *   A frozen, complete settings object.
 */
export const normalize = (raw) => {
  const given = raw !== null && typeof raw === 'object' ? raw : {};
  const value = {};
  for (const name of Object.keys(RANGES)) {
    value[name] = clampNumber(name, given[name]);
  }
  value.depthCap = normalizeCap(given.depthCap);
  for (const name of TOGGLES) {
    value[name] = typeof given[name] === 'boolean' ? given[name] : DEFAULTS[name];
  }
  return Object.freeze(value);
};

/**
 * Whether two normalized settings objects carry the same values.
 */
const same = (a, b) => Object.keys(DEFAULTS).every((name) => a[name] === b[name]);

/**
 * Reads the stored settings, or null for nothing usable.
 */
const read = (storage) => {
  try {
    return JSON.parse(storage?.getItem(STORAGE_KEY) ?? 'null');
  } catch (e) {
    // Storage unavailable, or holding something that is not JSON: the
    // defaults apply, which is the state a fresh browser is in anyway.
    return null;
  }
};

/**
 * Builds a settings store over a Storage-shaped backend (null for none).
 *
 * @param {?Storage} storage
 *   The backend, or null for session-only state.
 *
 * @return {{get: Function, set: Function, reset: Function, subscribe: Function}}
 *   get() the current frozen settings; set(patch) merges a partial
 *   object over them, normalizes, persists and notifies on a real
 *   change; reset() returns to the defaults; subscribe(callback) hears
 *   every change with the new settings and returns an unsubscribe.
 */
export const createSettingsStore = (storage) => {
  const listeners = new Set();
  let value = normalize(read(storage));

  const persist = () => {
    try {
      storage?.setItem(STORAGE_KEY, JSON.stringify(value));
    } catch (e) {
      // Session-only from here; see the module docblock.
    }
  };

  const set = (patch) => {
    const next = normalize({ ...value, ...patch });
    if (same(next, value)) {
      return;
    }
    value = next;
    persist();
    listeners.forEach((callback) => callback(value));
  };

  return {
    get: () => value,
    set,
    reset: () => set(DEFAULTS),
    subscribe: (callback) => {
      listeners.add(callback);
      return () => listeners.delete(callback);
    },
  };
};

/**
 * The window's localStorage, or null off-window.
 *
 * Keyed off window, not the localStorage global: node ships an
 * experimental localStorage whose mere access warns, and this module is
 * imported by the node-run suite.
 */
export const windowStorage = () =>
  typeof window === 'undefined' ? null : window.localStorage;

/**
 * The per-window singleton the host and the panel share.
 */
export const settings = createSettingsStore(windowStorage());
