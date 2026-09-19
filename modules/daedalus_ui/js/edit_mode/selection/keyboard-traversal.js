import store from '../store/store';
import { setSelected, setDrill } from '../store/slices/selection-slice';
import { elementForKey } from './resolver';
import { register } from '../hotkeys/hotkeys';
import { announce } from '../utilities/announce';
import { labelForKey } from './label-for-key';
import {
  projectionRoot,
  projectionIndex,
} from '../components/page-elements/projection-index';
import { seatOf, siblingOf, parentOf, firstRowOf } from './traversal';

/**
 * Keyboard traversal of the ONE selection: arrows walk the tree the
 * panel projects (Up/Down among siblings, Left up to the parent, Right
 * drills to the first row) from the canvas or the panel alike, because
 * both surfaces render the same selection slice. The bindings live in the
 * dispatcher's TOOL context (Selector), not the selection context, so they
 * exist with nothing selected: any arrow then ENTERS the tree by selecting
 * the projection root, the keyboard's way in, matching how arrows are the
 * steering wheel once inside. The panel needs no key handling of its own,
 * only focusable rows (page-elements-tree.js) so screen readers track the
 * movement. Selection-context keys (verbs, the palette's insert session)
 * outrank traversal by context while a selection exists.
 *
 * The traversal tree is the selection index over BOTH tiers of the
 * blueprint PROJECTION (the panel's model, projection-index.js), not the
 * DOM: zero-render-seam rows (form-only stubs) traverse like anything
 * else, merged rows are one stop by construction, and markup rows (a
 * linked image's Raster shell, a list and its items inside a formatted
 * text block) are stops of their own, so Right from Image lands on Raster
 * and Left from a list item climbs to its list. The moves themselves are
 * pure (traversal.js); this file binds them to the store, the canvas
 * reveal, and the announcer. A selection with no projection node (a
 * programmatic selection from outside the universe; canvas clicks cannot
 * produce one) declines the key and lets it propagate.
 *
 * Left/Right also maintain the Selector's drill context the way the
 * pointer gestures do: Right = double-click's drill-in (the selection
 * becomes the context, its first row the selection), Left = Escape's
 * step-up (the parent becomes the selection, the grandparent the context).
 * The context is element-tier, so a part seats it at its owner's parent,
 * exactly where the breadcrumb's crumb click does (traversal.js `drill`).
 *
 * F6 toggles DOM focus between the page-elements panel and the canvas:
 * a FOCUS toggle, never a selection transfer, since selection is shared
 * state and both surfaces already show it.
 */

/**
 * Commits a traversal step: select the pair, reveal on canvas, announce.
 * The canvas reveals the element the pair's key names (the owner, for a
 * part: a part has no element of its own to look up); the announcement
 * reads the row's own label (Raster, List item) from the one label
 * resolver. The panel row reveals (and takes focus when focus is in the
 * panel) through the tree's own selection effects.
 */
const commit = (step) => {
  store.dispatch(
    setSelected(step.part ? { key: step.key, part: step.part } : step.key),
  );
  elementForKey(step.key)?.scrollIntoView({
    block: 'nearest',
    behavior: 'smooth',
  });
  announce(labelForKey(step.address));
};

/**
 * Declines the key when the traversal grammar does not own it: focus sits
 * on an interactive control outside the panel tree (a verb button, a
 * toolbar link — their native keyboard behavior wins). The tool context
 * already gates on the Selector being current.
 */
const declines = (event) =>
  event.target instanceof Element &&
  !!event.target.closest('button, a[href], select, [contenteditable="true"]') &&
  !event.target.closest('.daedalus-ui-tree');

/**
 * The selection pair and the index, or null when the index cannot seat
 * the selection (no selection, or no projection node for its key).
 */
const seated = () => {
  const { selectedKey: key, selectedPart: part } = store.getState().selection;
  const index = projectionIndex();
  return key && seatOf(index, key, part) ? { index, key, part } : null;
};

