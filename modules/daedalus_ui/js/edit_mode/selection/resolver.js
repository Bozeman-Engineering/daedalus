/**
 * Selection resolution: a pointer target → a selectable key.
 *
 * THE PROJECTION IS THE SELECTION UNIVERSE: every pointer policy
 * resolves within the presented tree, the same projection the panel
 * renders, the arrows traverse, and the breadcrumb prints. A DOM key with
 * no projection row (a wrapper absorbed by a merged presentation, a shared
 * media entity's inner fields, a single-region section's region) can never
 * become the selection: selecting something the UI cannot name, label, or
 * traverse from leads nowhere. Before the mirror boots the filter is inert
 * for BOUNDARY and LEAF (their fallback is the raw chain); EDITABLE fails
 * closed instead (see keyForEditableItem).
 *
 * THE PROJECTION IS ALSO THE EDITING UNIVERSE. Attribution is host-blind
 * by design: a layout block hosted in a theme region renders its inner
 * components fully attributed (selectable keys, editable items) on every
 * page, because the same render is cached and shared across hosts and the
 * styling identity must land for visitors too. So a DOM attribute is an
 * ADDRESS and a capability descriptor, never authorization. What is
 * editable HERE is decided by membership in the root context's blueprint
 * projection, the same Map the Selector tool resolves within. Anything
 * outside it is inert to the armed edit session: no hover, no click, no
 * Tab stop. Its editing surface is its own root (the block_content edit
 * route), where it IS the main entity.
 *
 * There is ONE resolver, parameterized by a stop-depth policy. Contexts
 * differ only in the policy they pass, never in mechanism:
 *
 * - BOUNDARY (the Selector tool's default): stop at the outermost presented
 *   selectable within the current drill context — respect the grouping,
 *   like clicking a group in a design tool. Double-click drills the context
 *   one level; drill steps are PROJECTION steps, so inside a merged block
 *   the absorbed wrapper chain is not a sequence of stops. ONE exception
 *   DEEPENS the stop: pointing at the SELECTION means the selection. When
 *   the chain under the cursor contains a selected key at or inside the
 *   default stop, resolution stops at the innermost such member — however
 *   the selection was made (panel row, deep select), hovering it names it,
 *   so outline, click, and drag arming agree with the committed selection
 *   instead of re-resolving past it to the boundary (the pointer-side twin
 *   of the lift's "Space lifts what a drag would grab"). Deepen-only: a
 *   selected ancestor never coarsens resolution below a drilled context.
 * - LEAF (deep select, Cmd/Ctrl+click): the deepest presented selectable
 *   under the cursor — skips the drill dance, never the presented
 *   structure (the design-tool convention: deep select reaches layers the
 *   panel shows, folded or not).
 * - EDITABLE (the armed edit session): the deepest editable leaf — "one
 *   click on the word you want" — EXCEPT inside a presentation root
 *   (data-presentation="unit|merged"), a subtree that presents as one
 *   element: there the root's boundary item wins (a media reference's inner
 *   image field is "the image"; editing it means changing the reference,
 *   not the shared media entity's file). A MERGED root additionally
 *   promotes the SELECTION KEY to the root's own key — the outermost
 *   carrier (the block placement, so move/trash/duplicate/drag all arm) —
 *   while the edit still routes to the inner leaf item.
 *
 * The main entity wrapper (data-main-entity) is the canvas: it never
 * resolves. Selection starts at its direct selectable children.
 */

import store from '../store/store';
import { projectionIndex } from '../components/page-elements/projection-index';

export const POLICY = {
  BOUNDARY: 'boundary',
  LEAF: 'leaf',
  EDITABLE: 'editable',
};

/**
 * The editable-leaf marker is the editing engine's vocabulary, not ours:
 * the edit session injects it here when an engine registers. Edit identity
 * rides the engine's per-delta field items, not the selectable wrappers
 * around them — a field WRAPPER is selectable, its ITEMS are editable —
 * so editable resolution works item-first. Until an engine registers,
 * nothing resolves as editable.
 */
let editableItemSelector = null;

