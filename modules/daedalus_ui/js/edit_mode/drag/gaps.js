/**
 * Gap geometry: a cursor position → the placement it means.
 *
 * The caret model replaces dropzones: instead of rendering a drop target
 * per possible destination, the pointer position resolves to the ONE
 * nearest insertion gap in the container under the cursor, and the result
 * is expressed directly in the server's placement grammar
 * {anchor, placement} — the same contract the AI path speaks.
 *
 * Container resolution rides the drag event's real target (the browser
 * already hit-tests through nested layouts for us), and the flow model is
 * the server's: each container holds ONE mixed ordered flow of direct
 * children, block placements and nested containers alike (regions have no
 * keys and no gap space), and the root layout field's rows are the root
 * flow. Anchor kind is irrelevant to addressing: the cursor resolves to
 * the nearest gap in the flow under it, spelled {anchor, placement} with
 * the preceding item as the canonical anchor. One legality rule runs
 * after resolution, never during it: the root flow holds only sections,
 * so a block dropped in a root-flow gap wraps in a new section there
 * (newSection, the [add_section, place-inside] composition); container
 * flows hold anything, so every interior gap is a plain placement for
 * both drag kinds.
 *
 * Orientation is inferred from sibling rect geometry (dominant axis of
 * consecutive deltas), so horizontal flows get a vertical caret without
 * per-layout configuration.
 *
 * The page-elements tree is a second drop surface, resolved in
 * tree-gaps.js: its drop unit is the gap LINE between two visible rows,
 * the depth at a line that can mean several drops chosen by the cursor's
 * x, so no gap geometry is read from the panel at all. resolveDropTarget
 * dispatches a cursor over a tree row (a keyed row, a region row, or a
 * shell row claiming slots) there. Tree targets carry none of the canvas
 * gap's rect inputs, so liveCaret answers null for them and their panel
 * face is drawn from the location alone (treeCaretForLocation), the same
 * face every canvas drop also gets.
 */

import {
  projectionIndex,
  projectionRoot,
} from '../components/page-elements/projection-index';
import { markupForKey, containerEmpty } from '../blueprint/markup-index';
import { warnMissingEntityUi } from '../utilities/warn';
import { resolveRowTarget } from './tree-gaps';

/**
 * Parses a selectable key into segments, or null.
 */
const segments = (element) =>
  element?.getAttribute('data-selectable')?.split(':') ?? null;

/**
 * True when an element's selectable key is a block PLACEMENT
 * (block:{uuid}), as opposed to a field of an inline block
 * (block:{uuid}:{name}).
 */
const isPlacement = (element) => {
  const parts = segments(element);
  return parts?.[0] === 'block' && parts.length === 2;
};

/**
 * The nearest container at or above an element that is the page's own, or
 * null at the root flow.
 *
 * The main entity is the canvas's edge. The page renders INSIDE foreign
 * documents' containers that wear selectable keys like its own: the site
 * frame's section around the route's output (and its main slot door), a
 * template's sections around a locked page. A container above the main
 * entity is that outer document's flow, never the flow a page section
 * sits in, so every container walk stops at the main entity: a container
 * found above it answers null, the root flow, exactly as if the frame were
 * not there. (A pointer outside the main entity never reaches these
 * resolvers: resolveDropTarget answers null for it.)
 */
const containerAt = (element) => {
  const container =
    element?.closest('[data-selectable^="container:"]') ?? null;
  return container && container.closest('[data-main-entity]')
    ? container
    : null;
};

/**
 * The nearest container ancestor of an element, or null at the root flow.
 *
 * Containers all the way down: a nested container is an ordinary child of
 * its parent container, which owns the nested flow.
 */
const enclosingContainer = (element) =>
  containerAt(element?.parentElement ?? null);

/**
 * The items of one flow, in document order: a container's direct children
 * of BOTH kinds, block placements and nested containers in one mixed
 * ordered flow (the server's region model), or the root layout field's
 * rows (container null; structurally sections only).
 *
 * @param {?Element} container
 *   The container owning the flow, or null for the root flow.
 * @param {Element} scope
 *   The element to search under (the container, or the main entity for
 *   the root flow).
 */
