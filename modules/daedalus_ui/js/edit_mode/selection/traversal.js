import { splitAddress } from '../components/page-elements/projection-index';
import {
  seatAddress,
  reachableRows,
  reachableParent,
} from '../components/page-elements/reach';

/**
 * THE traversal grammar for the ONE selection over the two-tier
 * selection index (projection-index.js), pure: each move takes the index,
 * the reach predicate (reach.js: which rows the panel currently draws),
 * the selected key and the selector the selection seats on (the styling
 * target's selector when the target sits on the selected element, null
 * otherwise) and answers with the step to commit, or null when there is
 * nowhere to go. The keyboard bindings (keyboard-traversal.js) are a
 * thin adapter over these so the node suite can lock the order without
 * a DOM or a store.
 *
 * The tree walked is the panel's own nesting, both tiers, as DRAWN:
 * siblings are the reachable `rows` of the address's `rowParent` (a
 * Raster shell sits beside the owner's other fields, a list item beside
 * its sibling items), Right drills into the first reachable row (Raster
 * from a linked Image while the Markup view draws shells, the claimed
 * field straight from the Image while it does not; the list from a
 * Formatted text block in every view, instance rows being content),
 * Left climbs to the nearest reachable row parent (the list from an
 * item, the owner block from the list, the Image from its claimed field
 * when the shell between them is not drawn). An undrawn row is passed
 * through in both directions, its rows hoisted into its place exactly as
 * the tree hoists them (reach.js reachableRows, reachableParent), so
 * arrows can never land on a row the panel does not show, and the stops
 * are exactly the crumbs the breadcrumb draws (ancestry.js climbs the
 * same pointer).
 *
 * A part the index does not place, or places on a row the panel is not
 * drawing, seats on its owner (reach.js seatAddress): the unlinked
 * image's hoisted picture has no row, so Right from that block finds no
 * row and the key is handled without a move; a shell part targeted
 * while the Markup view is off moves as if its owner were selected. An
 * element key the index cannot place has no seat at all: the caller
 * declines the key.
 *
 * Every step carries the address split back out (the element key to
 * select, the part's selector to target under it, commit-address.js) and
 * `drill`, the element parent of the committed key: the Selector's drill
 * context is an element-tier notion (pointer resolution), so a part seats
 * it where its owner's crumb click does, at the owner's parent, and an
 * element at its own parent, where double-click's drill-in (Right's
 * shape) and the crumb click (Left's shape) seat it. The context is read
 * by pointer resolution and by the click-outside exit only; Escape
 * ignores it and deselects.
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
 * Where a selection pair sits in the drawn tree: its seat address and
 * entry, or null when the index cannot place the element key at all.
 *
 * @param {?Map<string, {rowParent: ?string, rows: string[]}>} index
 *   The projection index, or null before the mirror boots.
 * @param {function(string): boolean} reach
 *   The reach predicate (reach.js reachPredicate()).
 * @param {string} key
 *   The selected element key (the owner, for a part selection).
 * @param {?string} selector
 *   The selector the selection seats on (a part target's), or null for
 *   the bare element.
 *
 * @return {?{address: string, entry: object}}
 */
export const seatOf = (index, reach, key, selector = null) => {
  const address = seatAddress(index, reach, key, selector);
  const entry = index?.get(address);
  return entry ? { address, entry } : null;
};

/**
 * The sibling `delta` drawn rows away among the nearest reachable row
 * parent's reachable rows (both tiers), or null at the edge. The root is
 * its own only sibling.
 *
 * @return {?{address: string, key: string, selector: ?string, drill: ?string}}
 */
export const siblingOf = (index, reach, key, selector, delta) => {
  const current = seatOf(index, reach, key, selector);
  if (!current) {
    return null;
  }
  const above = reachableParent(index, reach, current.address);
  const siblings = above
    ? reachableRows(index, reach, above)
    : [current.address];
  const next = siblings[siblings.indexOf(current.address) + delta];
  return next ? stepTo(index, next) : null;
};

/**
 * The nearest reachable row parent (the enclosing part, or the owner
 * from a top-level part, or the element parent; an undrawn shell is
 * passed through), or null at the root.
 *
 * @return {?{address: string, key: string, selector: ?string, drill: ?string}}
 */
export const parentOf = (index, reach, key, selector = null) => {
  const current = seatOf(index, reach, key, selector);
  const above = current && reachableParent(index, reach, current.address);
  return above ? stepTo(index, above) : null;
};

/**
 * The first drawn row inside the selection (a shell part, a carrier, a
 * child element, or what an undrawn shell hoists), or null for a leaf.
 *
 * @return {?{address: string, key: string, selector: ?string, drill: ?string}}
 */
export const firstRowOf = (index, reach, key, selector = null) => {
  const current = seatOf(index, reach, key, selector);
  const first = current && reachableRows(index, reach, current.address)[0];
  return first ? stepTo(index, first) : null;
};
