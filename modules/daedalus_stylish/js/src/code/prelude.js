import {
  ANCHOR,
  CHILD,
  NEXT,
  SUBSEQUENT,
  SUFFIX,
  parseSelector,
  printCompound,
} from '../../../../daedalus_ui/js/edit_mode/materials/relative-selector.js';

/**
 * A row's prelude: the nested-rule head a designer would write for it.
 *
 * Code mode is the page's stylesheet in CSS-nesting form: every chrome
 * row is a nested block and its code face is that block's prelude,
 * spelled exactly as the dialect's parser reads nesting sugar
 * (DialectCssParser::parseRule: `&` is the row's parent, a bare relative
 * selector is glued behind it, an `@wrapper` prelude nests around the
 * rule). One spelling for the code view and the AI; the words the
 * naming spine mints ride beside it as the human label, never inside it.
 *
 * The spelling, from the canonical selector and the guide's condition
 * table (`conditions[name].wrapper`, the same table the printers
 * dispatch on, so the row can never disagree with the print):
 *
 * - The element itself under a condition: `&` with the states glued
 *   (`&:hover`, `&:not(:last-child)`), the at-rule wrappers before it
 *   in compound order (`@container (max-width: 640px) &:hover`), which
 *   is the canonical axis order because the compound key is canonical.
 * - A selector row (a part, a chain, a type, a tag) at the default
 *   condition: the chain alone (`> pb-text:has(a)`, `p`), the nesting
 *   grammar's bare relative prelude.
 * - A selector row under a condition: the chain after `&` with the
 *   states glued to its last compound (`& > pb-text:has(a):hover`),
 *   because a state belongs on the addressed element and the dialect
 *   refuses it anywhere else.
 * - The owner's own generated box: `&::before`, its states before the
 *   pseudo-element (`&:hover::before`); a chain's pseudo-element the same
 *   (`& > pb-text:hover::after`), the terminal position CSS gives it.
 * - A condition part the guide does not declare spells as `:name`, so a
 *   row over inert data still reads honestly instead of printing nothing
 *   (the printers' emit gate is theirs; a row must exist to be removed).
 * - A selector outside the grammar prints verbatim, as the map carries
 *   it.
 *
 * The parts are for chrome: each `>`, `+` and `~` combinator is its own
 * part so the renderer can chip it with the title the symbol needs
 * (selector-chrome.js), each at-rule wrapper is its own part, and the
 * rest is text. Concatenating the parts' texts IS the prelude string,
 * so what a test reads back, what a copy carries and what the eye sees
 * are one text.
 */

/** A part kind: an at-rule wrapper (`@container (max-width: 640px)`). */
export const WRAPPER = 'wrapper';
/** A part kind: a symbol combinator (`>`, `+`, `~`) wearing a chip. */
export const COMBINATOR = 'combinator';
/** A part kind: plain prelude text. */
export const TEXT = 'text';

const COMBINATOR_SYMBOLS = { [CHILD]: '>', [NEXT]: '+', [SUBSEQUENT]: '~' };

/**
 * A condition's at-rule wrappers and its glued states, in compound
 * order (the canonical axis order).
 */
const conditionPieces = (condition, guide) => {
  const wrappers = [];
  let states = '';
  if (typeof condition === 'string' && condition !== '' && condition !== 'default') {
    condition.split('+').forEach((part) => {
      const wrapper = guide?.conditions?.[part]?.wrapper;
      if (typeof wrapper === 'string' && wrapper.startsWith('@')) {
        wrappers.push(wrapper);
      } else if (typeof wrapper === 'string' && wrapper.startsWith(':')) {
        states += wrapper;
      } else {
        states += `:${part}`;
      }
    });
  }
  return { wrappers, states };
};

/**
 * Merges adjacent text parts, so a renderer and a reader see the fewest
 * pieces that still separate the chipped and wrapped ones.
 */
const mergeText = (parts) =>
  parts.reduce((merged, part) => {
    const last = merged[merged.length - 1];
    if (part.kind === TEXT && last?.kind === TEXT) {
      last.text += part.text;
    } else {
      merged.push({ ...part });
    }
    return merged;
  }, []);

/**
 * The prelude as parts for chrome rendering.
 *
 * @param {?string} selector
 *   The row's canonical relative selector, or null (or '') for the
 *   element itself.
 * @param {string} condition
 *   The row's condition: 'default' or a canonical compound ('hover',
 *   'hover+narrow').
 * @param {?object} guide
 *   The style guide payload (conditions: name => {wrapper, ...}).
 *
 * @return {Array<{kind: string, text: string, combinator?: string}>}
 *   The parts in reading order; their texts concatenate to prelude().
 */
export const preludeParts = (selector, condition = 'default', guide = null) => {
  const { wrappers, states } = conditionPieces(condition, guide);
  const conditioned = wrappers.length > 0 || states !== '';
  const parts = [];
  wrappers.forEach((wrapper) => {
    parts.push({ kind: WRAPPER, text: wrapper }, { kind: TEXT, text: ' ' });
  });
  if (selector === null || selector === undefined || selector === '') {
    parts.push({ kind: TEXT, text: `${ANCHOR}${states}` });
    return mergeText(parts);
  }
  const parsed = parseSelector(selector);
  if (parsed === null) {
    parts.push({
      kind: TEXT,
      text: conditioned ? `${ANCHOR} ${selector}${states}` : selector,
    });
    return mergeText(parts);
  }
  const { compounds, pseudo } = parsed;
  const tail = `${states}${pseudo ? `${SUFFIX}${pseudo}` : ''}`;
  if (!compounds.length) {
    parts.push({ kind: TEXT, text: `${ANCHOR}${tail}` });
    return mergeText(parts);
  }
  if (conditioned) {
    parts.push({ kind: TEXT, text: `${ANCHOR} ` });
  }
  compounds.forEach((compound, index) => {
    if (index > 0) {
      parts.push({ kind: TEXT, text: ' ' });
    }
    const symbol = COMBINATOR_SYMBOLS[compound.combinator];
    if (symbol) {
      parts.push(
        { kind: COMBINATOR, text: symbol, combinator: compound.combinator },
        { kind: TEXT, text: ' ' },
      );
    }
    parts.push({ kind: TEXT, text: printCompound(compound) });
  });
  if (tail !== '') {
    parts.push({ kind: TEXT, text: tail });
  }
  return mergeText(parts);
};

/**
 * The prelude as one string: the parts' texts concatenated.
 *
 * @param {?string} selector
 *   The row's canonical relative selector, or null for the element.
 * @param {string} condition
 *   The row's condition ('default' or a canonical compound).
 * @param {?object} guide
 *   The style guide payload.
 *
 * @return {string}
 *   The nested-rule prelude.
 */
export const prelude = (selector, condition = 'default', guide = null) =>
  preludeParts(selector, condition, guide)
    .map((part) => part.text)
    .join('');
