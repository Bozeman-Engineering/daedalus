import store from '../store/store';
import { setTarget, setDrill } from '../store/slices/selection-slice';
import { commitAddress } from './commit-address';
import { elementForKey } from './resolver';
import { releasePanelFocus } from './panel-focus';
import { register } from '../hotkeys/hotkeys';
import { announce } from '../utilities/announce';
import { labelForKey } from './label-for-key';
import {
  projectionRoot,
  projectionIndex,
} from '../components/page-elements/projection-index';
import {
  reachPredicate,
  seatAddress,
} from '../components/page-elements/reach';
import { seatOf, siblingOf, parentOf, firstRowOf } from './traversal';

/**
 * Keyboard traversal of the ONE selection: arrows walk the tree the
 * panel projects (Up/Down among siblings, Left up to the parent, Right
 * drills to the first row) from the canvas or the panel alike, because
 * both surfaces render the same selection slice. The bindings are GLOBAL
 * (the one keyboard grammar of Edit Mode), not selection-context, so they
 * exist with nothing selected: any arrow then ENTERS the tree by selecting
 * the projection root, the keyboard's way in, matching how arrows are the
 * steering wheel once inside; a landing is a plain selection, and Enter
 * (edit-session.js, the editor door) is how the selection starts editing.
 * The panel needs no key handling of its own, only focusable rows
 * (page-elements-tree.js) so screen readers track the movement.
 * Selection-context keys (verbs, the palette's insert session) and the
 * open edit's keys outrank traversal by context; while typing nothing here
 * fires (the dispatcher's typing partition).
 *
 * The traversal tree is the selection index over BOTH tiers of the
 * blueprint PROJECTION (the panel's model, projection-index.js), not the
 * DOM, read through REACH (reach.js: the rows the panel currently
 * draws): zero-render-seam rows (form-only stubs) traverse like anything
 * else, merged rows are one stop by construction, and markup rows are
 * stops of their own exactly while the panel draws them (a linked
 * image's Raster shell with the Markup view on, so Right from Image
 * lands on Raster; the claimed field straight from Image with it off; a
 * list and its items inside a formatted text block in every view, so
 * Left from a list item always climbs to its list). The moves themselves
 * are pure (traversal.js); this file binds them to the store, the canvas
 * reveal, and the announcer, taking one reach predicate per keystroke.
 * A selection with no projection node (a programmatic selection from
 * outside the universe; canvas clicks cannot produce one) declines the
 * key and lets it propagate.
 *
 * Left/Right also maintain the drill context: Right is the
 * keyboard's drill-in, double-click's shape (the selection becomes the
 * context, its first row the selection), and Left is the keyboard's
 * step-up, the breadcrumb's shape (the parent becomes the selection, the
 * grandparent the context). These two and the crumb click are the ONLY
 * climbing gestures: Escape deselects and never moves. The context is
 * element-tier, so a part seats it at its owner's parent, exactly where
 * the crumb click does (traversal.js `drill`).
 *
 * The styling target rides the walk as the row it seats on: a placed,
 * drawn part target IS its row (Up/Down among the owner's rows, Left to
 * the row parent), while a target the index does not place (a tag tier,
 * a chain seated from code mode, an ancestor rule, a hoisted part) or
 * places on a row the panel is not drawing (a shell part with the
 * Markup view off) sits on the selected element, and Left from it drops
 * the target back to the bare element (the same drop Escape's target
 * rung makes) instead of climbing past it.
 *
 * F6 toggles DOM focus between the page-elements panel and the canvas:
 * a FOCUS toggle, never a selection transfer, since selection is shared
 * state and both surfaces already show it.
 */

/**
 * Commits a traversal step: select the element and target the part
 * (commit-address.js), reveal on canvas, announce. The canvas reveals
 * the element the step's key names (the owner, for a part: a part has no
 * element of its own to look up) by the one reveal rule
 * (Drupal.Daedalus.scrollReveal); the announcement reads the row's own
 * label (Raster, List item) from the one label resolver. The panel row
 * reveals (and takes focus when focus is in the panel) through the
 * tree's own selection effects.
 */
const commit = (step) => {
  commitAddress(step.key, step.selector);
  Drupal.Daedalus.scrollReveal(elementForKey(step.key), { behavior: 'smooth' });
  announce(labelForKey(step.address));
};

