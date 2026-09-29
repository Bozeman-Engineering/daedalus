/**
 * Self-asserting tests for the dimension-value logic behind the
 * DimensionField: parse/format (the server's grammar, mirrored),
 * expression evaluation, DevTools step math, and clamping from the
 * property definition (`negative`) plus style-guide constraints
 * (min/max, with the server's px/pt/rem/em comparability rule).
 *
 * Run directly: `node tests/js/dimension-value.test.mjs`. Exits non-zero
 * on failure.
 */
import { strict as assert } from 'node:assert';
import {
  applyStep,
  clamp,
  compareToBound,
  contextUnit,
  convertLength,
  DEFAULT_UNITS,
  evaluateExpression,
  fieldState,
  formatDimension,
  formatNumber,
  lengthIn,
  numberFace,
  openChrome,
  parseDimension,
  quantize,
  SCRUB_PIXELS_PER_STEP,
  scrubAdvance,
  scrubRate,
  seedAnchor,
  settleText,
  stepSize,
  unitGrain,
  unitMemory,
  unitOptions,
  unitPrecision,
  UNITS,
} from '../../js/src/widgets/lib/dimension-value.js';

const tests = [];
const test = (name, run) => tests.push([name, run]);

// --- parseDimension -------------------------------------------------------

test('parseDimension accepts the server grammar', () => {
  assert.deepEqual(parseDimension('16px'), { number: 16, unit: 'px' });
  assert.deepEqual(parseDimension('1.5rem'), { number: 1.5, unit: 'rem' });
  assert.deepEqual(parseDimension('.5rem'), { number: 0.5, unit: 'rem' });
  assert.deepEqual(parseDimension('-4px'), { number: -4, unit: 'px' });
  assert.deepEqual(parseDimension('-.25em'), { number: -0.25, unit: 'em' });
  assert.deepEqual(parseDimension('50%'), { number: 50, unit: '%' });
  assert.deepEqual(parseDimension('0'), { number: 0, unit: '' });
  assert.deepEqual(parseDimension('1.5'), { number: 1.5, unit: '' });
  assert.deepEqual(parseDimension('  16px  '), { number: 16, unit: 'px' }, 'surrounding whitespace is trimmed');
});

test('parseDimension round-trips every known unit', () => {
  UNITS.forEach((unit) => {
    assert.deepEqual(parseDimension(`2${unit}`), { number: 2, unit }, unit);
  });
});

test('parseDimension rejects what the field must degrade on', () => {
  // Everything here stays plain text in the widget — never normalized.
  const rejected = [
    'calc(100% - 8px)',
    'var(--space-m)',
    '8px 16px',
    'auto',
    'normal',
    'inherit',
    '10 px',
    '3foo',
    '3emx',
    'px',
    '',
    '  ',
    '2.',
    '--4px',
    '1,5rem',
  ];
  rejected.forEach((value) => assert.equal(parseDimension(value), null, JSON.stringify(value)));
  assert.equal(parseDimension(null), null);
  assert.equal(parseDimension(undefined), null);
  assert.equal(parseDimension(16), null, 'only strings parse — callers format first');
});

// --- format ---------------------------------------------------------------

test('formatNumber caps decimals and strips float noise', () => {
  assert.equal(formatNumber(4 / 3), '1.3333');
  assert.equal(formatNumber(0.1 + 0.2), '0.3');
  assert.equal(formatNumber(16), '16');
  assert.equal(formatNumber(-0.25), '-0.25');
  assert.equal(formatNumber(1.25), '1.25');
  assert.equal(formatNumber(0), '0');
});

test('formatDimension re-attaches the unit, bare for none', () => {
  assert.equal(formatDimension(16, 'px'), '16px');
  assert.equal(formatDimension(0.5, 'rem'), '0.5rem');
  assert.equal(formatDimension(1.5, ''), '1.5');
  assert.equal(formatDimension(1.5), '1.5');
});

test('parse/format round trip is stable', () => {
  ['16px', '0.5rem', '-4px', '50%', '1.5', '0'].forEach((value) => {
    const parsed = parseDimension(value);
    assert.equal(formatDimension(parsed.number, parsed.unit), value, value);
  });
});

// --- expression evaluation ------------------------------------------------

test('expressions evaluate with precedence and parentheses', () => {
  assert.deepEqual(evaluateExpression('16*2'), { number: 32, unit: null });
  assert.deepEqual(evaluateExpression('2+3*4'), { number: 14, unit: null });
  assert.deepEqual(evaluateExpression('(2+3)*4'), { number: 20, unit: null });
  assert.deepEqual(evaluateExpression('10 - 4 / 2'), { number: 8, unit: null });
  assert.deepEqual(evaluateExpression('-4'), { number: -4, unit: null });
  assert.deepEqual(evaluateExpression('2--3'), { number: 5, unit: null }, 'unary minus chains');
});

