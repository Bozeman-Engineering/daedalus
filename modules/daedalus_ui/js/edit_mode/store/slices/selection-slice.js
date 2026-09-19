import { createSlice } from '@reduxjs/toolkit';

/**
 * Selection state — the object-first editing spine.
 *
 * All keys are selectable keys (the data-selectable attribute values, see
 * \Drupal\daedalus_blueprint_api\Render\SelectableKey). Canvas outline, page-elements
 * panel, verb menu, and non-app consumers (e.g. the AI chat panel) all read
 * selection from here; only keys are stored, never DOM references — the DOM
 * element for a key is looked up on demand so selection survives markup
 * swaps.
 *
 * The selection is an ORDERED SET plus a primary; single-select is a
 * set of one, so two invariants hold everywhere: selectedKey is a member of
 * selectedKeys, and both are empty together. Single-select consumers keep
 * reading selectedKey, which IS the primary; set-aware consumers (multi
 * outlines, multi verbs, the AI chat chips) read selectedKeys.
 *
 * Parts (stylable sub-elements, e.g. a card's icon) ride the spine as a
 * FACET beside the element key, never inside it: keys stay pure
 * data-addressable identities so element-only consumers (verbs, ops, drag)
 * read selectedKey untouched, while part-aware surfaces (canvas part paint,
 * tree part rows, the daedalus_stylish inspector, code mode) read the pair. A part
 * is only ever valid with its key — any key change without an explicit
 * part clears the facet — and multi-select is element-only: growing or
 * replacing the set clears selectedPart.
 *
 * - hoverKey: what a click would select right now (policy-resolved).
 * - hoverPart: the hovered part as {key, part}, or null. It carries its own
 *   owner key because part hover need not ride element hover: a tree
 *   part-row hover lights the part WITHOUT lighting the owner element's
 *   hover outline (setHoverPart), while canvas Cmd-hover sets both
 *   (setHover with a part).
 * - selectedKey: the PRIMARY selection (the committed selection).
 * - selectedPart: the committed part facet (a part id), or null. Its owner
 *   is always selectedKey; it never outlives it.
 * - selectedKeys: the ordered multi-select set; membership order is
 *   selection order.
 * - drillKey: the drill context entered by double-click. Hover/click resolve
 *   one level inside it; null means the page root (the main entity wrapper,
 *   which is the canvas, never a selection target). Unrelated to the part
 *   facet despite the "drill" wording both concepts attract.
 * - editingKey: the key a LIVE edit lands on, or null. Derived by the edit
 *   session from the engine's editChanged report (the one writer: every
 *   open/close of an edit flows through it), so selection-anchored chrome
 *   (the frame's dash, the chip face) reacts to the edit's real lifecycle
 *   — including paths the session did not initiate. It is edit state, not
 *   selection state: clearSelection leaves it alone, because only the
 *   edit's own end (the engine's null report) retires it.
 */

/**
 * Normalizes a hover/select payload to its {key, part} pair. Callers pass
 * either a bare key (part implicitly null — the "key change without an
 * explicit part clears the facet" invariant) or {key, part}. A part
 * without a key is invalid and drops to null.
 */
const normalizeTarget = (payload) => {
  const { key = null, part = null } =
    payload && typeof payload === 'object' ? payload : { key: payload ?? null };
  return { key, part: key ? part : null };
};

const selectionSlice = createSlice({
  name: 'selection',
  initialState: {
    hoverKey: null,
    hoverPart: null,
    selectedKey: null,
    selectedPart: null,
    selectedKeys: [],
    drillKey: null,
    editingKey: null,
  },
  reducers: {
    setHover: (state, action) => {
      const { key, part } = normalizeTarget(action.payload);
      state.hoverKey = key;
      state.hoverPart = part ? { key, part } : null;
    },
    /**
     * Sets ONLY the part-hover facet, leaving the element hover alone —
     * the tree part rows' writer: hovering a part row lights the part
     * without lighting the owner element's hover outline. Pass {key, part}
     * or null to clear.
     */
    setHoverPart: (state, action) => {
      const { key, part } = normalizeTarget(action.payload);
      state.hoverPart = part ? { key, part } : null;
    },
    /**
     * Commits a single selection: the set collapses to the one key. The
     * payload is a bare key or {key, part}; a bare key clears the part
     * facet (a part is only ever selected explicitly).
     */
    setSelected: (state, action) => {
      const { key, part } = normalizeTarget(action.payload);
      state.selectedKey = key;
      state.selectedPart = part;
      state.selectedKeys = key ? [key] : [];
    },
    /**
     * Toggles a key's set membership (Shift+click on canvas, Cmd+click on
     * a panel row). Adding makes the key the primary; removing hands the
     * primary to the most recently selected survivor. Multi-select is
     * element-only, so any toggle clears the part facet.
     */
    toggleSelected: (state, action) => {
      const key = action.payload;
      if (!key) {
        return;
      }
      state.selectedPart = null;
      if (state.selectedKeys.includes(key)) {
        state.selectedKeys = state.selectedKeys.filter(
          (member) => member !== key,
        );
        if (state.selectedKey === key) {
          state.selectedKey =
            state.selectedKeys[state.selectedKeys.length - 1] ?? null;
        }
      } else {
        state.selectedKeys.push(key);
        state.selectedKey = key;
      }
    },
    /**
     * Removes a key from the set if present (chip dismiss, stale-key
     * pruning) — a no-op when it is not a member.
     */
    removeSelected: (state, action) => {
      const key = action.payload;
      if (!state.selectedKeys.includes(key)) {
        return;
      }
      state.selectedPart = null;
      state.selectedKeys = state.selectedKeys.filter(
        (member) => member !== key,
      );
      if (state.selectedKey === key) {
        state.selectedKey =
          state.selectedKeys[state.selectedKeys.length - 1] ?? null;
      }
    },
    /**
     * Replaces the whole set (panel Shift-range). The primary defaults to
     * the last key; pass primary explicitly to keep the clicked row primary.
     * A set gesture is element-only: it clears the part facet.
     */
    setSelectedSet: (state, action) => {
      const { keys = [], primary = null } = action.payload ?? {};
      state.selectedPart = null;
      state.selectedKeys = [...new Set(keys)];
      state.selectedKey = state.selectedKeys.includes(primary)
        ? primary
        : (state.selectedKeys[state.selectedKeys.length - 1] ?? null);
    },
    setDrill: (state, action) => {
      state.drillKey = action.payload;
    },
    /**
     * Records the key the live edit lands on (null when the edit ended).
     * Dispatched only by the edit session's editChanged report — the
     * engine's setter is the one origin, so this field never predicts.
     */
    setEditingKey: (state, action) => {
      state.editingKey = action.payload ?? null;
    },
    clearSelection: (state) => {
      state.hoverKey = null;
      state.hoverPart = null;
      state.selectedKey = null;
      state.selectedPart = null;
      state.selectedKeys = [];
      state.drillKey = null;
    },
  },
});

export const {
  setHover,
  setHoverPart,
  setSelected,
  toggleSelected,
  removeSelected,
  setSelectedSet,
  setDrill,
  setEditingKey,
  clearSelection,
} = selectionSlice.actions;
export default selectionSlice.reducer;
