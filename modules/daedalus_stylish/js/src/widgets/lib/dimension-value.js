/**
 * Pure value logic for dimension inputs (the DimensionField's math half).
 *
 * Mirrors the server's dimension grammar (PropertyGroupBase::parseDimension)
 * so the client predicts exactly what the server will accept — prediction,
 * not enforcement; the server still validates every commit. On top of the
 * grammar this adds the pro-field mechanics: expression evaluation
 * (`16*2`, `4/3em`), the DevTools step scheme (the unit's grain / Shift
 * 10 / Alt a tenth of the grain / Cmd 100), the per-unit feel (what a
 * gesture-generated value may carry: whole pixels, hundredths of a rem),
 * and clamping from the property definition (`negative`) plus
 * style-guide constraints (min/max), with the server's comparability rule
 * (px, pt, rem, em interconvert; anything else only against itself).
 *
 * Anything this module cannot parse — calc(), var(), keywords, multi-value
 * shorthands — returns null; the field degrades to plain text rather than
 * silently normalizing (the honesty rule).
 */

/**
 * Units accepted in dimension values (server's PropertyGroupBase::UNITS).
 */
export const UNITS = [
  'px',
  'rem',
  'em',
  '%',
  'vw',
  'vh',
  'vmin',
  'vmax',
  'ch',
  'ex',
  'pt',
  'svh',
  'svw',
  'dvh',
  'dvw',
  'lvh',
  'lvw',
];

/**
 * Units offered when the style guide declares none.
 */
export const DEFAULT_UNITS = ['px', 'em', 'rem', '%'];

/**
 * Px-per-unit for the interconvertible units (server's PX_BASIS).
 */
const PX_BASIS = { px: 1, pt: 4 / 3, rem: 16, em: 16 };

// Longest-first so 'rem' wins over 'em' ('4rem' is 4rem, not '4r' + 'em')
// and 'vmin'/'svh' win over their prefixes.
const UNITS_BY_LENGTH = [...UNITS].sort((a, b) => b.length - a.length);

// The server's component regex, verbatim semantics: an optional sign, a
// number ('16', '1.5', '.5'), an optional known unit, nothing else.
const DIMENSION = new RegExp(
  `^(-?(?:\\d+(?:\\.\\d+)?|\\.\\d+))(${UNITS.join('|')})?$`,
);

/**
 * Parses one CSS dimension component.
 *
 * @param {*} text
 *   The raw value ('16px', '.5rem', '-4px', '0', '1.5').
 * @return {?{number: number, unit: string}}
 *   The parsed value — unit is '' for a bare number — or null for anything
 *   outside the strict grammar (keywords, calc()/var(), shorthands,
 *   unknown units, spaces inside the component).
 */
export const parseDimension = (text) => {
  if (typeof text !== 'string') {
    return null;
  }
  const match = text.trim().match(DIMENSION);
  if (!match) {
    return null;
  }
  return { number: Number(match[1]), unit: match[2] ?? '' };
};

/**
 * Renders a number without float noise: at most 4 decimals, no trailing
 * zeros (4/3 → '1.3333', 0.30000000000000004 → '0.3').
 */
export const formatNumber = (number) => String(Number(number.toFixed(4)));

/**
 * Formats a number + unit back to a CSS component ('' unit → bare number).
 */
export const formatDimension = (number, unit = '') =>
  `${formatNumber(number)}${unit}`;

/**
 * The input's face for a shown value: the number alone when the text is
 * a dimension with a unit (the unit select beside the input carries the
 * unit, so '8.5rem' reads '8.5'), the text verbatim otherwise (bare
 * numbers, and everything outside the grammar: calc(), keywords,
 * shorthands, mid-typing states). The number keeps its spelling ('.5rem'
 * faces '.5', '16.0px' faces '16.0'): a face never rewrites what it can
 * parse.
 *
 * @param {*} text
 *   The shown value.
 * @return {string}
 */