test('one unit tags the result wherever it appears', () => {
  assert.deepEqual(evaluateExpression('16px*2'), { number: 32, unit: 'px' });
  assert.deepEqual(evaluateExpression('2*16px'), { number: 32, unit: 'px' });
  const thirds = evaluateExpression('4/3em');
  assert.equal(thirds.unit, 'em');
  assert.ok(Math.abs(thirds.number - 4 / 3) < 1e-12);
  assert.deepEqual(evaluateExpression('50%/2'), { number: 25, unit: '%' });
  assert.deepEqual(evaluateExpression('16px'), { number: 16, unit: 'px' }, 'a plain dimension is a one-term expression');
  assert.deepEqual(evaluateExpression('-4px'), { number: -4, unit: 'px' });
  assert.deepEqual(evaluateExpression('16'), { number: 16, unit: null }, 'no unit typed leaves the unit alone');
});

test('expressions the field cannot honestly resolve return null', () => {
  const rejected = [
    '4px+1em', // Two different units.
    '4px+4px', // Even the same unit twice: ambiguous intent, server-invalid shape.
    '16/0', // Non-finite.
    'calc(100% - 8px)',
    'var(--x)*2',
    '16*',
    '*16',
    '(2+3',
    '2+3)',
    '16 24', // Adjacent numbers, no operator (shorthand-ish).
    'auto',
    '',
    '   ',
  ];
  rejected.forEach((value) => assert.equal(evaluateExpression(value), null, JSON.stringify(value)));
  assert.equal(evaluateExpression(undefined), null);
});

// --- DevTools step math ---------------------------------------------------

test('numberFace shows the number alone when a unit rides the select', () => {
  assert.equal(numberFace('8.5rem'), '8.5');
  assert.equal(numberFace('.5rem'), '.5', 'the spelling survives: a face never rewrites what it can parse');
  assert.equal(numberFace('16.0px'), '16.0');
  assert.equal(numberFace('-4px'), '-4');
  assert.equal(numberFace('50%'), '50');
  assert.equal(numberFace('  16px  '), '16', 'trimmed');
  assert.equal(numberFace('1.5'), '1.5', 'a bare number has no unit to drop');
  assert.equal(numberFace('0'), '0');
  assert.equal(numberFace('calc(100% - 2rem)'), 'calc(100% - 2rem)', 'outside the grammar: verbatim');
  assert.equal(numberFace('auto'), 'auto');
  assert.equal(numberFace('9r'), '9r', 'mid-typing text: verbatim');
  assert.equal(numberFace(''), '');
  assert.equal(numberFace(null), '');
});

test('unitPrecision: whole pixels, tenths of a rem, tenths elsewhere', () => {
  assert.equal(unitPrecision('px'), 0);
  assert.equal(unitPrecision('pt'), 0);
  assert.equal(unitPrecision('rem'), 1, '1.4rem is a padding; 1.41rem is nothing anyone wants');
  assert.equal(unitPrecision('em'), 1);
  assert.equal(unitPrecision('ch'), 1);
  assert.equal(unitPrecision('ex'), 1);
  assert.equal(unitPrecision(''), 1, 'bare numbers (line-height) carry tenths');
  assert.equal(unitPrecision('%'), 1);
  assert.equal(unitPrecision('vw'), 1);
  assert.equal(unitPrecision('dvh'), 1);
});

test('unitPrecision: the fine modifier gives the font-relative units hundredths, nothing else changes', () => {
  assert.equal(unitPrecision('rem', true), 2, 'Alt keeps the DevTools fine ladder for the rare case');
  assert.equal(unitPrecision('em', true), 2);
  assert.equal(unitPrecision('ch', true), 2);
  assert.equal(unitPrecision('ex', true), 2);
  assert.equal(unitPrecision('', true), 2);
  assert.equal(unitPrecision('px', true), 0, 'a fraction of a pixel is nothing a screen can draw');
  assert.equal(unitPrecision('pt', true), 0);
  assert.equal(unitPrecision('%', true), 1, 'a percent\'s fine step is already a tenth');
  assert.equal(unitPrecision('vw', true), 1);
  assert.equal(unitPrecision('rem', false), 1);
  assert.equal(unitPrecision('rem', undefined), 1, 'an absent flag is not fine');
});

