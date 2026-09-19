/**
 * @file
 * The canvas state vector: pure state, DOM-free so node can test it.
 *
 * One value per emulation axis:
 * - width:  a number of CSS pixels, or null for the full canvas.
 * - scheme: 'auto' (follow the OS) | 'light' | 'dark'.
 * - motion: 'auto' (follow the OS) | 'full' | 'reduce'.
 *
 * The vector is addressable state, not buried UI state: the store is the
 * one source of truth the chips, the drag handles, the ruler, and any
 * programmatic caller (the AI's render tool, tests) all read and write.
 * Application to the page (canvas width, media emulation) is the shell's
 * job; this module only owns the values and their grammar.
 */

/**
 * The emulation axes, in chip order (width, scheme, motion).
 */
export const AXES = ['width', 'scheme', 'motion'];

/**
 * The scheme axis vocabulary. 'auto' follows the real OS preference.
 */
export const SCHEMES = ['auto', 'light', 'dark'];

/**
 * The motion axis vocabulary. 'auto' follows the real OS preference;
 * 'full' forces no-preference, 'reduce' forces reduced motion.
 */
export const MOTIONS = ['auto', 'full', 'reduce'];

/**
 * Width bounds. The minimum keeps the canvas usable (narrower than any
 * real device is noise); the maximum mirrors the responsive preview's
 * device bounds.
 */
export const MIN_WIDTH = 240;
export const MAX_WIDTH = 10000;

/**
 * Normalizes a candidate width.
 *
 * @param {*} value
 *   The candidate: a number, numeric string, or null/undefined/'' for full.
 *
 * @return {?number|undefined}
 *   The clamped integer width, null for "full", or undefined when the
 *   candidate is not a width at all (callers ignore it).
 */
export const normalizeWidth = (value) => {
  if (
    value === null ||
    value === undefined ||
    value === '' ||
    value === 'full'
  ) {
    return null;
  }
  const pixels = Number(value);
  if (!Number.isFinite(pixels)) {
    return undefined;
  }
  return Math.round(Math.min(MAX_WIDTH, Math.max(MIN_WIDTH, pixels)));
};

/**
 * Normalizes a candidate axis value; undefined when unrecognized.
 */
const normalize = {
  width: normalizeWidth,
  scheme: (value) => (SCHEMES.includes(value) ? value : undefined),
  motion: (value) => (MOTIONS.includes(value) ? value : undefined),
};

/**
 * Parses the vector's boot state from a URL query string.
 *
 * The query parameters are the vector's out-of-band address: a headless
 * renderer (or a pasted URL) can load the page already posed at a state,
 * e.g. ?canvasWidth=375&canvasScheme=dark&canvasMotion=reduce.
 *
 * @param {string} search
 *   The location.search string (leading '?' optional).
 *
 * @return {Object}
 *   A partial vector containing only the recognized parameters.
 */
export const parseBootState = (search) => {
  const params = new URLSearchParams(search);
  const partial = {};
  const names = {
    width: 'canvasWidth',
    scheme: 'canvasScheme',
    motion: 'canvasMotion',
  };
  for (const axis of AXES) {
    if (!params.has(names[axis])) {
      continue;
    }
    const value = normalize[axis](params.get(names[axis]));
    if (value !== undefined) {
      partial[axis] = value;
    }
  }
  return partial;
};

/**
 * Creates the vector store.
 *
 * @param {Object} [initial]
 *   A partial initial state over the default (full width, auto, auto).
 *
 * @return {Object}
 *   The store: get() (a copy), set(partial) (returns the axes that
 *   changed), subscribe(listener) (called with (state, changedAxes);
 *   returns an unsubscribe function).
 */
export const createVector = (initial = {}) => {
  const state = { width: null, scheme: 'auto', motion: 'auto' };
  const listeners = new Set();

  const set = (partial) => {
    const changed = [];
    for (const axis of AXES) {
      if (!(axis in partial)) {
        continue;
      }
      const value = normalize[axis](partial[axis]);
      if (value === undefined || value === state[axis]) {
        continue;
      }
      state[axis] = value;
      changed.push(axis);
    }
    if (changed.length) {
      const snapshot = { ...state };
      listeners.forEach((listener) => listener(snapshot, changed));
    }
    return changed;
  };

  set(initial);

  return {
    get: () => ({ ...state }),
    set,
    subscribe: (listener) => {
      listeners.add(listener);
      return () => listeners.delete(listener);
    },
  };
};