export const numberFace = (text) => {
  const trimmed = typeof text === 'string' ? text.trim() : '';
  const parsed = parseDimension(trimmed);
  return parsed !== null && parsed.unit !== ''
    ? trimmed.slice(0, -parsed.unit.length)
    : trimmed;
};

/**
 * Units whose gesture-generated values are whole numbers: a fraction of
 * a pixel is nothing a screen can draw.
 */
const WHOLE_UNITS = ['px', 'pt'];

/**
 * Units whose gesture-generated values carry hundredths: font-relative
 * units are small (one rem is sixteen pixels), so a tenth is a coarse
 * step and a hundredth (0.16px at the root) is finer than anyone can
 * see. Bare numbers (line-height) live here too.
 */
const HUNDREDTH_UNITS = ['rem', 'em', 'ch', 'ex', ''];

/**
 * Decimal places a gesture-generated value may carry in a unit: 0 for
 * pixels and points, 2 for the font-relative units and bare numbers, 1
 * for everything else (percentages and the viewport units). Typed
 * values are the exact-value path and never pass through here.
 *
 * @param {string} unit
 * @return {number}
 */
export const unitPrecision = (unit) => {
  if (WHOLE_UNITS.includes(unit)) {
    return 0;
  }
  return HUNDREDTH_UNITS.includes(unit) ? 2 : 1;
};

/**
 * Rounds a gesture-generated value to its unit's precision.
 *
 * @param {number} number
 * @param {string} unit
 * @return {number}
 */
export const quantize = (number, unit) =>
  Number(number.toFixed(unitPrecision(unit)));

/**
 * The unmodified step in a unit: 1 for whole and tenth units (a pixel, a
 * percent), 0.1 for the font-relative units and bare numbers (a whole
 * rem per press is too coarse to steer with).
 *
 * @param {string} unit
 * @return {number}
 */
export const unitGrain = (unit) => (unitPrecision(unit) === 2 ? 0.1 : 1);

/**
 * Converts a bound (a dimension string or bare number) into `unit`.
 *
 * @return {?number}
 *   The bound's number expressed in `unit`, or null when the two are not
 *   comparable — same rule as the server's compare(): identical units
 *   always compare, px/pt/rem/em interconvert, everything else does not.
 */
const boundInUnit = (bound, unit) => {
  const parsed = parseDimension(String(bound));
  if (parsed === null) {
    return null;
  }
  if (parsed.unit === unit) {
    return parsed.number;
  }
  if (unit in PX_BASIS && parsed.unit in PX_BASIS) {
    return (parsed.number * PX_BASIS[parsed.unit]) / PX_BASIS[unit];
  }
  return null;
};

/**
 * Compares a parsed value against a bound (spaceship semantics).
 *
 * @return {?number}
 *   -1, 0, or 1, or null when the units are not comparable.
 */
export const compareToBound = (number, unit, bound) => {
  const boundNumber = boundInUnit(bound, unit);
  if (boundNumber === null) {
    return null;
  }
  return Math.sign(number - boundNumber);
};

/**
 * Clamps a number (in its own unit) to the property's reality.
 *
 * Two clamp sources, both facts the server already enforces:
 * - definition.negative: absent/false floors the value at 0 (padding);
 *   true leaves negatives open (margin).
 * - constraints.min / constraints.max: style-guide bounds, converted into
 *   the value's unit when comparable. An incomparable bound (min '2ch'
 *   against a px value) is skipped — the widget cannot honestly clamp what
 *   it cannot compare; the server reports those on commit.
 *
 * @return {number}
 *   The clamped number, still in `unit`.
 */
export const clamp = (number, unit, definition = {}, constraints = {}) => {
  let result = number;
  if (!definition.negative && result < 0) {
    result = 0;
  }
  if (constraints.min !== undefined) {
    const min = boundInUnit(constraints.min, unit);
    if (min !== null && result < min) {
      result = min;
    }
  }
  if (constraints.max !== undefined) {
    const max = boundInUnit(constraints.max, unit);
    if (max !== null && result > max) {
      result = max;
    }
  }
  return result;
};

