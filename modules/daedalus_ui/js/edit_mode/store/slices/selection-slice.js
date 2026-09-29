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
 * The STYLING TARGET rides the spine beside the selection, never inside
 * it: keys stay pure data-addressable identities so element-only
 * consumers (verbs, ops, drag, the edit session) read selectedKey untouched,
 * while styling surfaces (canvas selector paint, tree part rows, the
 * daedalus_stylish inspector, code mode) read the target. A target is an
 * address `{key, selector}`: `key` the selected element or one of its
 * ancestors, `selector` a canonical relative selector
 * (materials/relative-selector.js), the same string style-map keys carry
 * after their `::`, so `${target.key}::${target.selector}` IS the styling
 * address. Four shapes: a declared part of the selection ('.cls'), a tag
 * tier of it ('p'), a chain seated from code mode ('> pb-text .quote'),
 * or an ancestor rule reaching it (`{ancestor key, '> pb-text'}`, set
 * from the inspector's switcher). A target is only ever valid with a
 * single selection: it is set explicitly (setTarget) and cleared by every
 * selection write, and multi-select never carries one.
 *
 * - hoverKey: what a click would select right now (policy-resolved).
 * - hoverSelector: the hovered selector as {key, selector}, or null. It
 *   carries its own owner key because selector hover need not ride element
 *   hover: a tree part-row hover lights the part WITHOUT lighting the owner
 *   element's hover outline (setHoverSelector), while canvas Cmd-hover sets
 *   both (setHover with a selector).
 * - selectedKey: the PRIMARY selection (the committed selection).
 * - target: the styling target as {key, selector}, or null (styling acts
 *   on the selected element itself). It never outlives the selection.
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
 *   selection state: clearSelection leaves it alone, because only the
 *   edit's own end (the engine's null report) retires it.
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
     * payload is a bare key; any selection write clears the styling
     * target (a target is only ever set explicitly, after its selection).
     */
    setSelected: (state, action) => {
      const key = action.payload ?? null;
      state.selectedKey = key;
      state.target = null;
      state.selectedKeys = key ? [key] : [];
    },
    /**
     * Sets the styling target for the CURRENT single selection: the
     * payload is {key, selector} (key the selected element or an ancestor
     * of it, a canonical relative selector), or null to drop it. Without
     * a single selection there is nothing for a target to belong to, so
     * it drops to null; ancestry is the facade's check (the slice holds
     * no tree).
     */
    setTarget: (state, action) => {
      const { key = null, selector = null } = action.payload ?? {};
      state.target =
        key && selector && state.selectedKeys.length === 1
          ? { key, selector }
          : null;
    },
    /**
     * Toggles a key's set membership (Shift+click on canvas, Cmd+click on
     * a panel row). Adding makes the key the primary; removing hands the
     * primary to the most recently selected survivor. Multi-select is
     * element-only, so any toggle clears the styling target.
     */
    toggleSelected: (state, action) => {
      const key = action.payload;
      if (!key) {
        return;
      }
      state.target = null;
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
     * A set gesture is element-only: it clears the styling target.
     */
    setSelectedSet: (state, action) => {
      const { keys = [], primary = null } = action.payload ?? {};
      state.target = null;
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
    clearSelection: (state) => {
      state.hoverKey = null;
      state.hoverSelector = null;
      state.selectedKey = null;
      state.target = null;
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
  toggleSelected,
  removeSelected,
  setSelectedSet,
  setDrill,
  setEditingKey,
  setPickerArmed,
  clearSelection,
} = selectionSlice.actions;
export default selectionSlice.reducer;
