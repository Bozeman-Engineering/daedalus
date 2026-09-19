import { projectionIndex } from '../components/page-elements/projection-index';
import { fallbackLabel } from './label-for-key';

/**
 * THE type resolver for a selectable key: the one place a spine key becomes
 * a TYPE name — what kind of thing this element is, never what it says.
 * The content-derived wording (a text block's row reading its own text)
 * stays with labelForKey; this is its sibling read of the same projection:
 * the node's kind plus, for bundle-bearing nodes, the blueprint's bundle.
 * Containers speak the layout vocabulary (a top-level container is a
 * Section of the page, a nested one a Container), blocks their bundle
 * ("Text", "Image"), entities their bundle ("Page"), fields their
 * humanized field name — the vocabulary fallback already derives exactly
 * that from the key, so field rows and index-less keys share it. Never
 * null, never empty: the canvas chips read THIS resolver, so a selection's
 * type can never disagree with the projection that placed it.
 */

/**
 * A bundle machine name as a display type ("text" → "Text").
 *
 * Bundles keep their full name: the field-vocabulary prefix strips
 * (field_, layout_) do not apply to bundle identities.
 *
 * @param {string} bundle
 *   The bundle machine name.
 *
 * @return {string}
 *   The humanized bundle name.
 */
const humanizeBundle = (bundle) => {
  const words = String(bundle).replace(/[_-]+/g, ' ').trim();
  return words.charAt(0).toUpperCase() + words.slice(1);
};

/**
 * The type name for a key against a given projection index: the pure core,
 * for callers that hold the index (and for the node suite).
 *
 * @param {?Map<string, {label: string, kind: string, bundle: ?string, parent: ?string}>} index
 *   The projection index, or null before the mirror boots.
 * @param {string} key
 *   The selectable key.
 *
 * @return {string}
 */
export const typeFor = (index, key) => {
  const node = index?.get(key);
  if (!node) {
    return fallbackLabel(key);
  }
  switch (node.kind) {
    case 'container':
      // Top-level containers are the page's sections (the root-flow drop
      // grammar's newSection); a container nested inside another is a
      // grouping Container.
      return index.get(node.parent)?.kind === 'container'
        ? 'Container'
        : 'Section';

    case 'block':
    case 'entity':
      return node.bundle ? humanizeBundle(node.bundle) : fallbackLabel(key);

    default:
      // Fields (and any future kind): the key's own vocabulary IS the type
      // (the humanized field name), shared with index-less keys.
      return fallbackLabel(key);
  }
};

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