const flowItems = (container, scope = container) =>
  Array.from(scope.querySelectorAll('[data-selectable]')).filter(
    (candidate) =>
      (segments(candidate)?.[0] === 'container' || isPlacement(candidate)) &&
      enclosingContainer(candidate) === container,
  );

/**
 * The surface an empty container's drop lands in visually: the part the
 * container's markup annotation says claims the regions slot (a
 * root-depth container's children mount inside its layout part, not the
 * full-bleed root), otherwise the container root itself (a nested
 * container's part hoists onto the root, no slot claim survives, and a
 * container the model does not speak for degrades to its root). The
 * annotation names the part; the DOM query only locates its element.
 */
const dropSurface = (container) => {
  const claimant = markupForKey(container.getAttribute('data-selectable'))
    ?.slots?.regions;
  return (
    (claimant ? container.querySelector(`:scope > ${claimant}`) : null) ??
    container
  );
};

/**
 * Whether a container's flow is empty: the markup annotation's answer
 * (containerEmpty — the resolver's one {empty: true} fact, the same
 * answer the composed render printed) when the model speaks for the key,
 * the DOM item scan when it does not. A flow that resolved no items is
 * empty for gap purposes either way: gaps are computed FROM items, so an
 * annotation/DOM desync (a mid-mutation moment) degrades to the
 * whole-container 'inside' target instead of a broken caret.
 */
const flowIsEmpty = (container, items) =>
  items.length === 0 ||
  (containerEmpty(container.getAttribute('data-selectable')) ?? false);

/**
 * The attribute a slot block's root wears on the editing canvas (stamped
 * by daedalus_ui's LayoutBuilderBlockAttributes with the slot id): the
 * door a document rendered inside the template fills.
 */
export const SLOT_DOOR_ATTRIBUTE = 'data-daedalus-slot';

/**
 * The nearest slot door around an element whose slot is EMPTY, or null.
 *
 * An empty door (no filling container rendered inside it, so the slot's
 * stand-in or its default shows) is one 'inside' target on the door's
 * own block key, the same grammar an empty container speaks: the server
 * lands the placement in the page's slot section, minted for it. A
 * filled door holds the page's slot section, a container like any other,
 * whose own flow the container rules below address; the door itself is
 * then no target. Whether the door MAY be dropped into is the drag
 * controller's question (insert of the block key, through the one
 * client gate: the spine stamps insert on a door exactly when this
 * document may fill it).
 */
const emptySlotDoor = (element) => {
  const door = element.closest(`[${SLOT_DOOR_ATTRIBUTE}]`);
  // The page's own doors only: the site frame's main slot door wraps the
  // whole page from outside the main entity and is never a target here (a
  // blank page is the root flow's one inside target, below).
  if (!door || !isPlacement(door) || !door.closest('[data-main-entity]')) {
    return null;
  }
  return door.querySelector('[data-selectable^="container:"]') ? null : door;
};

/**
 * The inside target of an empty slot door.
 */
const slotDoorTarget = (door) => ({
  location: { anchor: segments(door)[1], placement: 'inside' },
  container: door,
  empty: true,
  insert: { parent: door, before: null },
});

/**
 * The positional unit of a flow item: the item plus any pure wrapper
 * elements around it.
 *
 * The flow's actual children can sit a level above the attributed items —
 * every inline block renders inside daedalus_ui's entity-wrapper div.
 * Sibling insertion against the item's own element can never reposition
 * it there;
 * position changes must move and anchor on the unit. A pure wrapper holds
 * exactly this one item and carries no selectable identity of its own.
 */
export const unitOf = (item) => {
  let unit = item;
  while (
    unit.parentElement &&
    !unit.parentElement.hasAttribute('data-selectable') &&
    unit.parentElement.childElementCount === 1
  ) {
    unit = unit.parentElement;
  }
  return unit;
};

