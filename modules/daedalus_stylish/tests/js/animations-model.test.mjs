// cspell:ignore transfrom
/**
 * Self-asserting tests for the Animations face's pure model
 * (animations-model.js): the row roster and the card's gesture roster
 * (the palette model's twins), the offset grammar (the PHP twin of
 * AnimationStepsItem::canonicalOffset()), the keyframes session's step
 * track, the per-step analysis with the keyframe-only rules, and the
 * live preview sheet.
 *
 * Run directly (`node tests/js/animations-model.test.mjs`) or with the
 * rest of the suite (`node --test tests/js/*.test.mjs`). Exits non-zero
 * on failure.
 */
import { strict as assert } from 'node:assert';
import {
  SEED_STEPS,
  analyzeStep,
  animationCardGestures,
  animationPreviewCss,
  animationRows,
  canonicalOffset,
  canonicalizeSteps,
  insertStep,
  moveStep,
  printStep,
  removeStep,
  sameSteps,
  settledStep,
  stepDeclaration,
  stepStops,
  stepsOf,
} from '../../js/src/animations-model.js';
import { widePropertyNames } from '../../js/src/code/wide-tier.js';
import { printKeyframes } from '../../js/src/printer.js';
import { EMPTY_MATERIALS, animationRecord, roster, swatchRecord, tokenRecord } from './materials-fixture.mjs';

const tests = [];
const test = (name, run) => tests.push([name, run]);

// The animations as the materials store carries them: records in roster
// order; spin is module-shipped (the distro lock).
const FADE = animationRecord('fade_in', 'Fade in', { '0%': { opacity: '0' }, '100%': { opacity: '1' } }, { description: 'Opacity from nothing to full.' });
const SPIN = animationRecord('spin', 'Spin', { from: { transform: 'rotate(0deg)' }, to: { transform: 'rotate(360deg)' } }, { origin: 'shipped' });
const RISE = animationRecord('rise', 'Rise', { '0%': { opacity: '0', transform: 'translateY(1rem)' }, '60%': { opacity: '1' }, '100%': { transform: 'none' } });
// Spin under the developer gate: the provider stamps it updatable.
const OPEN_SPIN = animationRecord('spin', 'Spin', { from: { transform: 'rotate(0deg)' }, to: { transform: 'rotate(360deg)' } }, { origin: 'shipped', updatable: true });
const ANIMATIONS = [FADE, SPIN, RISE];
const USED = { usages: 2, shipped_state: null, provider: null };
const UNUSED = { usages: 0, shipped_state: null, provider: null };
const SHIPPED = { usages: 0, shipped_state: 'unmodified', provider: 'daedalus_stylish' };
const GRANTS = { canCreate: true, canUpdate: true, canDelete: true, canDeleteInUse: true };

test('canonicalOffset: the PHP twin folds from/to, trims percents, refuses the rest', () => {
  assert.equal(canonicalOffset('from'), '0%');
  assert.equal(canonicalOffset(' TO '), '100%');
  assert.equal(canonicalOffset('050.0%'), '50%');
  assert.equal(canonicalOffset('12.50%'), '12.5%');
  assert.equal(canonicalOffset('.5%'), '0.5%');
  assert.equal(canonicalOffset('0%'), '0%');
  assert.equal(canonicalOffset('33.33333%'), '33.3333%');
  assert.equal(canonicalOffset('101%'), null);
  assert.equal(canonicalOffset('-1%'), null);
  assert.equal(canonicalOffset('50'), null);
  assert.equal(canonicalOffset('0%, 50%'), null);
  assert.equal(canonicalOffset('halfway'), null);
  assert.equal(canonicalOffset(''), null);
});

test('canonicalizeSteps: offsets fold and sort; uncanonical keys sort last, later spellings win', () => {
  assert.deepEqual(Object.keys(canonicalizeSteps({ to: {}, '50%': {}, from: {} })), ['0%', '50%', '100%']);
  // Two spellings of one offset collapse with the later entry.
  assert.deepEqual(canonicalizeSteps({ from: { opacity: '0' }, '0%': { opacity: '0.2' } }), { '0%': { opacity: '0.2' } });
  assert.deepEqual(Object.keys(canonicalizeSteps({ weird: {}, '100%': {}, also: {}, '0%': {} })), ['0%', '100%', 'also', 'weird']);
  assert.deepEqual(canonicalizeSteps(null), {});
});