/**
 * Declines the key when the traversal grammar does not own it: focus sits
 * on an interactive control outside the panel tree (a verb button, a
 * toolbar link: their native keyboard behavior wins), the stroke's
 * `control` fact as the keyboard sensor read it in whichever document
 * the key was pressed. Typing (a contenteditable, an open editor's
 * widget) is the dispatcher's typing partition, never reached here.
 */
const declines = (stroke) => stroke.control;

/**
 * The selection, the selector it seats on, the index and the reach
 * predicate for this keystroke, or null when the index cannot seat the
 * selection (no selection, or no projection node for its key). The
 * selector is the target's when the target sits on the selected element
 * (a part row, or an unplaced tag or chain); an ancestor-rule target
 * seats the bare element.
 */
const seated = () => {
  const { selectedKey: key, target } = store.getState().selection;
  const selector = target && target.key === key ? target.selector : null;
  const index = projectionIndex();
  const reach = reachPredicate();
  return key && seatOf(index, reach, key, selector)
    ? { index, reach, key, selector, target }
    : null;
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
  commit({ address: root, key: root, selector: null });
};

const traverseSiblings = (delta) => (stroke) => {
  if (declines(stroke)) {
    return false;
  }
  const current = seated();
  if (!current) {
    return enter();
  }
  const step = siblingOf(
    current.index,
    current.reach,
    current.key,
    current.selector,
    delta,
  );
  if (!step) {
    // At the edge of the sibling list: handled (no page scroll), no move.
    return;
  }
  commit(step);
};

const traverseOut = (stroke) => {
  if (declines(stroke)) {
    return false;
  }
  const current = seated();
  if (!current) {
    return enter();
  }
  // A target the index does not place, or places on a row the panel is
  // not drawing, sits on the selected element: Left drops it back to the
  // bare element (the drop Escape's target rung makes), the symmetric
  // move to Right seating a part. A drawn part climbs its row parent
  // like any row.
  if (
    current.target &&
    seatOf(current.index, current.reach, current.key, current.selector)
      .address === current.key
  ) {
    store.dispatch(setTarget(null));
    announce(labelForKey(current.key));
    return;
  }
  const step = parentOf(
    current.index,
    current.reach,
    current.key,
    current.selector,
  );
  if (!step) {
    return;
  }
  // The step-up shape (the breadcrumb's crumb click commits the same):
  // the parent becomes the selection, the grandparent the drill context.
  commit(step);
  store.dispatch(setDrill(step.drill));
};

const traverseIn = (stroke) => {
  if (declines(stroke)) {
    return false;
  }
  const current = seated();
  if (!current) {
    return enter();
  }
  const step = firstRowOf(
    current.index,
    current.reach,
    current.key,
    current.selector,
  );
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
  if (releasePanelFocus()) {
    // To the canvas: canvas keys need no DOM focus (the dispatcher is
    // document-level), so "focus" means releasing the panel (the door
    // Escape's deselect shares, panel-focus.js) and showing where the
    // selection sits.
    Drupal.Daedalus.scrollReveal(
      elementForKey(store.getState().selection.selectedKey),
      { behavior: 'smooth' },
    );
    announce(Drupal.t('Canvas focused.'));
    return;
  }
  const focusRow = () => {
    // Focus follows the seat (reach.js seatAddress): the target's row
    // while a placed, drawn part is targeted (the row the tree marks
    // active), else the selected element's row, exactly where the walks
    // and the breadcrumb stand.
    const { selectedKey: key, target } = store.getState().selection;
    const seat =
      key &&
      seatAddress(
        projectionIndex(),
        reachPredicate(),
        key,
        target && target.key === key ? target.selector : null,
      );
    const row =
      (seat &&
        seat !== key &&
        mount.querySelector(`[data-tree-address="${seat}"]`)) ||
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
      id: 'selection.traverse_next',
      key: 'arrowdown',
      label: Drupal.t('Select the next element'),
      action: traverseSiblings(1),
    },
    {
      id: 'selection.traverse_previous',
      key: 'arrowup',
      label: Drupal.t('Select the previous element'),
      action: traverseSiblings(-1),
    },
    {
      id: 'selection.traverse_out',
      key: 'arrowleft',
      label: Drupal.t('Select the parent element'),
      action: traverseOut,
    },
    {
      id: 'selection.traverse_in',
      key: 'arrowright',
      label: Drupal.t('Select the first child element'),
      action: traverseIn,
    },
  ].forEach((spec) =>
    register({
      ...spec,
      when: 'global',
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
