/**
 * Self-asserting tests for the Fonts face's pure model (fonts-model.js):
 * the row roster and the card's gesture roster (the animations model's
 * twins), the mint card's face drafts (the file-name guess, the op's
 * faces, the duplicate-face rule), the stack grammar behind the Font
 * row token editor's family chips, the font scale read and the delivery
 * trigger.
 *
 * Run directly (`node tests/js/fonts-model.test.mjs`) or with the rest of
 * the suite (`node --test tests/js/*.test.mjs`). Exits non-zero on
 * failure.
 */
import { strict as assert } from 'node:assert';
import {
  GENERIC_FAMILIES,
  addFamilyToStack,
  duplicateFace,
  faceDraft,
  faceLabel,
  facesOf,
  fontCardGestures,
  fontRows,
  fontScale,
  fontsSignature,
  guessFace,
  stackComponents,
} from '../../js/src/fonts-model.js';
import { fontOptions, fontSpelling } from '../../js/src/materials.js';
import { EMPTY_MATERIALS, fontRecord, roster, swatchRecord } from './materials-fixture.mjs';

const tests = [];
const test = (name, run) => tests.push([name, run]);

const REGULAR = { weight: '400', style: 'normal', source: { file: 'f-regular' } };
const ITALIC = { weight: '400', style: 'italic', source: { file: 'f-italic' } };
const BOLD = { weight: '700', style: 'normal', source: { file: 'f-bold' } };
// The fonts as the materials store carries them: records in roster
// order; Test Sans is module-shipped (the distro lock).
const INTER = fontRecord('inter', 'Inter', 'Inter', [REGULAR, ITALIC, BOLD], { description: 'The body face.' });
const TEST_SANS = fontRecord('test_sans', 'Test Sans', 'Test Sans', [REGULAR], { origin: 'shipped' });
const RANGE = fontRecord('inter_variable', 'Inter Variable', 'Inter Variable', [{ weight: '100 900', style: 'normal', source: { file: 'f-vf' } }]);
// Test Sans under the developer gate: the provider stamps it updatable.
const OPEN_TEST_SANS = fontRecord('test_sans', 'Test Sans', 'Test Sans', [REGULAR], { origin: 'shipped', updatable: true });
const FONTS = [INTER, TEST_SANS, RANGE];
const USED = { usages: 2, shipped_state: null, provider: null };
const UNUSED = { usages: 0, shipped_state: null, provider: null };
const GRANTS = { canCreate: true, canUpdate: true, canDelete: true, canDeleteInUse: true };

test('faceLabel: the weight, the style only when it is not normal', () => {
  assert.equal(faceLabel(REGULAR), '400');
  assert.equal(faceLabel(ITALIC), '400 italic');
  assert.equal(faceLabel(BOLD), '700');
  assert.equal(faceLabel({ weight: '100 900', style: 'normal', source: {} }), '100 900');
  // A face missing its facts reads as the server's defaults.
  assert.equal(faceLabel({ source: {} }), '400');
});

test('fontRows: the flagged roster in roster order, no counts', () => {
  assert.deepEqual(fontRows(FONTS), [
    { reference: 'inter', label: 'Inter', family: 'Inter', spelling: '"Inter"', faces: ['400', '400 italic', '700'], shipped: false },
    { reference: 'test_sans', label: 'Test Sans', family: 'Test Sans', spelling: '"Test Sans"', faces: ['400'], shipped: true },
    { reference: 'inter_variable', label: 'Inter Variable', family: 'Inter Variable', spelling: '"Inter Variable"', faces: ['100 900'], shipped: false },
  ]);
  // `shipped` is the origin, not the lock: the gate-open record still
  // reads shipped.
  assert.equal(fontRows([OPEN_TEST_SANS])[0].shipped, true);
  // The needle matches the label, the machine name or the family.
  assert.deepEqual(fontRows(FONTS, { search: 'SANS' }).map((row) => row.reference), ['test_sans']);
  assert.deepEqual(fontRows(FONTS, { search: 'inter_' }).map((row) => row.reference), ['inter_variable']);
  assert.deepEqual(fontRows(FONTS, { search: 'Inter' }).map((row) => row.reference), ['inter', 'inter_variable']);
  assert.deepEqual(fontRows(FONTS, { search: 'nothing' }), []);
  assert.deepEqual(fontRows(null), []);
});

test('fontCardGestures: a site font with full grants gets the editing roster', () => {
  assert.deepEqual(fontCardGestures(INTER, GRANTS, USED), { rename: true, edit: true, delete: true });
});

