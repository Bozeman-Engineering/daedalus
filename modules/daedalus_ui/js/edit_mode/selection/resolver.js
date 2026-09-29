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
 * for BOUNDARY and LEAF (their fallback is the raw chain); the editing gate
 * fails closed instead (see keyForEditableItem).
 *
 * THE EDITING UNIVERSE IS THE PROJECTION PLUS THE MIRROR'S SUBJECTS.
 * Attribution is host-blind by design: a layout block hosted in a theme
 * region renders its inner components fully attributed (selectable keys,
 * editable items) on every page, because the same render is cached and
 * shared across hosts and the styling identity must land for visitors too.
 * So a DOM attribute is an ADDRESS and a capability descriptor, never
 * authorization. What is editable HERE is decided against the root
 * context's mirror: a leaf whose committed key the projection presents
 * (the same Map the selection grammar resolves within), or whose key names a
 * known field of a known subject on the mirror (blueprint/subject-field.js)
 * even when the tree folds that field's row. Rendered evidence beats
 * display inference: the projection says which fields earn a row, the
 * leaf on the page says the field rendered. Anything outside both is
 * inert to the editor door: no editor, no Tab stop. Its editing surface
 * is its own root (the block_content edit route), where it IS the main
 * entity.
 *
 * There is ONE resolver, parameterized by a stop-depth policy. Contexts
 * differ only in the policy they pass, never in mechanism:
 *
 * - BOUNDARY (the default): stop at the outermost presented
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
 *
 * Editing is not a policy: the editor door (edit_session/edit-session.js)
 * opens the leaf a KEY owns (or, for a pointer gesture, the leaf under
 * the pointer that commits that key), resolved by the one grammar above, and inside
 * a presentation root (data-presentation="unit|merged", a subtree that
 * presents as one element) the root's boundary item is the leaf (a media
 * reference's inner image field is "the image"; editing it means changing
 * the reference, not the shared media entity's file), a MERGED root
 * promoting the committed key to the root's own (the block placement, so
 * move/trash/duplicate/drag all arm) while the edit still routes to the
 * inner leaf item (presentationBoundaryItem, keyForEditableItem).
 *
 * The main entity wrapper (data-main-entity) is the canvas: it never
 * resolves. Selection starts at its direct selectable children.
 */

import store from '../store/store';
import { projectionIndex } from '../components/page-elements/projection-index';
import { isSubjectField } from '../blueprint/subject-field';

export const POLICY = {
  BOUNDARY: 'boundary',
  LEAF: 'leaf',
};

/**
 * The editable leaves are the editing engine's vocabulary, not ours: the
 * editor door injects a provider of the engine's CANVAS leaves (its
 * items() member's canvas list, document order) when an engine registers,
 * so nothing here names another module's attributes. Edit identity rides
 * the engine's per-delta field items, not the selectable wrappers around
 * them — a field WRAPPER is selectable, its ITEMS are editable — so the
 * editing gate works item-first, by membership in that list. Until an
 * engine registers, nothing is editable.
 *
 * @type {?Function}
 */
let editableItems = null;

/**
 * Injects the registered engine's canvas-leaf provider.
 *
 * @param {Function} provider
 *   () => Element[], the engine's editable canvas leaves in document order.
 */
export const setEditableItems = (provider) => {
  editableItems = provider;
};

/**
 * The engine's canvas leaves, or null before an engine registers.
 *
 * @return {?Element[]}
 */
