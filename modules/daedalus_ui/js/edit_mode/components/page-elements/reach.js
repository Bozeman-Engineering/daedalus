/**
 * Reach: what the user is currently offered to land on.
 *
 * The selection spine carries two tiers of truth. EXISTENCE is the
 * projection index (projection-index.js): every address on the page,
 * element keys and `key::selector` part addresses, composed
 * view-independently. REACH is this predicate over it: of everything
 * that exists, the rows the Page elements panel currently depicts, which
 * is what an entry road may land on. The pointer's depth stop (Cmd-click,
 * the picker), the keyboard's next row, the breadcrumb's crumbs and an
 * Explode plane all read reach; a programmatic seat (the facade's
 * target(), a styling module's write-address switcher, code mode, the AI)
 * never does, addressing existence and beyond it, and rides its element
 * with a target crumb when its address is not reachable.
 *
 * Reach is keyed to the tree's views, not to a styling mode: each index
 * entry records the `source` its row was composed with ('element' for a
 * model row, 'instance' for a minted rich-text carrier, a view id for a
 * view's own rows: the Markup view's for a declared shell row and a
 * generated box), and a source is depicted when it is content (element,
 * instance: drawn in every view) or when its view's Show-menu toggle is
 * on (the view slice's `views` map). No second projection and no memo:
 * a lookup in the map every reader already holds, plus one boolean.
 *
 * Reach is also gated by the actor: a depicted row whose verdict allows
 * nothing (selection/capable-model.js selectableIn: a template's
 * elements on a page that does not own them) is drawn but not reachable,
 * so the arrows walk past it to its selectable rows exactly as they walk
 * past an undrawn shell, and the breadcrumb never names it.
 */

import store from '../../store/store';
import { projectionIndex, selectorAddress } from './projection-index';
import { selectableIn } from '../../selection/capable-model';

/**
 * Whether rows of a source are currently depicted. Pure: the visibility
 * map is the view slice's `views` (view id → boolean).
 *
 * @param {string} source
 *   An index entry's source: 'element', 'instance', or a view id.
 * @param {Object<string, boolean>} visibility
 *   The view visibility map (state.view.views).
 *
 * @return {boolean}
 */
export const depicted = (source, visibility) =>
  source === 'element' || source === 'instance' || visibility[source] === true;

/**
 * A view tree with every pseudo row whose source is not depicted removed
 * and its children hoisted into its place: the tree's draw filter, the
 * same rule the walks will read, applied to the rows about to render.
 * Pure over the visibility map it is handed.
 *
 * The page-elements tree composes its pseudo rows exactly as the index
 * does (instance rows under the model projection, generated-box rows
 * under whichever tree renders) and then draws through this filter, so
 * the rows on screen are precisely the reachable ones: an instance row
 * survives because its source is 'instance', a box row because the
 * Markup view is on, a contrib view's row because its toggle is. Model
 * rows always pass (source 'element' by construction). Hoisting mirrors
 * the index's transparent rows and the reach-filtered parent climb: a
 * row the panel does not draw still shows what sits inside it, under
 * the nearest drawn ancestor.
 *
 * @param {object} node
 *   A view tree node (projection.js projectTree output or a provider's
 *   tree in the seam's vocabulary), with pseudo rows stamped `source`.
 * @param {Object<string, boolean>} visibility
 *   The view visibility map (state.view.views).
 *
 * @return {object}
 *   The node with undepicted pseudo rows pruned at every depth.
 */
export const depictedRows = (node, visibility) => {
  const children = [];
  for (const child of node.children) {
    const kept = depictedRows(child, visibility);
    if (child.pseudo && !depicted(child.source, visibility)) {
      children.push(...kept.children);
    } else {
      children.push(kept);
    }
  }
  return { ...node, children };
};

/**
 * The reach predicate over a given index and visibility map, pure: the
 * node suite drives it over a hand-built index exactly as the app drives
 * it over the memoized one (reachPredicate).
 *
 * @param {?Map<string, {source: string}>} index
 *   The projection index, or null before the mirror boots.
 * @param {Object<string, boolean>} visibility
 *   The view visibility map (state.view.views).
 *
 * @return {function(string): boolean}
 *   address → whether the address exists, its row is depicted and its
 *   element is selectable. With no index nothing exists, so nothing is
 *   reachable.
 */