test('animationRows: the flagged roster in roster order, no counts', () => {
  // `shipped` is the origin, not the lock: the gate-open record still
  // reads shipped.
  assert.equal(animationRows([OPEN_SPIN])[0].shipped, true);
  assert.deepEqual(animationRows(ANIMATIONS), [
    { reference: 'fade_in', label: 'Fade in', spelling: 'motion-fade_in', offsets: ['0%', '100%'], shipped: false },
    { reference: 'spin', label: 'Spin', spelling: 'motion-spin', offsets: ['0%', '100%'], shipped: true },
    { reference: 'rise', label: 'Rise', spelling: 'motion-rise', offsets: ['0%', '60%', '100%'], shipped: false },
  ]);
  assert.deepEqual(animationRows(ANIMATIONS, { search: 'RISE' }).map((row) => row.reference), ['rise']);
  assert.deepEqual(animationRows(ANIMATIONS, { search: 'fade_' }).map((row) => row.reference), ['fade_in']);
  assert.deepEqual(animationRows(ANIMATIONS, { search: 'nothing' }), []);
  assert.deepEqual(animationRows(null), []);
});

test('animationCardGestures: a site animation with full grants gets the editing roster', () => {
  assert.deepEqual(animationCardGestures(FADE, GRANTS, USED), {
    rename: true,
    editSteps: true,
    revisions: true,
    revert: false,
    delete: true,
  });
});

test('animationCardGestures: the distro lock splits the roster', () => {
  assert.deepEqual(animationCardGestures(SPIN, GRANTS, SHIPPED), {
    rename: false,
    editSteps: false,
    revisions: false,
    revert: false,
    delete: false,
  });
  const modified = { ...SHIPPED, shipped_state: 'modified' };
  assert.equal(animationCardGestures(SPIN, GRANTS, modified).revert, true);
  assert.equal(animationCardGestures(SPIN, GRANTS, modified).editSteps, false);
  assert.equal(animationCardGestures(SPIN, GRANTS, { ...SHIPPED, shipped_state: 'defaults_changed' }).revert, true);
  assert.equal(animationCardGestures(SPIN, { ...GRANTS, canUpdate: false }, modified).revert, false);
  // Before the management read lands, revert waits while the read-only
  // posture already holds.
  assert.equal(animationCardGestures(SPIN, GRANTS, null).revert, false);
});

test('animationCardGestures: under the developer gate a shipped animation carries both rosters', () => {
  // Shipped AND updatable: the editing gestures return, revert still
  // follows the management state.
  assert.deepEqual(animationCardGestures(OPEN_SPIN, GRANTS, { ...SHIPPED, shipped_state: 'modified' }), {
    rename: true,
    editSteps: true,
    revisions: true,
    revert: true,
    delete: true,
  });
  assert.equal(animationCardGestures(OPEN_SPIN, GRANTS, SHIPPED).revert, false);
  assert.equal(animationCardGestures(OPEN_SPIN, { ...GRANTS, canUpdate: false }, SHIPPED).editSteps, false);
});

test('animationCardGestures: delete carries the two permission grades', () => {
  assert.equal(animationCardGestures(FADE, { canDelete: true }, USED).delete, false);
  assert.equal(animationCardGestures(FADE, { canDeleteInUse: true }, USED).delete, true);
  assert.equal(animationCardGestures(RISE, { canDelete: true }, UNUSED).delete, true);
  assert.equal(animationCardGestures(RISE, { canDeleteInUse: true }, UNUSED).delete, true);
  assert.equal(animationCardGestures(RISE, {}, UNUSED).delete, false);
  // An in-flight read counts as unreferenced for the affordance; the
  // prompt re-reads the live count before anything runs.
  assert.equal(animationCardGestures(FADE, { canDelete: true }, null).delete, true);
});

test('stepStops and stepsOf: the stored map and the track round-trip through canonical offsets', () => {
  const stops = stepStops(SPIN.value);
  assert.deepEqual(stops, [
    { position: '0%', declarations: { transform: 'rotate(0deg)' } },
    { position: '100%', declarations: { transform: 'rotate(360deg)' } },
  ]);
  assert.deepEqual(stepsOf(stops), { '0%': { transform: 'rotate(0deg)' }, '100%': { transform: 'rotate(360deg)' } });
  // A step whose declarations are not a map reads as empty.
  assert.deepEqual(stepStops({ '50%': null }), [{ position: '50%', declarations: {} }]);
  assert.deepEqual(stepStops(SEED_STEPS).map((stop) => stop.position), ['0%', '100%']);
});

test('sameSteps: canonical offsets, declarations by property and value', () => {
  assert.equal(sameSteps(SPIN.value, { '0%': { transform: 'rotate(0deg)' }, '100%': { transform: 'rotate(360deg)' } }), true);
  assert.equal(sameSteps(FADE.value, { '0%': { opacity: '0' }, '100%': { opacity: '0.9' } }), false);
  assert.equal(sameSteps(FADE.value, { '0%': { opacity: '0' } }), false);
  assert.equal(sameSteps({ '0%': {} }, { '0%': { opacity: '1' } }), false);
  assert.equal(sameSteps({}, {}), true);
});

