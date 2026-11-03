/**
 * @file
 * The per-pseudo-element vocabularies on the client.
 *
 * The one client reader of data/pseudo-vocabularies.json (hand-maintained
 * from each pseudo-element's specification; the PHP twin is
 * Vocabulary\PseudoVocabularies). The file is bundled, so the client and
 * the server read the same committed table and the pseudo-vocabularies
 * battery guards its shape. The client reads it for one derivation: the
 * grants of a table pseudo-element's box (style-guide.js pseudoNode, the
 * twin of VocabularyRosterBuilder::pseudoNode): `li::marker` offers the
 * properties the browser styles on a marker, grouped by the curated
 * groups the payload serves, never its host's padding. Refusing a
 * property outside the allowlist is the server's law
 * (STYLE_PROPERTY_NOT_ON_PSEUDO), applied at the write in every mode.
 */
import table from '../../data/pseudo-vocabularies.json' with { type: 'json' };

/**
 * The properties the browser styles on a pseudo-element, or null.
 *
 * @param {string} pseudo
 *   The bare name (`marker`, never `::marker`).
 *
 * @return {?string[]}
 *   The allowlist in the table's order, null for a pseudo-element the
 *   table does not carry (its vocabulary is its host's).
 */
export const pseudoVocabulary = (pseudo) =>
  Array.isArray(table[pseudo]?.properties) ? [...table[pseudo].properties] : null;

/**
 * Every pseudo-element the table carries, in the table's order.
 *
 * @return {string[]}
 */
export const vocabularyPseudos = () => Object.keys(table);
