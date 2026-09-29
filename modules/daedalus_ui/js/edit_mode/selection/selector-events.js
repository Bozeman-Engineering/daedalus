import store from '../store/store';
import {
  setHover,
  setHoverSelector,
  setSelected,
  setTarget,
  toggleSelected,
  setDrill,
  setPickerArmed,
  clearSelection,
} from '../store/slices/selection-slice';
import { commitAddress } from './commit-address';
import {
  resolve,
  chainKeys,
  representedChainKeys,
  elementForKey,
} from './resolver';
import { isDeep, policyFor } from './depth-policy';
import { togglePicker } from './picker';
import { releasePanelFocus } from './panel-focus';
import { getPartBehavior } from '../components/page-elements/view-registry';
import { register } from '../hotkeys/hotkeys';
import { openEditor } from '../edit_session/edit-session';

/**
 * The selection's pointer grammar and its Escape: the ONE pointer of Edit
 * Mode.
 *
 * Document-level listeners, attached once; every handler early-returns
 * unless Edit Mode is on (the store's mode slice, null outside every
 * mode). Editing is a state of the selection,
 * not a second pointer: with an edit open the open edit's press decision
 * (edit-session.js onClick, a capture listener attached before these)
 * keeps, switches or commits the edit first, and the press then lands
 * here as any click does.
 *
 * The grammar (design-tool conventions):
 * - hover: resolve what a click would select, show it (the outline).
 *   Hovering the committed selection resolves to the selection itself
 *   (see the resolver's BOUNDARY policy), so a panel-selected nested block
 *   outlines, re-clicks, and drag-arms as itself, not as its boundary
 *   group.
 * - click: commit the selection. Cmd/Ctrl+click deep-selects the deepest
 *   DEPICTED thing under the cursor; every gesture resolves within the
 *   rows the page elements panel currently draws (an element always, a
 *   part only while its row is depicted: reach.js). Shift+click toggles
 *   the resolved key in the multi-select SET (the modifier split: Shift builds the set,
 *   Cmd changes depth). Clicking bare canvas clears. Clicks outside the
 *   canvas (toolbar, sidebars) are none of our business.
 * - Cmd also reaches PARTS (stylable sub-elements): while held, the part
 *   behavior's resolution seam (view-registry.js) resolves the part under
 *   the pointer — hover carries its selector as the hoverSelector facet,
 *   and a click on one selects the owner and targets the part under it
 *   (a declared part is the selector '.cls'; commit-address.js). The seam
 *   offers only parts whose row the panel depicts (the registry's reach
 *   gate: an instance carrier in every view, a shell part while the
 *   Markup view is on), so with Markup off the gesture stops at the
 *   element, where the tree, the keyboard and the breadcrumb stop too.
 *   Element and part resolution compete by DEPTH: the part wins only when
 *   its hit sits at or inside the LEAF-resolved element (partWins), so
 *   Cmd is always "the deepest depicted thing under the cursor" whichever
 *   kind that is.
 *   Plain hover/click stay element-only; part indication in the canvas is
 *   deliberately Cmd-gated, like deep element resolution already is.
 * - the element picker: the page elements panel's picker toggle (or its
 *   `selector.picker` hotkey) arms the selection slice's pickerArmed,
 *   the second source of the ONE depth policy (depth-policy.js): armed,
 *   hover and click resolve exactly as with Cmd held, elements and parts
 *   alike. The first canvas click the Selector handles (a commit, a Shift
 *   toggle, the keyless clear) disarms it; so do Escape (the ladder's
 *   first rung) and leaving Edit Mode. Clicks outside the canvas
 *   leave it armed.
 * - double-click: drill one level into the current selection and select
 *   what is under the cursor there, a projection step, not a raw DOM step;
 *   at a LEAF (nothing deeper under the cursor) the double-click EDITS it
 *   through the editor door (edit-session.js openEditor), the drill's
 *   shape at the bottom of the tree. Cmd (or the armed picker) on the click
 *   halves deep-selects first, so a nested leaf is one gesture; the
 *   resolver's deepen rule keeps the leaf selected through the second
 *   click. Climbing back out is ArrowLeft's or the breadcrumb's; Escape
 *   never traverses.
 * - Escape: the way out, never a move. An open edit cancels first
 *   (edit-session.js, the editing rung of the same ladder, outranking this
 *   one by context); then an armed picker drops; then a standing styling
 *   target (back to the bare element, the selection kept); then the
 *   selection clears, whatever drill context stands with it
 *   (clearSelection nulls the context too).
 */

const editModeActive = () => store.getState().mode.mode === 'edit';

/**
 * Drops the armed picker, if armed. The canvas gesture that lands
 * consumes the arming, DevTools' "select an element" shape.
 */
const disarmPicker = () => {
  if (store.getState().selection.pickerArmed) {
    store.dispatch(setPickerArmed(false));
  }
};

