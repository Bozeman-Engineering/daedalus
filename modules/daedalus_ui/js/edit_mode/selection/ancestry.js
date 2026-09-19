import {
  placedAddress,
  splitAddress,
} from '../components/page-elements/projection-index';
import { labelFor } from './label-for-key';

/**
 * THE ancestry resolver for a selection: the breadcrumb's "how deep am I?",
 * derived from the selection index over BOTH tiers
 * (projection-index.js). A selection is the store's {selectedKey,
 * selectedPart} pair, which IS an index address split into (owner, part);
 * the trail is that address's row ancestry: part parents up to the owner
 * (a list item under its list), then the owner's row parents to the page
 * root. It climbs `rowParent`, the panel's own nesting, so the crumbs are
 * exactly the stops ArrowLeft visits (keyboard traversal walks the same
 * pointer) and a field claimed into a shell names the shell above it.
 *
 * Every crumb carries its address split back into the pair, so a crumb
 * click commits exactly what the tree's rows commit: setSelected(key) for
 * an element crumb, setSelected({key, part}) for a part crumb. Labels come
 * from the one label resolver (label-for-key.js), so the breadcrumb can
 * never word an ancestor differently from the tree or the chips.
 *
 * A part the index does not place has no crumb: the trail is its owner's.
 * The index is the selection universe (a hoisted part is absent by
 * construction), so a facet naming a non-node, which the facade's public
 * select() can still commit, degrades to the element it rides on rather
 * than to a made-up name. An element key the index cannot place is its
 * own one-crumb trail: the depth indicator degrades to a name, never to
 * nothing.
 */

/**
 * The selection's ancestry, root first, selection last.
 *
 * @param {?Map<string, {label: string, rowParent: ?string}>} index
 *   The projection index, or null before the mirror boots.
 * @param {string} key
 *   The selected element key (the owner, for a part selection).
 * @param {?string} part
 *   The selected part id (the declared selector or instance class), or
 *   null for an element selection.
 *
 * @return {Array<{address: string, key: string, part: ?string, label: string}>}
 */
export const ancestryOf = (index, key, part = null) => {
  const crumb = (address) => ({
    address,
    ...splitAddress(address),
    label: labelFor(index, address),
  });
  const trail = [crumb(placedAddress(index, key, part))];
  let above = index?.get(trail[0].address)?.rowParent;
  while (above) {
    trail.unshift(crumb(above));
    above = index.get(above)?.rowParent;
  }
  return trail;
};