/**
 * The element that really contains a flow: the parent of the items'
 * positional UNITS. The items' own parentElement can be a per-item wrapper
 * spanning a single item (the entity-wrapper div), so using it as the
 * gap container would clamp the caret's end-of-flow line — and draw the
 * container highlight — around ONE item instead of the whole flow.
 */
const flowContainer = (items) => unitOf(items[0]).parentElement;

/**
 * Infers the flow orientation of a list of rects.
 *
 * @return {'vertical'|'horizontal'}
 */
const orientationOf = (rects) => {
  if (rects.length < 2) {
    return 'vertical';
  }
  let dx = 0;
  let dy = 0;
  for (let i = 1; i < rects.length; i++) {
    dx += Math.abs(rects[i].left - rects[i - 1].left);
    dy += Math.abs(rects[i].top - rects[i - 1].top);
  }
  return dx > dy ? 'horizontal' : 'vertical';
};

/**
 * Finds the insertion index for a cursor position along the flow axis.
 *
 * Standard list-drop rule: the cursor falls before the first item whose
 * midpoint it precedes. Works for wrapped flows too, because midpoints are
 * compared in document order along the dominant axis.
 */
const insertionIndex = (rects, orientation, x, y) => {
  for (let i = 0; i < rects.length; i++) {
    const rect = rects[i];
    if (orientation === 'vertical') {
      // For wrapped/grid flows an item on a later row is "after" anything
      // on an earlier row; the midpoint test on the dominant axis handles
      // that because rects are in document order.
      if (y < rect.top + rect.height / 2) {
        return i;
      }
    } else if (x < rect.left + rect.width / 2 && y < rect.bottom) {
      return i;
    }
  }
  return rects.length;
};

/**
 * The caret line rect for an insertion index, inset to the container's
 * content box.
 */
/**
 * The caret coordinate between two edges: their midpoint, pulled 2px inside
 * the container at the first and last gap so the caret stays visible.
 */
const edgeBiasedMidpoint = (index, count, low, high) => {
  if (index === 0) {
    return Math.min(low + 2, (low + high) / 2);
  }
  if (index === count) {
    return Math.max(high - 2, (low + high) / 2);
  }
  return (low + high) / 2;
};

const caretRect = (container, rects, orientation, index) => {
  const containerRect = container.getBoundingClientRect();
  const inset = 6;
  if (orientation === 'vertical') {
    // The line sits in the middle of the gap between the two neighbors —
    // or hugs the container content edge at either end.
    const above = index > 0 ? rects[index - 1].bottom : containerRect.top;
    const below =
      index < rects.length ? rects[index].top : containerRect.bottom;
    const y = edgeBiasedMidpoint(index, rects.length, above, below);
    return {
      left: containerRect.left + inset,
      top: y,
      width: Math.max(containerRect.width - inset * 2, 0),
      height: 0,
      orientation,
    };
  }
  const before = index > 0 ? rects[index - 1].right : containerRect.left;
  const after = index < rects.length ? rects[index].left : containerRect.right;
  const x = edgeBiasedMidpoint(index, rects.length, before, after);
  // The cross extent follows the neighboring item, not the container: in a
  // horizontal flow the container may be much taller than the row.
  const reference = rects[Math.min(index, rects.length - 1)];
  return {
    left: x,
    top: reference.top,
    width: 0,
    height: reference.height,
    orientation,
  };
};

/**
 * Builds a drop-target result for a specific gap index in a container.
 *
 * @return {Object}
 *   The gap target (see resolveDropTarget).
 */
const gapAt = (container, items, rects, orientation, index, describe) => {
  const anchorItem = index > 0 ? items[index - 1] : items[0];
  const placement = index > 0 ? 'after' : 'before';
  return {
    ...describe(anchorItem, placement),
    container,
    empty: false,
    insert: { parent: items[0].parentElement, before: items[index] ?? null },
    items,
    index,
    orientation,
  };
};