test('fontCardGestures: the distro lock withdraws every gesture; the developer gate returns them', () => {
  assert.deepEqual(fontCardGestures(TEST_SANS, GRANTS, UNUSED), { rename: false, edit: false, delete: false });
  assert.deepEqual(fontCardGestures(OPEN_TEST_SANS, GRANTS, UNUSED), { rename: true, edit: true, delete: true });
  assert.equal(fontCardGestures(OPEN_TEST_SANS, { ...GRANTS, canUpdate: false }, UNUSED).edit, false);
});

test('fontCardGestures: delete carries the two permission grades', () => {
  assert.equal(fontCardGestures(INTER, { canDelete: true }, USED).delete, false);
  assert.equal(fontCardGestures(INTER, { canDeleteInUse: true }, USED).delete, true);
  assert.equal(fontCardGestures(INTER, { canDelete: true }, UNUSED).delete, true);
  assert.equal(fontCardGestures(INTER, { canDeleteInUse: true }, UNUSED).delete, true);
  assert.equal(fontCardGestures(INTER, {}, UNUSED).delete, false);
  // An in-flight read counts as unnamed for the affordance; the prompt
  // re-reads the live count before anything runs.
  assert.equal(fontCardGestures(INTER, { canDelete: true }, null).delete, true);
});

test('fontOptions and fontSpelling: the roster projected, the quoted family the spelling', () => {
  const materials = roster(INTER, swatchRecord('brand_red', 'color', '#b91c1c'), TEST_SANS);
  assert.deepEqual(fontOptions(materials).map((entry) => [entry.name, entry.label, entry.family, entry.spelling, entry.faces.length, entry.description]), [
    ['inter', 'Inter', 'Inter', '"Inter"', 3, 'The body face.'],
    ['test_sans', 'Test Sans', 'Test Sans', '"Test Sans"', 1, ''],
  ]);
  assert.equal(fontOptions(materials)[0].record, INTER);
  assert.equal(fontSpelling(INTER), '"Inter"');
  assert.equal(fontSpelling(swatchRecord('brand_red', 'color', '#b91c1c')), null);
  assert.deepEqual(fontOptions(EMPTY_MATERIALS), []);
});

test('fontScale: the scale the guide binds font-family to, else null', () => {
  assert.equal(fontScale({ constraintDefaults: { 'font-family': { scale: 'font' }, 'font-size': { scale: 'text' } } }), 'font');
  assert.equal(fontScale({ constraintDefaults: { 'font-size': { scale: 'text' } } }), null);
  assert.equal(fontScale({}), null);
  assert.equal(fontScale(null), null);
});

test('stackComponents: commas outside quotes split, quoted commas do not', () => {
  assert.deepEqual(stackComponents('"Inter", Georgia, serif'), ['"Inter"', 'Georgia', 'serif']);
  assert.deepEqual(stackComponents("'Comma, Sans', sans-serif"), ["'Comma, Sans'", 'sans-serif']);
  assert.deepEqual(stackComponents('  Inter  ,, '), ['Inter']);
  assert.deepEqual(stackComponents(''), []);
  assert.deepEqual(stackComponents(null), []);
});

test('addFamilyToStack: alone on an empty stack, before a trailing generic, else appended, never twice', () => {
  assert.equal(addFamilyToStack('', '"Inter"'), '"Inter"');
  assert.equal(addFamilyToStack('Georgia, serif', '"Inter"'), 'Georgia, "Inter", serif');
  assert.equal(addFamilyToStack('sans-serif', '"Inter"'), '"Inter", sans-serif');
  assert.equal(addFamilyToStack('"Test Sans"', '"Inter"'), '"Test Sans", "Inter"');
  // Already named, quoted or bare, in either quote: unchanged (the
  // components re-join canonically spaced).
  assert.equal(addFamilyToStack('Inter, sans-serif', '"Inter"'), 'Inter, sans-serif');
  assert.equal(addFamilyToStack("'Inter',serif", '"Inter"'), "'Inter', serif");
  // The generic rule is case-insensitive and reaches every generic.
  assert.equal(addFamilyToStack('Georgia, MONOSPACE', '"Inter"'), 'Georgia, "Inter", MONOSPACE');
  GENERIC_FAMILIES.forEach((generic) => {
    assert.equal(addFamilyToStack(generic, '"Inter"'), `"Inter", ${generic}`, generic);
  });
  // A generic that is not last is a family in the middle: append.
  assert.equal(addFamilyToStack('serif, Georgia', '"Inter"'), 'serif, Georgia, "Inter"');
});

