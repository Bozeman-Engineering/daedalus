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
 * only. `blueprint` is therefore always the server's LAST WORD: a reader
 * holding it can tell committed truth from anything a gesture previews.
 *
 * draft is the open gesture's resolved markup, `null | {key, markup}`: a
 * client-side sibling of the mirror, never a write INTO it. When an
 * editor previews a composed edit (a text block's tag pick, a link typed
 * into a text or image block) the element resolver's client twin computes
 * the markup tree the data WILL render as, the canvas is morphed to it,
 * and the same tree is drafted here for the one key being edited through
 * the facade's draftMarkup door (markupDrafted). Readers never consume
 * `blueprint` or `draft` directly: they read the EFFECTIVE tree
 * (blueprint/effective-blueprint.js), the mirror with the draft's markup
 * merged over the drafted node's `_element.markup`, so the page-elements
 * panel, the styling inspector and every other mirror reader follow the
 * gesture live instead of waiting one round trip. Every mirror
 * replacement (blueprintBooted, blueprintReplaced) resets the draft to
 * null: server truth supersedes the preview, whichever op produced it.
 * No edit-session hook clears it; a cancel re-previews the committed
 * shape through the same door, and a commit leaves the draft standing
 * until its response lands, so nothing flickers back mid-flight.
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
    draft: null,
  },
  reducers: {
    blueprintBooted: (state, action) => {
      const { blueprint, verdict = null, ...root } = action.payload;
      state.root = root;
      state.blueprint = blueprint;
      state.verdict = verdict;
      state.draft = null;
    },
    // Payload: {blueprint, verdict?}. A response without a verdict (an
    // older seam) leaves the one in place.
    blueprintReplaced: (state, action) => {
      state.blueprint = action.payload.blueprint;
      if (action.payload.verdict !== undefined) {
        state.verdict = action.payload.verdict;
      }
      state.draft = null;
    },
    // Payload: {key, markup}. The open gesture's resolved markup for one
    // selectable key; the next preview on any key overwrites it.
    markupDrafted: (state, action) => {
      const { key, markup } = action.payload;
      state.draft = { key, markup };
    },
    draftCleared: (state) => {
      state.draft = null;
    },
  },
});

export const {
  blueprintBooted,
  blueprintReplaced,
  markupDrafted,
  draftCleared,
} = blueprintSlice.actions;
export default blueprintSlice.reducer;
