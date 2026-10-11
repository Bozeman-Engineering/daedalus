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
 *
 * verdict is the host-level verdict ({can, refused}: what the actor may
 * do at the root and, explained, why not; BlueprintElementAnnotator::
 * hostVerdict), delivered on the envelope and refreshed by every mutation
 * response beside the blueprint. The document notice reads it; the
 * per-element verdicts ride the blueprint's `_element.can`.
 */
const blueprintSlice = createSlice({
  name: 'blueprint',
  initialState: {
    root: null,
    blueprint: null,
    verdict: null,
  },
  reducers: {
    blueprintBooted: (state, action) => {
      const { blueprint, verdict = null, ...root } = action.payload;
      state.root = root;
      state.blueprint = blueprint;
      state.verdict = verdict;
    },
    // Payload: {blueprint, verdict?}. A response without a verdict (an
    // older seam) leaves the one in place.
    blueprintReplaced: (state, action) => {
      state.blueprint = action.payload.blueprint;
      if (action.payload.verdict !== undefined) {
        state.verdict = action.payload.verdict;
      }
    },
  },
});

export const { blueprintBooted, blueprintReplaced } = blueprintSlice.actions;
export default blueprintSlice.reducer;
