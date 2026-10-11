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
 * STYLING rides the spine beside the selection as two facts, never
 * inside it: keys stay pure data-addressable identities so element-only
 * consumers (verbs, ops, drag, the edit session) read selectedKey
 * untouched, while styling surfaces (canvas selector paint, tree part
 * rows, the daedalus_stylish inspector, code mode, the AI chips) read the
 * two facts. Both are `{key, selector}` pairs whose `selector` is a
 * canonical relative selector (materials/relative-selector.js), the same
 * string style-map keys carry after their `::`, so `${key}::${selector}`
 * IS a style-map key.
 *
 * - The TARGET is the SUBJECT facet: what the styling rows describe. Its
 *   `key` is ALWAYS the selected key (the reducer nulls any other), so it
 *   names something on the selected element: a declared part ('.cls'), a
 *   minted instance ('.s-abc'), a tag tier ('p'), a chain seated from
 *   code mode ('> pb-text .quote'). Null means the element itself. The
 *   subject changes only through selection gestures (tree, canvas,
 *   code-mode row header, keyboard).
 * - The ADDRESS is the WRITE address: where a styling write lands when it
 *   is not the subject's own key. Its `key` is the selected element or
 *   one of its ancestors (ancestry is the facade's check against the
 *   projection index; the slice holds no tree), its selector the rule
 *   the write rebases to ("every list item in Welcome" is
 *   `{container key, 'li'}`). Null means the write lands at the subject's
 *   own key. A chip pick changes only the address; the rows stay the
 *   subject's. No mode gates its legality; a mode chooses the projection.
 *
 * Both facts are only ever valid with a single selection: each is set
 * explicitly (setTarget, setAddress) and cleared by every selection
 * write, multi-select never carries either, and a target write clears
 * the address (a new subject has no address picked yet).
 *
 * - hoverKey: what a click would select right now (policy-resolved).
 * - hoverSelector: the hovered selector as {key, selector}, or null. It
 *   carries its own owner key because selector hover need not ride element
 *   hover: a tree part-row hover lights the part WITHOUT lighting the owner
 *   element's hover outline (setHoverSelector), while canvas Cmd-hover sets
 *   both (setHover with a selector).
 * - selectedKey: the PRIMARY selection (the committed selection).
 * - target: the subject facet as {key, selector}, or null (the rows
 *   describe the selected element itself). It never outlives the
 *   selection.
 * - address: the write address as {key, selector}, or null (writes land
 *   at the subject's own key). It never outlives the selection or the
 *   target it was picked under.
 * - selectedKeys: the ordered multi-select set; membership order is
 *   selection order.
 * - drillKey: the drill context entered by double-click. Hover/click resolve
 *   one level inside it; null means the page root (the main entity wrapper,
 *   which is the canvas, never a selection target). Unrelated to the
 *   styling target despite the "drill" wording both concepts attract.
 * - editingKey: the key a LIVE edit lands on, or null. Derived by the edit
 *   session from the engine's editChanged report (the one writer: every
 *   open/close of an edit flows through it), so selection-anchored chrome
 *   (the frame's dash, the chip face) reacts to the edit's real lifecycle
 *   — including paths the session did not initiate. It is edit state, not
 *   selection state: no selection reducer touches it, because only the
 *   edit's own end (the engine's null report) retires it. The two agree
 *   whenever an edit is open (editing is a fact of the one selection),
 *   and what keeps them agreeing lives OUTSIDE the reducer: every
 *   selection write comes through the selection door
 *   (selection/select-door.js), which ends an open edit before a write
 *   leaves it, or refuses the write when the edit cannot end. The
 *   reducer stays unaware of edits; it only records what landed.
 * - pickerArmed: whether the element picker is armed. Depth state beside
 *   drillKey (the other depth context): the Selector's depth policy
 *   (selection/depth-policy.js) reads it as a second source of "deep"
 *   next to the held modifier, so canvas hover and click resolve the
 *   deepest presented element and part while it is armed. It is the
 *   picker gesture's, not the selection's: clearSelection leaves it
 *   alone, and disarming is the Selector grammar's (a handled canvas
 *   click, Escape, leaving Edit Mode) or the toggle's.
 */

/**
 * Normalizes a hover payload to its {key, selector} pair. Callers pass
 * either a bare key (selector implicitly null: a key change without an
 * explicit selector clears the hover facet) or {key, selector}. A
 * selector without a key is invalid and drops to null.
 */
const normalizeAddress = (payload) => {
  const { key = null, selector = null } =
    payload && typeof payload === 'object' ? payload : { key: payload ?? null };
  return { key, selector: key ? selector : null };
};