/**
 * Builds a drop-target result from a container and a cursor position.
 *
 * @return {?Object}
 *   The resolved target (see resolveDropTarget), or null.
 */
const gapTarget = (container, items, x, y, describe) => {
  const rects = items.map((item) => item.getBoundingClientRect());
  const orientation = orientationOf(rects);
  const index = insertionIndex(rects, orientation, x, y);
  return gapAt(container, items, rects, orientation, index, describe);
};

/**
 * Describes a flow gap in the server grammar: the anchor is the adjacent
 * item's uuid, whatever its kind (the grammar is kind-blind).
 */
const describeFlowGap = (anchorItem, placement) => ({
  location: { anchor: segments(anchorItem)[1], placement },
});

/**
 * The one legality rule, applied AFTER resolution: the root section list
 * holds only sections, so a block landing in a root-flow gap keeps its
 * meaning by wrapping in a new section there (the location then addresses
 * the SECTION gap; ops-builders compose the [add_section, place-inside]
 * batch). Container flows hold anything; every other gap passes through
 * untouched.
 */
export const legalize = (target, targets) =>
  targets === 'block' && target.rootFlow
    ? { ...target, newSection: true }
    : target;

/**
 * Edge-zone resolution: a cursor within an edge band of an enclosing
 * section resolves to that SECTION's own flow — the sibling gap in its
 * parent flow — instead of the container-first nesting the interior
 * keeps.
 *
 * With full-bleed containers virtually every pixel sits inside some
 * container, so without this the root flow's sibling gaps (between page
 * sections, bottom of the page) are geometrically unreachable by pointer;
 * the edge bands restore them while leaving nesting fully
 * pointer-reachable from container interiors.
 *
 * Enclosing sections are tested OUTERMOST first, so when nested sections'
 * edge bands coincide the outermost flow (the root flow at page-section
 * boundaries) claims the cursor. The band is measured along the parent
 * FLOW's axis (a horizontal flow tests left/right edges), sized
 * min(28px, 25% of the section's flow-axis extent) so short sections keep
 * an interior.
 *
 * @return {?Object}
 *   A flow gap target (see resolveDropTarget), or null when the cursor is
 *   in no enclosing section's edge band.
 */
const resolveEdgeZoneTarget = (element, main, x, y) => {
  const chain = [];
  for (
    let section = containerAt(element);
    section;
    section = enclosingContainer(section)
  ) {
    chain.unshift(section);
  }
  for (const section of chain) {
    const parent = enclosingContainer(section);
    const items = flowItems(parent, parent ?? main);
    // The band runs along the PARENT flow's axis: that flow is where the
    // resulting sibling gap lives (orientationOf defaults a lone section
    // to vertical).
    const orientation = orientationOf(
      items.map((item) => item.getBoundingClientRect()),
    );
    const rect = section.getBoundingClientRect();
    const [position, leading, trailing] =
      orientation === 'vertical'
        ? [y, rect.top, rect.bottom]
        : [x, rect.left, rect.right];
    const edge = Math.min(28, (trailing - leading) * 0.25);
    if (position - leading <= edge || trailing - position <= edge) {
      const target = gapTarget(
        flowContainer(items),
        items,
        x,
        y,
        describeFlowGap,
      );
      if (!parent) {
        target.rootFlow = true;
      }
      return target;
    }
  }
  return null;
};

/**
 * Recomputes a resolved target's caret line from live geometry.
 *
 * The caret is drawn every animation frame (scroll, autoscroll, and markup
 * changes move everything), so the drop target stores its inputs and the
 * line is derived on demand — the outline's read-live-each-frame pattern.
 *
 * @param {Object} drop
 *   A result of resolveDropTarget().
 *
 * @return {?{left, top, width, height, orientation}}
 *   The caret line rect, or null for empty targets or stale elements.
 */
