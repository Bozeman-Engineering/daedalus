/**
 * The store's selection gate: the backstop under every road to a
 * selection.
 *
 * The pointer chain, the editing gate, reach, commitAddress and the tree
 * rows all ask `selectable` before they commit, but the store has about
 * fifteen dispatch sites and a public facade (Drupal.Daedalus.Selection:
 * select, toggle, selectSet, hover), the tree's own gestures, applySelect
 * and the stylish sources go around most of them. One gate at the
 * store's door makes the rule hold whoever dispatches: a selection
 * action naming a key the actor can do nothing to (capable-model.js
 * selectableIn, against the live projection index) drops that key.
 *
 * Reducers stay pure over their own slice and cannot read the index
 * (which composes from the mirror outside the store), so the gate is a
 * middleware over the selection actions rather than a clause in each
 * reducer; it sits in front of them, so a raw dispatch passes it too.
 * Before the mirror boots there is no universe to judge against, and
 * the gate is inert, as the pointer chain's filter is (resolver.js
 * representedChainKeys): the mirror boots synchronously before any
 * handler is armed, so no gesture precedes it.
 *
 * This file is the pure rule (what to do with one action); store.js
 * installs it.
 */

/**
 * The gated action types and how each carries its keys.
 */
const SET_ONE = ['selection/setSelected', 'selection/toggleSelected'];
const SET_MANY = 'selection/setSelectedSet';
const HOVER = ['selection/setHover', 'selection/setHoverSelector'];

/**
 * Whether an action is one the gate judges.
 *
 * @param {{type: string}} action
 *
 * @return {boolean}
 */
export const gatedAction = (action) =>
  SET_ONE.includes(action.type) ||
  action.type === SET_MANY ||
  HOVER.includes(action.type);

/**
 * The key a hover payload names: a bare key or {key, selector}.
 */
const hoverKey = (payload) =>
  payload && typeof payload === 'object' ? (payload.key ?? null) : (payload ?? null);

/**
 * The action to pass on for a dispatched action, or null to drop it.
 *
 * - setSelected / toggleSelected of an unselectable key: dropped (the
 *   selection stays what it was; a null payload, the clear, passes).
 * - setSelectedSet: the unselectable keys are filtered out, a primary
 *   among them dropped (the reducer then defaults it); an empty
 *   survivor set still passes, as the empty set it is.
 * - setHover / setHoverSelector of an unselectable key: dropped (hover
 *   names what a click would select, and a click would select nothing).
 * - any other action: passed through untouched.
 *
 * @param {{type: string, payload?: *}} action
 *   The dispatched action.
 * @param {function(?string): boolean} selectable
 *   The predicate (capable.js selectable, or any stand-in in a suite).
 *
 * @return {?object}
 */
export const gateSelectionAction = (action, selectable) => {
  if (SET_ONE.includes(action.type)) {
    const key = action.payload ?? null;
    return key === null || selectable(key) ? action : null;
  }
  if (action.type === SET_MANY) {
    const { keys = [], primary = null } = action.payload ?? {};
    const kept = keys.filter((key) => selectable(key));
    if (kept.length === keys.length && (primary === null || selectable(primary))) {
      return action;
    }
    return {
      ...action,
      payload: { keys: kept, primary: primary !== null && selectable(primary) ? primary : null },
    };
  }
  if (HOVER.includes(action.type)) {
    const key = hoverKey(action.payload);
    return key === null || selectable(key) ? action : null;
  }
  return action;
};

/**
 * The middleware: gates every dispatch through gateSelectionAction over
 * the live index, inert while no index exists.
 *
 * @param {function(): ?Map} index
 *   The projection index reader (projection-index.js projectionIndex).
 * @param {function(?Map, ?string): boolean} selectableIn
 *   The pure predicate over an index (capable-model.js selectableIn).
 *
 * @return {Function}
 *   A Redux middleware.
 */
export const selectionGate = (index, selectableIn) => () => (next) => (action) => {
  if (!gatedAction(action)) {
    return next(action);
  }
  const live = index();
  if (!live) {
    return next(action);
  }
  const gated = gateSelectionAction(action, (key) => selectableIn(live, key));
  return gated === null ? undefined : next(gated);
};
