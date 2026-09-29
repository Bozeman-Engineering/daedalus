/**
 * Self-asserting tests for the chip-row model (chip-row-model.js): the
 * inspector's pose surface — faces, lit state, menu item derivation, and
 * scheme resolution.
 *
 * Run directly (`node tests/js/chip-row.test.mjs`) or via
 * PrinterEquivalenceTest, which executes this file inside the PHPUnit
 * suite. Exits non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import test from 'node:test';
import {
  chipFaces,
  chipMenuItems,
  systemScheme,
} from '../../js/src/chip-row-model.js';

// The shipped condition table's shape and order, 'mobile' standing in for
// the @media width vocabulary the canvas cannot pose (the popout
// viewport's business).
const CONDITIONS = {
  hover: { label: 'Hover', wrapper: ':hover', axis: 'state' },
  focus: { label: 'Focus', wrapper: ':focus', axis: 'state' },
  narrow: { label: 'Narrow', wrapper: '@container (max-width: 767px)', axis: 'width', band: { max: 767 } },
  medium: { label: 'Medium', wrapper: '@container (min-width: 768px) and (max-width: 1023px)', axis: 'width', band: { min: 768, max: 1023 } },
  mobile: { label: 'Mobile', wrapper: '@media (max-width: 767px)', axis: 'width' },
  dark: { label: 'Dark mode', wrapper: '@media (prefers-color-scheme: dark)', axis: 'scheme', preference: { feature: 'prefers-color-scheme', value: 'dark' } },
  'reduced-motion': { label: 'Reduced motion', wrapper: '@media (prefers-reduced-motion: reduce)', axis: 'motion', preference: { feature: 'prefers-reduced-motion', value: 'reduce' } },
};

const REST = { width: null, scheme: 'auto', motion: 'auto' };

const byAxis = (chips) => Object.fromEntries(chips.map((chip) => [chip.axis, chip]));

test('scheme resolution reads prefers-color-scheme, degrades to light', () => {
  assert.equal(systemScheme((query) => ({ matches: query.includes('dark') })), 'dark');
  assert.equal(systemScheme(() => ({ matches: false })), 'light');
  assert.equal(systemScheme(() => null), 'light');
  assert.equal(systemScheme(undefined), 'light');
});

test('resting faces: axis names dim, Base at full width, the resolved scheme', () => {
  const chips = byAxis(chipFaces(REST, null, CONDITIONS, { scheme: 'dark' }));
  assert.deepEqual(chips.state, {
    axis: 'state', face: { kind: 'axis-name' }, lit: false, disabled: false,
  });
  assert.deepEqual(chips.width, {
    axis: 'width', face: { kind: 'base' }, lit: false, disabled: false,
  });
  // The scheme face is the RESOLVED system value, never "Auto" — dim
  // (following the system), so on a dark computer it reads Dark unlit.
  assert.deepEqual(chips.scheme, {
    axis: 'scheme', face: { kind: 'scheme', value: 'dark' }, lit: false, disabled: false,
  });
  assert.deepEqual(chips.motion, {
    axis: 'motion', face: { kind: 'axis-name' }, lit: false, disabled: false,
  });
});

test('no vector at all rests the same way', () => {
  const chips = byAxis(chipFaces(null, null, CONDITIONS, { scheme: 'light' }));
  assert.equal(chips.width.face.kind, 'base');
  assert.deepEqual(chips.scheme.face, { kind: 'scheme', value: 'light' });
});

test('the state chip needs a state vocabulary and a selection', () => {
  // No state conditions: no state chip at all.
  const { hover, focus, ...noStates } = CONDITIONS;
  assert.deepEqual(
    chipFaces(REST, null, noStates, {}).map((chip) => chip.axis),
    ['width', 'scheme', 'motion'],
  );
  // Without a selection the chip disables (force state is
  // selection-scoped); the canvas axes keep working.
  const chips = byAxis(chipFaces(REST, null, CONDITIONS, { hasSelection: false }));
  assert.equal(chips.state.disabled, true);
  assert.equal(chips.width.disabled, false);
});

test('a forced state lights the chip with its condition label', () => {
  const chips = byAxis(chipFaces(REST, 'hover', CONDITIONS, {}));
  assert.deepEqual(chips.state.face, { kind: 'condition', name: 'hover', label: 'Hover' });
  assert.equal(chips.state.lit, true);
  // A force state outside the state vocabulary is ignored.
  assert.equal(byAxis(chipFaces(REST, 'narrow', CONDITIONS, {})).state.lit, false);
});

test('width faces: the write target, never canvas geometry', () => {
  const width = (px) => byAxis(chipFaces({ ...REST, width: px }, null, CONDITIONS, {})).width;
  // Any width inside a condition's band wears the condition's label:
  // the midpoint px a menu pick poses, and a dragged handle alike.
  assert.deepEqual(width(767), {
    axis: 'width',
    face: { kind: 'condition', name: 'narrow', label: 'Narrow' },
    lit: true,
    disabled: false,
  });
  assert.deepEqual(width(437).face, { kind: 'condition', name: 'narrow', label: 'Narrow' });
  assert.equal(width(437).lit, true);
  // The medium band is a true range with both edges inclusive.
  assert.deepEqual(width(800).face, { kind: 'condition', name: 'medium', label: 'Medium' });
  assert.equal(width(800).lit, true);
  // Above every band: Base, dim. Base applies at every width, and px
  // never reaches the face (the ruler owns geometry).
  assert.deepEqual(width(1440).face, { kind: 'base' });
  assert.equal(width(1440).lit, false);
});

test('unposed, the face derives from the measured stage width', () => {
  const measured = (px) => byAxis(chipFaces({ ...REST, measured: px }, null, CONDITIONS, {})).width;
  // A full-width canvas in the base band says Base, dim — now literally
  // true: it renders base, writes base, says Base.
  assert.deepEqual(measured(1440).face, { kind: 'base' });
  assert.equal(measured(1440).lit, false);
  // A window narrower than the base band's floor honestly wears the band
  // it renders (decision B: no see/write divergence, no force-base).
  assert.deepEqual(measured(900).face, { kind: 'condition', name: 'medium', label: 'Medium' });
  assert.equal(measured(900).lit, true);
  assert.deepEqual(measured(600).face, { kind: 'condition', name: 'narrow', label: 'Narrow' });
  // A pose always beats the measurement.
  const posed = byAxis(chipFaces({ ...REST, width: 384, measured: 1440 }, null, CONDITIONS, {})).width;
  assert.deepEqual(posed.face, { kind: 'condition', name: 'narrow', label: 'Narrow' });
});

test('forced scheme and motion light their value faces', () => {
  const chips = byAxis(chipFaces({ ...REST, scheme: 'light', motion: 'reduce' }, null, CONDITIONS, { scheme: 'dark' }));
  assert.deepEqual(chips.scheme.face, { kind: 'scheme', value: 'light' });
  assert.equal(chips.scheme.lit, true);
  assert.deepEqual(chips.motion.face, { kind: 'motion', value: 'reduce' });
  assert.equal(chips.motion.lit, true);
  assert.deepEqual(
    byAxis(chipFaces({ ...REST, motion: 'full' }, null, CONDITIONS, {})).motion.face,
    { kind: 'motion', value: 'full' },
  );
});

test('state menu: No state plus the state vocabulary', () => {
  const items = chipMenuItems('state', { vector: REST, forceState: 'hover', conditions: CONDITIONS });
  assert.deepEqual(items.map((item) => [item.key, item.checked]), [
    ['', false], ['hover', true], ['focus', false],
  ]);
  assert.deepEqual(items[0].label, { kind: 'no-state' });
  assert.deepEqual(items[0].action, { type: 'force-state', state: null });
  assert.deepEqual(items[1].label, { kind: 'condition', name: 'hover', label: 'Hover' });
  assert.deepEqual(items[2].action, { type: 'force-state', state: 'focus' });
});

test('width menu: Base (un-pose) plus posable conditions, no empty option', () => {
  const items = chipMenuItems('width', { vector: REST, conditions: CONDITIONS });
  // 'mobile' (@media, viewport-owned) is excluded; no preview payload,
  // no tail.
  assert.deepEqual(items.map((item) => item.key), ['', 'narrow', 'medium']);
  assert.deepEqual(items[0].label, { kind: 'base' });
  assert.equal(items[0].checked, true);
  assert.deepEqual(items[0].action, { type: 'pose', axis: 'width', value: null });
  // Condition entries hint their band's edges (two-sided on a true
  // range) and pose the band's midpoint.
  assert.deepEqual(items[1].hint, { kind: 'range', max: 767 });
  assert.deepEqual(items[1].action, { type: 'pose', axis: 'width', value: 384 });
  assert.deepEqual(items[2].hint, { kind: 'range', min: 768, max: 1023 });
  assert.deepEqual(items[2].action, { type: 'pose', axis: 'width', value: 896 });
});

test('width menu checks the active condition, dragged or picked', () => {
  const keys = (px) => chipMenuItems('width', { vector: { ...REST, width: px }, conditions: CONDITIONS })
    .filter((item) => item.checked)
    .map((item) => item.key);
  assert.deepEqual(keys(767), ['narrow']);
  // A dragged width inside the band checks the condition writes land at.
  assert.deepEqual(keys(437), ['narrow']);
  assert.deepEqual(keys(800), ['medium']);
  // Above every band the Base entry checks: writes land at base, the
  // same write-target reading as every other checkmark.
  assert.deepEqual(keys(1440), ['']);
});

test('width menu checks from the measured width when unposed', () => {
  const keys = (px) => chipMenuItems('width', { vector: { ...REST, measured: px }, conditions: CONDITIONS })
    .filter((item) => item.checked)
    .map((item) => item.key);
  // The natural canvas in the base band checks the un-pose entry.
  assert.deepEqual(keys(1440), ['']);
  // Narrower windows check the band the canvas renders: un-posing on
  // them honestly lands there (no force-base escape hatch).
  assert.deepEqual(keys(900), ['medium']);
  assert.deepEqual(keys(600), ['narrow']);
});

test('the width menu carries the preview-window tail when the host does', () => {
  const items = chipMenuItems('width', { vector: REST, conditions: CONDITIONS, previewWindow: true });
  assert.deepEqual(items.map((item) => item.key ?? 'separator'), ['', 'narrow', 'medium', 'separator', 'preview-window']);
  assert.equal(items[3].separator, true);
  const tail = items[4];
  assert.deepEqual(tail.label, { kind: 'preview-window' });
  assert.deepEqual(tail.action, { type: 'preview-window' });
  // A check gutter entry so the tail aligns with the value items, never
  // reads as checked.
  assert.equal(tail.checked, false);
});

test('scheme menu: resolved system entry plus Light and Dark, with write targets', () => {
  const items = chipMenuItems('scheme', { vector: REST, conditions: CONDITIONS, scheme: 'dark' });
  assert.deepEqual(items.map((item) => item.key), ['auto', 'light', 'dark']);
  // The follow-the-system entry wears the RESOLVED word and the system
  // marker: "Dark (system)" on a dark computer.
  assert.deepEqual(items[0].label, { kind: 'scheme', value: 'dark', system: true });
  assert.deepEqual(items[0].hint, { kind: 'write-target', value: 'auto' });
  assert.equal(items[0].checked, true);
  assert.deepEqual(items[0].action, { type: 'pose', axis: 'scheme', value: 'auto' });
  assert.deepEqual(items[1].label, { kind: 'scheme', value: 'light', system: false });
  assert.deepEqual(items[1].hint, { kind: 'write-target', value: 'light' });
  assert.deepEqual(items[2].action, { type: 'pose', axis: 'scheme', value: 'dark' });
  // Forcing dark on a dark system: the forced entry checks, the system
  // entry does not — the write-target distinction the 2-option menu
  // could not carry.
  const forced = chipMenuItems('scheme', { vector: { ...REST, scheme: 'dark' }, conditions: CONDITIONS, scheme: 'dark' });
  assert.deepEqual(forced.map((item) => item.checked), [false, false, true]);
});

test('motion menu: Auto (system), Full motion, Reduced', () => {
  const items = chipMenuItems('motion', { vector: { ...REST, motion: 'reduce' }, conditions: CONDITIONS });
  assert.deepEqual(items.map((item) => [item.key, item.checked]), [
    ['auto', false], ['full', false], ['reduce', true],
  ]);
  assert.deepEqual(items[0].label, { kind: 'motion', value: 'auto', system: true });
  assert.deepEqual(items[1].label, { kind: 'motion', value: 'full', system: false });
  assert.deepEqual(items[2].action, { type: 'pose', axis: 'motion', value: 'reduce' });
});

test('an unknown axis derives nothing', () => {
  assert.deepEqual(chipMenuItems('bogus', { vector: REST, conditions: CONDITIONS }), []);
});

test('structural facts have no chip and join no menu', () => {
  const withFacts = {
    hover: CONDITIONS.hover,
    'first-child': { label: 'First child', wrapper: ':first-child', axis: 'structural', fact: true },
    odd: { label: 'Odd', wrapper: ':nth-child(odd)', axis: 'structural', fact: true },
    narrow: CONDITIONS.narrow,
    dark: CONDITIONS.dark,
  };
  // The four canvas axes only, in row order: a fact is the element's
  // place in the markup, which no pose can change.
  assert.deepEqual(
    chipFaces(REST, null, withFacts, { hasSelection: true }).map((chip) => chip.axis),
    ['state', 'width', 'scheme', 'motion'],
  );
  // A fact handed as the force state lights nothing.
  const chips = byAxis(chipFaces(REST, 'first-child', withFacts, { hasSelection: true }));
  assert.deepEqual(chips.state.face, { kind: 'axis-name' });
  assert.equal(chips.state.lit, false);
  // The state menu offers the state vocabulary alone; the structural axis
  // has no menu.
  assert.deepEqual(
    chipMenuItems('state', { vector: REST, forceState: null, conditions: withFacts }).map((item) => item.key),
    ['', 'hover'],
  );
  assert.deepEqual(chipMenuItems('structural', { vector: REST, forceState: null, conditions: withFacts }), []);
});