test('quantize rounds a gesture value to its unit\'s precision', () => {
  assert.equal(quantize(12.384, 'px'), 12);
  assert.equal(quantize(12.5, 'px'), 13);
  assert.equal(quantize(8.656, 'rem'), 8.7);
  assert.equal(quantize(8.64, 'rem'), 8.6);
  assert.equal(quantize(1.41, 'rem'), 1.4, 'the free scrub\'s 1.41rem lands on 1.4rem');
  assert.equal(quantize(33.333, '%'), 33.3);
  assert.equal(quantize(1.456, ''), 1.5);
});

test('quantize under the fine modifier keeps hundredths of a rem, whole pixels still', () => {
  assert.equal(quantize(8.656, 'rem', true), 8.66);
  assert.equal(quantize(8.654, 'rem', true), 8.65);
  assert.equal(quantize(1.456, '', true), 1.46);
  assert.equal(quantize(12.384, 'px', true), 12);
  assert.equal(quantize(33.333, '%', true), 33.3);
});

test('unitGrain: a pixel or a percent per step, a tenth of a rem', () => {
  assert.equal(unitGrain('px'), 1);
  assert.equal(unitGrain('%'), 1);
  assert.equal(unitGrain('rem'), 0.1);
  assert.equal(unitGrain('em'), 0.1);
  assert.equal(unitGrain(''), 0.1);
});

test('stepSize sizes the unmodified and Alt steps to the unit', () => {
  assert.equal(stepSize({}, 'rem'), 0.1);
  assert.equal(stepSize({ altKey: true }, 'rem'), 0.01);
  assert.equal(stepSize({ shiftKey: true }, 'rem'), 10, 'Shift and Cmd keep their DevTools meaning in every unit');
  assert.equal(stepSize({ metaKey: true }, 'rem'), 100);
  assert.equal(stepSize({}, '%'), 1);
  assert.equal(stepSize({ altKey: true }, '%'), 0.1);
  assert.deepEqual(scrubAdvance(1, SCRUB_PIXELS_PER_STEP, {}, 'rem'), { raw: 1.1, number: 1.1, step: 0.1 }, 'a rem scrub walks tenths');
  assert.equal(scrubAdvance(1, 2, { altKey: true }, 'rem').number, 1.01, 'Alt-scrub in rem lands on hundredths, never a third decimal');
});

test('stepSize follows the DevTools modifier scheme', () => {
  assert.equal(stepSize({}), 1);
  assert.equal(stepSize(), 1);
  assert.equal(stepSize({ shiftKey: true }), 10);
  assert.equal(stepSize({ altKey: true }), 0.1);
  assert.equal(stepSize({ metaKey: true }), 100);
  assert.equal(stepSize({ ctrlKey: true }), 100);
  assert.equal(stepSize({ metaKey: true, shiftKey: true }), 100, 'largest modifier wins');
  assert.equal(stepSize({ altKey: true, shiftKey: true }), 0.1, 'alt means fine grain even with shift down');
});

test('scrubRate is the unit-independent multiplier twin of stepSize', () => {
  assert.equal(scrubRate({}), 1);
  assert.equal(scrubRate(), 1);
  assert.equal(scrubRate({ shiftKey: true }), 10);
  assert.equal(scrubRate({ altKey: true }), 0.1);
  assert.equal(scrubRate({ metaKey: true }), 100);
  assert.equal(scrubRate({ ctrlKey: true }), 100);
  assert.equal(scrubRate({ metaKey: true, shiftKey: true }), 100, 'largest modifier wins, as in stepSize');
  assert.equal(scrubRate({ altKey: true, shiftKey: true }), 0.1, 'alt means fine even with shift down, as in stepSize');
  for (const modifiers of [{}, { shiftKey: true }, { altKey: true }, { metaKey: true }, { metaKey: true, shiftKey: true }, { altKey: true, shiftKey: true }]) {
    assert.equal(scrubRate(modifiers), stepSize(modifiers, 'px') / stepSize({}, 'px'), 'the ladder is stepSize relative to the px grain');
  }
});

test('applyStep adds without float noise', () => {
  assert.equal(applyStep(1.15, 1, 0.1), 1.25);
  assert.equal(applyStep(0.3, -1, 0.1), 0.2);
  assert.equal(applyStep(16, 1, 10), 26);
  assert.equal(applyStep(16, -1, 100), -84);
  assert.equal(applyStep(0, -1, 0.1), -0.1);
  assert.equal(applyStep(1.25, 1, 1), 2.25, 'precision of the value survives a coarse step');
  assert.equal(applyStep(5, 3, 1), 8, 'scrub deltas ride the same math');
});

// --- clamping -------------------------------------------------------------

test('definition.negative floors at zero, or does not', () => {
  const padding = {}; // No negative flag: padding-like.
  const margin = { negative: true };
  assert.equal(clamp(-5, 'px', padding), 0);
  assert.equal(clamp(-5, 'px', margin), -5);
  assert.equal(clamp(5, 'px', padding), 5);
  assert.equal(clamp(0, 'px', padding), 0);
});