export const liveCaret = (drop) => {
  // Empty targets have no line; element targets (kind: 'element') carry
  // no items — their chrome is the caret's element face.
  if (!drop || drop.empty || !drop.items) {
    return null;
  }
  if (
    !drop.container.isConnected ||
    drop.items.some((item) => !item.isConnected)
  ) {
    return null;
  }
  const rects = drop.items.map((item) => item.getBoundingClientRect());
  return caretRect(drop.container, rects, drop.orientation, drop.index);
};

/**
 * The selectable key of the root entity, the page under edit, or null
 * before the canvas mounts.
 */
export const rootEntityKey = () =>
  document
    .querySelector('[data-main-entity]')
    ?.getAttribute('data-selectable') ?? null;

/**
 * The selector of the rendered shell row that claims a model row's
 * sections (data-tree-owner / data-tree-claims, stamped by the tree on a
 * claiming pseudo row): the root flow's drop surface when the owner is
 * the root entity.
 */
const sectionsRowSelector = (ownerKey) =>
  `[data-tree-owner="${CSS.escape(ownerKey)}"][data-tree-claims~="section"]`;

/**
 * The projection keys a placement location may address in the panel, in
 * preference order — ONE derivation feeding both the panel caret's row
 * lookup (treeRowForLocation, which first prefers the keyless
 * sections-claiming shell row for the root inside location) and the
 * drop-reveal key the drag controller publishes to the store.
 *
 * @return {string[]}
 */
export const treeRowKeyCandidates = (location) => {
  if (!location) {
    return [];
  }
  const { anchor, placement } = location;
  return placement === 'inside'
    ? // Inside a container, the root entity, or a slot door (a block whose
      // interior is the page's slot section).
      [`container:${anchor}`, `entity:${anchor}`, `block:${anchor}`]
    : [`block:${anchor}`, `container:${anchor}`];
};

/**
 * The projection key of the container a location inserts INTO: the depth
 * indicator's subject, one derivation for both of its faces (the caret's
 * container chip and the breadcrumb's drop trail). An 'inside' location
 * already addresses the container; before/after address a sibling, whose
 * projection parent is the container's nearest presented key.
 *
 * @return {?string}
 */
export const containerKeyForLocation = (location) => {
  const index = projectionIndex();
  if (!location || !index) {
    return null;
  }
  const rowKey = treeRowKeyCandidates(location).find((candidate) =>
    index.has(candidate),
  );
  if (!rowKey) {
    return null;
  }
  return location.placement === 'inside'
    ? rowKey
    : (index.get(rowKey)?.parent ?? null);
};

/**
 * Whether a placement location lands in the root flow (a page band's
 * position): the container it inserts into is the projection root, the
 * page entity itself, rather than a container on the page.
 *
 * The ONE depth read a drag source's acceptsTarget answers with, at every
 * placement door: the pointer drag and the keyboard lift hand it the gap
 * they resolved, click-to-place the target it builds from its anchor. It
 * reads the location alone (through the projection index, the mirror's
 * structure) so a target that carries no DOM-derived rootFlow flag (a
 * bare {location}) answers exactly as a resolved gap does. Before the
 * mirror boots nothing is known, and unknown is not the root flow.
 *
 * @return {boolean}
 */
export const isRootFlowLocation = (location) => {
  const key = containerKeyForLocation(location);
  return key !== null && key === projectionRoot();
};

/**
 * The page-elements row a placement location addresses: the anchor's row
 * for before/after, the container's row for inside. Null when the panel
 * (or the row — folded ancestors don't render) is not in the DOM.
 *
 * The inverse of the tree resolver's row → location mapping (tree-gaps.js),
 * and the seam that lets every drop location render a panel face: the
 * location is the
 * one carrier, the row is derived. The root inside location has two faces
 * in the markup view: the shell row claiming the root's sections holds
 * the section rows, so when it is rendered the caret sits under it (the
 * row the sections append to), and under the root row otherwise.
 *
 * @return {?{row: Element, placement: string}}
 */
