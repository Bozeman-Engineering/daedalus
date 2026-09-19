import {
  placedAddress,
  splitAddress,
} from '../components/page-elements/projection-index';

/**
 * THE traversal grammar for the ONE selection over the two-tier
 * selection index (projection-index.js), pure: each move takes the index
 * and the store's {selectedKey, selectedPart} pair and answers with the
 * step to commit, or null when there is nowhere to go. The keyboard
 * bindings (keyboard-traversal.js) are a thin adapter over these so the
 * node suite can lock the order without a DOM or a store.
 *
 * The tree walked is the panel's own nesting, both tiers: siblings are the
 * `rows` of the address's `rowParent` (a Raster shell sits beside the
 * owner's other fields, a list item beside its sibling items), Right
 * drills into the first row (Raster from a linked Image, the list from a
 * Formatted text block, the claimed field from Raster), Left climbs to
 * the row parent (the list from an item, the owner block from the list).
 * These are exactly the stops the breadcrumb draws (ancestry.js climbs the
 * same pointer), so arrows can never reach a row the crumbs cannot name.
 *
 * A part the index does not place seats on its owner (placedAddress): the
 * unlinked image's hoisted picture has no row, so Right from that block
 * finds no row and the key is handled without a move. An element key the
 * index cannot place has no seat at all: the caller declines the key.
 *
 * Every step carries the pair split back out (setSelected(key) for an
 * element, setSelected({key, part}) for a part) and `drill`, the element
 * parent of the committed pair's key: the Selector's drill context is an
 * element-tier notion (pointer resolution), so a part seats it where its
 * owner's crumb click does, at the owner's parent, and an element where the
 * pointer gestures do (Right: the parent becomes the context; Left: the
 * grandparent does).
 */

/**
 * The step for an index address: the pair it commits and the drill it
 * seats.
 */
const stepTo = (index, address) => {
  const pair = splitAddress(address);
  return { address, ...pair, drill: index.get(pair.key)?.parent ?? null };
};

/**
 * Where a selection pair sits in the index: its placed address and entry,
 * or null when the index cannot place the element key at all.
 *
 * @param {?Map<string, {rowParent: ?string, rows: string[]}>} index
 *   The projection index, or null before the mirror boots.
 * @param {string} key
 *   The selected element key (the owner, for a part selection).
 * @param {?string} part
 *   The selected part id, or null for an element selection.
 *
 * @return {?{address: string, entry: object}}
 */
export const seatOf = (index, key, part = null) => {
  const address = placedAddress(index, key, part);
  const entry = index?.get(address);
  return entry ? { address, entry } : null;
};

/**
 * The sibling `delta` rows away in the row parent's `rows` (both tiers),
 * or null at the edge. The root is its own only sibling.
 *
 * @return {?{address: string, key: string, part: ?string, drill: ?string}}
 */
export const siblingOf = (index, key, part, delta) => {
  const current = seatOf(index, key, part);
  if (!current) {
    return null;
  }
  const siblings = current.entry.rowParent
    ? (index.get(current.entry.rowParent)?.rows ?? [])
    : [current.address];
  const next = siblings[siblings.indexOf(current.address) + delta];
  return next ? stepTo(index, next) : null;
};

/**
 * The row parent (the enclosing part, or the owner from a top-level
 * part, or the element parent), or null at the root.
 *
 * @return {?{address: string, key: string, part: ?string, drill: ?string}}
 */
export const parentOf = (index, key, part = null) => {
  const above = seatOf(index, key, part)?.entry.rowParent;
  return above ? stepTo(index, above) : null;
};

/**
 * The first row inside the selection (a shell part, a carrier, or a
 * child element), or null for a leaf.
 *
 * @return {?{address: string, key: string, part: ?string, drill: ?string}}
 */
export const firstRowOf = (index, key, part = null) => {
  const first = seatOf(index, key, part)?.entry.rows[0];
  return first ? stepTo(index, first) : null;
};