test('guide min/max clamp in the value\'s own unit', () => {
  assert.equal(clamp(2, 'px', { negative: true }, { min: '4px' }), 4);
  assert.equal(clamp(100, 'px', {}, { max: '64px' }), 64);
  assert.equal(clamp(32, 'px', {}, { min: '4px', max: '64px' }), 32);
  // Cross-unit bounds convert via the px basis: 0.25rem = 4px, 4rem = 64px.
  assert.equal(clamp(2, 'px', { negative: true }, { min: '0.25rem' }), 4);
  assert.equal(clamp(100, 'px', {}, { max: '4rem' }), 64);
  assert.equal(clamp(6, 'rem', {}, { max: '64px' }), 4);
  assert.equal(clamp(9, 'pt', {}, { max: '8px' }), 6, 'pt converts at 4/3 px');
  // Unitless bounds against unitless values (line-height style).
  assert.equal(clamp(0.5, '', {}, { min: 1 }), 1);
  assert.equal(clamp(3, '', {}, { max: '2' }), 2);
});

test('incomparable or unparseable bounds never clamp', () => {
  // The server reports STYLE_VALUE_NOT_COMPARABLE on commit; the widget
  // must not pretend it can do the math.
  assert.equal(clamp(2, 'px', { negative: true }, { min: '2ch' }), 2);
  assert.equal(clamp(200, '%', {}, { max: '64px' }), 200);
  assert.equal(clamp(2, 'px', { negative: true }, { min: 'auto' }), 2);
  assert.equal(clamp(-3, '', { negative: true }, { min: '1rem' }), -3, 'bare numbers only compare with bare bounds');
});

test('negative floor and guide bounds compose', () => {
  assert.equal(clamp(-5, 'px', {}, { min: '4px' }), 4, 'floor at zero, then the guide raises it');
  assert.equal(clamp(-5, 'px', { negative: true }, { min: '-8px' }), -5, 'negative space stays open within the bound');
  assert.equal(clamp(-12, 'px', { negative: true }, { min: '-8px' }), -8);
});

// --- compareToBound -------------------------------------------------------

test('compareToBound mirrors the server comparability rule', () => {
  assert.equal(compareToBound(2, 'px', '4px'), -1);
  assert.equal(compareToBound(4, 'px', '4px'), 0);
  assert.equal(compareToBound(8, 'px', '4px'), 1);
  assert.equal(compareToBound(1, 'rem', '16px'), 0);
  assert.equal(compareToBound(12, 'pt', '16px'), 0);
  assert.equal(compareToBound(50, '%', '4px'), null);
  assert.equal(compareToBound(2, 'ch', '4px'), null);
  assert.equal(compareToBound(1.5, '', '1.5'), 0);
});

// --- unit options ---------------------------------------------------------

test('unitOptions: guide units when declared, defaults otherwise', () => {
  assert.deepEqual(unitOptions(), DEFAULT_UNITS);
  assert.deepEqual(unitOptions({}), ['px', 'em', 'rem', '%']);
  assert.deepEqual(unitOptions({ units: ['rem', 'px'] }), ['rem', 'px']);
  assert.deepEqual(unitOptions({ units: [] }), DEFAULT_UNITS, 'an empty declaration is no declaration');
});

test('unitOptions keeps the current unit representable', () => {
  assert.deepEqual(unitOptions({ units: ['rem'] }, 'vw'), ['rem', 'vw']);
  assert.deepEqual(unitOptions({}, 'ch'), ['px', 'em', 'rem', '%', 'ch']);
  assert.deepEqual(unitOptions({ units: ['rem'] }, 'rem'), ['rem'], 'no duplicate');
  assert.deepEqual(unitOptions({}, ''), DEFAULT_UNITS, 'a bare number adds no unit option');
  assert.deepEqual(unitOptions({}, null), DEFAULT_UNITS);
});

// --- field state ----------------------------------------------------------

test('fieldState classifies the shown text for the field chrome', () => {
  assert.deepEqual(fieldState(''), { mode: 'empty', number: 0, unit: null });
  assert.deepEqual(fieldState('   '), { mode: 'empty', number: 0, unit: null });
  assert.deepEqual(fieldState(null), { mode: 'empty', number: 0, unit: null });
  assert.deepEqual(fieldState('16px'), { mode: 'dimension', number: 16, unit: 'px' });
  assert.deepEqual(fieldState('1.5'), { mode: 'dimension', number: 1.5, unit: '' });
  assert.deepEqual(fieldState('-4em'), { mode: 'dimension', number: -4, unit: 'em' });
  assert.deepEqual(fieldState('calc(100% - 8px)'), { mode: 'text', number: null, unit: null });
  assert.deepEqual(fieldState('auto'), { mode: 'text', number: null, unit: null });
  assert.deepEqual(fieldState('8px 16px'), { mode: 'text', number: null, unit: null });
  assert.deepEqual(fieldState('16*'), { mode: 'text', number: null, unit: null }, 'mid-typing states are text');
});