export const treeRowForLocation = (location) => {
  const tree = location && document.querySelector('.daedalus-ui-tree');
  if (!tree) {
    return null;
  }
  if (location.placement === 'inside') {
    const rootKey = rootEntityKey();
    if (rootKey === `entity:${location.anchor}`) {
      const row = tree.querySelector(sectionsRowSelector(rootKey));
      if (row) {
        return { row, placement: 'inside' };
      }
    }
  }
  for (const key of treeRowKeyCandidates(location)) {
    const row = tree.querySelector(`[data-tree-key="${CSS.escape(key)}"]`);
    if (row) {
      return { row, placement: location.placement };
    }
  }
  return null;
};

/**
 * A page-elements row's content edge in viewport coordinates: where its
 * indent ends. The panel grammar pads the row itself one
 * --daedalus-outline-step per level (panel-controls.css), so the edge is
 * the row's left plus its computed padding. ONE reading for the tree
 * resolver's depth pick (tree-gaps.js pickByX) and the panel caret's line
 * start below, so the line the caret draws starts exactly where the x
 * pick reads that depth.
 *
 * @param {Element} row
 *   A tree row (the treeitem).
 *
 * @return {number}
 */
export const treeRowContentEdge = (row) =>
  row.getBoundingClientRect().left +
  parseFloat(getComputedStyle(row).paddingLeft);

/**
 * One outline step in pixels, read off a row: --daedalus-outline-step
 * (variables.css) is declared in rem, so a rem value scales by the root
 * font size; a px override passes through.
 */
const outlineStepPx = (row) => {
  const value = getComputedStyle(row)
    .getPropertyValue('--daedalus-outline-step')
    .trim();
  const number = parseFloat(value);
  return value.endsWith('rem')
    ? number * parseFloat(getComputedStyle(document.documentElement).fontSize)
    : number;
};

/**
 * The caret line rect for a location's panel face, or null (no row, or
 * the panel is hidden). Before/after bracket the row's SUBTREE (the li
 * wraps the children), because the drop lands outside the whole element,
 * not between it and its own children; inside sits under the container's
 * row. The line starts at the anchor row's content edge, one outline step
 * deeper for inside (the indent the appended child's own row would have),
 * so the line's indent says which depth the drop lands at: the depth the
 * tree resolver picked by x, or the depth a canvas gap's location means.
 *
 * @return {?{left, top, width, height, orientation}}
 */
export const treeCaretForLocation = (location) => {
  const resolved = treeRowForLocation(location);
  if (!resolved || !resolved.row.offsetParent) {
    return null;
  }
  const { row, placement } = resolved;
  const rowRect = row.getBoundingClientRect();
  const left =
    treeRowContentEdge(row) + (placement === 'inside' ? outlineStepPx(row) : 0);
  const itemRect =
    placement === 'inside'
      ? rowRect
      : (row.closest('.daedalus-ui-tree__item') ?? row).getBoundingClientRect();
  return {
    left,
    top: placement === 'before' ? itemRect.top : itemRect.bottom,
    width: Math.max(rowRect.right - left, 0),
    height: 0,
    orientation: 'vertical',
  };
};

/**
 * Resolves a drag position to a drop target.
 *
 * @param {Element} target
 *   The drag event's target (what the cursor is over).
 * @param {number} x
 * @param {number} y
 *   Viewport cursor coordinates.
 * @param {string} targets
 *   'block' or 'section' — what the drag source places.
 *
 * @return {?Object}
 *   Null when nothing under the cursor accepts the drag, otherwise:
 *   - kind: 'gap' — the drag layer's target-kind discriminant (element
 *     targets, kind 'element', resolve in element-targets.js).
 *   - location: {anchor, placement} — the server grammar (a tree target
 *     over a region row adds the region).
 *   - newSection: true when a block drop must be wrapped in a new section
 *     (the location then addresses the SECTION to create; the block goes
 *     inside it).
 *   - container: Element — highlighted while this target is active (the
 *     canvas container, or the panel row of the container inserted into).
 *   - empty: true when the target is an empty container (render the
 *     centered drop affordance instead of a caret line).
 *   - gapId: stable identity for hysteresis.
 *   Canvas targets also carry:
 *   - insert: {parent, before} — where a placeholder element belongs.
 *   - items / index / orientation: the gap's inputs, for liveCaret().
 *   Tree targets (tree-gaps.js) carry canvasElement instead, the anchor's
 *   canvas element for the move sources' descendant guard: no insertion
 *   point and no geometric line.
 */
