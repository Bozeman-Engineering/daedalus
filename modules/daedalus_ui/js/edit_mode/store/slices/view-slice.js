import { createSlice } from '@reduxjs/toolkit';

/**
 * The view slice: what the page-elements tree SHOWS (the Show menu's state).
 *
 * Purely visual, client-only toggles, so localStorage is their home (the
 * EditorSettings server store is for server-readable preferences). The
 * slice is the runtime source of truth; localStorage only seeds it and
 * receives write-through persistence, keyed site-wide (view preferences
 * are per editor, not per page).
 *
 * - parts: per-provider visibility of tree VIEWS (style targets…), keyed
 *   by the provider's displayToggle id. Absent = OFF: provider views are
 *   opt-in, the model projection is the default. (The key name predates
 *   the view-provider seam — it persists in localStorage, so it keeps the
 *   historical name.)
 * - renameKey: the selectable key whose tree row is in inline-rename state,
 *   or null. Surface state, not a preference: the Rename verb and the
 *   label double-click write it, the row reads it, and commit/cancel clear
 *   it, so it is NEVER persisted. It lives here (not row-local useState)
 *   because rename is a verb: the registry's run() must be able to
 *   put a row into rename state from outside the tree component.
 * - markedKeys: selectable keys whose rows wear the focus outline
 *   (app.tree.setRowMarks) — another module's coverage marking, e.g.
 *   daedalus_stylish's Outline Variant painting its wearers on the canvas and in
 *   this tree as one gesture. Transient like renameKey: one mark set at a
 *   time, last write wins, never persisted.
 */

const STORAGE_KEY = 'daedalusUiView';

const seed = () => {
  try {
    const stored = JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '{}');
    return {
      parts:
        typeof stored.parts === 'object' && stored.parts !== null
          ? stored.parts
          : {},
    };
  } catch (e) {
    return { parts: {} };
  }
};

const persist = (state) => {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify({ parts: state.parts }));
  } catch (e) {
    // Storage unavailable (private mode quirks): the session state works,
    // it just won't survive a reload.
  }
};

const viewSlice = createSlice({
  name: 'view',
  // renameKey and markedKeys are transient: they seed empty on every load
  // and persist() never writes them.
  initialState: { ...seed(), renameKey: null, markedKeys: [] },
  reducers: {
    setPartVisibility: (state, action) => {
      state.parts[action.payload.id] = action.payload.visible === true;
      persist(state);
    },
    setRenameKey: (state, action) => {
      state.renameKey = action.payload ?? null;
    },
    setMarkedKeys: (state, action) => {
      state.markedKeys = Array.isArray(action.payload)
        ? action.payload.filter((key) => typeof key === 'string')
        : [];
    },
  },
});

export const { setPartVisibility, setRenameKey, setMarkedKeys } =
  viewSlice.actions;
export default viewSlice.reducer;