/**
 * Injects the registered engine's editable-item selector.
 *
 * @param {string} selector
 *   A CSS selector matching the engine's editable leaf elements.
 */
export const setEditableItemSelector = (selector) => {
  editableItemSelector = selector;
};

/**
 * Finds the outermost presentation root containing an element.
 *
 * Presentation roots (data-presentation="unit|merged", stamped at
 * attribution beside the selectable key — OPEN stamps nothing) mark
 * subtrees that present as ONE element: a unit block type, a media
 * reference, a merged single-content block. The panel's projection agrees
 * from the blueprint's `_element.presentation` annotations — the same policy
 * decided both. A UNIT root keeps its children in the projection (folded
 * rows), so deep select and drill still reach them; what a MERGED root
 * absorbs leaves the projection, and with it the selection universe.
 * Outermost because nested units present as their largest enclosing unit —
 * a media field inside a unit block is part of the block.
 *
 * @param {Element} element
 *   The element whose presentation boundary is wanted.
 *
 * @return {?Element}
 */
const outermostPresentationRoot = (element) => {
  let root = null;
  let current = element.closest('[data-presentation]');
  while (current) {
    root = current;
    current = current.parentElement?.closest('[data-presentation]');
  }
  return root;
};

/**
 * The item that edits when an editable item is inside a presentation root:
 * the outermost editable item the root contains. Outside a presentation
 * root an item edits as itself. The edit session filters Tab traversal
 * with this so a presentation root's subtree is ONE stop — Tab never
 * enters what a click cannot reach.
 *
 * @param {Element} item
 *   An editable item (matches the engine's item selector).
 *
 * @return {Element}
 */
export const presentationBoundaryItem = (item) => {
  const root = outermostPresentationRoot(item);
  if (!root) {
    return item;
  }
  let outer = item.parentElement?.closest(editableItemSelector);
  while (outer && root.contains(outer)) {
    item = outer;
    outer = item.parentElement?.closest(editableItemSelector);
  }
  return item;
};

/**
 * Selection identity for the root's label leaf: a leaf whose key IS the
 * root's edit target (the delivered `_element` annotation's derived rule
 * that the entity's label field presents AS the entity row) commits the
 * ROOT row as the selection. The projection absorbed the label field's
 * row into the root row, so the field key has no row of its own;
 * selecting it would move the selection somewhere the panel cannot name.
 * This is a one-way mapping, leaf to row, never an edit route: the root
 * row has NO leaf of its own (its editor is the entity-form panel, which
 * the Edit verb opens without arming the session), and a theme that
 * prints the H1 as the label's leaf keeps it click-editable on its own.
 * This is the root-rule twin of the merged-root promotion via
 * data-presentation; the root's rule lives in the blueprint annotation,
 * so this reads the mirror where that one reads the DOM. Both run BEFORE
 * the projection gate in keyForEditableItem, which is why the absorbed
 * label leaf stays editable: the key it commits is the root row's.
 */
const rootKeyForEditTarget = (key) => {
  const { root, blueprint } = store.getState().blueprint;
  const target = blueprint?._element?.presentation?.edit_target;
  return target && root && key === `field:${root.uuid}:${target}`
    ? `entity:${root.uuid}`
    : key;
};

/**
 * The selection key an editable item commits: normally its selectable
 * wrapper's key; inside a MERGED presentation root, the root's own key
 * (the merged node absorbs its wrapper chain, so the outermost carrier is
 * the one selection identity); the root entity's edit-target leaf
 * commits the root entity key (rootKeyForEditTarget). Expects an item
 * already at its presentation boundary.
 *
 * THE GATE: the key is null unless the key it would COMMIT is in the
 * projection index. This is the one seam every editing door passes
 * through (click and hover via resolveEditable, editItem, and the edit
 * session's Tab traversal), so it is where the editing universe is
 * enforced. The test is on the committed key, never the raw wrapper key:
 * inside a merged root the inner wrappers are absent from the projection
 * BY DESIGN (the root absorbed them), and so is the root's label field;
 * testing raw keys would drop every merged block and the page title.
 * Testing the promoted key keeps those editable while a foreign host (a
 * theme-region layout block's inner components, attributed but outside
 * this root's blueprint) drops out. Fails CLOSED before the mirror boots:
 * the mirror boots synchronously from drupalSettings on the first
 * behavior attach, before any handler is armed, so a null index only ever
 * means "no editing universe on this page" and nothing is editable.
 *
 * @param {Element} item
 *   An editable item (matches the engine's item selector).
 *
 * @return {?string}
 *   The selection key, or null when the item has no selectable wrapper or
 *   its committed key is outside the root context's projection.
 */
