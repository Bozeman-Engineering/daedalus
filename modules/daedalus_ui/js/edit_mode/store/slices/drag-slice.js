import { createSlice } from '@reduxjs/toolkit';

/**
 * Caret-drag state — coarse lifecycle only.
 *
 * The slice tells components THAT a drag is running (and of which source
 * type), which is what gates the insertion caret's existence. The
 * per-pointer-move drop target (gap, rects, orientation) is deliberately
 * NOT store state: it changes every mousemove and is consumed by exactly
 * one rAF-driven component, so it lives in the drag controller's module
 * state and is read imperatively each frame — the same division the
 * outline uses (keys in the store, geometry read per frame).
 *
 * dropRowKey and dropContainerKey are the exceptions, and they honor the
 * same division: they are IDENTITY, not geometry — the projection keys of
 * the panel row the current drop location addresses and of the container
 * it inserts into — and they change per gap transition, not per mousemove.
 * The page-elements tree consumes the row key to unfold the path to the
 * caret (the drop's twin of the selection's auto-unfold), which is what
 * lets the caret's panel face draw inside folded containers; the selection
 * indicator consumes the container key to show the drop trail — where the
 * caret sits in the tree, the lift's depth indicator.
 */
const dragSlice = createSlice({
  name: 'drag',
  initialState: {
    // A registered-source drag is in progress (dragstart → dragend).
    active: false,
    // The registered source type driving the drag (e.g. 'new_block').
    sourceType: null,
    // What the source targets: 'block' (region gaps) or 'section'
    // (section-flow gaps). Drives which gaps the caret offers.
    targets: null,
    // A drop was committed and its mutation is in flight; the caret
    // lingers at the drop point in a committing style until it resolves.
    committing: false,
    // The projection key of the current drop location's panel row, or
    // null (no drop target, or its location has no projected row).
    dropRowKey: null,
    // The projection key of the container the current drop location
    // inserts into, or null.
    dropContainerKey: null,
  },
  reducers: {
    dragStarted: (state, action) => {
      state.active = true;
      state.sourceType = action.payload.sourceType;
      state.targets = action.payload.targets;
      state.committing = false;
    },
    dragEnded: (state) => {
      state.active = false;
      state.sourceType = null;
      state.targets = null;
      state.dropRowKey = null;
      state.dropContainerKey = null;
    },
    dropTargeted: (state, action) => {
      state.dropRowKey = action.payload.rowKey;
      state.dropContainerKey = action.payload.containerKey;
    },
    commitStarted: (state) => {
      state.committing = true;
    },
    commitFinished: (state) => {
      state.committing = false;
    },
  },
});

export const {
  dragStarted,
  dragEnded,
  commitStarted,
  commitFinished,
  dropTargeted,
} = dragSlice.actions;
export default dragSlice.reducer;