/**
 * The part under the pointer, as the hoverSelector facet shape
 * ({key, selector}), or null. Gated on the same depth policy that flips
 * element resolution to LEAF (the held modifier or the armed picker) —
 * part resolution rides that policy, no separate modifier state. The
 * facet carries the part's OWN owner key (the seam's parentKey): the
 * owning scope root can sit above the LEAF-resolved element, and the
 * @scope paint must root at the true owner. The seam's part id IS the
 * part's selector ('.cls').
 */
const resolvePart = (event, pickerArmed) => {
  if (!isDeep(event, pickerArmed)) {
    return null;
  }
  const hit = getPartBehavior()?.resolve?.(event.target) ?? null;
  return hit
    ? { key: hit.parentKey, selector: hit.id, hit: hit.hit ?? null }
    : null;
};

/**
 * Whether a resolved part beats the LEAF-resolved element: Cmd means "the
 * deepest thing under the cursor", and parts extend the universe BELOW
 * elements, never around them. The part wins only when its hit element
 * sits at or inside the element resolution's DOM node (the eyebrow inside
 * a selected-depth text field); a part matched on an ANCESTOR of the
 * deepest presented element (a container's root class over a block) loses
 * to the element, or Cmd+click on a merged block would commit its
 * section's part instead of the block.
 */
const partWins = (part, key) => {
  if (!part) {
    return false;
  }
  const element = elementForKey(key);
  return !element || !part.hit || element.contains(part.hit);
};

const onMouseMove = (event) => {
  if (!editModeActive()) {
    return;
  }
  // Inside a hover-scope owner (the page-elements tree rows), hover is
  // driven by that UI's own enter/leave handlers; the canvas resolver
  // stepping in here would clear the row's hover on every pointer move.
  if (
    event.target instanceof Element &&
    event.target.closest('[data-selection-hover-scope]')
  ) {
    return;
  }
  const state = store.getState().selection;
  const key = resolve(event.target, {
    policy: policyFor(event, state.pickerArmed),
    drillKey: state.drillKey,
    selectedKeys: state.selectedKeys,
  });
  if (key !== state.hoverKey) {
    store.dispatch(setHover(key));
  }
  // The dispatch guard covers the (key, selector) PAIR: a part change
  // under the same element (Cmd-hover sliding between two parts of one
  // block) must dispatch too. Compared against the store AFTER the element
  // dispatch, because a bare-key setHover just cleared the facet. The
  // facet previews what a click would commit, so it carries the part only
  // when the part would WIN the click (partWins), and never the DOM hit,
  // which is resolution plumbing, not state.
  const part = resolvePart(event, state.pickerArmed);
  const winner = partWins(part, key)
    ? { key: part.key, selector: part.selector }
    : null;
  const current = store.getState().selection.hoverSelector;
  if (winner?.key !== current?.key || winner?.selector !== current?.selector) {
    store.dispatch(setHoverSelector(winner));
  }
};

const onClick = (event) => {
  if (!editModeActive()) {
    return;
  }
  const state = store.getState().selection;
  const key = resolve(event.target, {
    policy: policyFor(event, state.pickerArmed),
    drillKey: state.drillKey,
    selectedKeys: state.selectedKeys,
  });

  if (key) {
    // The click is a selection gesture, not a page interaction: don't follow
    // links or press buttons that happen to live inside the selected element.
    event.preventDefault();
    event.stopPropagation();
    // A handled click consumes the armed picker, whichever branch below
    // commits it: the key was resolved at picker depth already, so the
    // commit lands on the leaf the picker lit.
    disarmPicker();
    if (state.drillKey && !chainKeys(event.target).includes(state.drillKey)) {
      // Committing outside the drilled context exits it.
      store.dispatch(setDrill(null));
    }
    if (event.shiftKey) {
      // Shift builds the multi-select set: toggle the key's membership
      // (adding makes it the primary). Works from empty too — the set of
      // one it produces is indistinguishable from a plain click.
      store.dispatch(toggleSelected(key));
      return;
    }
    // Cmd-click on a part targets it: the owner selected, the part's
    // selector the styling target under it — the canvas twin of a tree
    // part-row click, so the dashed Cmd-hover indication and the click
    // commit agree. The part commits only when it is the deepest thing
    // under the cursor (partWins); a part hit above the LEAF element
    // yields to it. commitAddress dispatches nothing for a same-address
    // click.
    const part = resolvePart(event, state.pickerArmed);
    if (partWins(part, key)) {
      commitAddress(part.key, part.selector);
      return;
    }
    // A plain click carries no selector, so clicking the already-selected
    // element while a target stands drops the target back to the bare
    // element; a same-address click dispatches nothing.
    commitAddress(key);
    return;
  }

  // A keyless click inside the canvas clears the selection; a click outside
  // the canvas (toolbar, sidebars, dialogs) is left alone, as is a click on
  // inline canvas chrome (the chrome owns its own interaction — see
  // selectableChain's [data-canvas-chrome] escape). A keyless SHIFT+click
  // is a no-op instead: a missed toggle must not destroy the set being
  // built. The keyless clear is a handled canvas click too, so it
  // consumes the armed picker (clearSelection itself leaves the flag
  // alone: disarming is the gesture's, not the selection's).
  if (
    !event.shiftKey &&
    event.target instanceof Element &&
    event.target.closest('[data-main-entity]') &&
    !event.target.closest('[data-canvas-chrome]')
  ) {
    disarmPicker();
    store.dispatch(clearSelection());
  }
};

