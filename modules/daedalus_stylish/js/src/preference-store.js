/**
 * Storage-backed view-preference stores (the UI mode's pattern, shared).
 *
 * A preference is client state, not a permission: a small enum persisted
 * in localStorage, normalized on every read so garbage degrades to the
 * default. One store per window; same-origin windows (the leader page and
 * the pop-out satellite) stay in sync through the storage event, which
 * only fires in OTHER windows — exactly the propagation needed.
 * receiveExternal() applies such a foreign change without writing it
 * back: the originating window already persisted it.
 *
 * Storage failures (private-mode quirks, full quota) degrade to session
 * state: the preference works, it just won't survive a reload.
 */

/**
 * Builds a preference store over a Storage-shaped backend (null for none).
 *
 * `normalize` collapses any stored/received value (null included) onto
 * the preference's value space; its answer for garbage IS the default.
 */
export const createPreferenceStore = (storage, key, normalize) => {
  const listeners = new Set();
  let value = (() => {
    try {
      return normalize(storage?.getItem(key));
    } catch (e) {
      return normalize(null);
    }
  })();
  const notify = () => listeners.forEach((callback) => callback(value));

  return {
    get: () => value,

    /**
     * Sets and persists the value; listeners hear real changes only.
     */
    set: (next) => {
      const normalized = normalize(next);
      if (normalized === value) {
        return;
      }
      value = normalized;
      try {
        storage?.setItem(key, value);
      } catch (e) {
        // Session-only from here; see the module docblock.
      }
      notify();
    },

    /**
     * Applies a change another window persisted — no write-back.
     */
    receiveExternal: (external) => {
      const normalized = normalize(external);
      if (normalized === value) {
        return;
      }
      value = normalized;
      notify();
    },

    subscribe: (callback) => {
      listeners.add(callback);
      return () => listeners.delete(callback);
    },
  };
};

/**
 * The window's localStorage, or null off-window.
 *
 * Keyed off window, not the localStorage global: node ≥22 ships an
 * experimental localStorage whose mere access warns/throws, and these
 * modules are imported by node-run test suites.
 */
export const windowStorage = () =>
  typeof window === 'undefined' ? null : window.localStorage;

/**
 * Wires a singleton store to the cross-window storage event.
 */
export const bindStorageSync = (store, key) => {
  if (typeof window === 'undefined') {
    return;
  }
  window.addEventListener('storage', (event) => {
    if (event.key === key) {
      store.receiveExternal(event.newValue);
    }
  });
};
