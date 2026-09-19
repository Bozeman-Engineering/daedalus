import store from '../store/store';
import {
  setHover,
  setHoverPart,
  setSelected,
  toggleSelected,
  setDrill,
  clearSelection,
} from '../store/slices/selection-slice';
import {
  resolve,
  chainKeys,
  representedChainKeys,
  parentKey,
  elementForKey,
  POLICY,
} from './resolver';
import { getPartBehavior } from '../components/page-elements/view-registry';
import { register } from '../hotkeys/hotkeys';

/**
 * The Selector tool's pointer and keyboard grammar.
 *
 * Document-level listeners, attached once; every handler early-returns
 * unless the Selector tool is current (currentTool is null outside Edit
 * Mode, so this single gate covers both). While any other tool is active
 * the page behaves as before — the old tools keep their own interaction
 * paths until each is replaced.
 *
 * The grammar (design-tool conventions):
 * - hover: resolve what a click would select, show it (the outline).
 *   Hovering the committed selection resolves to the selection itself
 *   (see the resolver's BOUNDARY policy), so a panel-selected nested block
 *   outlines, re-clicks, and drag-arms as itself, not as its boundary
 *   group.
 * - click: commit the selection. Cmd/Ctrl+click deep-selects the deepest
 *   PRESENTED element under the cursor; every gesture resolves within the
 *   panel's projected tree. Shift+click toggles the resolved key in
 *   the multi-select SET (the modifier split: Shift builds the set,
 *   Cmd changes depth). Clicking bare canvas clears. Clicks outside the
 *   canvas (toolbar, sidebars) are none of our business.
 * - Cmd also reaches PARTS (stylable sub-elements): while held, the part
 *   behavior's resolution seam (view-registry.js) resolves the part under
 *   the pointer — hover carries it as the hoverPart facet, and a click on
 *   one commits {owner key, part} onto the spine. Element and part
 *   resolution compete by DEPTH: the part wins only when its hit sits at
 *   or inside the LEAF-resolved element (partWins), so Cmd is always "the
 *   deepest presented thing under the cursor" whichever kind that is.
 *   Plain hover/click stay element-only; part indication in the canvas is
 *   deliberately Cmd-gated, like deep element resolution already is.
 * - double-click: drill one level into the current selection and select
 *   what is under the cursor there, a projection step, not a raw DOM step.
 * - Escape: step back up — select the drill context and pop it; with no
 *   drill, clear the selection.
 */

const selectorActive = () => store.getState().tool.currentTool === 'selector';

const policyForEvent = (event) =>
  event.metaKey || event.ctrlKey ? POLICY.LEAF : POLICY.BOUNDARY;

/**
 * The part under the pointer, as the hoverPart facet shape ({key, part}),
 * or null. Cmd-gated on the same modifier check that flips element
 * resolution to LEAF — part resolution rides that policy, no separate
 * modifier state. The facet carries the part's OWN owner key (the seam's
 * parentKey): the owning scope root can sit above the LEAF-resolved
 * element, and the @scope paint must root at the true owner.
 */
const resolvePart = (event) => {
  if (!event.metaKey && !event.ctrlKey) {
    return null;
  }
  const hit = getPartBehavior()?.resolve?.(event.target) ?? null;
  return hit
    ? { key: hit.parentKey, part: hit.id, hit: hit.hit ?? null }
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
  if (!selectorActive()) {
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
    policy: policyForEvent(event),
    drillKey: state.drillKey,
    selectedKeys: state.selectedKeys,
  });
  if (key !== state.hoverKey) {
    store.dispatch(setHover(key));
  }
  // The dispatch guard covers the (key, part) PAIR: a part change under
  // the same element (Cmd-hover sliding between two parts of one block)
  // must dispatch too. Compared against the store AFTER the element
  // dispatch, because a bare-key setHover just cleared the facet. The
  // facet previews what a click would commit, so it carries the part only
  // when the part would WIN the click (partWins), and never the DOM hit,
  // which is resolution plumbing, not state.
  const part = resolvePart(event);
  const winner = partWins(part, key)
    ? { key: part.key, part: part.part }
    : null;
  const current = store.getState().selection.hoverPart;
  if (winner?.key !== current?.key || winner?.part !== current?.part) {
    store.dispatch(setHoverPart(winner));
  }
};

