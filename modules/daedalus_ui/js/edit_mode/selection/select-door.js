/**
 * The selection write door's decision: does this selection write leave
 * the open edit?
 *
 * Editing is a fact of the ONE selection (edit_session/edit-session.js):
 * every door that opens an edit selects its key first, so while an edit
 * is open the selection IS the editing key. A selection write that lands
 * anywhere else (another key, a set, nothing) is therefore LEAVING the
 * edit, whatever moved it: a press, a placement's `select`, a page-tree
 * right-click, an inspector's "select parent". The door asks this
 * decision BEFORE it writes, so a leave the edit refuses (an invalid
 * panel widget) never moves the selection: no flicker, no snap-back.
 *
 * The decision is pure so the Node spec (tests/js/select-door.test.mjs)
 * pins it: `stay` when no edit is open, or when the next set is exactly
 * the editing key (styling the edited element, re-selecting it, toggling
 * a non-member off: the selection does not move off the edit); `leave`
 * otherwise, which includes a set that merely CONTAINS the editing key
 * (multi-select is element-only, no edit rides a set) and an empty set
 * (a clear leaves the edit the same as a move).
 *
 * The set helpers mirror the slice's toggleSelected and removeSelected
 * (store/slices/selection-slice.js) so the door can know the next set
 * before dispatching: the reducer stays the one writer; these only
 * predict what it will hold.
 *
 * The five write functions below are the door itself: each predicts the
 * next set, asks leaveDecision, and on LEAVE asks the edit session's
 * leaveEdit() (edit_session/edit-session.js) before dispatching. A
 * refusal writes nothing and returns false; every write that lands
 * returns true.
 *
 * EVERY selection write outside the slice's own repair comes through
 * here: the public facade (selection-facade.js), commitAddress, the
 * canvas grammar's toggle, drill and clears (selector-events.js), the
 * page-elements rows, the chip dismiss, the op queue's `select` landing
 * and the edit session's own editItem. The one direct dispatch left is
 * the op queue's prune of a selection whose element a swap removed: that
 * edit's end belongs to the engine's mutationApplied (a cancel, never a
 * commit), which runs after the prune. The door and the edit session
 * import each other (editItem selects through the door; the door asks
 * leaveEdit): a live-binding cycle used only at call time, never at
 * module evaluation, the same shape store.js and projection-index.js
 * already run.
 */
import store from '../store/store';
import {
  setSelected,
  setSelectedSet,
  toggleSelected,
  removeSelected,
  clearSelection,
} from '../store/slices/selection-slice';
import { leaveEdit } from '../edit_session/edit-session';

/** The decision: the write keeps the selection on the open edit. */
export const STAY = 'stay';

/** The decision: the write moves the selection off the open edit. */
export const LEAVE = 'leave';

/**
 * Whether a selection write whose next set is `nextKeys` leaves the open
 * edit.
 *
 * @param {object} facts
 *   - editingKey: the store's live edit key, or null when no edit is open.
 *   - nextKeys: the ordered set the write would commit (duplicates are
 *     collapsed the way setSelectedSet collapses them).
 *
 * @return {string}
 *   STAY or LEAVE.
 */
export const leaveDecision = ({ editingKey = null, nextKeys = [] } = {}) => {
  if (!editingKey) {
    return STAY;
  }
  const next = [...new Set(nextKeys.filter(Boolean))];
  return next.length === 1 && next[0] === editingKey ? STAY : LEAVE;
};

/**
 * The set after toggling a key's membership: the slice's toggleSelected
 * without the primary bookkeeping. A falsy key is the reducer's no-op.
 *
 * @param {string[]} keys
 *   The current set.
 * @param {?string} key
 *   The key to toggle.
 *
 * @return {string[]}
 */
export const toggledSet = (keys, key) => {
  if (!key) {
    return [...keys];
  }
  return keys.includes(key)
    ? keys.filter((member) => member !== key)
    : [...keys, key];
};

/**
 * The set after removing a key: the slice's removeSelected without the
 * primary bookkeeping. A non-member is the reducer's no-op.
 *
 * @param {string[]} keys
 *   The current set.
 * @param {?string} key
 *   The key to remove.
 *
 * @return {string[]}
 */
export const removedSet = (keys, key) =>
  keys.includes(key) ? keys.filter((member) => member !== key) : [...keys];

/**
 * The door's one body: decide over the live editing key, ask the edit
 * session on LEAVE, dispatch only when the selection may move. The
 * decision reads the store's editingKey (the editChanged report's truth)
 * and leaveEdit reads the engine's own state; they agree whenever an edit
 * is open, since the report is the engine's setter.
 *
 * @param {string[]} nextKeys
 *   The set the write would commit.
 * @param {object} action
 *   The slice action to dispatch when the write lands.
 *
 * @return {boolean}
 *   Whether the write landed.
 */
const write = (nextKeys, action) => {
  const { editingKey } = store.getState().selection;
  if (leaveDecision({ editingKey, nextKeys }) === LEAVE && !leaveEdit()) {
    return false;
  }
  store.dispatch(action);
  return true;
};

/**
 * Commits a single selection (the slice's setSelected): a key, or null for
 * the empty set.
 *
 * @param {?string} key
 *
 * @return {boolean}
 *   Whether the write landed (false when the open edit refused the leave).
 */
export const selectKey = (key) => write(key ? [key] : [], setSelected(key));

/**
 * Replaces the whole set (the slice's setSelectedSet).
 *
 * @param {string[]} keys
 * @param {?string} primary
 *   The key to keep primary; the reducer defaults to the last key.
 *
 * @return {boolean}
 *   Whether the write landed.
 */
export const selectKeys = (keys, primary = null) =>
  write(keys, setSelectedSet({ keys, primary }));

/**
 * Toggles a key's set membership (the slice's toggleSelected).
 *
 * @param {?string} key
 *
 * @return {boolean}
 *   Whether the write landed (a falsy key is the reducer's no-op, which
 *   lands).
 */
export const toggleKey = (key) =>
  write(toggledSet(store.getState().selection.selectedKeys, key), toggleSelected(key));

/**
 * Removes a key from the set (the slice's removeSelected).
 *
 * @param {?string} key
 *
 * @return {boolean}
 *   Whether the write landed (a non-member is the reducer's no-op, which
 *   lands).
 */
export const removeKey = (key) =>
  write(removedSet(store.getState().selection.selectedKeys, key), removeSelected(key));

/**
 * Empties the selection (the slice's clearSelection, which also drops
 * hover, drill and target).
 *
 * @return {boolean}
 *   Whether the write landed.
 */
export const clearKeys = () => write([], clearSelection());