export const resolveDropTarget = (target, x, y, targets) => {
  const element = target instanceof Element ? target : null;
  if (!element) {
    return null;
  }
  // A page-elements row: a keyed row, a region row (data-tree-region with
  // its owner) or a shell row claiming slots (data-tree-claims with its
  // owner). The tree resolver decides which of them are drop surfaces.
  const treeRow = element.closest(
    '[data-tree-key], [data-tree-region], [data-tree-claims]',
  );
  if (treeRow) {
    return resolveRowTarget(treeRow, x, y, targets);
  }
  if (element.closest('#daedalus-root')) {
    return null;
  }
  const main = element.closest('[data-main-entity]');
  if (!main) {
    return null;
  }

  const result = resolveCanvasTarget(element, main, x, y, targets);
  if (result) {
    result.kind = 'gap';
    const { anchor, placement } = result.location;
    result.gapId = `${result.newSection ? 'new-section|' : ''}${anchor}|${placement}`;
  }
  return result;
};

/**
 * Canvas resolution over the one-flow model, one resolver for both drag
 * kinds: edge bands first (a cursor near an enclosing container's edge
 * means the sibling gap in that container's parent flow, not a position
 * in whatever container happens to own the pixel), then the mixed flow of
 * the container under the cursor (an empty container is one 'inside'
 * target), then the root flow (a blank page is one 'inside' target on the
 * root entity). Kind shows only through legalize()'s root-flow rule.
 */
const resolveCanvasTarget = (element, main, x, y, targets) => {
  const edgeTarget = resolveEdgeZoneTarget(element, main, x, y);
  if (edgeTarget) {
    return legalize(edgeTarget, targets);
  }
  // An empty slot door (a slot block nothing fills yet): one 'inside'
  // target on the door, before the container around it claims the pixel.
  const door = emptySlotDoor(element);
  if (door) {
    return slotDoorTarget(door);
  }
  const container = containerAt(element);
  if (container) {
    const items = flowItems(container);
    if (flowIsEmpty(container, items)) {
      // An empty container: the drop lands INSIDE it ({anchor: container,
      // inside}, the same grammar the AI speaks).
      return {
        location: { anchor: segments(container)[1], placement: 'inside' },
        container,
        empty: true,
        insert: { parent: dropSurface(container), before: null },
      };
    }
    return gapTarget(flowContainer(items), items, x, y, describeFlowGap);
  }

  // Outside every container: the root flow (the entity's layout field
  // rows).
  const sections = flowItems(null, main);
  if (sections.length === 0) {
    // A blank page.
    const anchor = segments(main)?.[1];
    if (!anchor) {
      return null;
    }
    return legalize(
      {
        location: { anchor, placement: 'inside' },
        container: main,
        empty: true,
        insert: { parent: main, before: null },
        rootFlow: true,
      },
      targets,
    );
  }
  // Gaps span the flow container, the sections' units' shared parent,
  // keeping the caret aligned with the actual section boxes rather than
  // page chrome.
  return legalize(
    {
      ...gapTarget(flowContainer(sections), sections, x, y, describeFlowGap),
      rootFlow: true,
    },
    targets,
  );
};

/**
 * Stamps the drag-layer gapId onto a gap target (hysteresis + keyboard
 * stepping identity — same format resolveDropTarget produces).
 */
const withGapId = (target) => {
  target.kind = 'gap';
  const { anchor, placement } = target.location;
  target.gapId = `${target.newSection ? 'new-section|' : ''}${anchor}|${placement}`;
  return target;
};