const onClick = (event) => {
  if (!selectorActive()) {
    return;
  }
  const state = store.getState().selection;
  const key = resolve(event.target, {
    policy: policyForEvent(event),
    drillKey: state.drillKey,
    selectedKeys: state.selectedKeys,
  });

  if (key) {
    // The click is a selection gesture, not a page interaction: don't follow
    // links or press buttons that happen to live inside the selected element.
    event.preventDefault();
    event.stopPropagation();
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
    // Cmd-click on a part selects it: the owner's key with the part as the
    // facet — the canvas twin of a tree part-row click, so the dashed
    // Cmd-hover indication and the click commit agree. The part commits
    // only when it is the deepest thing under the cursor (partWins); a
    // part hit above the LEAF element yields to it. Same-tuple clicks
    // dispatch nothing, like the element guard below.
    const part = resolvePart(event);
    if (partWins(part, key)) {
      if (
        part.key !== state.selectedKey ||
        part.part !== state.selectedPart ||
        state.selectedKeys.length > 1
      ) {
        store.dispatch(setSelected({ key: part.key, part: part.part }));
      }
      return;
    }
    // The commit guard compares the (key, part) TUPLE, not just the key: a
    // plain click carries no part facet, so clicking the already-selected
    // element while a part is sub-selected is a real change ({key, null}
    // differs from {key, part}) and re-commits the element, clearing the
    // part drill. A same-tuple click still dispatches nothing.
    if (
      key !== state.selectedKey ||
      state.selectedPart !== null ||
      state.selectedKeys.length > 1
    ) {
      store.dispatch(setSelected(key));
    }
    return;
  }

  // A keyless click inside the canvas clears the selection; a click outside
  // the canvas (toolbar, sidebars, dialogs) is left alone, as is a click on
  // inline canvas chrome (the chrome owns its own interaction — see
  // selectableChain's [data-canvas-chrome] escape). A keyless SHIFT+click
  // is a no-op instead: a missed toggle must not destroy the set being
  // built.
  if (
    !event.shiftKey &&
    event.target instanceof Element &&
    event.target.closest('[data-main-entity]') &&
    !event.target.closest('[data-canvas-chrome]')
  ) {
    store.dispatch(clearSelection());
  }
};

const onDoubleClick = (event) => {
  if (!selectorActive()) {
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
  }
  // index === 0: a leaf — double-click will enter editing when the Edit
  // verb lands. index === -1: unrelated target; the click half of the
  // gesture already handled it.
};

/**
 * Escape steps back up: the drill context becomes the selection and the
 * context pops to its parent; with no drill, it clears the selection.
 * Registered as a Selector-tool hotkey binding — the dispatcher supplies
 * the tool gate and the typing guard.
 */
const onEscape = () => {
  const state = store.getState().selection;
  if (state.drillKey) {
    store.dispatch(setSelected(state.drillKey));
    store.dispatch(setDrill(parentKey(state.drillKey)));
  } else if (state.selectedKey) {
    store.dispatch(clearSelection());
  } else {
    // Nothing to step out of — not ours; let Escape propagate.
    return false;
  }
};

const onMouseLeave = () => {
  const { hoverKey, hoverPart } = store.getState().selection;
  if (selectorActive() && (hoverKey || hoverPart)) {
    // A bare-key setHover(null) clears the part facet with it.
    store.dispatch(setHover(null));
  }
};

/**
 * Attaches the Selector tool's listeners. Called once by the app entry.
 */
export const initSelectorEvents = () => {
  document.addEventListener('mousemove', onMouseMove, { passive: true });
  // Capture phase so a selection click wins before page handlers see it.
  document.addEventListener('click', onClick, true);
  document.addEventListener('dblclick', onDoubleClick, true);
  document.documentElement.addEventListener('mouseleave', onMouseLeave);

  register({
    id: 'selector.escape',
    key: 'escape',
    when: 'tool',
    tool: 'selector',
    label: Drupal.t('Clear the selection'),
    section: Drupal.t('Selection'),
    action: onEscape,
  });

  // Leaving the Selector tool drops the transient hover; the committed
  // selection survives tool switches so verbs and panels can still act on it.
  window.listenToStateChange(
    (state) => state.tool.currentTool,
    (currentTool) => {
      const { hoverKey, hoverPart } = store.getState().selection;
      if (currentTool !== 'selector' && (hoverKey || hoverPart)) {
        store.dispatch(setHover(null));
      }
    },
  );
};
