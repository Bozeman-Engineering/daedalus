/**
 * The page elements panel's drop surface: the gap line between rows.
 *
 * The tree's drop unit is the LINE between two visible rows, not the row.
 * A row-as-one-item model put two distinct drops (after a section, before
 * that section's first child) on one pixel line and let the canvas dead
 * zone pick between them by the direction the cursor arrived from. A gap
 * line instead can mean several drops at once, one per depth the line
 * sits at: after the deepest row above it, then after each ancestor that
 * row closes, up to the root flow. The cursor's horizontal position picks
 * the depth, the tree-editor convention, and the caret indents to the
 * chosen depth so the line itself says where the drop lands.
 *
 * The model is pure over two readings. The visible rows (readTreeRows,
 * the one DOM adapter): DOM order is the visible order, aria-level the
 * depth, aria-expanded whether a container shows its children, and the
 * identity is the key (data-tree-key), the region stamp (data-tree-region
 * with its owner) or the claim (data-tree-claims with its owner). The
 * projection index: `parent` and `children` (element keys only, parts
 * transparent) for the first-child and parent facts. Both are flat, so
 * the model is node-testable on flat row fixtures.
 *
 * Bands by y: a block row splits at its midpoint (the line above it, the
 * line below it); the page row, container rows, region rows and the
 * markup view's shell claiming the root's sections (the root row's twin)
 * have an 8px band per line and a middle meaning `inside`, the server's
 * append. A container row's lower band is thus the START of the container
 * (before its first flow child, `inside` when it is empty), the gap the
 * row model never offered; a collapsed container shows no first child, so
 * its line below means after it (expand to reach its start; the middle
 * appends). A region row is container-like: its middle appends to the
 * region (`inside` carrying the region name) and its lower band is the
 * region's start. A door row (a slot block's, data-tree-door with the
 * slot it declares) is container-like too: the page's fill of the slot
 * is its child row (slot-fills.js), so its lower band is the fill's
 * START and its middle is `inside` the door, the location the canvas's
 * empty-door target speaks and the op door lands in the page's slot
 * section, minting it; an empty door's middle and lower band both mean
 * `inside`. Whether the door may be dropped into is the controller's
 * gate on the door's insert verdict, as for any container. Inert rows
 * (fields, parts, values, other shells) resolve to nothing. Flow rows
 * are entity:* (the root), container:*, block:{uuid} (two segments) and
 * region rows.
 *
 * Targets carry no geometric line (none of the canvas gap's rect inputs):
 * liveCaret answers null for them, so the controller's dead zone (sized
 * for canvas gaps, whose distinct drops have distinct geometry) measures
 * nothing, and the panel face is drawn from the location alone
 * (treeCaretForLocation). The tree leaves the dead zone by construction,
 * not by a flag.
 */

import { elementForKey } from '../selection/resolver';
import {
  projectionIndex,
  projectionRoot,
} from '../components/page-elements/projection-index';
import {
  legalize,
  rootEntityKey,
  containerKeyForLocation,
  treeRowContentEdge,
} from './gaps';

/**
 * The band at a container-like row's top and bottom edges meaning the
 * line above and the line below; capped at a third of short rows so the
 * middle (`inside`) survives.
 */
const BAND_PX = 8;

/**
 * The visible rows of the page elements tree, in order: the model's one
 * DOM reading.
 *
 * `expanded` is false only for a row whose children are folded away
 * (aria-expanded="false"): a leaf, an empty container and the page row
 * (which never folds, so the tree stamps it no aria-expanded) all hide
 * nothing.
 *
 * @return {Array<{element: Element, key: ?string, region: ?string, owner: ?string, claims: ?string[], door: ?string, depth: number, expanded: boolean}>}
 */
export const readTreeRows = () =>
  Array.from(
    document.querySelectorAll('.daedalus-ui-tree [role="treeitem"]'),
  ).map((element) => ({
    element,
    key: element.getAttribute('data-tree-key'),
    region: element.getAttribute('data-tree-region'),
    owner: element.getAttribute('data-tree-owner'),
    door: element.getAttribute('data-tree-door'),
    claims: element.getAttribute('data-tree-claims')?.split(' ') ?? null,
    depth: Number(element.getAttribute('aria-level')),
    expanded: element.getAttribute('aria-expanded') !== 'false',
  }));

/**
 * A key's segments, or null.
 */
const segmentsOf = (key) => key?.split(':') ?? null;

/**
 * A key's uuid: the second segment.
 */
const uuidOf = (key) => segmentsOf(key)[1];

/**
 * True for a key that names an item of some flow: the root entity, a
 * container, or a block PLACEMENT (block:{uuid}; a block's field rows
 * carry a third segment and inline entities are not layout flow).
 */
const isFlowKey = (key) => {
  const parts = segmentsOf(key);
  return (
    !!parts &&
    parts.length === 2 &&
    (parts[0] === 'entity' || parts[0] === 'container' || parts[0] === 'block')
  );
};