/**
 * The DevTools step for a modifier state: Cmd/Ctrl 100, Shift 10, none
 * the unit's grain (unitGrain: 1 for px, 0.1 for rem), Alt a tenth of
 * the grain — the de facto standard for CSS value editing, with the
 * unmodified step sized to the unit.
 *
 * @param {{metaKey?: boolean, ctrlKey?: boolean, altKey?: boolean, shiftKey?: boolean}} modifiers
 *   Any object carrying the standard modifier flags (a keyboard or
 *   pointer event works as-is).
 * @param {string} unit
 *   The unit the gesture works in (px by default: the classic grid).
 */
export const stepSize = (modifiers = {}, unit = 'px') => {
  if (modifiers.metaKey || modifiers.ctrlKey) {
    return 100;
  }
  const grain = unitGrain(unit);
  if (modifiers.altKey) {
    return grain / 10;
  }
  if (modifiers.shiftKey) {
    return 10;
  }
  return grain;
};

/**
 * Number of decimal places a value carries (float-noise-free, via the
 * formatted representation).
 */
const decimalsOf = (number) => {
  const text = formatNumber(number);
  const dot = text.indexOf('.');
  return dot === -1 ? 0 : text.length - dot - 1;
};

/**
 * One arrow/stepper/scrub increment, DevTools-style.
 *
 * Plain addition then rounding to the operands' precision, so
 * 1.15 + 0.1 is 1.25, never 1.2499999999999998.
 *
 * @param {number} number
 *   The current value.
 * @param {number} direction
 *   +1 or -1 (or a scrub delta in steps).
 * @param {number} step
 *   The step size (stepSize()).
 * @return {number}
 */
export const applyStep = (number, direction, step) => {
  const decimals = Math.max(decimalsOf(number), decimalsOf(step));
  return Number((number + direction * step).toFixed(decimals));
};

/**
 * The unit list a dimension field offers.
 *
 * Style-guide `units` when declared, else the default four; the value's
 * current unit is always included so an existing value never renders as an
 * impossible state ('' — a bare number — is not a listed option).
 *
 * @return {string[]}
 */
export const unitOptions = (constraints = {}, currentUnit = null) => {
  const base =
    Array.isArray(constraints.units) && constraints.units.length
      ? [...constraints.units]
      : [...DEFAULT_UNITS];
  if (currentUnit && !base.includes(currentUnit)) {
    base.push(currentUnit);
  }
  return base;
};

/**
 * The unit an open field works in while its shown value carries none
 * (empty, mid-typing text): the one precedence for the select's face,
 * a bare number's settle, and every gesture's unit. An explicit pick
 * made while empty wins, then the unit the field last parsed (so `4/3`
 * typed over `16em` stays em), then the vocabulary's own unit (the
 * unit its stops share: rem stops open the select on rem), then the
 * ghost's (what the element wears beneath the unset row, shown whole
 * beside the select when the two disagree), then the first offered
 * unit. The vocabulary beats the ghost because the stops are what the
 * field magnetizes to and what the guide curated; a px computed default
 * beneath a rem scale is a fact to show, never the unit to type in.
 *
 * @param {{picked?: ?string, last?: ?string, spaceUnit?: ?string,
 *   ghostUnit?: ?string, options?: Array<string>}} context
 *
 * @return {string}
 */
export const contextUnit = ({
  picked = null,
  last = null,
  spaceUnit = null,
  ghostUnit = null,
  options = [],
} = {}) =>
  picked ?? last ?? spaceUnit ?? ghostUnit ?? options[0] ?? DEFAULT_UNITS[0];

/**
 * Horizontal pixels of pointer travel per scrub step (scrubAdvance).
 */
export const SCRUB_PIXELS_PER_STEP = 3;

