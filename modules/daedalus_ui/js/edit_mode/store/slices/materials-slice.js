import { createSelector, createSlice } from '@reduxjs/toolkit';
import { createRoster } from '../../materials/roster.js';
import {
  applyDelta,
  assertDelta,
  emptyRecords,
  flattenRecords,
  isEmptyDelta,
  recordsFromPayload,
} from '../../materials/delta.js';

/**
 * The materials store: the client copy of the site's materials roster.
 *
 * One record set (delivery shape, MaterialRecord::toArray(): tokens,
 * swatches, variants, blueprints, animations) every widget reads through
 * the facade:
 * pickers and wells offer its stops, the wear bar and palette list its
 * variants and blueprints, code completions and lint know its spellings,
 * the value grammar resolves against it. Consumers project; nobody keeps
 * a roster of its own.
 *
 * The server keeps authority. The store boots from the page-load payload
 * ({records: [...]}, delivered by BlueprintMirror on the same render as
 * the blueprint mirror, so canvas, mirror and roster are consistent by
 * construction) and applies the `materials` {upsert, remove} delta every
 * mutation response, gesture route and AI turn carries. Nothing fetches.
 *
 * Records are kept per kind in roster order and seated by the one rule
 * every window shares (materials/delta.js): a paired window without the
 * app bundle holds its own set over the same envelope and the relayed
 * deltas, and the two must agree record for record.
 *
 * `boots` counts the whole-set replacements, so a subscriber can tell a
 * boot (the page's envelope, re-delivered on every editable-page render
 * and carrying whatever other editors wrote since this window's last
 * boot) from a delta (a record this session's own request, or a paired
 * window's, just landed). The Add panel's creator mark reads the
 * distinction through Materials.onChange.
 */
const materialsSlice = createSlice({
  name: 'materials',
  initialState: {
    booted: false,
    boots: 0,
    records: emptyRecords(),
  },
  reducers: {
    /**
     * Replaces the whole record set from a page-load payload.
     */
    materialsBooted: (state, action) => {
      state.booted = true;
      state.boots += 1;
      state.records = recordsFromPayload(action.payload);
    },
    /**
     * Applies a {upsert, remove} delta (materials/delta.js applyDelta).
     */
    materialsDelta: (state, action) => {
      applyDelta(state.records, action.payload);
    },
  },
});

export const { materialsBooted, materialsDelta } = materialsSlice.actions;
export default materialsSlice.reducer;

/**
 * The delta guards, re-exported for the store's consumers (materials/delta.js).
 */
export { assertDelta, isEmptyDelta };

/**
 * The store's record set as one flat list, roster order (kind order, then
 * the seat order within the kind).
 */
export const selectRecords = createSelector(
  [(state) => state.materials.records],
  flattenRecords,
);

/**
 * The roster index (materials/roster.js) over the store's records, rebuilt
 * only when the record set changes: the read surface the facade and the
 * grammar resolve through.
 */
export const selectRoster = createSelector([selectRecords], (records) =>
  createRoster(records),
);
