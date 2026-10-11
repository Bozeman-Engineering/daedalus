import store from '../store/store';
import { elementForKey } from '../selection/resolver';
import { registerDragSource, makeDragDelegate } from './drag-controller';
import * as builders from './ops-builders';

/**
 * Selector drag: moving is a selection gesture, not a mode.
 *
 * While Edit Mode is on, the element hover resolution offers (what a click
 * would select at the current drill level) arms as a native drag source,
 * promoted to the nearest MOVABLE element at or above it: a block PLACEMENT
 * (block:{uuid}; three-segment keys are inline-block fields) or a section.
 * A resolved field never drags itself: grabbing it drags the placement that
 * owns it. Deep hover (Cmd/Ctrl, the deep-select policy) resolves the
 * innermost leaf, so deep-select+drag grabs its nearest movable ancestor
 * the same way. And because hover resolution is selection-aware (pointing
 * at the selection means the selection), a block selected from the panel
 * or by deep select drags as ITSELF under a plain pointer, never as the
 * boundary group around it.
 *
 * Canvas elements are never their own drag handles: an `<svg>` block root
 * cannot start a native drag at all, and inner native draggables (images,
 * links) would win the innermost-draggable race. The main entity wrapper
 * is the ONE delegated handle instead (makeDragDelegate): hover arming
 * remembers WHICH movable a drag means and toggles the wrapper's
 * `draggable`, and the wrapper's dragstart carries that element — drag
 * image included, native inner drags reinterpreted. So the selection
 * layer, not DOM nesting, still decides what a drag means. (The page-elements tree's
 * movable rows are independent, always-armed drag sources feeding the same
 * move sources — see page-elements-tree.js.)
 */

/**
 * The movable canvas element a drag currently means, or null.
 */
let armed = null;

/**
 * The main entity wrapper carrying the delegated handlers, or null.
 */
let delegate = null;

/**
 * The nearest movable element at or above a selectable key's element.
 *
 * @param {?string} key
 *   The hover-resolved selectable key.
 *
 * @return {?Element}
 *   A block-placement or section element, or null when nothing movable
 *   encloses the key (fields of the root entity, regions at the boundary).
 *   Whether the element MAY move is not answered here: the drag
 *   controller asks arrange of the drag data's key when the gesture
 *   starts (makeDragDelegate / makeDraggable), one gate for every source.
 *
 * Exported for the keyboard lift: Space on a selection lifts exactly
 * what a drag on it would grab.
 */
export const movableFor = (key) => {
  let element = elementForKey(key);
  while (element && !element.hasAttribute('data-main-entity')) {
    const selectable = element.getAttribute('data-selectable');
    const parts = selectable.split(':');
    if (
      parts[0] === 'container' ||
      (parts[0] === 'block' && parts.length === 2)
    ) {
      return element;
    }
    element = element.parentElement?.closest('[data-selectable]');
  }
  return null;
};

/**
 * The delegate's dragstart answer: the armed movable's move source.
 */
const resolveArmed = () => {
  if (!armed?.isConnected) {
    return null;
  }
  const key = armed.getAttribute('data-selectable');
  const parts = key.split(':');
  return {
    type: parts[0] === 'container' ? 'move_section' : 'move_block',
    // `key` names the element the drag MOVES: the controller asks arrange
    // of it at dragstart (the one gate for every source).
    data: { uuid: parts[1], element: armed, key },
    element: armed,
  };
};

const disarm = () => {
  armed = null;
  delegate?.removeAttribute('draggable');
};

const arm = (element) => {
  if (armed === element) {
    return;
  }
  if (!element) {
    disarm();
    return;
  }
  armed = element;
  // A markup swap can have replaced the wrapper; re-resolve it at arm
  // time (handler properties make re-delegation idempotent). A draggable
  // ancestor eats text selection, so the attribute is on ONLY while a
  // movable is under the pointer.
  const wrapper = document.querySelector('[data-main-entity]');
  if (!wrapper) {
    disarm();
    return;
  }
  if (wrapper !== delegate) {
    delegate?.removeAttribute('draggable');
    delegate = wrapper;
    makeDragDelegate(delegate, resolveArmed);
  }
  delegate.setAttribute('draggable', 'true');
};