const canvasItems = () => (editableItems ? editableItems() : null);

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
 * root an item edits as itself. The editor door filters Tab traversal
 * with this so a presentation root's subtree is ONE stop — Tab never
 * enters what the door cannot reach.
 *
 * @param {Element} item
 *   An editable item (one of the engine's canvas leaves).
 * @param {?Set<Element>} items
 *   The engine's canvas leaves as a set, for callers resolving many items
 *   in one pass; built from the provider when omitted.
 *
 * @return {Element}
 */
export const presentationBoundaryItem = (item, items = null) => {
  const root = outermostPresentationRoot(item);
  if (!root) {
    return item;
  }
  const set = items ?? new Set(canvasItems() ?? []);
  let boundary = item;
  for (
    let node = item.parentElement;
    node && root.contains(node);
    node = node.parentElement
  ) {
    if (set.has(node)) {
      boundary = node;
    }
  }
  return boundary;
};

/**
 * Selection identity for the root's label leaf: a leaf whose key IS the
 * root's edit target (the delivered `_element` annotation's derived rule
 * that the entity's label field presents AS the entity row) commits the
 * ROOT row as the selection. The projection absorbed the label field's
 * row into the root row, so the field key has no row of its own;
 * selecting it would move the selection somewhere the panel cannot name.
 * ONE mapping at both doors: the pointer chain (representedChainKeys, so
 * hovering the theme's H1 names the root row, a click selects it and a
 * double-click on it edits the title in place) and the editing gate
 * (keyForEditableItem, so the Tab walk lands on the H1 as the root row's
 * leaf). It is a leaf-to-row mapping, never a row-to-leaf one: the root
 * ROW owns no leaf (its editor is the entity-form panel, which Enter and
 * the Edit verb present), and the H1 sits outside the entity wrapper
 * where no key search finds it, which is why a pointer gesture routes by
 * the item it hit (edit-session.js) and only a keyless gesture by the
 * key. This is the root-rule twin of the merged-root promotion via
 * data-presentation; the root's rule lives in the blueprint annotation,
 * so this reads the mirror where that one reads the DOM. Both run BEFORE
 * the projection gate, which is why the absorbed label leaf stays
 * selectable and editable: the key it commits is the root row's.
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
 * THE GATE: the key is null unless the key it would COMMIT passes one of
 * two doors, both read off the mirror: the projection index (the key
 * presents as a row) or the subject-field rule (the key names a known
 * field of a known subject, isSubjectField). This is the one seam every
 * editing door passes through (the editor door's editItem and its Tab
 * traversal), so it is where the editing universe is enforced. The test is on the committed key, never
 * the raw wrapper key: inside a merged root the inner wrappers are absent
 * from the projection BY DESIGN (the root absorbed them), and so is the
 * root's label field; testing raw keys would drop every merged block and
 * the page title. Testing the promoted key keeps those editable while a
 * foreign host (a theme-region layout block's inner components, attributed
 * but outside this root's blueprint) drops out of both doors. The second
 * door is rendered evidence beating display inference: a field the tree
 * folds (a non-preserved root field on a Layout Builder display that a
 * theme prints anyway, a display-hidden block field a template renders)
 * has no row, but a leaf carrying its key is proof it rendered, and the
 * mirror vouches the field is the subject's. A field block placement
 * takes the first door instead: it presents MERGED into the field it
 * renders (data-presentation on the placement), so the leaf promotes to
 * the placement key and edits in place under one row. When one field
 * renders twice (preserved on the display AND placed as a field block),
 * both leaves pass and each commits its own key; elementForKey answers
 * with the first element in document order, the accepted resolution.
 * Fails CLOSED before the mirror boots: the mirror boots synchronously
 * from drupalSettings on the first behavior attach, before any handler is
 * armed, so a null mirror only ever means "no editing universe on this
 * page" and nothing is editable.
 *
 * @param {Element} item
 *   An editable item (one of the engine's canvas leaves).
 *
 * @return {?string}
 *   The selection key, or null when the item has no selectable wrapper or
 *   its committed key is outside the root context's editing universe.
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
  if (projectionIndex()?.has(key)) {
    return key;
  }
  const mirror = store.getState().blueprint;
  return isSubjectField(mirror.blueprint, mirror.root, key) ? key : null;
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
 * the raw chain, each key promoted to the row that presents it
 * (rootKeyForEditTarget: the root's label leaf presents AS the root row),
 * then filtered to the projection universe. Every pointer policy picks
 * its stop from THIS list, so a key the panel cannot show never becomes
 * the selection, and the promotion runs BEFORE the filter for the same
 * reason keyForEditableItem promotes first: the absorbed label leaf has
 * no row of its own, but the row it presents under does. Unfiltered
 * before the mirror boots.
 *
 * @return {string[]}
 *   Presented selectable keys, innermost first.
 */
export const representedChainKeys = (target) => {
  const index = projectionIndex();
  const keys = chainKeys(target).map(rootKeyForEditTarget);
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
