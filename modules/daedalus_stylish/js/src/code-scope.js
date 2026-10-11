/**
 * The code view's reading scope, as a preference.
 *
 * Code mode prints the exact document per element, and once the skin's
 * floor prints on the selection too, one scope over the whole page is
 * noise. The scope answers how much of the layout the tree prints:
 * `element` (the default, DevTools' posture: the selected element's
 * row alone), `subtree` (the selection with everything under it, the
 * blueprint case: a composition's fragments distributed over a subtree
 * read together), or `page` (the whole page tree, every element a row,
 * selection or not). It exists only in code mode, so its one control renders only
 * there (page-view.js: the showing row's sentence, "Showing <name>
 * only ▾", a menu of the three scopes said with the selected element's
 * name, rendered into the inspector's pinned head above the scrolling
 * body), and it is a display preference exactly
 * like the UI mode (preference-store.js): per browser, synced across
 * the leader and satellite windows through the storage event. The
 * projection it drives is page-model.js scopeNodes().
 */
import {
  bindStorageSync,
  createPreferenceStore,
  windowStorage,
} from './preference-store.js';

const STORAGE_KEY = 'daedalusStylishCodeScope';

export const CODE_SCOPE_STORAGE_KEY = STORAGE_KEY;

/**
 * The scope vocabulary, in segment order.
 */
export const CODE_SCOPES = ['element', 'subtree', 'page'];

/**
 * Collapses any stored/received value onto the scope space.
 *
 * Element is the default for everything unknown — a missing key, a
 * removed key (storage events deliver null), or a garbage write.
 */
export const normalizeCodeScope = (value) =>
  value === 'subtree' || value === 'page' ? value : 'element';

/**
 * Builds a scope store over a Storage-shaped backend (null for none).
 */
export const createCodeScopeStore = (storage) =>
  createPreferenceStore(storage, STORAGE_KEY, normalizeCodeScope);

/**
 * The per-window singleton the inspector's code branch reads.
 */
export const codeScope = createCodeScopeStore(windowStorage());

bindStorageSync(codeScope, STORAGE_KEY);