export const reachOver = (index, visibility) => (address) => {
  const entry = index?.get(address);
  return (
    entry !== undefined &&
    depicted(entry.source, visibility) &&
    selectableIn(index, address)
  );
};

/**
 * A reach predicate closed over the current index and view visibility,
 * for the pure walks (traversal, ancestry, the seat) that take the index
 * as an argument: one predicate per walk, so a view flip mid-walk cannot
 * split the answer. The index is memoized (projection-index.js), so the
 * map the predicate reads is the map the walk reads.
 *
 * @return {function(string): boolean}
 *   address → whether the address exists, its row is depicted and its
 *   element is selectable. Before the mirror boots nothing exists, so
 *   nothing is reachable.
 */
export const reachPredicate = () =>
  reachOver(projectionIndex(), store.getState().view.views);

/**
 * The index address a selection with a selector under it SEATS on for
 * the entry roads (the walks, the breadcrumb, F6's row): the selector
 * address when the index places it as a row AND that row is reachable,
 * else the owner key. A selector naming a non-row (a hoisted part, which
 * the facade's public target() can still seat; the children or a type
 * selector, which never have rows) or a row the panel does not currently
 * draw (a shell part with the Markup view off) rides on its element
 * rather than on nothing, so ancestry and traversal degrade the same
 * way: the trail ends with a target crumb, arrows move as if the owner
 * were selected. The seat never validates the target itself (a
 * programmatic seat stands whatever the view); it only says which row
 * stands for it now. Pure over the index and reach it is handed.
 *
 * @param {?Map<string, object>} index
 *   The projection index, or null before the mirror boots.
 * @param {function(string): boolean} reach
 *   The reach predicate (reachPredicate(), or reachOver in a suite).
 * @param {string} key
 *   The selected element key (the owner, for a part target).
 * @param {?string} selector
 *   The styling target's selector when the target sits on the selected
 *   element, or null for the bare element.
 *
 * @return {string}
 */
export const seatAddress = (index, reach, key, selector = null) =>
  selector !== null &&
  !!index?.has(selectorAddress(key, selector)) &&
  reach(selectorAddress(key, selector))
    ? selectorAddress(key, selector)
    : key;

/**
 * The rows the panel draws under an index address, in panel order: the
 * entry's `rows` with every unreachable row replaced by its own drawn
 * rows, recursively, the index-side twin of depictedRows (a shell the
 * Markup view is not drawing shows the field it claims in its place,
 * under the owner). Traversal's sibling and drill-in stops.
 *
 * @param {Map<string, {rows: string[]}>} index
 *   The projection index.
 * @param {function(string): boolean} reach
 *   The reach predicate.
 * @param {string} address
 *   An index address whose drawn rows to list.
 *
 * @return {string[]}
 */
export const reachableRows = (index, reach, address) =>
  (index.get(address)?.rows ?? []).flatMap((row) =>
    reach(row) ? [row] : reachableRows(index, reach, row),
  );

/**
 * The nearest reachable row parent of an index address, climbing
 * `rowParent` through every row the panel is not drawing, or null at the
 * root. Traversal's climb and the breadcrumb's next crumb: a field
 * claimed into an undrawn shell answers its owner, exactly where the
 * tree hoists the field's row.
 *
 * @param {Map<string, {rowParent: ?string}>} index
 *   The projection index.
 * @param {function(string): boolean} reach
 *   The reach predicate.
 * @param {string} address
 *   An index address whose drawn parent to find.
 *
 * @return {?string}
 */
export const reachableParent = (index, reach, address) => {
  let above = index.get(address)?.rowParent ?? null;
  while (above !== null && !reach(above)) {
    above = index.get(above)?.rowParent ?? null;
  }
  return above;
};

/**
 * Whether one address is reachable now: it exists in the projection index,
 * its row is depicted under the current view visibility and its element
 * is selectable.
 *
 * @param {string} address
 *   An index address (`key` or `key::selector`, projection-index.js
 *   selectorAddress).
 *
 * @return {boolean}
 */
export const reachable = (address) => reachPredicate()(address);
