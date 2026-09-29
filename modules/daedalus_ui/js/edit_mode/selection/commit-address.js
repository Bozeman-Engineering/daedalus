import store from '../store/store';
import { setSelected, setTarget } from '../store/slices/selection-slice';

/**
 * Commits an index address onto the spine: the element as the selection
 * and, when the address names a selector under it, that selector as the
 * styling target. The one road every in-app address commit takes (a
 * canvas Cmd-click on a part, a tree part-row click, a traversal step, a
 * breadcrumb crumb), so all four agree on what changes and what does
 * not: the selection is re-committed only when the element or the set
 * changes (a selection write clears the target), and the target is
 * written only when its value changes, so a same-address commit
 * dispatches nothing and no subscriber fires for a non-move.
 *
 * @param {string} key
 *   The element key to select (the owner, for a part address).
 * @param {?string} selector
 *   The canonical relative selector to target under it, or null for the
 *   bare element (which drops any standing target).
 */
export const commitAddress = (key, selector = null) => {
  const { selectedKey, selectedKeys, target } = store.getState().selection;
  const reselect = key !== selectedKey || selectedKeys.length !== 1;
  if (reselect) {
    store.dispatch(setSelected(key));
  }
  const standing = reselect ? null : target;
  const next = selector ? { key, selector } : null;
  if (
    standing?.key !== next?.key ||
    standing?.selector !== next?.selector
  ) {
    store.dispatch(setTarget(next));
  }
};