/**
 * Enumerates every gap a drag of this target kind could drop into: the
 * keyboard face of the caret. Space lifts, arrows step the caret
 * through this list, so the keyboard walks exactly the placements the
 * pointer's per-position resolution offers.
 *
 * The list mirrors resolveCanvasTarget over the one-flow model: every
 * container contributes its mixed flow's gaps (an empty container is one
 * 'inside' gap), and the root flow contributes its section gaps, wrapped
 * as new sections for block targets (the same root-only legality the
 * pointer applies). Both drag kinds walk the SAME positions; kind shows
 * only through that legality. Gaps are ordered by their caret line's
 * viewport position (top, then left), visual reading order, which
 * interleaves nested containers naturally.
 *
 * @param {string} targets
 *   'block' or 'section' — what the lifted source places.
 *
 * @return {Object[]}
 *   Gap targets in visual order (same shape as resolveDropTarget results).
 */
export const enumerateGapTargets = (targets) => {
  const main = document.querySelector('[data-main-entity]');
  if (!main) {
    warnMissingEntityUi('enumerateGapTargets', '[data-main-entity]');
    return [];
  }
  const results = [];
  const visible = (element) => {
    const rect = element.getBoundingClientRect();
    return rect.width > 0 || rect.height > 0;
  };

  const gapsFor = (container, items, rootFlow = false) => {
    const rects = items.map((item) => item.getBoundingClientRect());
    const orientation = orientationOf(rects);
    for (let index = 0; index <= items.length; index++) {
      const gap = gapAt(
        container,
        items,
        rects,
        orientation,
        index,
        describeFlowGap,
      );
      if (rootFlow) {
        gap.rootFlow = true;
      }
      results.push(withGapId(legalize(gap, targets)));
    }
  };

  // The root flow: the entity's layout field rows (a blank page is one
  // 'inside' gap on the root entity). Block targets wrap in a new section
  // at each root gap, exactly where the pointer resolves the wrap.
  const rootItems = flowItems(null, main);
  if (rootItems.length === 0) {
    const anchor = segments(main)?.[1];
    if (anchor && visible(main)) {
      results.push(
        withGapId(
          legalize(
            {
              location: { anchor, placement: 'inside' },
              container: main,
              empty: true,
              insert: { parent: main, before: null },
              rootFlow: true,
            },
            targets,
          ),
        ),
      );
    }
  } else {
    gapsFor(flowContainer(rootItems), rootItems, true);
  }

  // Every empty slot door is one 'inside' gap on the door (the page's
  // slot section is minted for it server-side), walked like an empty
  // container.
  Array.from(main.querySelectorAll(`[${SLOT_DOOR_ATTRIBUTE}]`))
    .filter((door) => visible(door) && emptySlotDoor(door) === door)
    .forEach((door) => {
      results.push(withGapId(slotDoorTarget(door)));
    });

  // One mixed flow per container; an empty container is one 'inside' gap
  // ({anchor: container, inside}, the AI's grammar). Whether a gap MAY be
  // dropped into is not decided here: the drag controller asks insert of
  // every target's container (dropAllowed), for the pointer and the
  // keyboard lift alike.
  Array.from(main.querySelectorAll('[data-selectable^="container:"]'))
    .filter(visible)
    .forEach((container) => {
      const items = flowItems(container);
      if (flowIsEmpty(container, items)) {
        results.push(
          withGapId({
            location: { anchor: segments(container)[1], placement: 'inside' },
            container,
            empty: true,
            insert: { parent: dropSurface(container), before: null },
          }),
        );
        return;
      }
      gapsFor(flowContainer(items), items);
    });

  const point = (target) => {
    if (target.empty) {
      const rect = target.container.getBoundingClientRect();
      return {
        top: rect.top + rect.height / 2,
        left: rect.left + rect.width / 2,
      };
    }
    const line = liveCaret(target);
    return line ? { top: line.top, left: line.left } : { top: 0, left: 0 };
  };
  return results
    .map((target) => ({ target, point: point(target) }))
    .sort((a, b) => a.point.top - b.point.top || a.point.left - b.point.left)
    .map(({ target }) => target);
};