// --- settle resolution ----------------------------------------------------

test('settleText: empty unsets', () => {
  assert.deepEqual(settleText('', 'px'), { kind: 'unset', text: '' });
  assert.deepEqual(settleText('   ', 'px'), { kind: 'unset', text: '' });
  assert.deepEqual(settleText(undefined, 'px'), { kind: 'unset', text: '' });
});

test('settleText: a dimension with a unit commits as typed', () => {
  assert.deepEqual(settleText('16.0px', 'em'), { kind: 'dimension', text: '16.0px' });
  assert.deepEqual(settleText('  .5rem ', 'px'), { kind: 'dimension', text: '.5rem' });
  assert.deepEqual(settleText('-4px', 'px'), { kind: 'dimension', text: '-4px' });
});

test('settleText: bare numbers keep the current unit', () => {
  assert.deepEqual(settleText('32', 'px'), { kind: 'dimension', text: '32px' });
  assert.deepEqual(settleText('-8', 'em'), { kind: 'dimension', text: '-8em' });
  assert.deepEqual(settleText('1.5', ''), { kind: 'dimension', text: '1.5' }, 'a bare context stays bare');
  assert.deepEqual(settleText('0'), { kind: 'dimension', text: '0' }, 'no fallback means bare');
});

test('settleText: expressions evaluate; a typed unit switches the unit', () => {
  assert.deepEqual(settleText('16*2', 'px'), { kind: 'expression', text: '32px' });
  assert.deepEqual(settleText('4/3em', 'px'), { kind: 'expression', text: '1.3333em' });
  assert.deepEqual(settleText('(2+3)*4', 'rem'), { kind: 'expression', text: '20rem' });
  assert.deepEqual(settleText('16*2', ''), { kind: 'expression', text: '32' });
});

test('settleText: everything else passes through untouched', () => {
  ['calc(100% - 8px)', 'var(--space-m)', 'auto', '8px 16px', '4px+1em', '16/0', '2.'].forEach((value) => {
    assert.deepEqual(settleText(value, 'px'), { kind: 'text', text: value }, JSON.stringify(value));
  });
});

// --- scrub math -----------------------------------------------------------

test('scrubAdvance accumulates travel and snaps to the step grid', () => {
  assert.deepEqual(scrubAdvance(16, SCRUB_PIXELS_PER_STEP, {}), { raw: 17, number: 17, step: 1 });
  assert.deepEqual(scrubAdvance(16, -2 * SCRUB_PIXELS_PER_STEP, {}), { raw: 14, number: 14, step: 1 });
  assert.deepEqual(scrubAdvance(16, SCRUB_PIXELS_PER_STEP, { shiftKey: true }), { raw: 26, number: 30, step: 10 }, 'coarse grain snaps to 10s');
  assert.deepEqual(scrubAdvance(1, SCRUB_PIXELS_PER_STEP, { altKey: true }), { raw: 1.1, number: 1.1, step: 0.1 });
  assert.deepEqual(scrubAdvance(0, SCRUB_PIXELS_PER_STEP, { metaKey: true }), { raw: 100, number: 100, step: 100 });
});

test('scrubAdvance keeps sub-step travel and strips float noise', () => {
  const first = scrubAdvance(16, 1, {});
  assert.equal(first.number, 16, 'a third of a step does not move the value');
  const second = scrubAdvance(first.raw, 1, {});
  const third = scrubAdvance(second.raw, 1, {});
  assert.equal(third.number, 17, 'three thirds do');
  assert.equal(scrubAdvance(0.2, SCRUB_PIXELS_PER_STEP, { altKey: true }).number, 0.3, 'no 0.30000000000000004');
});

// --- contextUnit -----------------------------------------------------------

test('contextUnit: a space that names its unit IS the unit; a pick or a remembered unit speaks only where it names none', () => {
  assert.equal(contextUnit({ spaceUnit: 'rem', picked: 'em', last: 'px' }), 'rem', 'a labeled field reads no memory');
  assert.equal(contextUnit({ spaceUnit: 'rem', last: 'px' }), 'rem', '`4/3` typed over a typed `10px` settles in the space\'s rem');
  assert.equal(contextUnit({ spaceUnit: '', picked: 'em' }), 'em', 'a bare-only table names no unit');
  assert.equal(contextUnit({ spaceUnit: null, picked: 'em', last: 'px' }), 'em', 'the select: an explicit pick first');
  assert.equal(contextUnit({ spaceUnit: null, last: 'px', options: ['rem', 'px'] }), 'px', 'then the last parsed unit: `4/3` typed over `16px` stays px');
});

