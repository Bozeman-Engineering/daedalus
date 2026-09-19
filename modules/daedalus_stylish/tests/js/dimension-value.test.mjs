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
  DEFAULT_UNITS,
  evaluateExpression,
  fieldState,
  formatDimension,
  formatNumber,
  numberFace,
  parseDimension,
  quantize,
  SCRUB_PIXELS_PER_STEP,
  scrubAdvance,
  settleText,
  stepSize,
  unitGrain,
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

test('unitPrecision: whole pixels, hundredths of a rem, tenths elsewhere', () => {
  assert.equal(unitPrecision('px'), 0);
  assert.equal(unitPrecision('pt'), 0);
  assert.equal(unitPrecision('rem'), 2);
  assert.equal(unitPrecision('em'), 2);
  assert.equal(unitPrecision('ch'), 2);
  assert.equal(unitPrecision(''), 2, 'bare numbers (line-height) carry hundredths');
  assert.equal(unitPrecision('%'), 1);
  assert.equal(unitPrecision('vw'), 1);
  assert.equal(unitPrecision('dvh'), 1);
});

test('quantize rounds a gesture value to its unit\'s precision', () => {
  assert.equal(quantize(12.384, 'px'), 12);
  assert.equal(quantize(12.5, 'px'), 13);
  assert.equal(quantize(8.656, 'rem'), 8.66);
  assert.equal(quantize(8.654, 'rem'), 8.65);
  assert.equal(quantize(33.333, '%'), 33.3);
  assert.equal(quantize(1.456, ''), 1.46);
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

// --- contextUnit -----------------------------------------------------------

test('contextUnit prefers the vocabulary\'s unit over the ghost\'s and the offered list', () => {
  // A rem scale beneath a px computed default opens the select on rem:
  // the stops are what the field magnetizes to; the ghost shows whole.
  assert.equal(contextUnit({ spaceUnit: 'rem', ghostUnit: 'px', options: ['px', 'em', 'rem', '%'] }), 'rem');
  assert.equal(contextUnit({ spaceUnit: null, ghostUnit: 'px', options: ['rem', 'px'] }), 'px', 'no vocabulary unit: the ghost\'s');
  assert.equal(contextUnit({ options: ['em', 'px'] }), 'em', 'nothing else: the first offered unit');
  assert.equal(contextUnit(), 'px', 'the built-in list\'s first unit as the floor');
});

test('contextUnit keeps an explicit pick and the last parsed unit above the vocabulary', () => {
  assert.equal(contextUnit({ picked: 'em', last: 'px', spaceUnit: 'rem' }), 'em');
  assert.equal(contextUnit({ last: 'px', spaceUnit: 'rem' }), 'px', '`4/3` typed over `16px` stays px');
});
