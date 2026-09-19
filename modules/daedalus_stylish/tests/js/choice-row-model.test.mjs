/**
 * Self-asserting tests for the choice row's pure half
 * (js/src/choice-row-model.js): the hide rule, the offer joined from the
 * widget stop table and the value space, the lit state normalized onto
 * the offer (segmentState or a caller-supplied mapper), the grip's
 * detent-scrub eligibility, and the painted-space rule.
 *
 * Run directly: `node tests/js/choice-row-model.test.mjs`. Exits non-zero
 * on failure.
 */
import { strict as assert } from 'node:assert';
import { EMPTY_MATERIALS, materialsFrom } from './materials-fixture.mjs';
import {
  choiceState,
  choiceStops,
  dimensionShaped,
  ghostFace,
  paintedSpace,
  restingValue,
  rowHidden,
  scrubbable,
} from '../../js/src/choice-row-model.js';
import { surfaceFor, valueSpaceFor } from '../../js/src/value-space.js';
import { positionAxes, propertyStops } from '../../js/src/widget-values.js';
import { STROKES, strokeState } from '../../js/src/widgets/lib/stroke-model.js';

const tests = [];
const test = (name, run) => tests.push([name, run]);

// --- fixtures -------------------------------------------------------------

// A strict guide minting a radius scale (numeric ramp), a shadow scale
// (text-shaped), and an empty scale; the stroke scale twins the width
// family's None through the guide's allowed '0'.
const guide = {
  enforcement: 'guarded',
  tokenScales: {
    radius: { label: 'Radius', prefix: 'radius', shape: 'dimension' },
    shadow: { label: 'Shadow', prefix: 'shadow', shape: 'text' },
    stroke: { label: 'Stroke', prefix: 'stroke', shape: 'dimension' },
    ghost: { label: 'Ghost', prefix: 'ghost', shape: 'dimension' },
    font: { label: 'Font family', prefix: 'font', shape: 'text' },
  },
  tokens: {
    radius: { sm: '4px', md: '8px', lg: '16px', full: '999px' },
    shadow: { sm: '0 1px 2px rgb(0 0 0 / 0.1)', md: '0 2px 8px rgb(0 0 0 / 0.15)', lg: '0 8px 24px rgb(0 0 0 / 0.2)' },
    stroke: { thin: '1px', medium: '2px', thick: '4px' },
    font: {
      body: 'Georgia, "Times New Roman", serif',
      heading: 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
      mono: '"SF Mono", Consolas, monospace',
    },
  },
};
const MATERIALS = materialsFrom(guide);
const DIMENSION = { type: 'dimension' };
const TEXT = { type: 'text' };

const spaceOf = (property, definition, constraints, options = {}) => {
  const space = valueSpaceFor(guide, MATERIALS, property, definition, constraints);
  const stops = propertyStops(guide, MATERIALS, property, definition, constraints);
  return { space, stops, offer: choiceStops(space, stops), surface: surfaceFor(space, options) };
};

// --- dimensionShaped / paintedSpace ---------------------------------------

test('dimensionShaped: lengths and numbers, nothing else', () => {
  assert.equal(dimensionShaped(DIMENSION), true);
  assert.equal(dimensionShaped({ type: 'number' }), true);
  assert.equal(dimensionShaped(TEXT), false);
  assert.equal(dimensionShaped({ type: 'keyword' }), false);
  assert.equal(dimensionShaped(null), false);
});

test('paintedSpace: color and image shapes paint, everything else faces as text', () => {
  assert.equal(paintedSpace({ type: 'color' }), true);
  assert.equal(paintedSpace({ type: 'image', media: true }), true);
  assert.equal(paintedSpace(DIMENSION), false);
  assert.equal(paintedSpace(TEXT), false);
  assert.equal(paintedSpace(null), false);
});

// --- rowHidden ------------------------------------------------------------

test('rowHidden: a closed space minting nothing hides unless a value is set', () => {
  const { space } = spaceOf('border-radius', DIMENSION, { scale: 'ghost' });
  assert.equal(space.kind, 'closed');
  assert.equal(space.stops.length, 0);
  assert.equal(rowHidden(space, null), true);
  assert.equal(rowHidden(space, ''), true);
  assert.equal(rowHidden(space, '  '), true, 'whitespace is no value');
  assert.equal(rowHidden(space, '3px'), false, 'a committed value keeps the row visible');
});

