import {
  selectorAddress,
  splitAddress,
} from '../components/page-elements/projection-index';
import {
  seatAddress,
  reachableParent,
} from '../components/page-elements/reach';
import { labelFor, targetLabel } from './label-for-key';

/**
 * THE ancestry resolver for a selection: the breadcrumb's "how deep am I?",
 * derived from the selection index over BOTH tiers
 * (projection-index.js), filtered by reach (reach.js: the rows the panel
 * currently draws). A selection is the store's selectedKey plus its
 * styling SUBJECT facet (the target, {key, selector} or null, key always
 * the selected key); the trail is the address the pair seats on
 * (reach.js seatAddress), climbed by its drawn row
 * ancestry: part parents up to the owner (a list item under its list),
 * then the owner's row parents to the page root. It climbs `rowParent`
 * through every row the panel is not drawing (reachableParent), the
 * panel's own nesting as shown, so the crumbs are exactly the stops
 * ArrowLeft visits (keyboard traversal walks the same pointer): a field
 * claimed into a shell names the shell above it while the Markup view
 * draws shells, and its owner directly while it does not.
 *
 * Every crumb carries its address split back into (key, selector), so a
 * crumb click commits exactly what the tree's rows commit
 * (commit-address.js: the element selected, the part targeted). Labels
 * come from the one label resolver (label-for-key.js), so the breadcrumb
 * can never word an ancestor differently from the tree or the chips.
 *
 * A facet the index places on a row the panel draws (a declared part
 * with the Markup view on, an instance carrier in every view) IS its
 * row and trails through the rows. Any other facet (a tag tier, a chain
 * seated from code mode, a hoisted part the facade's public target() can
 * still seat, a placed shell part while the Markup view is off) sits on
 * the selected element and ends the trail as one TARGET crumb after the
 * element's ("Welcome › every link"), worded by the styling module's
 * label seam (label-for-key.js targetLabel) or the raw selector when
 * none is registered: the breadcrumb names what the rows describe, never
 * a made-up row. The WRITE ADDRESS (the slice's `address`, a rule on the
 * selection or an ancestor) is never a crumb: the trail reads the facet
 * alone, and the inspector's chip is the address's readout. An element
 * key the index cannot place is its own one-crumb trail: the depth
 * indicator degrades to a name, never to nothing.
 */

/**
 * The selection's ancestry, root first, selection (or its facet) last.
 *
 * @param {?Map<string, {label: string, rowParent: ?string}>} index
 *   The projection index, or null before the mirror boots.
 * @param {function(string): boolean} reach
 *   The reach predicate (reach.js reachPredicate()).
 * @param {string} key
 *   The selected element key.
 * @param {?{key: string, selector: string}} target
 *   The styling subject facet (its key is the selected key), or null for
 *   the bare element.
 * @param {Function} labelTarget
 *   (key, selector) → the words for an unplaced facet's crumb; the
 *   label seam by default, injectable for the node suite.
 *
 * @return {Array<{address: string, key: string, selector: ?string, label: string}>}
 */
export const ancestryOf = (
  index,
  reach,
  key,
  target = null,
  labelTarget = targetLabel,
) => {
  const crumb = (address) => ({
    address,
    ...splitAddress(address),
    label: labelFor(index, address),
  });
  const own = target?.selector ?? null;
  const seat = seatAddress(index, reach, key, own);
  const trail = [crumb(seat)];
  let above = index?.has(seat) ? reachableParent(index, reach, seat) : null;
  while (above) {
    trail.unshift(crumb(above));
    above = reachableParent(index, reach, above);
  }
  if (own !== null && seat === key) {
    trail.push({
      address: selectorAddress(key, own),
      key,
      selector: own,
      label: labelTarget(key, own),
    });
  }
  return trail;
};
