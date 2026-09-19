/**
 * Finds the keyframes names a motion value references: the client twin of
 * KeyframesNames.php.
 *
 * PHP is authoritative (see its docblock for the grammar); this module
 * mirrors it so the lint, the completions and the usage reading agree
 * with what the server validates. KeyframesNamesTest locks the two over
 * tests/js/keyframes-cases.mjs. Change them together.
 */

/**
 * The properties whose values carry keyframes names.
 */
export const KEYFRAMES_PROPERTIES = ['animation-name', 'animation'];

const CSS_WIDE_KEYWORDS = [
  'inherit',
  'initial',
  'unset',
  'revert',
  'revert-layer',
];

const SHORTHAND_KEYWORDS = [
  'none',
  'ease',
  'linear',
  'ease-in',
  'ease-out',
  'ease-in-out',
  'step-start',
  'step-end',
  'normal',
  'reverse',
  'alternate',
  'alternate-reverse',
  'forwards',
  'backwards',
  'both',
  'running',
  'paused',
  'infinite',
  'auto',
];

const IDENT = /^-?[A-Za-z_][A-Za-z0-9_-]*$/;

/**
 * Splits a value at top-level separators; parentheses and quotes stay
 * intact, empty pieces are dropped.
 */
const split = (value, separators) => {
  const pieces = [];
  let current = '';
  let depth = 0;
  let quote = '';
  for (const char of value) {
    if (quote !== '') {
      if (char === quote) {
        quote = '';
      }
    } else if (char === '"' || char === "'") {
      quote = char;
    } else if (char === '(') {
      depth += 1;
    } else if (char === ')') {
      depth = Math.max(0, depth - 1);
    } else if (depth === 0 && separators.includes(char)) {
      if (current !== '') {
        pieces.push(current);
      }
      current = '';
      continue;
    }
    current += char;
  }
  if (current !== '') {
    pieces.push(current);
  }
  return pieces;
};

/**
 * Reads one token as a keyframes name, or null when it is not one.
 */
const nameComponent = (token, excluded) => {
  if (token === '') {
    return null;
  }
  const quoted = /^(["'])([\s\S]*)\1$/.exec(token);
  if (quoted) {
    return quoted[2] === '' ? null : quoted[2];
  }
  if (excluded.includes(token.toLowerCase()) || !IDENT.test(token)) {
    return null;
  }
  return token;
};

/**
 * The name component of one animation shorthand layer, or null.
 */
const shorthandName = (layer) => {
  for (const token of split(layer, ' \t\n\r\f')) {
    const name = nameComponent(token, [
      ...CSS_WIDE_KEYWORDS,
      ...SHORTHAND_KEYWORDS,
    ]);
    if (name !== null) {
      return name;
    }
  }
  return null;
};

/**
 * The keyframes names a value references, in order, deduplicated.
 *
 * @param {string} property
 *   The CSS property the value is written for.
 * @param {string} value
 *   The value as written.
 *
 * @return {string[]}
 *   The names; [] for any property outside KEYFRAMES_PROPERTIES.
 */
export const keyframesNamesInValue = (property, value) => {
  if (!KEYFRAMES_PROPERTIES.includes(property)) {
    return [];
  }
  const names = [];
  for (const layer of split(value, ',')) {
    const name =
      property === 'animation-name'
        ? nameComponent(layer.trim(), [...CSS_WIDE_KEYWORDS, 'none'])
        : shorthandName(layer);
    if (name !== null && !names.includes(name)) {
      names.push(name);
    }
  }
  return names;
};