const selectionSlice = createSlice({
  name: 'selection',
  initialState: {
    hoverKey: null,
    hoverSelector: null,
    selectedKey: null,
    target: null,
    address: null,
    selectedKeys: [],
    drillKey: null,
    editingKey: null,
    pickerArmed: false,
  },
  reducers: {
    setHover: (state, action) => {
      const { key, selector } = normalizeAddress(action.payload);
      state.hoverKey = key;
      state.hoverSelector = selector ? { key, selector } : null;
    },
    /**
     * Sets ONLY the selector-hover facet, leaving the element hover alone
     * — the tree part rows' writer: hovering a part row lights the part
     * without lighting the owner element's hover outline. Pass
     * {key, selector} or null to clear.
     */
    setHoverSelector: (state, action) => {
      const { key, selector } = normalizeAddress(action.payload);
      state.hoverSelector = selector ? { key, selector } : null;
    },
    /**
     * Commits a single selection: the set collapses to the one key. The
     * payload is a bare key; any selection write clears the subject facet
     * and the write address (each is only ever set explicitly, after its
     * selection).
     */
    setSelected: (state, action) => {
      const key = action.payload ?? null;
      state.selectedKey = key;
      state.target = null;
      state.address = null;
      state.selectedKeys = key ? [key] : [];
    },
    /**
     * Sets the subject facet for the CURRENT single selection: the
     * payload is {key, selector} (key the selected element, a canonical
     * relative selector), or null to drop it. A key other than the
     * selected key is not a subject facet (an ancestor's rule is an
     * ADDRESS, setAddress) and drops to null, as does a facet without a
     * single selection to belong to. A facet write clears the address:
     * the address was picked for the subject that just changed.
     */
    setTarget: (state, action) => {
      const { key = null, selector = null } = action.payload ?? {};
      state.target =
        key && key === state.selectedKey && selector && state.selectedKeys.length === 1
          ? { key, selector }
          : null;
      state.address = null;
    },
    /**
     * Sets the write address for the CURRENT single selection: the
     * payload is {key, selector} (key the selected element or an ancestor
     * of it, a canonical relative selector), or null to drop it, so
     * writes land at the subject's own key again. Without a single
     * selection there is nothing for an address to belong to, so it
     * drops to null; ancestry is the facade's check (the slice holds no
     * tree). It leaves the subject facet alone: a chip pick changes only
     * where the write lands.
     */
    setAddress: (state, action) => {
      const { key = null, selector = null } = action.payload ?? {};
      state.address =
        key && selector && state.selectedKeys.length === 1
          ? { key, selector }
          : null;
    },
    /**
     * Toggles a key's set membership (Shift+click on canvas, Cmd+click on
     * a panel row). Adding makes the key the primary; removing hands the
     * primary to the most recently selected survivor. Multi-select is
     * element-only, so any toggle clears the facet and the address.
     */
    toggleSelected: (state, action) => {
      const key = action.payload;
      if (!key) {
        return;
      }
      state.target = null;
      state.address = null;
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
      state.target = null;
      state.address = null;
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
     * A set gesture is element-only: it clears the facet and the address.
     */
    setSelectedSet: (state, action) => {
      const { keys = [], primary = null } = action.payload ?? {};
      state.target = null;
      state.address = null;
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
    /**
     * Arms or disarms the element picker. Written by the picker toggle
     * (selection/picker.js) and the Selector grammar's disarm rungs.
     */
    setPickerArmed: (state, action) => {
      state.pickerArmed = Boolean(action.payload);
    },
    /**
     * Empties the selection with its hover, drill, facet and address. editingKey
     * is left alone on purpose: a clear that leaves an open edit has
     * already had that edit ended by the selection door (select-door.js
     * clearKeys) before this reducer runs, and the engine's own report
     * retires the key; a clear the door refused never reaches here. The
     * reducer never ends an edit itself.
     */
    clearSelection: (state) => {
      state.hoverKey = null;
      state.hoverSelector = null;
      state.selectedKey = null;
      state.target = null;
      state.address = null;
      state.selectedKeys = [];
      state.drillKey = null;
    },
  },
});

export const {
  setHover,
  setHoverSelector,
  setSelected,
  setTarget,
  setAddress,
  toggleSelected,
  removeSelected,
  setSelectedSet,
  setDrill,
  setEditingKey,
  setPickerArmed,
  clearSelection,
} = selectionSlice.actions;
export default selectionSlice.reducer;