/**
 * A row's part in the flow: 'root' (the page row, or the markup view's
 * shell claiming the root's sections, the root row's twin), 'container',
 * 'door' (a slot block's row, container-like), 'block', 'region', or null
 * for an inert row (a field, a part, a value, any other shell, an inline
 * entity, a shell claiming sections of a non-root owner). Only the claim
 * and the owner decide for a shell, never its label.
 */
const rowKind = (row) => {
  if (row.region !== null) {
    return row.owner ? 'region' : null;
  }
  if (row.door !== null && isFlowKey(row.key)) {
    return 'door';
  }
  if (row.claims) {
    return row.claims.includes('section') &&
      !!row.owner &&
      row.owner === rootEntityKey()
      ? 'root'
      : null;
  }
  if (!isFlowKey(row.key)) {
    return null;
  }
  const [type] = segmentsOf(row.key);
  if (type === 'entity') {
    return row.key === rootEntityKey() ? 'root' : null;
  }
  return type;
};

/**
 * The key of the container a container-like row stands for: its own key,
 * or its owner's for a region row and the sections shell.
 */
const containerKeyOf = (row) => row.key ?? row.owner;

/**
 * The row of the container a location inserts into, for the target's
 * `container` (the row highlight). The root inside location has two faces
 * in the markup view: the shell claiming the root's sections holds the
 * section rows, so it is the face when rendered (the same preference
 * treeRowForLocation draws by), the root row otherwise.
 */
const containerRowFor = (rows, containerKey) =>
  rows.find(
    (row) =>
      row.claims !== null &&
      row.owner === containerKey &&
      rowKind(row) === 'root',
  ) ??
  rows.find((row) => row.key === containerKey) ??
  null;

/**
 * The candidate "after a row's element, in its parent flow".
 */
const afterRow = (rows, row, index) => ({
  location: { anchor: uuidOf(row.key), placement: 'after' },
  anchorKey: row.key,
  anchorRow: row,
  containerRow: containerRowFor(rows, index.get(row.key)?.parent ?? null),
});

/**
 * The candidate "inside a container-like row's element": the append, with
 * the region name when the row is a region's.
 */
const insideRow = (rows, row) => {
  const key = containerKeyOf(row);
  const location = { anchor: uuidOf(key), placement: 'inside' };
  if (row.region !== null) {
    location.region = row.region;
  }
  return {
    location,
    anchorKey: key,
    anchorRow: row,
    containerRow: row.region !== null ? row : containerRowFor(rows, key),
  };
};

/**
 * The START of a container-like row's flow when the row shows its
 * children: before its first flow child, `inside` when it has none. A
 * keyed row's first child is the projection index's (regions are
 * transparent there, so a multi-region container's start is its first
 * region's first block; a door's is its fill); a region row's is the
 * visible row beneath it, since the index has no entry for a region.
 * Null for a collapsed row and for rows that are no container.
 */
const startOf = (rows, i, index, kind) => {
  const row = rows[i];
  if (!row.expanded) {
    return null;
  }
  let firstKey = null;
  if (kind === 'root' || kind === 'container' || kind === 'door') {
    firstKey =
      (index.get(containerKeyOf(row))?.children ?? []).find(isFlowKey) ?? null;
  } else if (kind === 'region') {
    const below = rows[i + 1];
    firstKey = below && below.depth > row.depth ? below.key : null;
  } else {
    return null;
  }
  if (!firstKey) {
    return insideRow(rows, row);
  }
  return {
    location: { anchor: uuidOf(firstKey), placement: 'before' },
    anchorKey: firstKey,
    anchorRow: rows.find((candidate) => candidate.key === firstKey) ?? row,
    containerRow:
      kind === 'region' ? row : containerRowFor(rows, containerKeyOf(row)),
  };
};

/**
 * The drops the line below row i can mean, deepest first.
 *
 * When row i is a container-like row showing its children, the line is
 * the START of its flow and means that alone. Otherwise it means "after
 * row i" in its parent flow (the end of a collapsed region, for a region
 * row), then one candidate per flow ancestor row i closes (the next
 * visible row leaves that ancestor's subtree), up to the root flow: the
 * page row and its sections shell end the walk, a region or an inert
 * ancestor closes with no gap of its own (the grammar has no "after a
 * region", and a part row is no flow item). An inert row i contributes
 * no candidate of its own but still closes its ancestors, so the line
 * under a block's last field row means after the block.
 *
 * Every candidate carries the location, the anchor's key (the move
 * guard's canvas element), the row whose indent is the drop's depth
 * (pickByX reads its content edge) and the row of the container inserted
 * into (the highlight).
 *
 * @param {Array} rows
 *   The visible rows (readTreeRows).
 * @param {number} i
 *   The index of the row above the line; out of range yields no
 *   candidates (there is no line above the first row).
 * @param {Map} index
 *   The projection index.
 *
 * @return {Array<{location: Object, anchorKey: string, anchorRow: Object, containerRow: ?Object}>}
 */