test('openChrome: the space\'s unit as a label, the select where it names none, nothing on a unitless property', () => {
  assert.deepEqual(openChrome({ spaceUnit: 'rem' }), { unit: 'label', steppers: true });
  assert.deepEqual(openChrome({ spaceUnit: 'rem', mode: 'empty' }), { unit: 'label', steppers: true }, 'an empty field wears the space\'s unit');
  assert.deepEqual(openChrome({ spaceUnit: null }), { unit: 'select', steppers: true }, 'a free space offers the select');
  assert.deepEqual(openChrome({ spaceUnit: '' }), { unit: 'select', steppers: true }, 'a bare-only table names no unit');
  assert.deepEqual(openChrome(), { unit: 'select', steppers: true });
  assert.deepEqual(openChrome({ unitless: true, spaceUnit: 'rem' }), { unit: 'none', steppers: true }, 'a unitless property carries no unit, whatever its stops say');
});

test('openChrome: degraded text wears no chrome, except for the keystroke it is mid-typed', () => {
  assert.deepEqual(openChrome({ spaceUnit: 'rem', mode: 'text' }), { unit: 'none', steppers: false }, 'a per-side listing beside a rem label was the lie');
  assert.deepEqual(openChrome({ spaceUnit: null, mode: 'text' }), { unit: 'none', steppers: false }, 'a calc() on a free space too');
  assert.deepEqual(openChrome({ spaceUnit: 'rem', mode: 'text', typing: true }), { unit: 'label', steppers: true }, '`16*` in flight keeps the chrome');
  assert.deepEqual(openChrome({ spaceUnit: null, mode: 'text', typing: true }), { unit: 'select', steppers: true });
  assert.deepEqual(openChrome({ mode: 'text', typing: true, unitless: true }), { unit: 'none', steppers: true });
});

test('contextUnit falls to the offered list where the space names no unit; a placeholder has no say', () => {
  // A rem scale beneath a px computed default labels the field rem:
  // the stops are what the field magnetizes to; the placeholder is a
  // measurement and converts INTO rem (lengthIn), so nothing here reads
  // it.
  assert.equal(contextUnit({ spaceUnit: 'rem', options: ['px', 'em', 'rem', '%'] }), 'rem');
  assert.equal(contextUnit({ spaceUnit: null, options: ['rem', 'px'] }), 'rem', 'no vocabulary unit: the first offered');
  assert.equal(contextUnit({ options: ['em', 'px'] }), 'em', 'nothing else: the first offered unit');
  assert.equal(contextUnit(), 'px', 'the built-in list\'s first unit as the floor');
  assert.equal(contextUnit({ spaceUnit: 'rem', hintUnit: 'px' }), 'rem', 'the retired hint key is not read');
});

test('contextUnit keeps the select\'s explicit pick above its last parsed unit', () => {
  assert.equal(contextUnit({ picked: 'em', last: 'px' }), 'em');
  assert.equal(contextUnit({ picked: 'em', last: 'px', options: ['rem'] }), 'em', 'a pick beats the offered list too');
  assert.equal(contextUnit({ last: 'px' }), 'px', '`4/3` typed over `16px` stays px');
});

// --- convertLength / lengthIn ----------------------------------------------

// The canvas's measured bases (computed-read.js lengthBases): the
// document root's font size and the element's own, in px.
const ROOT_16 = { rootPx: 16, fontPx: 16 };
const ROOT_20 = { rootPx: 20, fontPx: 12 };

test('convertLength converts through the MEASURED root, never an assumed 16', () => {
  assert.equal(convertLength('32px', 'rem', ROOT_16), 2);
  assert.equal(convertLength('32px', 'rem', ROOT_20), 1.6, 'a 20px root: the same side is 1.6rem');
  assert.equal(convertLength('2rem', 'px', ROOT_20), 40, 'and back the other way through the same root');
  assert.equal(convertLength('24px', 'em', ROOT_20), 2, 'em converts through the element\'s own font size');
  assert.equal(convertLength('1.5em', 'rem', ROOT_20), 0.9, 'em to rem: 18px over a 20px root');
  assert.equal(convertLength('12pt', 'px', null), 16, 'points are physical: no base needed');
  assert.equal(convertLength('16px', 'px', null), 16, 'the same unit passes the number through');
  assert.equal(convertLength('1.5', '', null), 1.5, 'a bare number in a unitless field likewise');
  assert.equal(convertLength('17px', 'rem', ROOT_16), 1.0625, 'unrounded: the caller quantizes for a seed or a face');
});