test('rowHidden: a free non-dimension space with nothing to offer hides', () => {
  // A text property with no fallback table and no constraint: basic mode
  // has no free text input to render for it.
  const space = valueSpaceFor(null, EMPTY_MATERIALS, 'font-family', TEXT, null);
  assert.equal(space.kind, 'free');
  assert.equal(rowHidden(space, null), true);
  assert.equal(rowHidden(space, 'serif'), false);
});

test('rowHidden: a free dimension space shows (the field is its surface)', () => {
  const space = valueSpaceFor(null, EMPTY_MATERIALS, 'margin-top', DIMENSION, null);
  assert.equal(space.kind, 'free');
  assert.equal(rowHidden(space, null), false);
  // The definition argument overrides the space's own.
  assert.equal(rowHidden(space, null, TEXT), true);
  assert.equal(rowHidden(valueSpaceFor(null, EMPTY_MATERIALS, 'line-height', { type: 'number' }, null), null), false);
});

test('rowHidden: a space with stops never hides', () => {
  const { space } = spaceOf('box-shadow', TEXT, { scale: 'shadow' });
  assert.equal(rowHidden(space, null), false);
  const open = valueSpaceFor({ ...guide, enforcement: 'open' }, MATERIALS, 'box-shadow', TEXT, { scale: 'shadow' });
  assert.equal(open.kind, 'detented');
  assert.equal(rowHidden(open, null), false);
});

// --- choiceStops ----------------------------------------------------------

test('choiceStops: one entry per space stop, commit spelling from the widget table, face from the space', () => {
  const { offer } = spaceOf('border-radius', DIMENSION, { scale: 'radius' });
  assert.deepEqual(offer.map((stop) => stop.id), ['var(--radius-sm)', 'var(--radius-md)', 'var(--radius-lg)', 'var(--radius-full)']);
  assert.deepEqual(offer.map((stop) => stop.value), ['var(--radius-sm)', 'var(--radius-md)', 'var(--radius-lg)', 'var(--radius-full)'], 'a pick hands back the reference');
  assert.deepEqual(offer.map((stop) => stop.label), ['4px', '8px', '16px', '999px'], 'a numeric token faces as its value');
  assert.deepEqual(offer.map((stop) => stop.text), ['sm · 4px', 'md · 8px', 'lg · 16px', 'full · 999px'], 'the listbox line names the token');
  assert.deepEqual(offer.map((stop) => stop.matches), [['var(--radius-sm)'], ['var(--radius-md)'], ['var(--radius-lg)'], ['var(--radius-full)']]);
  assert.deepEqual(offer.map((stop) => stop.number), [4, 8, 16, 999]);
});

test('choiceStops: a shadow scale faces by name, its paint rides through', () => {
  const { offer } = spaceOf('box-shadow', TEXT, { scale: 'shadow' });
  assert.deepEqual(offer.map((stop) => stop.label), ['sm', 'md', 'lg']);
  assert.equal(offer[0].paint, '0 1px 2px rgb(0 0 0 / 0.1)');
  assert.equal(offer[0].group, 'Shadow');
  assert.deepEqual(offer.map((stop) => stop.number), [null, null, null]);
});

test('choiceStops: every entry carries its literal display value beside the commit', () => {
  // The face seam's read: the minted value at the editing condition,
  // never the var() the commit spells (the panel sits outside the token
  // scope and could not resolve it).
  const { offer } = spaceOf('border-radius', DIMENSION, { scale: 'radius' });
  assert.deepEqual(offer.map((stop) => stop.display), ['4px', '8px', '16px', '999px']);
  const { offer: none } = spaceOf('border-width', DIMENSION, { scale: 'stroke', allowed: ['0'] });
  assert.equal(none[3].display, '0', 'a literal stop displays as itself');
});