export const lineCandidates = (rows, i, index) => {
  const row = rows[i];
  if (!row) {
    return [];
  }
  const kind = rowKind(row);
  const start = startOf(rows, i, index, kind);
  if (start) {
    return [start];
  }
  const candidates = [];
  if (kind === 'block' || kind === 'container' || kind === 'door') {
    candidates.push(afterRow(rows, row, index));
  } else if (kind === 'region') {
    candidates.push(insideRow(rows, row));
  }
  const below = rows[i + 1];
  let depth = row.depth;
  for (let j = i - 1; j >= 0; j--) {
    const ancestor = rows[j];
    if (ancestor.depth >= depth) {
      continue;
    }
    depth = ancestor.depth;
    if (below && below.depth > ancestor.depth) {
      // Row i does not close this ancestor, so it closes none above it.
      break;
    }
    const ancestorKind = rowKind(ancestor);
    if (ancestorKind === 'root') {
      break;
    }
    if (
      ancestorKind === 'container' ||
      ancestorKind === 'block' ||
      ancestorKind === 'door'
    ) {
      candidates.push(afterRow(rows, ancestor, index));
    }
  }
  return candidates;
};

/**
 * The candidate the cursor's horizontal position picks at a line: the
 * deepest whose anchor row's content edge is at or left of the cursor,
 * else the shallowest. A line with one candidate means it at every x.
 *
 * @param {Array} candidates
 *   lineCandidates' answer, deepest first.
 * @param {number} x
 *   The cursor's viewport x.
 * @param {function(Object): number} contentEdge
 *   A row's content edge in viewport coordinates (the DOM adapter is
 *   rowContentEdge; specs pass their own).
 *
 * @return {?Object}
 */
export const pickByX = (candidates, x, contentEdge) =>
  candidates.find((candidate) => contentEdge(candidate.anchorRow) <= x) ??
  candidates[candidates.length - 1] ??
  null;

/**
 * A row record's content edge: where its indent ends, the one reading the
 * panel caret's line also starts from (gaps.js treeRowContentEdge), so
 * the depth x picks is the depth the drawn line shows.
 */
const rowContentEdge = (row) => treeRowContentEdge(row.element);

/**
 * The drop target a candidate means, in the drag layer's shape:
 * - kind: 'gap'.
 * - location: {anchor, placement[, region]}, the server grammar.
 * - container: the row of the container inserted into (the highlight).
 * - empty: true for an `inside` drop (the append affordance over the row).
 * - canvasElement: the anchor's canvas element, for the move sources'
 *   descendant guard; the panel is no canvas surface, so no insertion
 *   point and no placeholder.
 * - gapId: the location's identity.
 * - rootFlow: the location inserts into the projection root, read from
 *   the location (isRootFlowLocation's derivation) so a tree target and a
 *   canvas target agree on it.
 * Then legalize: a block drop in the root flow wraps in a new section.
 */
const targetFor = (candidate, targets) => {
  const { location, anchorKey, containerRow } = candidate;
  const region = location.region ? `|${location.region}` : '';
  return legalize(
    {
      kind: 'gap',
      location,
      container: containerRow?.element ?? null,
      empty: location.placement === 'inside',
      canvasElement: elementForKey(anchorKey),
      gapId: `tree|${anchorKey}|${location.placement}${region}`,
      rootFlow: containerKeyForLocation(location) === projectionRoot(),
    },
    targets,
  );
};

/**
 * Resolves a cursor over a page elements row to a drop target.
 *
 * @param {Element} row
 *   The row under the cursor (a treeitem).
 * @param {number} x
 * @param {number} y
 *   Viewport cursor coordinates.
 * @param {string} targets
 *   'block' or 'section', what the drag source places.
 *
 * @return {?Object}
 *   The target (see targetFor), or null: before the mirror boots, over an
 *   inert row, or at a line that means nothing (above the first row).
 */
export const resolveRowTarget = (row, x, y, targets) => {
  const index = projectionIndex();
  if (!index) {
    return null;
  }
  const rows = readTreeRows();
  const i = rows.findIndex((candidate) => candidate.element === row);
  if (i === -1) {
    return null;
  }
  const kind = rowKind(rows[i]);
  if (!kind) {
    return null;
  }
  const rect = row.getBoundingClientRect();
  let candidates;
  if (kind === 'block') {
    const above = y < rect.top + rect.height / 2;
    candidates = lineCandidates(rows, above ? i - 1 : i, index);
  } else {
    const band = Math.min(BAND_PX, rect.height / 3);
    if (y < rect.top + band) {
      candidates = lineCandidates(rows, i - 1, index);
    } else if (y > rect.bottom - band) {
      candidates = lineCandidates(rows, i, index);
    } else {
      candidates = [insideRow(rows, rows[i])];
    }
  }
  const pick = pickByX(candidates, x, rowContentEdge);
  return pick ? targetFor(pick, targets) : null;
};
