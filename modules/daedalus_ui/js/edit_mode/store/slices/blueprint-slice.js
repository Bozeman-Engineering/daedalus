import { createSlice } from '@reduxjs/toolkit';

/**
 * The blueprint mirror — the shared client read model of the page.
 *
 * One full blueprint of the route's main entity, projected by every
 * consumer: the page-elements panel renders structure from it, verbs check
 * capabilities, move computes anchors, the AI chat describes the selection.
 * Consumers project; nobody gets a bespoke read shape.
 *
 * The server keeps authority: clients never write the mirror. It boots from
 * drupalSettings (delivered with the same render that produced the
 * attributed markup, so mirror and canvas are consistent by construction)
 * and is replaced wholesale by the blueprint half of every mutation
 * response. Nothing fetches on boot; the GET endpoint is explicit re-sync
 * only.
 *
 * root is the identity envelope (entityType, entityId, uuid, label,
 * langcode, viewMode). It travels beside the blueprint because the
 * serializer does not emit the root uuid and mutation responses carry only
 * the blueprint.
 */
const blueprintSlice = createSlice({
  name: 'blueprint',
  initialState: {
    root: null,
    blueprint: null,
  },
  reducers: {
    blueprintBooted: (state, action) => {
      const { blueprint, ...root } = action.payload;
      state.root = root;
      state.blueprint = blueprint;
    },
    blueprintReplaced: (state, action) => {
      state.blueprint = action.payload;
    },
  },
});

export const { blueprintBooted, blueprintReplaced } = blueprintSlice.actions;
export default blueprintSlice.reducer;
