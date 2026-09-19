/**
 * The code view's variant line tints, as a preference.
 *
 * On (the default), a variant-supplied line washes in its layer's
 * identity hue with a naming hover title — the passive provenance
 * reading. Off prints every line plain: the wearing still delivers, the
 * wear bar still names it, only the per-line identification quiets.
 * Toggled from the wear-bar row menu in the code view; a display
 * preference exactly like the UI mode (preference-store.js), so it
 * persists per browser and syncs across the leader and satellite
 * windows through the storage event.
 */
import {
  bindStorageSync,
  createPreferenceStore,
  windowStorage,
} from './preference-store.js';

const STORAGE_KEY = 'daedalusStylishLineTints';

export const LINE_TINTS_STORAGE_KEY = STORAGE_KEY;

/**
 * Collapses any stored/received value onto on|off; on is the default.
 */
export const normalizeLineTints = (value) => (value === 'off' ? 'off' : 'on');

/**
 * Builds a tints store over a Storage-shaped backend (null for none).
 */
export const createLineTintsStore = (storage) =>
  createPreferenceStore(storage, STORAGE_KEY, normalizeLineTints);

/**
 * The per-window singleton the wear bar and the islands share.
 */
export const lineTints = createLineTintsStore(windowStorage());

bindStorageSync(lineTints, STORAGE_KEY);