test('choiceStops: the Font row over a font scale faces by name and displays each literal stack', () => {
  // font-family is text-shaped with no built-in table: the scale
  // binding is the whole row. Faces are the token names; `display` is
  // the stack the face wears; the listbox line names both; a pick
  // commits the reference. "heading" outgrows the chip budget, so the
  // row is the well, and the ring (no ramp) rather than the grip.
  const family = { type: 'family' };
  const { space, offer, surface } = spaceOf('font-family', family, { scale: 'font' });
  assert.equal(space.kind, 'closed');
  assert.equal(rowHidden(space, null), false, 'a minted scale shows the row');
  assert.deepEqual(offer.map((stop) => stop.label), ['body', 'heading', 'mono']);
  assert.deepEqual(offer.map((stop) => stop.display), [
    'Georgia, "Times New Roman", serif',
    'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
    '"SF Mono", Consolas, monospace',
  ]);
  assert.deepEqual(offer.map((stop) => stop.value), ['var(--font-body)', 'var(--font-heading)', 'var(--font-mono)']);
  assert.equal(offer[0].text, 'body · Georgia, "Times New Roman", serif');
  assert.equal(surface, 'well');
  assert.equal(scrubbable(space, surface), false);
  assert.equal(paintedSpace(family), false, 'a stack faces as text, never paint');
  // An unbound font-family on a guarded site has nothing to offer.
  const unbound = valueSpaceFor(guide, MATERIALS, 'font-family', family, null);
  assert.equal(rowHidden(unbound, null), true);
});

test('choiceStops: the None twin sits last and faces "None" with the literal as its commit', () => {
  const { offer } = spaceOf('border-width', DIMENSION, { scale: 'stroke', allowed: ['0'] });
  assert.deepEqual(offer.map((stop) => stop.label), ['1px', '2px', '4px', 'None']);
  const none = offer[3];
  assert.equal(none.id, 'none');
  assert.equal(none.value, '0');
  assert.equal(none.text, 'None · 0');
  assert.deepEqual(none.matches, ['0', '0px']);
});

test('choiceStops: the built-in fallback table rides through unchanged', () => {
  const { space, offer } = spaceOf('text-align', { type: 'keyword' }, null);
  assert.equal(space.kind, 'detented');
  assert.deepEqual(offer.map((stop) => [stop.id, stop.value, stop.label]), [
    ['start', 'start', 'Left'],
    ['center', 'center', 'Center'],
    ['end', 'end', 'Right'],
    ['justify', 'justify', 'Justify'],
  ]);
  assert.deepEqual(offer[0].matches, ['start', 'left'], 'aliases still light the stop');
});

// --- choiceState ----------------------------------------------------------

test('choiceState: segmentState by default, a committed reference lights its stop', () => {
  const { space, stops, offer } = spaceOf('border-radius', DIMENSION, { scale: 'radius' });
  assert.deepEqual(choiceState(space, offer, stops, 'var(--radius-md)'), { selected: 'var(--radius-md)', custom: false, value: null });
  assert.deepEqual(choiceState(space, offer, stops, null), { selected: null, custom: false, value: null });
  assert.deepEqual(choiceState(space, offer, stops, ''), { selected: null, custom: false, value: null });
});

test('choiceState: an unrepresentable value is custom and carries its raw text', () => {
  const { space, stops, offer } = spaceOf('border-radius', DIMENSION, { scale: 'radius' });
  // A literal equal to a token's value stays a literal until touched: the
  // chips read it as custom, honestly.
  assert.deepEqual(choiceState(space, offer, stops, '8px'), { selected: null, custom: true, value: '8px' });
  assert.deepEqual(choiceState(space, offer, stops, '50%'), { selected: null, custom: true, value: '50%' });
});

test('choiceState: a caller-supplied mapper passes through, its own custom value kept', () => {
  const { space, stops, offer } = spaceOf('border-width', DIMENSION, { scale: 'stroke', allowed: ['0'] });
  const strokeStops = { width: stops, style: propertyStops(null, EMPTY_MATERIALS, 'border-style', { type: 'keyword' }, null), color: [] };
  const lit = strokeState({ 'border-width': 'var(--stroke-thin)', 'border-style': 'solid' }, STROKES.border, strokeStops);
  assert.deepEqual(choiceState(space, offer, stops, 'var(--stroke-thin)', lit), { selected: 'var(--stroke-thin)', custom: false, value: null });
  const half = strokeState({ 'border-width': 'var(--stroke-thin)', 'border-style': null }, STROKES.border, strokeStops);
  assert.deepEqual(choiceState(space, offer, stops, 'var(--stroke-thin)', half), { selected: null, custom: true, value: 'var(--stroke-thin)' });
  const none = strokeState({ 'border-width': '0', 'border-style': 'solid' }, STROKES.border, strokeStops);
  assert.deepEqual(choiceState(space, offer, stops, '0', none), { selected: 'none', custom: false, value: null });
});

