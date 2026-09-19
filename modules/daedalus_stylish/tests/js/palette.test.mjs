/**
 * Self-asserting tests for the Palette face's pure model.
 *
 * Run directly (`node tests/js/palette.test.mjs`) or via
 * PrinterEquivalenceTest inside the PHPUnit suite. Locks the kind filter
 * vocabulary, the row roster with its honesty flags, the lifecycle card's
 * gesture roster (the shipped-state split, the delete grades), the
 * condition-honest editor split, and the read-only skin grouping. Exits
 * non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import {
  editablePair,
  editableValue,
  mergedPair,
  mergedValue,
  paletteFilters,
  paletteRows,
  skinGroups,
  swatchCardGestures,
} from '../../js/src/palette-model.js';
import { facePair } from '../../js/src/materials.js';
import { EMPTY_MATERIALS, materialsFrom, swatchRecord } from './materials-fixture.mjs';

const tests = [];
const test = (name, run) => tests.push([name, run]);

// The palette as the materials store carries it: swatch records in roster
// order; hero_fade is module-shipped (the distro lock).
const PALETTE = [
  swatchRecord('brand_red', 'color', '#b91c1c', 'Brand red'),
  swatchRecord('hero_fade', 'gradient', { default: 'linear-gradient(#000, #fff)', dark: 'linear-gradient(#fff, #000)' }, 'Hero fade', { origin: 'shipped' }),
  swatchRecord('dusk', 'gradient', 'linear-gradient(90deg, red, gold)', 'Dusk'),
];
// hero_fade under the developer gate: the provider stamps it updatable.
const OPEN_FADE = swatchRecord('hero_fade', 'gradient', { default: 'linear-gradient(#000, #fff)', dark: 'linear-gradient(#fff, #000)' }, 'Hero fade', { origin: 'shipped', updatable: true });
// The card's per-open management read (the usage route's answer).
const USED = { usages: 3, shipped_state: null, provider: null };
const UNUSED = { usages: 0, shipped_state: null, provider: null };
const SHIPPED = { usages: 0, shipped_state: 'unmodified', provider: 'site_skin' };

test('paletteFilters: distinct sorted kinds', () => {
  assert.deepEqual(paletteFilters(PALETTE), { kinds: ['color', 'gradient'] });
  assert.deepEqual(paletteFilters([{ id: 'x' }]), { kinds: [] });
  assert.deepEqual(paletteFilters(null), { kinds: [] });
});

test('paletteRows: the flagged roster in roster order, no counts', () => {
  assert.deepEqual(paletteRows(PALETTE), [
    { reference: 'brand_red', label: 'Brand red', kind: 'color', shipped: false },
    { reference: 'hero_fade', label: 'Hero fade', kind: 'gradient', shipped: true },
    { reference: 'dusk', label: 'Dusk', kind: 'gradient', shipped: false },
  ]);
  // `shipped` is the origin, not the lock: the gate-open record still
  // reads shipped.
  assert.equal(paletteRows([OPEN_FADE])[0].shipped, true);
});

test('paletteRows: search matches label or machine name, case-insensitive', () => {
  assert.deepEqual(paletteRows(PALETTE, { search: 'RED' }).map((row) => row.reference), ['brand_red']);
  assert.deepEqual(paletteRows(PALETTE, { search: 'hero_' }).map((row) => row.reference), ['hero_fade']);
  assert.deepEqual(paletteRows(PALETTE, { search: 'nothing' }), []);
});

test('paletteRows: the kind filter is exact; null means all', () => {
  assert.deepEqual(paletteRows(PALETTE, { kind: 'gradient' }).map((row) => row.reference), ['hero_fade', 'dusk']);
  assert.deepEqual(paletteRows(PALETTE, { kind: null }).length, 3);
});

const GRANTS = { canCreate: true, canUpdate: true, canDelete: true, canDeleteInUse: true };

test('swatchCardGestures: a site swatch with full grants gets the editing roster', () => {
  assert.deepEqual(swatchCardGestures(PALETTE[0], GRANTS, USED), {
    rename: true,
    editValue: true,
    revisions: true,
    revert: false,
    delete: true,
  });
});

test('swatchCardGestures: the distro lock splits the roster', () => {
  // Unmodified shipped: read-only, not even revert (nothing to converge).
  assert.deepEqual(swatchCardGestures(PALETTE[1], GRANTS, SHIPPED), {
    rename: false,
    editValue: false,
    revisions: false,
    revert: false,
    delete: false,
  });
  // Modified shipped: revert is the one gesture (it converges TOWARD disk).
  const modified = { ...SHIPPED, shipped_state: 'modified' };
  assert.equal(swatchCardGestures(PALETTE[1], GRANTS, modified).revert, true);
  assert.equal(swatchCardGestures(PALETTE[1], GRANTS, modified).editValue, false);
  // defaults_changed is actionable too.
  assert.equal(swatchCardGestures(PALETTE[1], GRANTS, { ...SHIPPED, shipped_state: 'defaults_changed' }).revert, true);
  // Without the update grade even revert withdraws.
  assert.equal(swatchCardGestures(PALETTE[1], { ...GRANTS, canUpdate: false }, modified).revert, false);
  // Shipped AND updatable (the developer gate): the editing gestures
  // return, revert still follows the management state.
  assert.deepEqual(swatchCardGestures(OPEN_FADE, GRANTS, modified), {
    rename: true,
    editValue: true,
    revisions: true,
    revert: true,
    delete: true,
  });
  assert.equal(swatchCardGestures(OPEN_FADE, GRANTS, SHIPPED).revert, false);
  assert.equal(swatchCardGestures(OPEN_FADE, { ...GRANTS, canUpdate: false }, SHIPPED).editValue, false);
  // Before the management read lands, revert waits (the state is unknown)
  // while the read-only posture already holds.
  assert.deepEqual(swatchCardGestures(PALETTE[1], GRANTS, null), {
    rename: false,
    editValue: false,
    revisions: false,
    revert: false,
    delete: false,
  });
});

test('swatchCardGestures: delete carries the two permission grades', () => {
  const used = PALETTE[0];
  const unused = PALETTE[2];
  // A referenced swatch needs the in-use grade — base delete is not enough.
  assert.equal(swatchCardGestures(used, { canDelete: true }, USED).delete, false);
  assert.equal(swatchCardGestures(used, { canDeleteInUse: true }, USED).delete, true);
  // An unreferenced one deletes on either grade.
  assert.equal(swatchCardGestures(unused, { canDelete: true }, UNUSED).delete, true);
  assert.equal(swatchCardGestures(unused, { canDeleteInUse: true }, UNUSED).delete, true);
  assert.equal(swatchCardGestures(unused, {}, UNUSED).delete, false);
  // An in-flight read counts as unreferenced for the affordance; the
  // prompt re-reads the live count before anything runs.
  assert.equal(swatchCardGestures(used, { canDelete: true }, null).delete, true);
});

test('swatchCardGestures: no grants, no gestures', () => {
  assert.deepEqual(swatchCardGestures(PALETTE[0], {}, USED), {
    rename: false,
    editValue: false,
    revisions: false,
    revert: false,
    delete: false,
  });
});

test('editableValue: strings edit whole; condition maps edit their default', () => {
  assert.deepEqual(editableValue('#b91c1c'), { draft: '#b91c1c', conditions: [] });
  assert.deepEqual(editableValue({ default: 'linear-gradient(#000, #fff)', dark: 'x', narrow: 'y' }), {
    draft: 'linear-gradient(#000, #fff)',
    conditions: ['dark', 'narrow'],
  });
  // Malformed shapes degrade to an empty draft, never a throw.
  assert.deepEqual(editableValue(null), { draft: '', conditions: [] });
  assert.deepEqual(editableValue({ dark: 'x' }), { draft: '', conditions: ['dark'] });
});

test('mergedValue: the inverse keeps every other condition entry verbatim', () => {
  assert.equal(mergedValue('#b91c1c', '#111111'), '#111111');
  assert.deepEqual(mergedValue({ default: 'a', dark: 'b' }, 'c'), { default: 'c', dark: 'b' });
});

// The pair guide: the guide alone decides which name rides the scheme
// axis ('dark' here), same axis rule as the split faces.
const PAIR_GUIDE = {
  conditions: {
    narrow: { label: 'Narrow', wrapper: '@container page (max-width: 500px)', axis: 'width' },
    dark: { label: 'Dark', wrapper: '@media (prefers-color-scheme: dark)', axis: 'scheme' },
  },
};

test('editablePair: the scheme entry splits out beside the default', () => {
  assert.deepEqual(editablePair(PAIR_GUIDE, '#b91c1c'), {
    draft: '#b91c1c', darkDraft: null, scheme: 'dark', conditions: [],
  });
  assert.deepEqual(editablePair(PAIR_GUIDE, { default: '#fdfdf6', dark: '#15151a', narrow: '#fff' }), {
    draft: '#fdfdf6', darkDraft: '#15151a', scheme: 'dark', conditions: ['narrow'],
  });
  // Compound keys never surface as the scheme draft (arc-1 axis rule):
  // they stay listed among the untouched conditions.
  assert.deepEqual(editablePair(PAIR_GUIDE, { default: '#fdfdf6', 'narrow+dark': '#15151a' }), {
    draft: '#fdfdf6', darkDraft: null, scheme: 'dark', conditions: ['narrow+dark'],
  });
  // An empty scheme entry is not an authorable pair half — and the pair
  // control owns the scheme name either way, so it never lists among the
  // untouched conditions (a commit drops the meaningless entry).
  assert.deepEqual(editablePair(PAIR_GUIDE, { default: '#fdfdf6', dark: '' }), {
    draft: '#fdfdf6', darkDraft: null, scheme: 'dark', conditions: [],
  });
  // Without a scheme axis there is no pair authoring: 'dark' is just a
  // condition entry the editor keeps verbatim.
  assert.deepEqual(editablePair({}, { default: '#fdfdf6', dark: '#15151a' }), {
    draft: '#fdfdf6', darkDraft: null, scheme: null, conditions: ['dark'],
  });
});

test('mergedPair: authors the pair, keeps every other entry verbatim', () => {
  // A plain string stays plain without a scheme draft, and promotes to
  // the condition-keyed pair with one.
  assert.equal(mergedPair('#b91c1c', { draft: '#111111', darkDraft: null, scheme: 'dark' }), '#111111');
  assert.deepEqual(mergedPair('#b91c1c', { draft: '#111111', darkDraft: '#eeeeee', scheme: 'dark' }), {
    default: '#111111', dark: '#eeeeee',
  });
  // Other condition entries reassemble byte-identical.
  assert.deepEqual(
    mergedPair({ default: 'a', dark: 'b', narrow: 'c' }, { draft: 'A', darkDraft: 'B', scheme: 'dark' }),
    { default: 'A', dark: 'B', narrow: 'c' },
  );
});

test('mergedPair: a null scheme draft removes the entry, whole faces return', () => {
  // Removing the only paired entry collapses to a plain string: the face
  // paints whole again (single-valued stops stay whole, the arc-1 rule).
  assert.equal(mergedPair({ default: 'a', dark: 'b' }, { draft: 'A', darkDraft: null, scheme: 'dark' }), 'A');
  // With other condition entries the map stays a map.
  assert.deepEqual(
    mergedPair({ default: 'a', dark: 'b', narrow: 'c' }, { draft: 'A', darkDraft: null, scheme: 'dark' }),
    { default: 'A', narrow: 'c' },
  );
  // No scheme axis: exactly mergedValue, nothing invented or dropped.
  assert.deepEqual(mergedPair({ default: 'a', dark: 'b' }, { draft: 'A', darkDraft: null, scheme: null }), {
    default: 'A', dark: 'b',
  });
});

test('mergedPair: the editablePair round trip is the identity', () => {
  for (const value of ['#b91c1c', { default: '#fdfdf6', dark: '#15151a', narrow: '#fff' }]) {
    assert.deepEqual(mergedPair(value, editablePair(PAIR_GUIDE, value)), value);
  }
});

test('skinGroups: tokens grouped per scale label, shapes and scheme pairs carried', () => {
  const guide = {
    conditions: {
      dark: { label: 'Dark', axis: 'scheme' },
    },
    tokenScales: {
      color: { label: 'Colors', shape: 'color', prefix: 'color' },
      space: { label: 'Spacing', shape: 'dimension', prefix: 'space' },
      empty: { label: 'Empty', shape: 'color', prefix: 'empty' },
    },
    tokens: {
      color: { accent: '#1a1a2e', bg: { default: '#fff', dark: '#111' } },
      space: { 4: '1rem' },
    },
  };
  assert.deepEqual(skinGroups(guide, materialsFrom(guide)), [
    ['Colors', [
      { name: 'accent', ref: 'var(--color-accent)', value: '#1a1a2e', valueDark: null, shape: 'color' },
      { name: 'bg', ref: 'var(--color-bg)', value: '#fff', valueDark: '#111', shape: 'color' },
    ]],
    ['Spacing', [
      { name: '4', ref: 'var(--space-4)', value: '1rem', valueDark: null, shape: 'dimension' },
    ]],
  ]);
  assert.deepEqual(skinGroups({}, EMPTY_MATERIALS), []);
});

// The face pair's guide: a scheme axis in the conditions table (without
// it 'dark' is just a key), paired and single-valued entries on both
// palette halves (minted tokens, user swatches).
const FACE_GUIDE = {
  conditions: {
    dark: { label: 'Dark', wrapper: '@media (prefers-color-scheme: dark)', axis: 'scheme' },
  },
  tokenScales: {
    color: { label: 'Colors', shape: 'color', prefix: 'color' },
  },
  tokens: {
    color: { accent: '#1a1a2e', bg: { default: '#ffffff', dark: '#111111' } },
  },
  swatchKinds: { color: { label: 'Colors' } },
  swatches: [
    { machine_name: 'brand_red', label: 'Brand red', kind: 'color', value: '#b91c1c', variable: '--swatch-brand_red' },
    { machine_name: 'paper', label: 'Paper', kind: 'color', value: { default: '#fdfdf6', dark: '#15151a' }, variable: '--swatch-paper' },
  ],
};
const FACE_MATERIALS = materialsFrom(FACE_GUIDE);

test('facePair: single-valued literals keep a whole face', () => {
  assert.deepEqual(facePair(FACE_GUIDE, FACE_MATERIALS, '#b91c1c'), { paint: '#b91c1c', paintDark: null });
  assert.deepEqual(facePair(FACE_GUIDE, FACE_MATERIALS, 'linear-gradient(#000, #fff)'), { paint: 'linear-gradient(#000, #fff)', paintDark: null });
});

test('facePair: an own scheme entry splits the face', () => {
  assert.deepEqual(facePair(FACE_GUIDE, FACE_MATERIALS, { default: '#fdfdf6', dark: '#15151a' }), { paint: '#fdfdf6', paintDark: '#15151a' });
});

test('facePair: references substitute from the referenced entry\'s pair', () => {
  // A paired token/swatch reference splits: the dark pass substitutes the
  // referenced entry's scheme value, never resolves var().
  assert.deepEqual(facePair(FACE_GUIDE, FACE_MATERIALS, 'var(--color-bg)'), { paint: '#ffffff', paintDark: '#111111' });
  assert.deepEqual(facePair(FACE_GUIDE, FACE_MATERIALS, 'var(--swatch-paper)'), { paint: '#fdfdf6', paintDark: '#15151a' });
  // A var() fallback argument does not defeat the substitution.
  assert.deepEqual(facePair(FACE_GUIDE, FACE_MATERIALS, 'var(--color-bg, white)'), { paint: '#ffffff', paintDark: '#111111' });
  // Unpaired references paint alike on both passes: the face stays whole.
  assert.deepEqual(facePair(FACE_GUIDE, FACE_MATERIALS, 'var(--color-accent)'), { paint: '#1a1a2e', paintDark: null });
  assert.deepEqual(facePair(FACE_GUIDE, FACE_MATERIALS, 'var(--swatch-brand_red)'), { paint: '#b91c1c', paintDark: null });
});

test('facePair: a gradient splits iff a referenced stop is paired', () => {
  // The unpaired stop falls back to its default on the dark pass, so both
  // halves agree on it; the paired stop is what splits the face.
  assert.deepEqual(facePair(FACE_GUIDE, FACE_MATERIALS, 'linear-gradient(var(--color-bg), var(--swatch-brand_red))'), {
    paint: 'linear-gradient(#ffffff, #b91c1c)',
    paintDark: 'linear-gradient(#111111, #b91c1c)',
  });
  assert.deepEqual(facePair(FACE_GUIDE, FACE_MATERIALS, 'linear-gradient(var(--color-accent), var(--swatch-brand_red))'), {
    paint: 'linear-gradient(#1a1a2e, #b91c1c)',
    paintDark: null,
  });
});

test('facePair: a dark entry substitutes its references too', () => {
  // The scheme pass reads the value's own dark entry first, then
  // substitutes any reference inside it from the palette.
  assert.deepEqual(facePair(FACE_GUIDE, FACE_MATERIALS, { default: '#ffffff', dark: 'var(--swatch-brand_red)' }), { paint: '#ffffff', paintDark: '#b91c1c' });
});

test('facePair: honesty degradations collapse the face whole', () => {
  // Unknown references stay verbatim on both passes.
  assert.deepEqual(facePair(FACE_GUIDE, FACE_MATERIALS, 'var(--mystery)'), { paint: 'var(--mystery)', paintDark: null });
  // Without a conditions table 'dark' is not a scheme key: no pair.
  assert.deepEqual(facePair({ ...FACE_GUIDE, conditions: undefined }, FACE_MATERIALS, { default: '#fdfdf6', dark: '#15151a' }), { paint: '#fdfdf6', paintDark: null });
});

let failures = 0;
for (const [name, run] of tests) {
  try {
    run();
    console.log(`ok - ${name}`);
  }
  catch (error) {
    failures += 1;
    console.error(`not ok - ${name}`);
    console.error(error);
  }
}
if (failures > 0) {
  console.error(`${failures} failure(s)`);
  process.exit(1);
}
console.log(`${tests.length} tests passed`);