test('convertLength answers null when incomparable or unmeasured', () => {
  assert.equal(convertLength('50%', 'rem', ROOT_16), null, 'a percentage never converts into rem');
  assert.equal(convertLength('2ch', 'px', ROOT_16), null);
  assert.equal(convertLength('16px', '%', ROOT_16), null, 'nor px into a percentage');
  assert.equal(convertLength('16', 'rem', ROOT_16), null, 'a bare number names no length');
  assert.equal(convertLength('32px', 'rem', null), null, 'no bases (off canvas): rem cannot be measured, so nothing is assumed');
  assert.equal(convertLength('32px', 'em', { rootPx: 16, fontPx: null }), null, 'an unmeasured em base likewise');
  assert.equal(convertLength('32px', 'rem', { rootPx: 0, fontPx: 16 }), null, 'a zero root is no root');
  assert.equal(convertLength('calc(1rem + 2px)', 'rem', ROOT_16), null, 'outside the grammar');
  assert.equal(convertLength(null, 'rem', ROOT_16), null);
  assert.equal(convertLength(undefined, 'rem', ROOT_16), null);
  assert.equal(convertLength('', 'px', ROOT_16), null);
});

test('lengthIn re-spells a measurement in the gesture\'s unit at the unit\'s precision', () => {
  assert.equal(lengthIn('32px', 'rem', ROOT_16), '2rem', 'the face beside a rem select reads 2, never 32px');
  assert.equal(lengthIn('32px', 'rem', ROOT_20), '1.6rem');
  assert.equal(lengthIn('17px', 'rem', ROOT_16), '1.1rem', 'rem precision: the face reads what an unmodified gesture starts from, 1.0625 is no rem a gesture may carry');
  assert.equal(lengthIn('18px', 'rem', ROOT_16), '1.1rem', '1.125 rounds to the tenth');
  assert.equal(lengthIn('0.9rem', 'px', ROOT_16), '14px', '14.4 shown whole');
  assert.equal(lengthIn('16px', 'px', null), '16px', 'the same unit reads as itself, unrewritten');
  assert.equal(lengthIn(' 16.0px ', 'px', null), '16.0px', 'a face never rewrites what it can parse');
  assert.equal(lengthIn('50%', 'rem', ROOT_16), null, 'incomparable: the caller shows it whole');
  assert.equal(lengthIn('32px', 'rem', null), null, 'unmeasured: the caller shows it whole');
  assert.equal(lengthIn('calc(1rem + 2px)', 'rem', ROOT_16), null);
  assert.equal(lengthIn(null, 'rem', ROOT_16), null);
  assert.equal(lengthIn('32px', null, ROOT_16), null, 'a space naming no unit converts to nothing');
});

// --- seedAnchor ------------------------------------------------------------

test('seedAnchor converts a px placeholder into the gesture\'s unit through the measured root', () => {
  // The theme's computed 16px side beneath a rem scale: the first tick
  // goes 1rem to 1.1rem, never 0 to 0.1rem.
  assert.equal(seedAnchor('16px', 'rem', ROOT_16), 1);
  assert.equal(seedAnchor('24px', 'rem', ROOT_16), 1.5);
  assert.equal(seedAnchor('24px', 'em', ROOT_16), 1.5);
  assert.equal(seedAnchor('32px', 'rem', ROOT_20), 1.6, 'the page\'s root, not 16');
  assert.equal(seedAnchor('24px', 'em', ROOT_20), 2, 'the element\'s own font size for em');
  assert.equal(seedAnchor('1.5rem', 'px', ROOT_16), 24, 'and back the other way');
  assert.equal(seedAnchor('12pt', 'px'), 16, 'points ride the physical basis with no bases at all');
  assert.equal(seedAnchor('16px', 'px'), 16, 'the same unit passes the number through');
  assert.equal(seedAnchor('1.5', ''), 1.5, 'a bare number seeds a unitless field as itself');
});

test('seedAnchor rounds the converted seed to the unit\'s precision', () => {
  assert.equal(seedAnchor('17px', 'rem', ROOT_16), 1.1, '1.0625 is no rem value a gesture may carry; the seed is the face\'s 1.1');
  assert.equal(seedAnchor('1.06rem', 'px', ROOT_16), 17, '16.96 shown whole');
  assert.equal(seedAnchor('0.9rem', 'px', ROOT_16), 14, '14.4 shown whole');
});