const onDoubleClick = (event) => {
  if (!editModeActive()) {
    return;
  }
  const state = store.getState().selection;
  if (!state.selectedKey) {
    return;
  }
  const keys = representedChainKeys(event.target);
  const index = keys.indexOf(state.selectedKey);
  if (index > 0) {
    // There is something deeper under the cursor inside the selection:
    // enter the selection and select it.
    event.preventDefault();
    event.stopPropagation();
    store.dispatch(setDrill(state.selectedKey));
    store.dispatch(setSelected(keys[index - 1]));
    return;
  }
  if (index === 0 && openEditor(state.selectedKey, event.target) !== null) {
    // A leaf: nothing deeper to drill into, so the double-click edits it,
    // the leaf under the pointer first (the theme's H1 commits the root
    // row's key from outside the entity wrapper, where no key search
    // finds it). The door decides whether the key has an editor (a
    // section has none); with no engine registered the gesture is nobody's.
    event.preventDefault();
    event.stopPropagation();
  }
  // index === -1: unrelated target; the click half of the gesture already
  // handled it.
};

/**
 * Escape deselects; it never traverses. An armed picker drops first (the
 * selection and its target stay: DevTools' Escape leaves the picker
 * without touching what is selected); then a standing styling target
 * (the selection stays, back to the bare element: the same drop ArrowLeft
 * makes from an unplaced target); then the selection clears in one press,
 * whatever drill context the arrows, a double-click or a crumb click left
 * standing (clearSelection nulls it too), so a context is never a rung
 * the user has to climb through. A stray context with nothing selected
 * clears the same way. The clear also releases the page-elements panel's
 * DOM focus (panel-focus.js, the move F6-to-canvas makes): tree rows are
 * focusable, and a row left focused after its selection is gone would
 * wear the browser's focus-visible ring, Escape being a key press. The
 * picker and target rungs keep the selection, so they keep the focus.
 * Registered as a global hotkey binding: the open edit's cancel, the
 * lift's cancel and the palette's insert session outrank it by context,
 * and the dispatcher supplies the typing guard.
 */
const onEscape = () => {
  const state = store.getState().selection;
  if (state.pickerArmed) {
    store.dispatch(setPickerArmed(false));
  } else if (state.target) {
    store.dispatch(setTarget(null));
  } else if (state.selectedKey || state.drillKey) {
    store.dispatch(clearSelection());
    releasePanelFocus();
  } else {
    // Nothing to drop: not ours; let Escape propagate.
    return false;
  }
};

const onMouseLeave = () => {
  const { hoverKey, hoverSelector } = store.getState().selection;
  if (editModeActive() && (hoverKey || hoverSelector)) {
    // A bare-key setHover(null) clears the selector facet with it.
    store.dispatch(setHover(null));
  }
};

/**
 * Attaches the selection's pointer listeners and its Escape. Called once
 * by the app entry, after the open edit's (initEditSession) so the edit's
 * press decision runs first in the capture phase.
 */
export const initSelectorEvents = () => {
  document.addEventListener('mousemove', onMouseMove, { passive: true });
  // Capture phase so a selection click wins before page handlers see it.
  document.addEventListener('click', onClick, true);
  document.addEventListener('dblclick', onDoubleClick, true);
  document.documentElement.addEventListener('mouseleave', onMouseLeave);

  register({
    id: 'selection.escape',
    key: 'escape',
    when: 'global',
    label: Drupal.t('Clear the selection'),
    section: Drupal.t('Selection'),
    action: onEscape,
  });

  // The element picker's hotkey, DevTools' binding. Global; the
  // dispatcher's typing guard keeps it out of text entry. The panel's
  // picker button calls the same toggle.
  register({
    id: 'selector.picker',
    key: 'mod+shift+c',
    when: 'global',
    label: Drupal.t('Pick an element'),
    section: Drupal.t('Selection'),
    remappable: true,
    action: togglePicker,
  });

  // Leaving Edit Mode drops the transient hover and the armed picker
  // (every handler above early-returns outside the mode, so an armed
  // picker there is meaningless); the committed selection survives so
  // verbs and panels can still act on it.
  window.listenToStateChange(
    (state) => state.mode.mode,
    (mode) => {
      const { hoverKey, hoverSelector, pickerArmed } =
        store.getState().selection;
      if (mode !== 'edit' && (hoverKey || hoverSelector)) {
        store.dispatch(setHover(null));
      }
      if (mode !== 'edit' && pickerArmed) {
        store.dispatch(setPickerArmed(false));
      }
    },
  );
};
