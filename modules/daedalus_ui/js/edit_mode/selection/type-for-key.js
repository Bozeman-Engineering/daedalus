import { projectionIndex } from '../components/page-elements/projection-index';
import { fallbackLabel } from './label-for-key';

/**
 * THE type resolver for a selectable key: the one place a spine key becomes
 * a TYPE name — what kind of thing this element is, never what it says.
 * The content wording (a text block's row reading its own text) stays with
 * labelForKey; this is its sibling read of the same projection: the index
 * entry's `elementType`, which is the server-minted `_element.type` the
 * projection copied onto the row ("Section" for a container at page depth,
 * "Container" nested, a block type's label, the root's bundle label, a
 * field's own word). Nothing is derived here: the words are minted once,
 * in PHP, with t(), and read by every surface (this resolver, the tree,
 * the stylish inspector in every host, the explain tool, the AI), so a
 * selection's type can never disagree with the tree that placed it. Keys
 * the index does not place (foreign entities reached by canvas click) and
 * entries without a type word (part addresses) fall back to the spine
 * key's own vocabulary. Never null, never empty: the canvas chips read
 * THIS resolver.
 */

/**
 * The type name for a key against a given projection index: the pure core,
 * for callers that hold the index (and for the node suite).
 *
 * @param {?Map<string, {label: string, elementType?: ?string}>} index
 *   The projection index, or null before the mirror boots.
 * @param {string} key
 *   The selectable key.
 *
 * @return {string}
 */
export const typeFor = (index, key) =>
  index?.get(key)?.elementType ?? fallbackLabel(key);

/**
 * The type name for a selectable key, read against the live projection
 * index. Never null.
 *
 * @param {string} key
 *   The selectable key.
 *
 * @return {string}
 */
export const typeForKey = (key) => typeFor(projectionIndex(), key);