/**
 * Enters the tree from nothing: with no selection at all, any arrow
 * selects the projection root — the keyboard's entry point. A selection
 * that exists but has no projection node (outside the universe) is not
 * ours to move: decline and let the key propagate.
 */
const enter = () => {
  if (store.getState().selection.selectedKey) {
    return false;
  }
  const root = projectionRoot();
  if (!root) {
    return false;
  }
  store.dispatch(setDrill(null));
  commit({ address: root, key: root, part: null });
};

const traverseSiblings = (delta) => (event) => {
  if (declines(event)) {
    return false;
  }
  const current = seated();
  if (!current) {
    return enter();
  }
  const step = siblingOf(current.index, current.key, current.part, delta);
  if (!step) {
    // At the edge of the sibling list: handled (no page scroll), no move.
    return;
  }
  commit(step);
};

const traverseOut = (event) => {
  if (declines(event)) {
    return false;
  }
  const current = seated();
  if (!current) {
    return enter();
  }
  const step = parentOf(current.index, current.key, current.part);
  if (!step) {
    return;
  }
  // Escape's step-up shape: the parent becomes the selection, the
  // grandparent the drill context.
  commit(step);
  store.dispatch(setDrill(step.drill));
};

const traverseIn = (event) => {
  if (declines(event)) {
    return false;
  }
  const current = seated();
  if (!current) {
    return enter();
  }
  const step = firstRowOf(current.index, current.key, current.part);
  if (!step) {
    return;
  }
  // Double-click's drill-in shape: the selection becomes the context.
  store.dispatch(setDrill(step.drill));
  commit(step);
};

/**
 * F6: toggle DOM focus between the page-elements panel and the canvas.
 */
const toggleSurfaceFocus = () => {
  const mount = document.getElementById('page-elements-tree');
  if (!mount) {
    return false;
  }
  if (mount.contains(document.activeElement)) {
    // To the canvas: canvas keys need no DOM focus (the dispatcher is
    // document-level), so "focus" means releasing the panel and showing
    // where the selection sits.
    document.activeElement.blur();
    elementForKey(store.getState().selection.selectedKey)?.scrollIntoView({
      block: 'nearest',
      behavior: 'smooth',
    });
    announce(Drupal.t('Canvas focused.'));
    return;
  }
  const focusRow = () => {
    const key = store.getState().selection.selectedKey;
    const row =
      (key && mount.querySelector(`[data-tree-key="${key}"]`)) ||
      mount.querySelector('[data-tree-key]');
    if (row) {
      row.focus();
      announce(Drupal.t('Page elements panel focused.'));
    }
  };
  const panel = document.getElementById('page_elements');
  if (panel?.classList.contains('daedalus-ui-hidden')) {
    Drupal.Daedalus.SidebarManager.openSidebar('page_elements')
      .then(focusRow)
      .catch(() => {});
    return;
  }
  focusRow();
};

/**
 * Registers the traversal and focus-toggle bindings. Called once by the
 * app entry, after initHotkeys().
 */
export const initKeyboardTraversal = () => {
  [
    {
      id: 'selector.traverse_next',
      key: 'arrowdown',
      label: Drupal.t('Select the next element'),
      action: traverseSiblings(1),
    },
    {
      id: 'selector.traverse_previous',
      key: 'arrowup',
      label: Drupal.t('Select the previous element'),
      action: traverseSiblings(-1),
    },
    {
      id: 'selector.traverse_out',
      key: 'arrowleft',
      label: Drupal.t('Select the parent element'),
      action: traverseOut,
    },
    {
      id: 'selector.traverse_in',
      key: 'arrowright',
      label: Drupal.t('Select the first child element'),
      action: traverseIn,
    },
  ].forEach((spec) =>
    register({
      ...spec,
      when: 'tool',
      tool: 'selector',
      section: Drupal.t('Selection'),
      remappable: true,
    }),
  );

  register({
    id: 'app.focus_toggle',
    key: 'f6',
    when: 'global',
    label: Drupal.t('Toggle panel / canvas focus'),
    section: Drupal.t('Selection'),
    remappable: true,
    action: toggleSurfaceFocus,
  });
};