test('choiceState: a collapsed twin lights the shared face; a selection the offer lacks reads custom', () => {
  // Two tokens sharing one display value collapse into one space stop
  // (the first wins the id); a committed second twin still lights it.
  const twinGuide = {
    enforcement: 'guarded',
    tokenScales: { radius: { label: 'Radius', prefix: 'radius', shape: 'dimension' } },
    tokens: { radius: { sm: '4px', small: '4px', md: '8px' } },
  };
  const twinMaterials = materialsFrom(twinGuide);
  const space = valueSpaceFor(twinGuide, twinMaterials, 'border-radius', DIMENSION, { scale: 'radius' });
  const stops = propertyStops(twinGuide, twinMaterials, 'border-radius', DIMENSION, { scale: 'radius' });
  const offer = choiceStops(space, stops);
  assert.deepEqual(offer.map((stop) => stop.id), ['var(--radius-sm)', 'var(--radius-md)']);
  assert.deepEqual(choiceState(space, offer, stops, 'var(--radius-small)'), { selected: 'var(--radius-sm)', custom: false, value: null });
  // A mapper naming a stop the offer cannot show: custom, never nothing.
  assert.deepEqual(choiceState(space, offer, stops, 'var(--radius-xl)', { selected: 'var(--radius-xl)', custom: false }), { selected: null, custom: true, value: 'var(--radius-xl)' });
});

// --- scrubbable -----------------------------------------------------------

test('scrubbable: chips or the well over a numeric ramp scrub on the grip', () => {
  const radius = spaceOf('border-radius', DIMENSION, { scale: 'radius' });
  assert.notEqual(radius.surface, 'field', 'a closed dimension space is never the field');
  assert.equal(scrubbable(radius.space, radius.surface), true);
  // The None twin ('0') parses as a number, so the ramp survives it.
  const stroke = spaceOf('border-width', DIMENSION, { scale: 'stroke', allowed: ['0'] });
  assert.equal(stroke.surface, 'chips');
  assert.equal(scrubbable(stroke.space, stroke.surface), true);
});

test('scrubbable: keyword and shadow vocabularies hold the ring, the field scrubs itself', () => {
  const shadow = spaceOf('box-shadow', TEXT, { scale: 'shadow' });
  assert.equal(scrubbable(shadow.space, shadow.surface), false);
  const align = spaceOf('text-align', { type: 'keyword' }, null);
  assert.equal(scrubbable(align.space, align.surface), false);
  const open = valueSpaceFor({ ...guide, enforcement: 'open' }, MATERIALS, 'border-radius', DIMENSION, { scale: 'radius' });
  assert.equal(surfaceFor(open), 'field');
  assert.equal(scrubbable(open, 'field'), false);
  // One stop is no ramp.
  const single = valueSpaceFor(guide, MATERIALS, 'border-radius', DIMENSION, { allowed: ['3px'] });
  assert.equal(scrubbable(single, surfaceFor(single)), false);
});

// --- restingValue / ghostFace ---------------------------------------------

// A guide with a width single and a scheme single, so a pose can sit at
// Base, at one single, or at a compound; the entries below are the raw
// condition-keyed shapes a control model's RESOLVED `entry` and the
// provenance's two defaults entries carry.
const posedGuide = {
  ...guide,
  conditions: {
    medium: { label: 'Medium', axis: 'width' },
    dark: { label: 'Dark', axis: 'scheme' },
  },
};
const restingAt = (condition, entry, skinEntry = null, declaredEntry = null) =>
  restingValue({
    guide: posedGuide,
    condition,
    property: 'font-size',
    entry,
    provenance: { skinEntry, declaredEntry },
  });

test('restingValue: the skin delivers when the map sets nothing, raw', () => {
  const skin = { default: { 'font-size': 'var(--text-body)' } };
  const declared = { default: { 'font-size': '1rem' } };
  assert.equal(restingAt('default', null, skin, declared), 'var(--text-body)', 'the var() spelling, never faced');
  assert.equal(restingAt('medium', {}, skin, declared), 'var(--text-body)', 'the skin\'s Base block reaches Medium');
  assert.equal(restingAt('medium+dark', {}, skin, declared), 'var(--text-body)');
});

