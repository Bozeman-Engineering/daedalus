/**
 * The inspector's basic/advanced/code UI mode.
 *
 * Basic (the default) is the curated surface: declared controls over the
 * style map, style-target rows hidden. Advanced is the nuts-and-bolts view:
 * raw property groups, style-target rows in the page-elements tree. Code is
 * the islands CSS editor over the same map — a peer mode, not a detour, so
 * it lives in the same enum with the same persistence. All three are
 * projections of the SAME style-map data through the same write path —
 * the mode is a preference, not a permission, so it lives as client
 * state in localStorage (preference-store.js), like the Show-menu
 * visibility it drives (style-target rows on for everything but basic).
 *
 * The mode is one of the inspector's four controls (four controls, one
 * loud mode; surface-state.js names them): its segments sit on the row
 * under the panel title beside the Materials toggle, exactly one of the
 * four buttons lit, and picking a mode always lands in the styling
 * place (the place is source-owned state, never this store's). The
 * code scope (code-scope.js) is this store's sibling, the one control
 * that exists only in code mode.
 */
import {
  bindStorageSync,
  createPreferenceStore,
  windowStorage,
} from './preference-store.js';

const STORAGE_KEY = 'daedalusStylishInspectorMode';

export const UI_MODE_STORAGE_KEY = STORAGE_KEY;

/**
 * Collapses any stored/received value onto the mode space.
 *
 * Basic is the default for everything unknown — a missing key, a removed
 * key (storage events deliver null), or a garbage write.
 */
export const normalizeUiMode = (value) =>
  value === 'advanced' || value === 'code' ? value : 'basic';

/**
 * Builds a mode store over a Storage-shaped backend (null for none).
 */
export const createUiModeStore = (storage) =>
  createPreferenceStore(storage, STORAGE_KEY, normalizeUiMode);

/**
 * The per-window singleton the inspector and hosts share.
 */
export const uiMode = createUiModeStore(windowStorage());

bindStorageSync(uiMode, STORAGE_KEY);
