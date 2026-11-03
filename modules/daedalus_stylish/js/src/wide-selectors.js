/**
 * @file
 * The wide selector tier on the client: what the browser knows.
 *
 * The one client reader of data/wide-selectors.json (generated from the
 * pinned mdn-data and html-tag-names by js/src/tools/generate-wide-
 * selectors.mjs; the PHP twin is WideSelectors). The file is bundled, so
 * the client and the server read the same committed universe and the
 * wide-selectors battery holds it in lockstep with the generator. The
 * tier answers one question, "does this word exist", for the style
 * guide's admission walk (style-guide.js judgeQualifiers: a pseudo-class
 * outside the tier lands nowhere in every mode, the server's
 * STYLE_SELECTOR_UNKNOWN) and for the completion lanes that offer the
 * browser's own names; it never grants anything, admission by mode is
 * the roster's and the validator's.
 */
import tier from '../../data/wide-selectors.json' with { type: 'json' };

const pseudoClasses = new Set(tier.pseudoClasses);
const pseudoElements = new Set(tier.pseudoElements);
const tags = new Set(tier.tags);

/**
 * Every pseudo-class name the tier knows, sorted, parens stripped.
 *
 * @return {string[]}
 */
export const widePseudoClasses = () => [...tier.pseudoClasses];

/**
 * Every pseudo-element name the tier knows, sorted.
 *
 * @return {string[]}
 */
export const widePseudoElements = () => [...tier.pseudoElements];

/**
 * Every HTML element name the tier knows, sorted.
 *
 * @return {string[]}
 */
export const wideTags = () => [...tier.tags];

/**
 * Whether a pseudo-class name exists (`hover`, never `:hover`).
 *
 * @param {string} name
 *   The bare name.
 *
 * @return {boolean}
 */
export const knowsPseudoClass = (name) => pseudoClasses.has(name);

/**
 * Whether a pseudo-element name exists (`marker`, never `::marker`).
 *
 * @param {string} name
 *   The bare name.
 *
 * @return {boolean}
 */
export const knowsPseudoElement = (name) => pseudoElements.has(name);

/**
 * Whether an HTML element name exists.
 *
 * @param {string} name
 *   The lowercase tag name.
 *
 * @return {boolean}
 */
export const knowsTag = (name) => tags.has(name);
