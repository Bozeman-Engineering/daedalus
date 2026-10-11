/**
 * Self-asserting tests for the choice row's pure half
 * (js/src/choice-row-model.js): the hide rule, the offer joined from the
 * widget stop table and the value space, the lit state normalized onto
 * the offer (segmentState or a caller-supplied mapper), the grip's
 * detent-scrub eligibility, the painted-space rule, and the row's origin
 * over its stack read (originFor: the winner lit, whichever layer
 * delivers it, and the sentence naming that layer).
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
  originFor,
  paintedSpace,
  rowHidden,
  scrubbable,
} from '../../js/src/choice-row-model.js';
import { buildStack, stackAt } from '../../js/src/style-stack.js';
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

// --- originFor over a stack read ---------------------------------------------

// A guide with a width single and a scheme single, so a pose can sit at
// Base, at one single, or at a compound; the stack is built the way a
// control model builds it (a text block's root, the skin's and the
// declaration's block_type blocks, a worn variant, the own entry).
const UUID = 'aaaaaaaa-1111-2222-3333-444444444444';
const KEY = `block:${UUID}`;
const posedGuide = {
  ...guide,
  conditions: {
    medium: { label: 'Medium', axis: 'width' },
    dark: { label: 'Dark', axis: 'scheme' },
  },
};
const library = [{
  id: 'loud',
  label: 'Loud',
  scope: 'block_type:text',
  value: { '': { dark: { 'font-size': '3rem' } } },
}];
const stackOf = ({ own = null, skin = null, declared = null, worn = false } = {}) =>
  buildStack({
    guide: {
      ...posedGuide,
      ...(skin ? { skinDefaults: { 'block_type:text': { '': skin } } } : {}),
      ...(declared ? { declaredDefaults: { 'block_type:text': { '': declared } } } : {}),
    },
    map: { [KEY]: { ...(own ?? {}), ...(worn ? { variants: [{ variant: 'loud', fragment: '' }] } : {}) } },
    key: KEY,
    variants: library,
    blockTypes: { [UUID]: 'text' },
  });
const originAt = (stack, condition, property = 'font-size') =>
  originFor({ guide: posedGuide, materials: MATERIALS, condition, property, stack });
// The block under a section whose `> pb-text` rule wears Loud (the worn
// ancestor layer of T10), the guide naming the text type so the rule
// reads in the switcher's words.
const SECTION = 'container:cccccccc-1111-2222-3333-444444444444';
const wordedGuide = { ...posedGuide, blockTypes: { text: { label: 'Text' } } };
const underRule = () => buildStack({
  guide: wordedGuide,
  map: { [`${SECTION}::> pb-text`]: { variants: [{ variant: 'loud', fragment: '' }] } },
  key: KEY,
  variants: library,
  blockTypes: { [UUID]: 'text' },
  tree: [{ kind: 'container', uuid: SECTION.slice('container:'.length), layout_id: 'daedalus_stylish_container', _element: { name: 'Section', type: 'Section' }, children: [{ kind: 'block', uuid: UUID, block_type: 'text', _element: { name: 'Text', type: 'Text' } }] }],
});
const withDrupal = (run) => {
  globalThis.Drupal = {
    t: (text, args = {}) => Object.entries(args).reduce((out, [key, value]) => out.split(key).join(String(value)), text),
  };
  try {
    run();
  }
  finally {
    delete globalThis.Drupal;
  }
};

test('originFor: the skin delivers when the map sets nothing, lit in the skin hue, faced', () => withDrupal(() => {
  const stack = stackOf({ skin: { default: { 'font-size': 'var(--radius-md)' } }, declared: { default: { 'font-size': '1rem' } } });
  const base = originAt(stack, 'default');
  assert.equal(base.delivers, true);
  assert.equal(base.layer, 'skin:block_type:text');
  assert.equal(base.header, '8px from the active skin', 'the reference faces at the pose, never its var() spelling');
  assert.deepEqual(base.doorways.map((doorway) => doorway.id), ['skin']);
  assert.equal(stackAt(stack, 'font-size', 'default').winner.value, 'var(--radius-md)', 'the read stays raw; only the sentence faces');
  assert.equal(originAt(stack, 'medium').header, '8px from the active skin at Base', 'the skin\'s Base block reaches Medium');
  assert.equal(originAt(stack, 'medium+dark').header, '8px from the active skin at Base');
}));

test('originFor: the declaration delivers beneath a skin that sets nothing for the property', () => withDrupal(() => {
  const stack = stackOf({ skin: { default: { 'line-height': '1.5' } }, declared: { default: { 'font-size': '1rem' } } });
  const origin = originAt(stack, 'medium');
  assert.equal(origin.delivers, true);
  assert.equal(origin.layer, 'declared:block_type:text');
  assert.equal(origin.header, '1rem from the text block type declaration at Base');
  assert.deepEqual(origin.doorways.map((doorway) => [doorway.id, doorway.scope, doorway.property]), [['declared', 'block_type:text', 'font-size']], 'the one doorway opens the Law tab at the declaration');
  assert.equal(originAt(stackOf({ declared: { default: { 'font-size': '1rem' } } }), 'medium').layer, 'declared:block_type:text', 'no skin at all');
}));

test('originFor: an own Base value beats the defaults tier at another pose', () => withDrupal(() => {
  const stack = stackOf({
    own: { default: { 'font-size': '2rem' } },
    skin: { default: { 'font-size': 'var(--radius-md)' }, medium: { 'font-size': '3rem' } },
  });
  const origin = originAt(stack, 'medium');
  assert.equal(origin.delivers, true, 'the map\'s Base value inherits into Medium over the skin\'s own Medium block');
  assert.equal(origin.layer, 'element');
  assert.equal(origin.header, '2rem from Base');
  assert.deepEqual(origin.doorways.map((doorway) => doorway.id), ['drive'], 'the way to edit it is posing the canvas at Base');
  assert.equal(originAt(stack, 'medium+dark').header, '2rem from Base');
}));

test('originFor: a worn variant\'s value at another condition is the lit value within the compound', () => withDrupal(() => {
  const stack = stackOf({ worn: true, skin: { default: { 'font-size': 'var(--radius-md)' } } });
  const compound = originAt(stack, 'medium+dark');
  assert.equal(compound.delivers, true);
  assert.equal(compound.layer, 'variant:loud');
  assert.equal(compound.reference, 'loud');
  assert.equal(compound.header, '3rem from Loud at Dark', 'Dark applies within Medium+Dark');
  assert.deepEqual(compound.doorways.map((doorway) => doorway.id), ['document', 'drive']);
  assert.equal(originAt(stack, 'dark').header, '3rem from Loud', 'at Dark itself no tail');
  assert.equal(originAt(stack, 'medium').header, '8px from the active skin at Base', 'Dark does not reach Medium: the skin delivers');
}));

test('originFor: a value set at the pose is the row\'s own; over a lower layer it overrides', () => withDrupal(() => {
  const own = stackOf({ own: { default: { 'font-size': '2rem' }, medium: { 'font-size': '2.5rem' } } });
  assert.equal(originAt(own, 'medium').atPose, true);
  assert.equal(originAt(own, 'medium').title, '2.5rem set here at Medium. Also set at Base');
  assert.equal(originAt(own, 'default').atPose, true);
  assert.equal(originAt(own, 'default').header, '2rem set here');
  const over = stackOf({ own: { default: { 'font-size': '2rem' } }, skin: { default: { 'font-size': 'var(--radius-md)' } } });
  const origin = originAt(over, 'default');
  assert.equal(origin.atPose, true);
  assert.equal(origin.layer, 'element', 'the winner hues the mark; the beaten story is the sentence\'s');
  assert.equal(origin.header, "Your 2rem overrides the active skin's 8px");
  assert.deepEqual(origin.doorways.map((doorway) => doorway.id), ['unset', 'skin']);
}));

test('originFor: a variant an ancestor\'s rule wears is the row\'s origin as a variant, the guide handed through for the rule\'s words', () => withDrupal(() => {
  const origin = originFor({ guide: wordedGuide, materials: MATERIALS, condition: 'dark', property: 'font-size', stack: underRule() });
  assert.equal(origin.delivers, true);
  assert.equal(origin.reference, 'loud', 'the mark reads as a variant');
  assert.equal(origin.header, '3rem from Loud at Text blocks directly in Section');
  assert.deepEqual(origin.doorways.map((doorway) => [doorway.id, doorway.label]), [
    ['document', 'Edit Loud at Dark…'],
    ['ancestor', 'Edit at Text blocks directly in Section'],
  ]);
  assert.equal(origin.doorways[0].anchor, `${SECTION}::> pb-text`, 'the session anchors at the carrier');
}));

test('originFor: nothing delivering is "Not set" with no hue, or "not here" in the topmost author\'s hue; a read handed in is used as is', () => withDrupal(() => {
  const empty = stackOf();
  assert.equal(originAt(empty, 'default').delivers, false);
  assert.equal(originAt(empty, 'default').color, null);
  assert.equal(originAt(empty, 'default').title, 'Not set');
  const outside = stackOf({ own: { narrow: { 'font-size': '1rem' } }, skin: { dark: { 'font-size': '2rem' } } });
  assert.equal(originAt(outside, 'medium').delivers, false, 'blocks outside the pose do not reach it');
  assert.equal(originAt(outside, 'medium').layer, 'element', 'the element authors above the skin');
  assert.equal(originAt(outside, 'medium').title, 'Set at narrow, not here. Also set at Dark', 'the fixture labels no Narrow: the raw name');
  // A caller's own read (a control model's reads[property]) wins over
  // the stack's, so a widget never reads twice.
  const stack = stackOf({ skin: { default: { 'font-size': '1rem' } } });
  const read = stackAt(stack, 'font-size', 'default', posedGuide.conditions);
  assert.equal(originFor({ guide: posedGuide, materials: MATERIALS, condition: 'default', property: 'font-size', stack, read }).title, '1rem from the active skin');
}));

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