/**
 * Classifies a field's shown text for the DimensionField's chrome.
 *
 * @param {*} text
 *   The shown value.
 * @return {{mode: string, number: ?number, unit: ?string}}
 *   - mode 'empty': nothing set; gestures start from 0 in the field's
 *     fallback unit (number 0, unit null).
 *   - mode 'dimension': within the grammar; number and unit ('' for a
 *     bare number) carry the parse.
 *   - mode 'text': outside the grammar (calc()/var(), keywords,
 *     shorthands, mid-typing states); the field degrades to plain text.
 */
export const fieldState = (text) => {
  const trimmed = typeof text === 'string' ? text.trim() : '';
  if (trimmed === '') {
    return { mode: 'empty', number: 0, unit: null };
  }
  const parsed = parseDimension(trimmed);
  if (parsed === null) {
    return { mode: 'text', number: null, unit: null };
  }
  return { mode: 'dimension', number: parsed.number, unit: parsed.unit };
};

/**
 * Resolves typed text at settle time (blur/Enter) into the value to
 * commit.
 *
 * The pro-field typing contract:
 * - Empty unsets ('', which the caller maps to null).
 * - A dimension WITH a unit commits exactly as typed ('16.0px' stays
 *   '16.0px': the exact-value path never rewrites what it can parse).
 * - A bare number or an expression keeps the current unit unless the text
 *   names one ('32' in a px field becomes '32px'; '4/3em' switches to
 *   em): evaluateExpression's "unit null means keep the current unit".
 * - Anything else (calc()/var(), keywords, shorthands) passes through
 *   untouched: honest degradation, the server judges it.
 *
 * No clamping here: typed values are the exact-value path; a silent
 * client clamp would hide what the server's validation reports.
 *
 * @param {*} text
 *   The typed text.
 * @param {string} fallbackUnit
 *   The unit to keep when the text names none ('' commits a bare number).
 * @return {{kind: string, text: string}}
 *   kind 'unset' | 'dimension' | 'expression' | 'text', and the text to
 *   settle.
 */
export const settleText = (text, fallbackUnit = '') => {
  const trimmed = typeof text === 'string' ? text.trim() : '';
  if (trimmed === '') {
    return { kind: 'unset', text: '' };
  }
  const parsed = parseDimension(trimmed);
  if (parsed !== null && parsed.unit !== '') {
    return { kind: 'dimension', text: trimmed };
  }
  const result = evaluateExpression(trimmed);
  if (result !== null) {
    return {
      kind: parsed !== null ? 'dimension' : 'expression',
      text: formatDimension(result.number, result.unit ?? fallbackUnit),
    };
  }
  return { kind: 'text', text: trimmed };
};

/**
 * One scrub tick: accumulate pointer travel, snap to the step grid.
 *
 * The accumulator (`raw`) preserves sub-step travel between ticks; the
 * reported number snaps to multiples of the active step (Shift-scrub
 * lands on 10s, Alt-scrub on 0.1s; the modifier changes grain
 * mid-drag). The caller owns clamping and pinning the accumulator at a
 * clamp so there is no rubber-band distance to scrub back through.
 *
 * @param {number} raw
 *   The running accumulator (starts at the value under the pointer).
 * @param {number} movementX
 *   The tick's horizontal pointer travel (PointerEvent.movementX).
 * @param {{metaKey?: boolean, ctrlKey?: boolean, altKey?: boolean, shiftKey?: boolean}} modifiers
 *   The tick's modifier state (the pointer event works as-is).
 * @param {string} unit
 *   The unit the scrub works in (sizes the unmodified step, stepSize).
 * @return {{raw: number, number: number, step: number}}
 */
export const scrubAdvance = (raw, movementX, modifiers = {}, unit = 'px') => {
  const step = stepSize(modifiers, unit);
  const advanced = raw + (movementX / SCRUB_PIXELS_PER_STEP) * step;
  return {
    raw: advanced,
    number: Number((Math.round(advanced / step) * step).toFixed(4)),
    step,
  };
};

