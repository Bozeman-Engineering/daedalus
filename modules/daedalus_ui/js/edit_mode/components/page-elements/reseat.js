/**
 * The re-seat: the selection follows the rows when a view flips.
 *
 * A styling target may sit on a row the Page elements panel draws only
 * while a view is on (a shell part or a generated box under the Markup
 * view), and a row click may select a key the index places no row for
 * (an absorbed field row the Markup view draws under its wrapper while
 * the merged row lands deeper, compose.js). When the view turns off, by
 * the Show kebab or by the styling mode's mirror (both writers land in
 * the view slice, so both fire here), that row is gone from the panel
 * and the selection would stand on nothing drawn. Tim's rule (2026-10-04):
 * the selection moves up to the next visible parent. So on every change
 * of the view visibility map this subscriber asks reseatAddress what the
 * selection should move to and commits it through commitAddress, the one
 * road every in-app address commit takes, so every consumer (the
 * breadcrumb, the tree's primary row, the inspector's session, the
 * canvas paint) sees the move as it sees any other.
 *
 * Only the FLIP re-seats. A seat made while the view is already off
 * stands: a programmatic seat (the facade's target(), the inspector's
 * switcher, code mode, the AI) addresses existence, not reach, and rides
 * its element row with a target crumb until the user moves it. The
 * subscriber fires on a change of the map alone, so such a seat is never
 * revisited.
 */

import store, { listenToStateChange } from '../../store/store';
import {
  projectionIndex,
  selectorAddress,
  splitAddress,
} from './projection-index';
import { reachPredicate, reachableParent } from './reach';
import { commitAddress } from '../../selection/commit-address';
import { subjectKeyOf } from '../../selection/capable-model';

/**
 * The index address the selection moves to after a view flip, or null
 * for nothing: pure over the index, the reach predicate and the
 * selection it is handed, so the node suite drives it over a hand-built
 * index as the subscriber drives it over the live one.
 *
 * Two rules, in order:
 * - A subject facet (the target, keyed by the selected element) whose
 *   address the index PLACES (a row exists for it in some view) but
 *   reach no longer answers climbs to the nearest reachable row parent
 *   (reach.js reachableParent: the owner for a shell part, the owner's
 *   drawn ancestor past a nested shell). A facet the index does not
 *   place (a tag tier, a chain from code mode, the children selector) is
 *   not a row in any view, so no flip undraws it: it stands. A facet
 *   still reachable (an instance row's, drawn in every view) stands. The
 *   write address is ignored here: it seats no row, so the flip reads
 *   the facet alone (the commit that moves the facet drops the address
 *   with it, as every facet write does).
 * - A selected key in no index (an absorbed field row's key, kept while
 *   its wrapper stood) falls to its subject (capable-model.js
 *   subjectKeyOf: the placement for a block field, the root for a root
 *   field), when the index places the subject and reach answers it.
 *
 * Only a single selection moves: a set carries no target, and a set
 * member's row is the set's question, not the flip's.
 *
 * @param {?Map<string, object>} index
 *   The projection index, or null before the mirror boots.
 * @param {function(string): boolean} reach
 *   The reach predicate taken under the visibility the flip produced.
 * @param {{selectedKey: ?string, selectedKeys: string[], target: ?{key: string, selector: string}}} selection
 *   The store's selection slice (its `address` is not read).
 *
 * @return {?string}
 *   An index address to commit (`key` or `key::selector`), or null.
 */
export const reseatAddress = (index, reach, selection) => {
  const { selectedKey, selectedKeys, target } = selection;
  if (!index || !selectedKey || selectedKeys.length !== 1) {
    return null;
  }
  if (target) {
    const address = selectorAddress(target.key, target.selector);
    if (index.has(address) && !reach(address)) {
      return reachableParent(index, reach, address);
    }
  }
  if (!index.has(selectedKey)) {
    const subject = subjectKeyOf(selectedKey);
    return subject !== null && reach(subject) ? subject : null;
  }
  return null;
};

/**
 * Installs the re-seat on the view visibility map, at boot.
 *
 * The same mechanism as app.tree.onViewVisibilityChange, so the kebab's
 * checkbox and the styling mode's mirror (setViewVisibility) both reach
 * it. Dispatching from inside listenToStateChange is safe: the change is
 * recorded before the callback runs (store.js), so the commit's own
 * store updates re-enter with nothing new to deliver.
 *
 * @return {Function}
 *   The store's unsubscribe.
 */
export const initSelectionReseat = () =>
  listenToStateChange(
    (state) => state.view.views,
    () => {
      const address = reseatAddress(
        projectionIndex(),
        reachPredicate(),
        store.getState().selection,
      );
      if (address !== null) {
        const { key, selector } = splitAddress(address);
        commitAddress(key, selector);
      }
    },
  );
