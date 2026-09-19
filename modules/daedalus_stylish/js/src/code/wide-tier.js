import { lexer } from 'css-tree';

/**
 * The wide property tier's client half: csstree's embedded tables.
 *
 * The server recognizes every standard CSS property through the generated
 * wide-tier PropertyGroups (data/wide-properties.json, built from the
 * pinned mdn-data). The client deliberately does NOT receive that file —
 * zero drupalSettings growth — and instead reads csstree's embedded lexer
 * tables, which derive from the same pinned mdn-data (css-tree pins the
 * exact version the generator uses; the wide-properties test battery locks
 * the parity). The tables are a slight SUPERSET of the server's vocabulary
 * (csstree carries some non-standard, non-prefixed names the generator's
 * standard-only filter drops): the client is therefore permissive in the
 * safe direction — a typed superset-only property gets no client error,
 * commits, and the sovereign server rejects it. The dangerous direction
 * (server-recognized but client-flagged) is what the parity test forbids.
 */

/**
 * Grammar types the keyword walk treats as terminal.
 *
 * Mirrors the generator's ATOMS: expanding these would drown a property's
 * own vocabulary in generic noise (color expands to the full named-color
 * list; length/number/time carry no keywords worth offering).
 */
const TERMINAL_TYPES = new Set([
  'length',
  'length-percentage',
  'percentage',
  'time',
  'angle',
  'flex',
  'number',
  'integer',
  'color',
]);

/**
 * Maximum ref-expansion depth for the keyword walk (the generator's cap).
 */
const DEPTH_CAP = 12;

const hasOwn = (object, key) =>
  Object.prototype.hasOwnProperty.call(object ?? {}, key);

/**
 * Whether a property name is in the wide tier's client vocabulary.
 *
 * Vendor-prefixed names (and the '--*' custom-property entry) are out:
 * the server's generated tier is standard-only, and custom properties keep
 * their existing (unrecognized) posture.
 *
 * @param {string} property
 *   The CSS property name.
 *
 * @return {boolean}
 */
export const isWideProperty = (property) =>
  !property.startsWith('-') && hasOwn(lexer.properties, property);

let cachedNames = null;

/**
 * Every wide-tier property name, sorted, for property-name completion.
 *
 * @return {string[]}
 */
export const widePropertyNames = () => {
  if (cachedNames === null) {
    cachedNames = Object.keys(lexer.properties)
      .filter((name) => !name.startsWith('-'))
      .sort();
  }
  return cachedNames;
};

/**
 * Collects keyword terminals from one grammar node into `out`.
 */
const collectKeywords = (node, out, seen, depth) => {
  if (!node || depth > DEPTH_CAP) {
    return;
  }
  switch (node.type) {
    case 'Group':
      node.terms.forEach((term) => collectKeywords(term, out, seen, depth));
      return;

    case 'Multiplier':
      collectKeywords(node.term, out, seen, depth);
      return;

    case 'Keyword':
      out.add(node.name.toLowerCase());
      return;

    case 'Type': {
      if (TERMINAL_TYPES.has(node.name) || seen.has(`t:${node.name}`)) {
        return;
      }
      seen.add(`t:${node.name}`);
      collectKeywords(lexer.types[node.name]?.syntax, out, seen, depth + 1);
      seen.delete(`t:${node.name}`);
      return;
    }

    case 'Property': {
      if (seen.has(`p:${node.name}`)) {
        return;
      }
      seen.add(`p:${node.name}`);
      collectKeywords(
        lexer.properties[node.name]?.syntax,
        out,
        seen,
        depth + 1,
      );
      seen.delete(`p:${node.name}`);
      break;
    }

    default:
    // Functions, tokens, strings: no keywords to offer.
  }
};

const keywordCache = new Map();

/**
 * The keyword vocabulary of a wide-tier property's grammar, for value
 * completion.
 *
 * Walks the property's grammar in csstree's tables the way the generator
 * walks mdn-data: named <syntax> and <'property'> refs expand
 * (cycle-guarded, depth-capped), atom types stay terminal so a color- or
 * dimension-bearing grammar doesn't flood the popup with generic values.
 *
 * @param {string} property
 *   The CSS property name.
 *
 * @return {string[]}
 *   The grammar's keywords in first-seen order, [] for an unknown name.
 */
export const wideValueKeywords = (property) => {
  if (!isWideProperty(property)) {
    return [];
  }
  if (!keywordCache.has(property)) {
    const out = new Set();
    collectKeywords(lexer.properties[property]?.syntax, out, new Set(), 0);
    keywordCache.set(property, [...out]);
  }
  return keywordCache.get(property);
};