test('guessFace: the usual words, a bare hundred, the variable marker, italic', () => {
  assert.deepEqual(guessFace('Inter-Regular.woff2'), { weight: '400', style: 'normal' });
  assert.deepEqual(guessFace('inter-bold.woff'), { weight: '700', style: 'normal' });
  assert.deepEqual(guessFace('Inter_SemiBoldItalic.woff2'), { weight: '600', style: 'italic' });
  assert.deepEqual(guessFace('inter-700-italic.woff2'), { weight: '700', style: 'italic' });
  assert.deepEqual(guessFace('source-sans-pro-v21-latin-700italic.woff2'), { weight: '700', style: 'italic' });
  assert.deepEqual(guessFace('Inter-ExtraLight.woff2'), { weight: '200', style: 'normal' });
  assert.deepEqual(guessFace('Inter-Black.woff2'), { weight: '900', style: 'normal' });
  assert.deepEqual(guessFace('InterVariable.woff2'), { weight: '400', style: 'normal' }, 'a joined word is no marker');
  assert.deepEqual(guessFace('Inter-Variable.woff2'), { weight: '100 900', style: 'normal' });
  assert.deepEqual(guessFace('scope-one-v14-latin-regular.woff2'), { weight: '400', style: 'normal' });
  assert.deepEqual(guessFace('mystery.woff2'), { weight: '400', style: 'normal' });
  assert.deepEqual(guessFace(''), { weight: '400', style: 'normal' });
});

test('faceDraft and facesOf: an upload becomes a draft, drafts become the op\'s faces', () => {
  const draft = faceDraft({ file: 'uuid-1', name: 'inter-bold-italic.woff2', format: 'woff2', url: '/files/inter-bold-italic.woff2' }, 7);
  assert.deepEqual(draft, { key: 7, file: 'uuid-1', name: 'inter-bold-italic.woff2', format: 'woff2', url: '/files/inter-bold-italic.woff2', weight: '700', style: 'italic' });
  assert.deepEqual(facesOf([draft, { ...draft, key: 8, file: 'uuid-2', weight: ' ', style: '' }]), [
    { weight: '700', style: 'italic', source: { file: 'uuid-1' } },
    { weight: '400', style: 'normal', source: { file: 'uuid-2' } },
  ]);
  assert.equal(faceDraft({ file: 'u', name: 'x.woff', url: '/x.woff' }, 1).format, null);
});

test('duplicateFace: the first draft repeating an earlier (weight, style), keywords folded', () => {
  const regular = { file: 'a', weight: '400', style: 'normal' };
  assert.equal(duplicateFace([regular, { file: 'b', weight: '700', style: 'normal' }]), -1);
  assert.equal(duplicateFace([regular, { file: 'b', weight: 'regular', style: 'normal' }]), 1);
  assert.equal(duplicateFace([regular, { file: 'b', weight: '400', style: 'italic' }, { file: 'c', weight: 'Normal', style: 'NORMAL' }]), 2);
  assert.equal(duplicateFace([{ file: 'a', weight: '100 900', style: 'normal' }, { file: 'b', weight: '100  900', style: 'normal' }]), 1);
  assert.equal(duplicateFace([]), -1);
});

test('fontsSignature: changes exactly when a font joins, leaves or changes', () => {
  const materials = roster(INTER, TEST_SANS);
  const signature = fontsSignature(materials);
  assert.equal(fontsSignature(roster(INTER, TEST_SANS)), signature);
  assert.notEqual(fontsSignature(roster(INTER)), signature, 'a font left');
  assert.notEqual(fontsSignature(roster(INTER, TEST_SANS, RANGE)), signature, 'a font joined');
  assert.notEqual(fontsSignature(roster(fontRecord('inter', 'Inter', 'Inter', [REGULAR]), TEST_SANS)), signature, 'a face left');
  assert.notEqual(fontsSignature(roster(fontRecord('inter', 'Inter', 'Inter Display', [REGULAR, ITALIC, BOLD]), TEST_SANS)), signature, 'the family changed');
  // A label or description edit reprints nothing (the @font-face carries
  // neither); a swatch never enters the signature.
  assert.equal(fontsSignature(roster(fontRecord('inter', 'Renamed', 'Inter', [REGULAR, ITALIC, BOLD], { description: 'x' }), TEST_SANS)), signature);
  assert.equal(fontsSignature(roster(INTER, TEST_SANS, swatchRecord('brand_red', 'color', '#b91c1c'))), signature);
  assert.equal(fontsSignature(EMPTY_MATERIALS), '[]');
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
  console.error(`${failures} of ${tests.length} fonts-model tests failed.`);
  process.exit(1);
}
console.log(`${tests.length} fonts-model tests passed.`);