test('moveStep: a drag lands at the clamped percent unless a step already sits there', () => {
  const stops = stepStops(RISE.value);
  const moved = moveStep(stops, 1, 40.04);
  assert.deepEqual(moved.stops.map((stop) => stop.position), ['0%', '40%', '100%']);
  assert.deepEqual(moved.stops[1].declarations, { opacity: '1' });
  assert.equal(moved.selected, 1);
  // Crossing reorders; the declarations ride with their step.
  const crossed = moveStep(stops, 0, 80);
  assert.deepEqual(crossed.stops.map((stop) => stop.position), ['60%', '80%', '100%']);
  assert.deepEqual(crossed.stops[1].declarations, { opacity: '0', transform: 'translateY(1rem)' });
  // Onto another step's offset: refused (one step per offset).
  assert.equal(moveStep(stops, 1, 100), null);
  assert.equal(moveStep(stops, 1, 130), null);
  // A step may stay where it is (its own offset is not a collision).
  assert.equal(moveStep(stops, 1, 60).stops[1].position, '60%');
});

test('insertStep: an empty step at the click, sorted, selected; refused on an occupied offset', () => {
  const stops = stepStops(FADE.value);
  const added = insertStep(stops, 50);
  assert.deepEqual(added.stops, [
    { position: '0%', declarations: { opacity: '0' } },
    { position: '50%', declarations: {} },
    { position: '100%', declarations: { opacity: '1' } },
  ]);
  assert.equal(added.selected, 1);
  assert.equal(insertStep(stops, 100), null);
  assert.equal(insertStep(stops, 0.04), null);
});

test('removeStep: the one-step floor and selection survival', () => {
  const stops = stepStops(RISE.value);
  assert.deepEqual(removeStep(stops, 1, 1), { stops: [stops[0], stops[2]], selected: 1 });
  assert.equal(removeStep(stops, 0, 2).selected, 1);
  assert.equal(removeStep([stops[0]], 0, 0), null);
  assert.deepEqual(removeStep([stops[0], stops[1]], 0, 0), { stops: [stops[1]], selected: 0 });
});

test('printStep: a step\'s declarations as island text, one per line', () => {
  assert.equal(printStep({ opacity: '0', transform: 'translateY(1rem)' }), 'opacity: 0;\ntransform: translateY(1rem);');
  assert.equal(printStep({}), '');
  assert.equal(printStep(null), '');
});

// A guide with two curated groups: the step vocabulary is every group,
// whatever a kind would declare.
const guide = {
  enforcement: 'guarded',
  groups: {
    motion: {
      label: 'Motion',
      properties: {
        'animation-name': { label: 'Animation', type: 'animation_name', keywords: ['none'] },
        'animation-duration': { label: 'Duration', type: 'dimension' },
        'animation-timing-function': { label: 'Easing', type: 'text' },
        animation: { label: 'Animation (shorthand)', type: 'text' },
      },
    },
    typography: {
      label: 'Typography',
      properties: {
        color: { label: 'Text color', type: 'color' },
      },
    },
  },
  conditions: { hover: { label: 'Hover', wrapper: ':hover' } },
};
const analyze = (text, materials = EMPTY_MATERIALS, extra = {}) => analyzeStep(text, { guide: { ...guide, ...extra }, materials });
const errorsOf = (analysis) => analysis.diagnostics.filter((diagnostic) => diagnostic.severity === 'error');

test('stepDeclaration: the universal vocabulary, every served group plus the wide tier, no value spaces', () => {
  assert.deepEqual(stepDeclaration(guide), {
    grants: {
      motion: ['animation-name', 'animation-duration', 'animation-timing-function', 'animation'],
      typography: ['color'],
      'wide:*': widePropertyNames(),
    },
  });
  assert.deepEqual(stepDeclaration({}), { grants: { 'wide:*': widePropertyNames() } });
  assert.ok(widePropertyNames().includes('transform'), 'the wide tier carries every csstree property');
});

test('analyzeStep: a wide-tier property validates inside a step on a strict site (the server declares every group)', () => {
  const analysis = analyze('transform: rotate(3deg);\nopacity: 0;', EMPTY_MATERIALS, { enforcement: 'strict' });
  assert.deepEqual(errorsOf(analysis), []);
  assert.deepEqual(analysis.declarations.map((record) => record.valid), [true, true]);
  // An unknown property is still refused.
  assert.equal(errorsOf(analyze('transfrom: none;', EMPTY_MATERIALS, { enforcement: 'strict' }))[0].code, 'INVALID_STYLE_PROPERTY');
});