test('seedAnchor under the fine modifier seeds the hundredths the gesture will carry', () => {
  assert.equal(seedAnchor('17px', 'rem', ROOT_16, true), 1.06, 'Alt held at the start: the seed matches the fine ladder');
  assert.equal(seedAnchor('17px', 'rem', ROOT_16, false), 1.1);
  assert.equal(seedAnchor('1.06rem', 'px', ROOT_16, true), 17, 'pixels stay whole under the fine modifier');
  assert.equal(seedAnchor('50%', 'rem', ROOT_16, true), 0, 'incomparable still seeds 0');
});

test('seedAnchor seeds 0 for an incomparable, unmeasured or missing placeholder', () => {
  assert.equal(seedAnchor('50%', 'rem', ROOT_16), 0, 'a percentage never converts into rem');
  assert.equal(seedAnchor('2ch', 'px', ROOT_16), 0);
  assert.equal(seedAnchor('16px', '%', ROOT_16), 0, 'nor px into a percentage');
  assert.equal(seedAnchor('16px', 'rem'), 0, 'no bases (the satellite has no canvas): no root is assumed');
  assert.equal(seedAnchor('0', 'rem', ROOT_16), 0, 'a bare zero is zero in every unit');
  assert.equal(seedAnchor(null, 'rem', ROOT_16), 0, 'no placeholder: zero');
  assert.equal(seedAnchor(undefined, 'rem', ROOT_16), 0);
  assert.equal(seedAnchor('', 'px', ROOT_16), 0);
  assert.equal(seedAnchor('calc(1rem + 2px)', 'rem', ROOT_16), 0, 'outside the grammar: zero, never a guess');
});

// --- unitMemory ------------------------------------------------------------

test('unitMemory remembers a value that carries a unit and retires the pick', () => {
  assert.deepEqual(
    unitMemory(fieldState('16px'), { last: 'rem', picked: 'em' }),
    { last: 'px', picked: null },
  );
  assert.deepEqual(
    unitMemory(fieldState('1.5'), { last: 'px', picked: 'em', bareContext: true }),
    { last: '', picked: null },
    'a bare number where bare is a real context (line-height) is the context',
  );
  assert.deepEqual(
    unitMemory(fieldState('16px'), { last: 'rem', picked: 'em', typing: true }),
    { last: 'px', picked: null },
    'a parsed value supersedes the pick even mid-typing',
  );
});

test('unitMemory keeps the context while typing is in flight', () => {
  assert.deepEqual(
    unitMemory(fieldState('4/3'), { last: 'em', typing: true }),
    { last: 'em', picked: null },
    '`4/3` typed over `16em` stays em',
  );
  assert.deepEqual(
    unitMemory(fieldState(''), { last: 'px', picked: null, typing: true }),
    { last: 'px', picked: null },
    'a clear typed in the field keeps its unit',
  );
  assert.deepEqual(
    unitMemory(fieldState(''), { picked: 'em', typing: true }),
    { last: null, picked: 'em' },
    'a pick made while empty survives typing into the empty field',
  );
  assert.deepEqual(
    unitMemory(fieldState('12'), { last: 'rem', typing: true }),
    { last: 'rem', picked: null },
    'mid-typed digits where bare is no context keep the unit they will settle in',
  );
});

test('unitMemory forgets on any outside arrival that carries no unit', () => {
  // Element A wearing own padding: 16px, then element B with only
  // padding-top: 2rem under a rem scale: the field shows "top 2rem"
  // (text mode) and must not settle a typed 3 as 3px.
  assert.deepEqual(
    unitMemory(fieldState('top 2rem'), { last: 'px', picked: null }),
    { last: null, picked: null },
    'a per-side listing arriving from outside forgets the last unit',
  );
  assert.deepEqual(
    unitMemory(fieldState(''), { last: 'px', picked: 'em' }),
    { last: null, picked: null },
    'emptied from outside (an unset element, the row menu, an undo) forgets both',
  );
  assert.deepEqual(
    unitMemory(fieldState('calc(1rem + 2px)'), { last: 'px' }),
    { last: null, picked: null },
    'a committed expression outside the grammar carries no unit',
  );
  assert.deepEqual(
    unitMemory(fieldState('12'), { last: 'rem' }),
    { last: null, picked: null },
    'a bare number where bare is no context carries none either',
  );
  assert.deepEqual(unitMemory(fieldState('')), { last: null, picked: null }, 'nothing to forget is still nothing');
});

let failures = 0;
for (const [name, run] of tests) {
  try {
    run();
    console.log(`ok - ${name}`);
  } catch (error) {
    failures += 1;
    console.error(`not ok - ${name}`);
    console.error(error.message);
  }
}
if (failures) {
  console.error(`${failures} of ${tests.length} dimension-value tests failed.`);
  process.exit(1);
}
console.log(`${tests.length} dimension-value tests passed.`);
