import {
  projectionIndex,
  splitAddress,
} from '../components/page-elements/projection-index';
import { getPartBehavior } from '../components/page-elements/view-registry';

/**
 * THE label resolver for an index address: the one place a spine key
 * becomes a display name, what the element SAYS (a text block's row
 * reads its own content). The panel projection speaks first (the same
 * shared index that labels the tree, the breadcrumb, and keyboard
 * traversal: projection-index.js), and its label is the server-minted
 * `_element.name` the projection copied onto the row (a section's custom
 * label else its type word, a merged block's content excerpt, the root's
 * entity label: BlueprintElementAnnotator mints them once, with t(), and
 * every host reads them); keys the projection cannot place (foreign
 * entities reached by canvas click, rows absorbed by a merged
 * presentation) fall back to a readable name derived from the spine
 * key's own vocabulary, the one derivation left. Never null, never empty:
 * every content-labeled surface (tree rows, indicator chips, breadcrumb,
 * the AI chat's chips, traversal announcements) reads THIS resolver, so
 * the same element can never be worded two ways. Its sibling read of the
 * same projection is the type resolver (type-for-key.js): the canvas
 * chips name what the element IS, not what it says.
 *
 * Addresses span both tiers: an element key or a `key::selector` address
 * (projection-index.js selectorAddress). A placed part address labels
 * from the index alone (the name the tree's row shows: a shell part's
 * matched-form label such as Raster, a carrier's excerpt, a list's word);
 * an address the index does not place
 * (a tag tier, a chain) labels its OWNER, the element
 * the address styles under, never the key's kind. The vocabulary below is
 * the ELEMENT key grammar. What a selector itself is called ("every
 * link") is the styling module's vocabulary: targetLabel asks the part
 * behavior seam for it.
 */

/**
 * The vocabulary fallback for keys with no projection node.
 *
 * @param {string} key
 *   The selectable key.
 *
 * @return {string}
 *   A readable name; 'Element' when the key's vocabulary says nothing.
 */
export const fallbackLabel = (key) => {
  const segments = String(key).split(':');
  const humanize = (name) => {
    const words = name
      .replace(/^field_/, '')
      .replace(/[_-]+/g, ' ')
      .trim();
    return words.charAt(0).toUpperCase() + words.slice(1) || 'Element';
  };
  switch (segments[0]) {
    case 'entity':
      return 'Entity';

    case 'container':
      return 'Container';

    case 'field':
      return humanize(segments[2] ?? segments[0]);

    case 'block':
      return segments.length > 2 ? humanize(segments[2]) : 'Block';

    default:
      return 'Element';
  }
};

/**
 * The display label for an address against a given projection index:
 * the pure core, for callers that hold the index (and for the node
 * suite). A placed `key::selector` address reads its own row's label;
 * any other address reads its owner's.
 *
 * @param {?Map<string, {label: string}>} index
 *   The projection index, or null before the mirror boots.
 * @param {string} address
 *   The selectable key, or a `key::selector` address.
 *
 * @return {string}
 */
export const labelFor = (index, address) => {
  const placed = index?.get(address)?.label;
  if (placed !== undefined) {
    return placed;
  }
  const { key } = splitAddress(address);
  return index?.get(key)?.label ?? fallbackLabel(key);
};

/**
 * The words for a styling target's selector ("every link", "Raster"):
 * the part behavior's label seam when a styling module registered one
 * (daedalus_stylish's selectorLabel), else the raw selector, so a target
 * crumb always says something true.
 *
 * @param {string} key
 *   The target's owner key.
 * @param {string} selector
 *   The target's canonical relative selector.
 *
 * @return {string}
 */
export const targetLabel = (key, selector) =>
  getPartBehavior()?.label?.(key, selector) ?? selector;

/**
 * The display label for a selectable key, read against the live projection
 * index. Never null.
 *
 * @param {string} key
 *   The selectable key.
 *
 * @return {string}
 */
export const labelForKey = (key) => labelFor(projectionIndex(), key);