test('analyzeStep: declarations of any curated group validate under the universal vocabulary', () => {
  const analysis = analyze('color: #1a1a2e;\nanimation-timing-function: ease-out;\nopacity: 0;');
  assert.deepEqual(analysis.declarations.map((record) => [record.property, record.value, record.valid]), [
    ['color', '#1a1a2e', true],
    ['animation-timing-function', 'ease-out', true],
    // A wide-tier property on an open site: recognized, hygiene only.
    ['opacity', '0', true],
  ]);
  assert.deepEqual(errorsOf(analysis), []);
  assert.deepEqual(settledStep(analysis.declarations), {
    declarations: { color: '#1a1a2e', 'animation-timing-function': 'ease-out', opacity: '0' },
    hasErrors: false,
  });
});

test('analyzeStep: the wearer\'s animation properties are refused inside a step', () => {
  // The trigger pair too: timeline-trigger carries no animation- prefix
  // but is the wearer's WHEN exactly as animation-trigger is.
  const analysis = analyze('animation-duration: 1s;\nanimation: spin 1s;\ntimeline-trigger: --in-view view() entry 20%;\nanimation-trigger: --in-view play-once;\nanimation-timing-function: linear;');
  const refused = errorsOf(analysis).filter((diagnostic) => diagnostic.code === 'INVALID_ANIMATION_STEP_PROPERTY');
  assert.equal(refused.length, 4);
  assert.match(refused[0].message, /animation-timing-function only/);
  assert.deepEqual(analysis.declarations.map((record) => record.valid), [false, false, false, false, true]);
  // The refused lines hold out of the settle; the easing lands.
  assert.deepEqual(settledStep(analysis.declarations), {
    declarations: { 'animation-timing-function': 'linear' },
    hasErrors: true,
  });
});

test('analyzeStep: a media token is refused inside a step', () => {
  const analysis = analyze("background-image: url('media:11111111-1111-1111-1111-111111111111');");
  const [refused] = errorsOf(analysis);
  assert.equal(refused.code, 'INVALID_STYLE_VALUE');
  assert.equal(analysis.declarations[0].valid, false);
  assert.equal(refused.from, analysis.declarations[0].valueFrom);
});

test('analyzeStep: the island rules still hold (a selector, a condition, a token reference)', () => {
  // Structure is chrome, never characters, and a step has no door to
  // hand a block to (it addresses no key on any page): a selector block
  // and a condition block are errors here, nothing saves.
  assert.match(errorsOf(analyze('.x { color: red; }'))[0].message, /holds declarations only/);
  assert.match(errorsOf(analyze('@hover { color: red; }'))[0].message, /holds declarations only/);
  // A minted token resolves; on a strict site an unminted one errors.
  const materials = roster(tokenRecord('color', 'accent', '#1a1a2e'), swatchRecord('brand_red', 'color', '#b91c1c'));
  assert.deepEqual(errorsOf(analyze('color: var(--color-accent);', materials)), []);
  assert.equal(analyze('color: var(--color-missing);', materials, { enforcement: 'strict', tokenScales: { color: { label: 'Color', shape: 'color', prefix: 'color' } } }).declarations[0].valid, false);
  // Diagnostics stay sorted by position.
  const mixed = analyze('animation-delay: 1s;\ncolor: var(--color-missing);', materials, { enforcement: 'strict', tokenScales: { color: { label: 'Color', shape: 'color', prefix: 'color' } } });
  assert.deepEqual(mixed.diagnostics.map((diagnostic) => diagnostic.from), [...mixed.diagnostics.map((diagnostic) => diagnostic.from)].sort((a, b) => a - b));
});

test('animationPreviewCss: every rostered animation prints, a draft substitutes for the one it edits', () => {
  const materials = roster(FADE, SPIN);
  assert.equal(animationPreviewCss(materials), [
    printKeyframes('motion-fade_in', FADE.value),
    printKeyframes('motion-spin', SPIN.value),
  ].join('\n'));
  const draft = { '0%': { opacity: '0.2' }, '100%': { opacity: '1' } };
  const previewed = animationPreviewCss(materials, new Map([['fade_in', draft]]));
  assert.ok(previewed.startsWith(printKeyframes('motion-fade_in', draft)));
  assert.ok(previewed.includes('opacity: 0.2'));
  assert.ok(previewed.endsWith(printKeyframes('motion-spin', SPIN.value)));
  // A draft with nothing printable drops its animation from the sheet;
  // an empty roster prints nothing.
  assert.equal(animationPreviewCss(materials, new Map([['fade_in', { '0%': {} }]])), printKeyframes('motion-spin', SPIN.value));
  assert.equal(animationPreviewCss(EMPTY_MATERIALS), '');
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
  console.error(`${failures} of ${tests.length} animations-model tests failed.`);
  process.exit(1);
}
console.log(`${tests.length} animations-model tests passed.`);