// Arming follows the store: the hover key names WHAT the drag layer means,
// the mode gates WHETHER dragging is on, an open edit suspends it, and a
// blueprint replacement means a markup swap may have recreated the armed
// key's element: re-resolve. Memoized on those four inputs so the
// per-dispatch cost is comparisons, not DOM queries.
let last = { mode: null, hoverKey: null, editingKey: null, blueprint: null };

const syncArming = () => {
  const state = store.getState();
  // Mid-drag, arming holds still: re-arming would retarget or disarm the
  // delegate under the in-flight gesture (its dragend is what ends it).
  // `last` stays stale on purpose so the dragEnded dispatch catches up.
  if (state.drag.active) {
    return;
  }
  const next = {
    mode: state.mode.mode,
    hoverKey: state.selection.hoverKey,
    editingKey: state.selection.editingKey,
    blueprint: state.blueprint.blueprint,
  };
  if (
    next.mode === last.mode &&
    next.hoverKey === last.hoverKey &&
    next.editingKey === last.editingKey &&
    next.blueprint === last.blueprint
  ) {
    return;
  }
  last = next;
  if (next.mode !== 'edit') {
    disarm();
    return;
  }
  // No canvas drag while ANY edit is open. The edited element is the
  // selection and hover on the selection resolves to the selection, so
  // hover-only arming would keep the wrapper draggable for the whole
  // edit; and WebKit lets a draggable ancestor win the mousedown over an
  // editable descendant (Chrome lets the descendant win it as a text
  // selection), so selecting text inside the typing surface would start
  // a native drag of the wrapper, reinterpreted here as a block move.
  // Editing and moving are sequential (the edit commits, then the block
  // moves), so the rule is the open edit itself, not whether the armed
  // movable contains it; arming resumes from the hover key when the edit
  // closes. (The page-elements tree's rows drop their own `draggable`
  // while renaming for the same reason.)
  if (next.editingKey !== null) {
    disarm();
    return;
  }
  arm(movableFor(next.hoverKey));
};

/**
 * Never offer a gap the move cannot mean: one anchored on the dragged
 * element itself (moving relative to yourself is a no-op, and a section
 * anchor must still exist after the server removes the moved section), or
 * one inside the dragged element's own content (the client mirror of the
 * server's descendant-anchor guard). Tree-row targets carry the anchor's
 * CANVAS element (the row itself is never inside the dragged element), so
 * the containment check tests the element the location actually means.
 */
const acceptsTarget = (target, data) =>
  target.location.anchor !== data.uuid &&
  !data.element?.contains(target.canvasElement ?? target.container);

/**
 * Registers the move sources and starts hover arming. Called once by the
 * app entry, after initDrag().
 */
export const initSelectorDrag = () => {
  // The move gesture through the caret drag layer. Moves are optimistic
  // (the moved-markup class of drop): the dragged node itself moves into
  // the gap on drop (its markup already exists, so no placeholder
  // materializes) and snaps back if the server rejects the move.
  registerDragSource('move_block', {
    targets: 'block',
    materialize: false,
    optimistic: 'move',
    acceptsTarget,
    operations: (target, data) => builders.moveBlock(target, data),
  });

  registerDragSource('move_section', {
    targets: 'section',
    materialize: false,
    optimistic: 'move',
    // Sections move ANYWHERE (sections all the way down): before/after a
    // sibling at any depth, or 'inside' a region/the root — the
    // move_element grammar's depth crossings are value moves. The base
    // guard still rejects self-anchored and self-contained targets.
    acceptsTarget,
    operations: (target, data) => builders.moveSection(target, data),
  });

  store.subscribe(syncArming);
};
