/**
 * @file
 * The fold store: the panel opens as the editor left it, remembered in
 * the browser.
 *
 * Which sets are folded is purely visual, client-only state, so
 * localStorage is its home and not EditorSettings (the server store is
 * for what the server reads; the rule EditorSettings and daedalus_ui's
 * view slice state). One key, STORAGE_KEY, holding the collapse model's
 * `folds` as JSON: explicit postures only, set id to true (collapsed) or
 * false (expanded). A set absent from it shows its default (outline.js),
 * so the store never writes a default and an empty map is removed
 * rather than stored.
 *
 * Every access is guarded. A blocked store (a private window, a full
 * quota, a sandbox that throws on the mere read of `window.localStorage`)
 * degrades to the defaults on read and to session state on write: the
 * panel folds, it just does not remember. Garbage under the key (not
 * JSON, not a map, a value that is not a boolean) reads as no postures,
 * entry by entry.
 *
 * Node 22 and later ship an experimental localStorage whose mere access
 * warns or throws, and this file is imported by node-run tests, so the
 * backend is keyed off `window` and never off the bare global.
 *
 * Pure apart from the storage it is handed: no DOM, no Preact.
 */

/**
 * The localStorage key.
 */
export const STORAGE_KEY = 'daedalusLayoutAddPanel';

/**
 * The window's localStorage, or null off-window or where reading it
 * throws.
 *
 * @return {?Storage}
 */
export const windowStorage = () => {
  try {
    return typeof window === 'undefined' ? null : window.localStorage ?? null;
  } catch (e) {
    return null;
  }
};

/**
 * The postures a stored value holds, or null for none.
 *
 * @param {*} raw
 *   The stored string (or anything: null, garbage).
 *
 * @return {?object}
 *   `{ [id]: boolean }` holding only the entries that are postures;
 *   null when there are none.
 */
export const normalizeFolds = (raw) => {
  if (typeof raw !== 'string' || raw === '') {
    return null;
  }
  let parsed;
  try {
    parsed = JSON.parse(raw);
  } catch (e) {
    return null;
  }
  if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
    return null;
  }
  const folds = {};
  Object.entries(parsed).forEach(([id, posture]) => {
    if (id !== '' && typeof posture === 'boolean') {
      folds[id] = posture;
    }
  });
  return Object.keys(folds).length ? folds : null;
};

/**
 * The remembered postures, or null for the defaults.
 *
 * @param {?Storage} storage
 *   A Storage-shaped backend; null for none.
 *
 * @return {?object}
 */
export const readFolds = (storage = windowStorage()) => {
  try {
    return normalizeFolds(storage?.getItem(STORAGE_KEY) ?? null);
  } catch (e) {
    return null;
  }
};

/**
 * Remembers the postures; the next read answers them.
 *
 * @param {?Storage} storage
 *   A Storage-shaped backend; null for none.
 * @param {?object} folds
 *   The collapse model's explicit postures; null, or a map with nothing
 *   in it, forgets.
 *
 * @return {boolean}
 *   Whether the store took the write; false means session-only from
 *   here, which the caller need not act on.
 */
export const writeFolds = (storage, folds) => {
  const kept = {};
  Object.entries(folds ?? {}).forEach(([id, posture]) => {
    if (id !== '' && typeof posture === 'boolean') {
      kept[id] = posture;
    }
  });
  try {
    if (!storage) {
      return false;
    }
    if (Object.keys(kept).length) {
      storage.setItem(STORAGE_KEY, JSON.stringify(kept));
    } else {
      storage.removeItem(STORAGE_KEY);
    }
    return true;
  } catch (e) {
    return false;
  }
};