test('restingValue: the declaration delivers beneath a skin that sets nothing for the property', () => {
  const skin = { default: { 'line-height': '1.5' } };
  const declared = { default: { 'font-size': '1rem' } };
  assert.equal(restingAt('default', {}, skin, declared), '1rem');
  assert.equal(restingAt('medium', {}, skin, declared), '1rem');
  assert.equal(restingAt('medium', {}, null, declared), '1rem', 'no skin at all');
});

test('restingValue: an own Base value beats the defaults tier at another pose', () => {
  const entry = { default: { 'font-size': '2rem' } };
  const skin = { default: { 'font-size': 'var(--text-body)' }, medium: { 'font-size': '3rem' } };
  assert.equal(restingAt('medium', entry, skin), '2rem', 'the map\'s Base value inherits into Medium over the skin\'s own Medium block');
  assert.equal(restingAt('medium+dark', entry, skin), '2rem');
});

test('restingValue: a worn variant\'s value at another condition rests beneath the compound', () => {
  // The resolved entry already carries the worn variant's blocks; its
  // Dark value is the row's value at Dark and the ghost at Medium+Dark.
  const entry = { dark: { 'font-size': '3rem' } };
  const skin = { default: { 'font-size': 'var(--text-body)' } };
  assert.equal(restingAt('medium+dark', entry, skin), '3rem', 'Dark applies within Medium+Dark');
  assert.equal(restingAt('dark', entry, skin), '3rem', 'at Dark itself the value is the row\'s own');
  assert.equal(restingAt('medium', entry, skin), 'var(--text-body)', 'Dark does not reach Medium: the skin rests');
});

test('restingValue: a value set at the pose returns itself', () => {
  const entry = { default: { 'font-size': '2rem' }, medium: { 'font-size': '2.5rem' } };
  const skin = { default: { 'font-size': 'var(--text-body)' } };
  assert.equal(restingAt('medium', entry, skin), '2.5rem');
  assert.equal(restingAt('default', entry, skin), '2rem');
});

test('restingValue: nothing delivering returns null', () => {
  assert.equal(restingAt('default', null), null);
  assert.equal(restingAt('medium', {}, null, null), null);
  assert.equal(restingAt('medium', { narrow: { 'font-size': '1rem' } }, { dark: { 'font-size': '2rem' } }), null, 'blocks outside the pose do not reach it');
  assert.equal(restingAt('medium', { default: { 'font-size': null } }), null, 'a null is an unset, not a value');
});

test('ghostFace: a reference faces at the pose, a literal as itself, nothing as null', () => {
  assert.equal(ghostFace(guide, MATERIALS, 'var(--radius-md)', 'default'), '8px');
  assert.equal(ghostFace(guide, MATERIALS, 'var(--radius-nope)', 'default'), 'var(--radius-nope)', 'an unresolvable reference faces honestly as its spelling');
  assert.equal(ghostFace(guide, MATERIALS, '12px', 'default'), '12px');
  assert.equal(ghostFace(guide, MATERIALS, 2, 'default'), '2', 'a number faces as its string');
  assert.equal(ghostFace(guide, MATERIALS, null, 'default'), null);
  assert.equal(ghostFace(guide, MATERIALS, undefined, 'default'), null);
  assert.equal(ghostFace(guide, MATERIALS, '', 'default'), null);
});

// --- runner ---------------------------------------------------------------

test('an axis-relative fallback: the offer is the axis table, faced per screen axis, chips under icons', () => {
  const axis = positionAxes({ display: 'grid' }).horizontal;
  const space = valueSpaceFor(guide, MATERIALS, 'justify-self', { type: 'keyword' }, null, 'default', axis.stops);
  const stops = propertyStops(guide, MATERIALS, 'justify-self', { type: 'keyword' }, null, axis.stops);
  const offer = choiceStops(space, stops);
  assert.deepEqual(offer.map((stop) => [stop.id, stop.value, stop.label]), [
    ['start', 'start', 'Left'],
    ['center', 'center', 'Center'],
    ['end', 'end', 'Right'],
  ]);
  assert.equal(surfaceFor(space, { icons: { start: [], center: [], end: [] } }), 'chips');
  // An alias lights the stop through the table's matches.
  assert.equal(choiceState(space, offer, stops, 'self-end').selected, 'end');
  assert.equal(scrubbable(space, 'chips'), false, 'a keyword vocabulary holds the ring');
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
  console.error(`${failures} of ${tests.length} choice-row tests failed.`);
  process.exit(1);
}
console.log(`${tests.length} choice-row tests passed.`);