export const keyForEditableItem = (item) => {
  const wrapper = item.closest('[data-selectable]');
  if (!wrapper || wrapper.hasAttribute('data-main-entity')) {
    return null;
  }
  let key = wrapper.getAttribute('data-selectable');
  const root = outermostPresentationRoot(item);
  if (root && root.getAttribute('data-presentation') === 'merged') {
    key = root.getAttribute('data-selectable') || key;
  }
  key = rootKeyForEditTarget(key);
  return projectionIndex()?.has(key) ? key : null;
};

/**
 * Resolves a pointer target to the editable leaf it lands on.
 *
 * The exact delta item at or above the target wins — clicking the second
 * paragraph of a multi-value field edits that item, where the old per-field
 * indicators always opened the first — and it is the DEEPEST item: a nested
 * entity's attributed fields (a paragraph's text) edit directly. The one
 * exception is a presentation root (data-presentation): its subtree edits
 * as one element, so the item climbs to the outermost editable item inside
 * the root — clicking a media reference's inner image edits the reference
 * field — and a MERGED root promotes the selection key to the root itself.
 * A click on wrapper padding (between items) falls back to the innermost
 * selectable wrapper that owns an editable item, and yields that item
 * under the same presentation rule.
 *
 * @param {EventTarget} target
 *   The pointer event target.
 *
 * @return {?{item: Element, key: string}}
 *   The editable item and its wrapper's selectable key, or null when
 *   nothing editable is under the target.
 */
export const resolveEditable = (target) => {
  const element = target instanceof Element ? target : null;
  if (!element || !editableItemSelector) {
    return null;
  }
  const ownedItem = (wrapper) =>
    Array.from(wrapper.querySelectorAll(editableItemSelector)).find(
      (candidate) => candidate.closest('[data-selectable]') === wrapper,
    ) ?? null;

  const withKey = (item) => {
    item = presentationBoundaryItem(item);
    const key = keyForEditableItem(item);
    return key ? { item, key } : null;
  };

  const item = element.closest(editableItemSelector);
  if (item) {
    return withKey(item);
  }
  for (const wrapper of selectableChain(element)) {
    const owned = ownedItem(wrapper);
    if (owned) {
      return withKey(owned);
    }
  }
  return null;
};

/**
 * Collects the selectable wrappers above (and including) a target.
 *
 * @param {EventTarget} target
 *   The pointer event target.
 *
 * @return {Element[]}
 *   Selectable elements, innermost first, excluding the canvas.
 */
export const selectableChain = (target) => {
  const chain = [];
  let element = target instanceof Element ? target : null;
  // Inline canvas chrome (a [data-canvas-chrome] subtree, e.g. daedalus_layout's
  // section preset picker) mounts INSIDE attributed markup but is not page
  // content: nothing above it may resolve, or interacting with the chrome
  // would hover/select — and the capture-phase click grammar would
  // swallow — the element it happens to be mounted in.
  if (element?.closest('[data-canvas-chrome]')) {
    return [];
  }
  element = element?.closest('[data-selectable]');
  while (element) {
    if (element.hasAttribute('data-main-entity')) {
      break;
    }
    chain.push(element);
    element = element.parentElement?.closest('[data-selectable]');
  }
  return chain;
};

/**
 * Collects the selectable keys above (and including) a target.
 *
 * The RAW attribution chain, containment tests only. Anything that picks
 * a selection from it must go through representedChainKeys (the projection
 * filter) instead.
 *
 * @return {string[]}
 *   Selectable keys, innermost first.
 */
export const chainKeys = (target) => {
  return selectableChain(target).map((element) =>
    element.getAttribute('data-selectable'),
  );
};