/**
 * Evaluates an arithmetic expression over at most one unit.
 *
 * The pro-field grammar: numbers with an optional attached unit, + - * /,
 * parentheses, unary minus. One unit total; it tags the result wherever it
 * appears ('4/3em' → 1.3333em, '16px*2' → 32px). A second distinct-or-same
 * unit, an unknown token, a non-finite result (division by zero) all
 * return null — the field then falls back to honest text handling.
 *
 * @param {*} text
 *   The typed expression. A plain dimension ('16px') is a valid
 *   expression of one term.
 * @return {?{number: number, unit: ?string}}
 *   The result; unit is null when the expression carries none (the field
 *   keeps its current unit) and the typed unit otherwise (typed unit
 *   switches the unit).
 */
export const evaluateExpression = (text) => {
  if (typeof text !== 'string') {
    return null;
  }
  const tokens = tokenize(text);
  if (tokens === null) {
    return null;
  }
  const state = { tokens, position: 0, unit: null, valid: true };
  const number = parseSum(state);
  if (
    !state.valid ||
    state.position !== state.tokens.length ||
    !Number.isFinite(number)
  ) {
    return null;
  }
  return { number, unit: state.unit };
};

const tokenize = (text) => {
  const tokens = [];
  let index = 0;
  while (index < text.length) {
    const char = text[index];
    if (/\s/.test(char)) {
      index += 1;
      continue;
    }
    if ('+-*/()'.includes(char)) {
      tokens.push({ type: char });
      index += 1;
      continue;
    }
    const number = /^(?:\d+(?:\.\d+)?|\.\d+)/.exec(text.slice(index));
    if (!number) {
      return null;
    }
    index += number[0].length;
    const rest = text.slice(index);
    const unit = UNITS_BY_LENGTH.find(
      (candidate) =>
        rest.startsWith(candidate) &&
        !/[a-zA-Z]/.test(rest[candidate.length] ?? ''),
    );
    if (unit) {
      index += unit.length;
    } else if (/^[a-zA-Z]/.test(rest)) {
      // A letter run that is no known unit ('calc', 'auto', '3foo'):
      // outside the grammar.
      return null;
    }
    tokens.push({
      type: 'number',
      value: Number(number[0]),
      unit: unit ?? null,
    });
  }
  return tokens;
};

const parseSum = (state) => {
  let value = parseProduct(state);
  while (state.valid) {
    const token = state.tokens[state.position];
    if (!token || (token.type !== '+' && token.type !== '-')) {
      break;
    }
    state.position += 1;
    const right = parseProduct(state);
    value = token.type === '+' ? value + right : value - right;
  }
  return value;
};

const parseProduct = (state) => {
  let value = parseUnary(state);
  while (state.valid) {
    const token = state.tokens[state.position];
    if (!token || (token.type !== '*' && token.type !== '/')) {
      break;
    }
    state.position += 1;
    const right = parseUnary(state);
    value = token.type === '*' ? value * right : value / right;
  }
  return value;
};

const parseUnary = (state) => {
  const token = state.tokens[state.position];
  if (token?.type === '-') {
    state.position += 1;
    return -parseUnary(state);
  }
  return parsePrimary(state);
};

const parsePrimary = (state) => {
  const token = state.tokens[state.position];
  if (!token) {
    state.valid = false;
    return NaN;
  }
  if (token.type === 'number') {
    state.position += 1;
    if (token.unit !== null) {
      if (state.unit !== null) {
        // Two units — even the same one twice — is not a dimension
        // expression this field can honestly resolve.
        state.valid = false;
        return NaN;
      }
      state.unit = token.unit;
    }
    return token.value;
  }
  if (token.type === '(') {
    state.position += 1;
    const value = parseSum(state);
    if (state.tokens[state.position]?.type !== ')') {
      state.valid = false;
      return NaN;
    }
    state.position += 1;
    return value;
  }
  state.valid = false;
  return NaN;
};
