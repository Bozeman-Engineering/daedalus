import { projectionIndex } from '../components/page-elements/projection-index';

/**
 * THE label resolver for an index address: the one place a spine key
 * becomes a display name, what the element SAYS (a text block's row
 * reads its own content). The panel projection speaks first (the same
 * shared index that labels the tree, the breadcrumb, and keyboard
 * traversal: projection-index.js); keys the projection cannot place
 * (foreign entities reached by canvas click, rows absorbed by a merged
 * presentation) fall back to a readable name derived from the spine
 * key's own vocabulary. Never null, never empty: every content-labeled
 * surface (tree rows, indicator chips, breadcrumb, the AI chat's chips,
 * traversal announcements) reads THIS resolver, so the same element can
 * never be worded two ways. Its sibling read of the same projection is
 * the type resolver (type-for-key.js): the canvas chips name what the
 * element IS, not what it says.
 *
 * Addresses span both tiers: an element key or a `key::selector` part
 * address (projection-index.js partAddress). A part address labels from
 * the index alone (the matched-form label the tree's row shows: Raster,
 * Bulleted list, List item); it needs no vocabulary fallback because part
 * addresses exist only in the index, and callers (ancestry.js) ask only
 * for placed ones. The vocabulary below is the ELEMENT key grammar.
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
 * The display label for a key against a given projection index: the pure
 * core, for callers that hold the index (and for the node suite).
 *
 * @param {?Map<string, {label: string}>} index
 *   The projection index, or null before the mirror boots.
 * @param {string} key
 *   The selectable key.
 *
 * @return {string}
 */
export const labelFor = (index, key) =>
  index?.get(key)?.label ?? fallbackLabel(key);

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