/**
 * Collects the presented selectable keys above (and including) a target:
 * the raw chain filtered to the projection universe. Every pointer
 * policy picks its stop from THIS list, so a key the panel cannot show
 * never becomes the selection. Unfiltered before the mirror boots.
 *
 * @return {string[]}
 *   Presented selectable keys, innermost first.
 */
export const representedChainKeys = (target) => {
  const index = projectionIndex();
  const keys = chainKeys(target);
  return index ? keys.filter((key) => index.has(key)) : keys;
};

/**
 * Resolves a pointer target to the selectable key a policy stops at.
 *
 * @param {EventTarget} target
 *   The pointer event target.
 * @param {Object} options
 *   - policy: one of POLICY (default BOUNDARY).
 *   - drillKey: the current drill context key, or null for the page root.
 *     Only the BOUNDARY policy uses it.
 *   - selectedKeys: the committed selection set. Only the BOUNDARY policy
 *     uses it: pointing at a selected element resolves to it (innermost
 *     selected member of the chain) when that deepens the drill/boundary
 *     stop — never when it would coarsen it.
 *
 * @return {?string}
 *   The resolved selectable key, or null when nothing selectable is under
 *   the target (editing chrome, the bare canvas, unattributed markup).
 */
export const resolve = (
  target,
  { policy = POLICY.BOUNDARY, drillKey = null, selectedKeys = [] } = {},
) => {
  if (policy === POLICY.EDITABLE) {
    return resolveEditable(target)?.key ?? null;
  }

  const keys = representedChainKeys(target);
  if (keys.length === 0) {
    return null;
  }

  if (policy === POLICY.LEAF) {
    return keys[0];
  }

  // BOUNDARY. The context (drill or page root) picks the default stop.
  let stop = keys.length - 1;
  if (drillKey) {
    const index = keys.indexOf(drillKey);
    if (index > 0) {
      // One level inside the drill context.
      stop = index - 1;
    } else if (index === 0) {
      // The drilled element itself, with nothing deeper under the cursor.
      stop = 0;
    }
    // index === -1 — outside the drill context: fall through to the
    // page-root boundary. The event layer resets the drill when a click
    // commits out here.
  }
  // The selection deepens the stop, never coarsens it: pointing at a
  // selected element means IT, not the group that would swallow it — a
  // selection is a deliberate commitment to a depth, wherever it was made
  // (panel row, deep select), and hover must not resolve PAST it back to
  // the boundary. A selected ANCESTOR of the stop changes nothing, so a
  // drilled context is never swallowed by a stale outer selection.
  const selectedIndex = keys.findIndex((key) => selectedKeys.includes(key));
  if (selectedIndex !== -1 && selectedIndex <= stop) {
    return keys[selectedIndex];
  }
  return keys[stop];
};

/**
 * Finds the DOM element currently carrying a selectable key.
 *
 * Keys are globally unique on the page, and elements are re-created by
 * markup swaps, so lookup is always by key, on demand.
 *
 * @param {?string} key
 *   The selectable key.
 *
 * @return {?Element}
 */
export const elementForKey = (key) => {
  // Keys contain no quotes or backslashes, so they are safe in a quoted
  // attribute selector.
  return key ? document.querySelector(`[data-selectable="${key}"]`) : null;
};

/**
 * Finds a selectable key's presented parent.
 *
 * The projection index's parent pointer, the same ancestry the panel and
 * the breadcrumb render. A key outside the projection (a programmatic
 * selection) falls back to its nearest presented DOM ancestor.
 *
 * @param {?string} key
 *   The selectable key whose parent is wanted.
 *
 * @return {?string}
 *   The parent key, or null at the top of the tree (the canvas).
 */
export const parentKey = (key) => {
  const index = projectionIndex();
  if (index?.has(key)) {
    return index.get(key).parent;
  }
  let element = elementForKey(key)?.parentElement?.closest('[data-selectable]');
  while (element && !element.hasAttribute('data-main-entity')) {
    const candidate = element.getAttribute('data-selectable');
    if (!index || index.has(candidate)) {
      return candidate;
    }
    element = element.parentElement?.closest('[data-selectable]');
  }
  return null;
};
