/**
 * Self-asserting tests for the Skin tab's pure model (skin-model.js):
 * the sections and their rows under one search, the one card gesture
 * roster with its locks, the route a kind's identity gestures take, the
 * token value grammar (the condition-honest split, the scheme pair, the
 * name grammar, the editor by shape), the keyframes grammar (the offset
 * twin, the step track, the per-step analysis, the preview sheet) and
 * the font grammar (faces, the derived name, the stack, the delivery
 * trigger).
 *
 * Run directly (`node tests/js/skin-model.test.mjs`) or with the rest of
 * the suite (`node --test tests/js/*.test.mjs`). Exits non-zero on
 * failure.
 */
import { strict as assert } from 'node:assert';
import {
  GENERIC_FAMILIES,
  SEED_STEPS,
  SKIN_KINDS,
  addFamilyToStack,
  analyzeStep,
  animationPreviewCss,
  canonicalOffset,
  canonicalizeSteps,
  copySeed,
  duplicateFace,
  editablePair,
  editableValue,
  faceDraft,
  faceLabel,
  facesOf,
  fontName,
  fontScale,
  fontsSignature,
  guessFace,
  insertStep,
  keyframesSummary,
  materialCardGestures,
  materialLayer,
  materialRoute,
  materialSpelling,
  mergedPair,
  mergedValue,
  mintableKinds,
  moveStep,
  printStep,
  removeStep,
  sameSteps,
  settledStep,
  skinSections,
  stackComponents,
  stepDeclaration,
  stepStops,
  stepsOf,
  storedFaceDraft,
  tokenEditorKind,
  tokenNameError,
  tokenPaintsFace,
  tokenSeed,
  tokenValueText,
} from '../../js/src/skin-model.js';
import { widePropertyNames } from '../../js/src/code/wide-tier.js';
import { printKeyframes } from '../../js/src/printer.js';
import { fontOptions, fontSpelling } from '../../js/src/materials.js';
import {
  EMPTY_MATERIALS,
  animationRecord,
  fontRecord,
  materialsFrom,
  roster,
  tokenRecord,
} from './materials-fixture.mjs';

const tests = [];
const test = (name, run) => tests.push([name, run]);

// ═════ The route and the kinds. ═════

test('SKIN_KINDS: the three sections in tab order', () => {
  assert.deepEqual(SKIN_KINDS, ['token', 'animation', 'font']);
});

test('materialRoute: the skin section then the id, a token id as two segments', () => {
  assert.equal(materialRoute('token', 'color/brand-red'), 'daedalus_stylish/skin/tokens/color/brand-red');
  assert.equal(materialRoute('token', 'space/4'), 'daedalus_stylish/skin/tokens/space/4');
  assert.equal(materialRoute('animation', 'fade_in'), 'daedalus_stylish/skin/animations/fade_in');
  assert.equal(materialRoute('font', 'scope_one'), 'daedalus_stylish/skin/fonts/scope_one');
  // Reserved characters ride encoded; a token id without a slash is its
  // scale alone with an empty name (the server refuses it).
  assert.equal(materialRoute('font', 'a b'), 'daedalus_stylish/skin/fonts/a%20b');
  assert.equal(materialRoute('token', 'color'), 'daedalus_stylish/skin/tokens/color/');
  assert.throws(() => materialRoute('variant', 'x'), /not a skin material kind/);
});

// ═════ Token values: the editor split and the scheme pair. ═════

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
  // Compound keys never surface as the scheme draft: they stay listed
  // among the untouched conditions.
  assert.deepEqual(editablePair(PAIR_GUIDE, { default: '#fdfdf6', 'narrow+dark': '#15151a' }), {
    draft: '#fdfdf6', darkDraft: null, scheme: 'dark', conditions: ['narrow+dark'],
  });
  // An empty scheme entry is not an authorable pair half, and the pair
  // control owns the scheme name either way.
  assert.deepEqual(editablePair(PAIR_GUIDE, { default: '#fdfdf6', dark: '' }), {
    draft: '#fdfdf6', darkDraft: null, scheme: 'dark', conditions: [],
  });
  // Without a scheme axis there is no pair authoring.
  assert.deepEqual(editablePair({}, { default: '#fdfdf6', dark: '#15151a' }), {
    draft: '#fdfdf6', darkDraft: null, scheme: null, conditions: ['dark'],
  });
});

test('mergedPair: authors the pair, keeps every other entry verbatim', () => {
  assert.equal(mergedPair('#b91c1c', { draft: '#111111', darkDraft: null, scheme: 'dark' }), '#111111');
  assert.deepEqual(mergedPair('#b91c1c', { draft: '#111111', darkDraft: '#eeeeee', scheme: 'dark' }), {
    default: '#111111', dark: '#eeeeee',
  });
  assert.deepEqual(
    mergedPair({ default: 'a', dark: 'b', narrow: 'c' }, { draft: 'A', darkDraft: 'B', scheme: 'dark' }),
    { default: 'A', dark: 'B', narrow: 'c' },
  );
});

test('mergedPair: a null scheme draft removes the entry, whole faces return', () => {
  assert.equal(mergedPair({ default: 'a', dark: 'b' }, { draft: 'A', darkDraft: null, scheme: 'dark' }), 'A');
  assert.deepEqual(
    mergedPair({ default: 'a', dark: 'b', narrow: 'c' }, { draft: 'A', darkDraft: null, scheme: 'dark' }),
    { default: 'A', narrow: 'c' },
  );
  assert.deepEqual(mergedPair({ default: 'a', dark: 'b' }, { draft: 'A', darkDraft: null, scheme: null }), {
    default: 'A', dark: 'b',
  });
});

test('mergedPair: the editablePair round trip is the identity', () => {
  for (const value of ['#b91c1c', { default: '#fdfdf6', dark: '#15151a', narrow: '#fff' }]) {
    assert.deepEqual(mergedPair(value, editablePair(PAIR_GUIDE, value)), value);
  }
});

test('tokenValueText: the default entry of a map, the string itself', () => {
  assert.equal(tokenValueText(tokenRecord('space', '4', '1rem')), '1rem');
  assert.equal(tokenValueText(tokenRecord('color', 'bg', { default: '#fff', dark: '#111' })), '#fff');
  assert.equal(tokenValueText(null), '');
});

// ═════ The name grammar and the editor by shape. ═════

test('tokenNameError: the grammar, then the roster', () => {
  const materials = roster(tokenRecord('space', '4', '1rem'), tokenRecord('color', 'accent', '#000'));
  assert.equal(tokenNameError('', 'space', materials), 'missing');
  for (const bad of ['Wide', 'hero gap', '-lead', 'lead-', 'a_b', 'é']) {
    assert.equal(tokenNameError(bad, 'space', materials), 'invalid', bad);
  }
  assert.equal(tokenNameError('4', 'space', materials), 'taken');
  // Names are per scale: the same name on another scale is free.
  assert.equal(tokenNameError('4', 'color', materials), null);
  for (const good of ['4', '2xl', 'accent-hover', 'a']) {
    assert.equal(tokenNameError(good, 'color', materials), null, good);
  }
  // No scale named (the server infers it): the roster is not asked.
  assert.equal(tokenNameError('4', null, materials), null);
});

test('tokenEditorKind, tokenSeed, tokenPaintsFace: the editor by shape', () => {
  const guide = { tokenScales: { color: { shape: 'color' } } };
  const bound = { ...guide, constraintDefaults: { 'font-family': { scale: 'font' } } };
  assert.equal(tokenEditorKind(guide, 'color', 'color'), 'color');
  assert.equal(tokenEditorKind(guide, 'gradient', 'gradient'), 'gradient');
  assert.equal(tokenEditorKind(guide, 'space', 'dimension'), 'text');
  assert.equal(tokenEditorKind(bound, 'font', 'text'), 'stack', 'the bound scale edits as a stack');
  assert.equal(tokenEditorKind(bound, 'gradient', 'gradient'), 'gradient', 'the shape decides before the binding');
  assert.equal(tokenEditorKind(guide, null, null), 'text');
  assert.equal(tokenSeed('color', guide, EMPTY_MATERIALS), '#808080');
  assert.match(tokenSeed('gradient', guide, EMPTY_MATERIALS), /^linear-gradient\(/);
  assert.equal(tokenSeed('dimension', guide, EMPTY_MATERIALS), '');
  assert.equal(tokenPaintsFace('color'), true);
  assert.equal(tokenPaintsFace('gradient'), true);
  assert.equal(tokenPaintsFace('dimension'), false);
  assert.equal(tokenPaintsFace(null), false);
});

// ═════ The sections and their rows. ═════

const GUIDE = {
  conditions: {
    dark: { label: 'Dark', axis: 'scheme' },
  },
  tokenScales: {
    color: { label: 'Colors', shape: 'color', prefix: 'color' },
    space: { label: 'Spacing', shape: 'dimension', prefix: 'space' },
    empty: { label: 'Empty', shape: 'color', prefix: 'empty' },
  },
};
const REGULAR = { weight: '400', style: 'normal', source: { file: 'f-regular' } };
const ITALIC = { weight: '400', style: 'italic', source: { file: 'f-italic' } };
const BOLD = { weight: '700', style: 'normal', source: { file: 'f-bold' } };
const FADE_STEPS = { '0%': { opacity: '0' }, '100%': { opacity: '1' } };
const RISE_STEPS = { '0%': { opacity: '0', transform: 'translateY(1rem)' }, '60%': { opacity: '1' }, '100%': { transform: 'none' } };
// The package ships accent and bg (a site override keeps bg shipped), the
// site minted 4 (described, withheld); the skin carries two animations
// (fade_in shipped) and two fonts (Test Sans shipped).
const ACCENT = tokenRecord('color', 'accent', '#1a1a2e');
const BG = tokenRecord('color', 'bg', { default: '#fff', dark: '#111' });
const FOUR = tokenRecord('space', '4', '1rem', 'space', { origin: 'user', description: 'The step.', offered: false });
const FADE = animationRecord('fade_in', 'fade_in', FADE_STEPS, { description: 'Opacity from nothing to full.', origin: 'shipped' });
const RISE = animationRecord('rise', 'rise', RISE_STEPS);
const INTER = fontRecord('inter', 'inter', 'Inter', [REGULAR, ITALIC, BOLD], { description: 'The body face.' });
const TEST_SANS = fontRecord('test_sans', 'test_sans', 'Test Sans', [REGULAR], { origin: 'shipped' });
const MATERIALS = roster(ACCENT, BG, FOUR, FADE, RISE, INTER, TEST_SANS);

test('skinSections: the scales with their tokens, then the animations, then the fonts', () => {
  const sections = skinSections(GUIDE, MATERIALS);
  assert.deepEqual(sections.map((section) => [section.id, section.kind, section.label, section.rows.map((row) => row.reference)]), [
    ['scale:color', 'token', 'Colors', ['color/accent', 'color/bg']],
    ['scale:space', 'token', 'Spacing', ['space/4']],
    ['animations', 'animation', null, ['fade_in', 'rise']],
    ['fonts', 'font', null, ['inter', 'test_sans']],
  ]);
  // A scale no token is minted on lists no section; an empty roster
  // lists nothing.
  assert.equal(sections.some((section) => section.id === 'scale:empty'), false);
  assert.deepEqual(skinSections(GUIDE, EMPTY_MATERIALS), []);
  assert.deepEqual(skinSections({}, EMPTY_MATERIALS), []);
});

test('skinSections: a token row carries its value, scheme half, shape, paint and record; no origin on the row', () => {
  const [colors, spacing] = skinSections(GUIDE, MATERIALS);
  assert.deepEqual(colors.rows[1], {
    kind: 'token',
    reference: 'color/bg',
    name: 'bg',
    spelling: 'var(--color-bg)',
    scale: 'color',
    shape: 'color',
    value: '#fff',
    valueDark: '#111',
    paint: true,
    description: '',
    offered: true,
    shipped: true,
    record: BG,
  });
  // A single-valued token has no scheme half; a dimension paints no face.
  assert.equal(colors.rows[0].valueDark, null);
  assert.deepEqual(spacing.rows[0], {
    kind: 'token',
    reference: 'space/4',
    name: '4',
    spelling: 'var(--space-4)',
    scale: 'space',
    shape: 'dimension',
    value: '1rem',
    valueDark: null,
    paint: false,
    description: 'The step.',
    offered: false,
    shipped: false,
    record: FOUR,
  });
  // The row's facts are what it shows and what the card locks on; no
  // layer or origin word is among them.
  assert.equal('layer' in colors.rows[0], false);
  assert.equal('origin' in colors.rows[0], false);
});

test('skinSections: an animation row carries its offsets, a font row its faces and family', () => {
  const [, , animations, fonts] = skinSections(GUIDE, MATERIALS);
  assert.deepEqual(animations.rows[0], {
    kind: 'animation',
    reference: 'fade_in',
    name: 'fade_in',
    spelling: 'motion-fade_in',
    offsets: ['0%', '100%'],
    description: 'Opacity from nothing to full.',
    offered: true,
    shipped: true,
    record: FADE,
  });
  assert.deepEqual(animations.rows[1].offsets, ['0%', '60%', '100%']);
  assert.deepEqual(fonts.rows[0], {
    kind: 'font',
    reference: 'inter',
    name: 'inter',
    spelling: '"Inter"',
    family: 'Inter',
    faces: ['400', '400 italic', '700'],
    description: 'The body face.',
    offered: true,
    shipped: false,
    record: INTER,
  });
  assert.equal(fonts.rows[1].shipped, true);
});

test('skinSections: one search over the skin, matching name, spelling, value, family and description', () => {
  const ids = (search) =>
    skinSections(GUIDE, MATERIALS, { search }).flatMap((section) => section.rows.map((row) => row.reference));
  assert.deepEqual(ids('accent'), ['color/accent']);
  assert.deepEqual(ids('FADE'), ['fade_in']);
  assert.deepEqual(ids('motion-'), ['fade_in', 'rise']);
  assert.deepEqual(ids('Inter'), ['inter']);
  assert.deepEqual(ids('the step'), ['space/4']);
  assert.deepEqual(ids('#fff'), ['color/bg'], 'the display value');
  assert.deepEqual(ids('#111'), [], 'the scheme half is not searched; the row does not show it as its value');
  assert.deepEqual(ids('1rem'), ['space/4']);
  assert.deepEqual(ids('nothing here'), []);
  // A matched section lists alone; the others drop.
  assert.deepEqual(skinSections(GUIDE, MATERIALS, { search: 'sans' }).map((section) => section.id), ['fonts']);
});

test('mintableKinds: the create grant, a token only with a scale to mint on', () => {
  assert.deepEqual(mintableKinds(GUIDE, { canCreate: true }), ['token', 'animation', 'font']);
  assert.deepEqual(mintableKinds({}, { canCreate: true }), ['animation', 'font']);
  assert.deepEqual(mintableKinds(GUIDE, { canUpdate: true }), []);
  assert.deepEqual(mintableKinds(GUIDE, {}), []);
  assert.deepEqual(mintableKinds(GUIDE, undefined), []);
});

test('copySeed: a token keeps its scale and value, an animation its steps, a font has no copy', () => {
  assert.deepEqual(copySeed(BG), { kind: 'token', scale: 'color', value: { default: '#fff', dark: '#111' }, description: '' });
  assert.deepEqual(copySeed(FOUR), { kind: 'token', scale: 'space', value: '1rem', description: 'The step.' });
  assert.deepEqual(copySeed(FADE), { kind: 'animation', steps: FADE_STEPS, description: 'Opacity from nothing to full.' });
  assert.equal(copySeed(INTER), null);
  assert.equal(copySeed(null), null);
});

test('materialLayer and materialSpelling: the record\'s origin names the layer, the kind its spelling', () => {
  assert.equal(materialLayer(ACCENT), 'package');
  assert.equal(materialLayer(FOUR), 'site');
  assert.equal(materialLayer(tokenRecord('space', '4', '1rem', 'space', { origin: 'ai' })), 'site');
  assert.equal(materialSpelling(ACCENT), 'var(--color-accent)');
  assert.equal(materialSpelling(FADE), 'motion-fade_in');
  assert.equal(materialSpelling(INTER), '"Inter"');
  assert.equal(materialSpelling(null), null);
});

// ═════ The card's gesture roster. ═════

// The management read on a consuming site (the package layer locked),
// the same token overridden by the site, a site-minted material, and the
// developer gate lifting the lock.
const GRANTS = { canCreate: true, canUpdate: true, canDelete: true, canDeleteInUse: true };
const CONSUMING = { usages: 0, layer: 'package', locked: true, provider: 'olivero', shipped_state: 'unmodified' };
const OVERRIDDEN = { ...CONSUMING, layer: 'both', shipped_state: 'modified' };
const MINTED = { usages: 0, layer: 'site', locked: true, provider: 'olivero', shipped_state: null };
const GATED = { ...CONSUMING, locked: false };
const NO_LOCKS = { rename: null, delete: null };

test('materialCardGestures: the update grade opens the value, description and offered editors on every material', () => {
  assert.deepEqual(materialCardGestures(ACCENT, GRANTS, CONSUMING), {
    editValue: true,
    editDescription: true,
    offer: true,
    rename: false,
    revert: false,
    delete: false,
    copy: true,
    locks: { rename: 'shipped', delete: 'shipped' },
  });
  // The same rule for a shipped animation and a shipped font: the steps
  // and faces edit into the site layer, the identity stays the package's.
  assert.equal(materialCardGestures(FADE, GRANTS, CONSUMING).editValue, true);
  assert.equal(materialCardGestures(FADE, GRANTS, CONSUMING).rename, false);
  assert.equal(materialCardGestures(TEST_SANS, GRANTS, CONSUMING).editValue, true);
  assert.equal(materialCardGestures(TEST_SANS, GRANTS, CONSUMING).copy, false, 'a font has no copy');
  // Without the update grade the card is read-only, whatever the layer,
  // and the rename lock is not shown (no grant to withhold).
  assert.deepEqual(materialCardGestures(FOUR, { canDelete: true }, MINTED), {
    editValue: false,
    editDescription: false,
    offer: false,
    rename: false,
    revert: false,
    delete: true,
    copy: false,
    locks: NO_LOCKS,
  });
  assert.deepEqual(materialCardGestures(ACCENT, {}, CONSUMING), {
    editValue: false,
    editDescription: false,
    offer: false,
    rename: false,
    revert: false,
    delete: false,
    copy: false,
    locks: NO_LOCKS,
  });
});

test('materialCardGestures: identity is the site\'s own on site-minted materials and where the lock is lifted', () => {
  assert.equal(materialCardGestures(FOUR, GRANTS, MINTED).rename, true);
  assert.equal(materialCardGestures(FOUR, GRANTS, MINTED).delete, true);
  assert.deepEqual(materialCardGestures(FOUR, GRANTS, MINTED).locks, NO_LOCKS);
  // The record alone already says so: the read in flight withholds nothing
  // the origin grants.
  assert.equal(materialCardGestures(RISE, GRANTS, null).rename, true);
  assert.equal(materialCardGestures(INTER, GRANTS, null).delete, true);
  // A package material: never on a consuming site, even overridden, and
  // the locks say why while the read is in flight too.
  assert.equal(materialCardGestures(ACCENT, GRANTS, OVERRIDDEN).rename, false);
  assert.equal(materialCardGestures(ACCENT, GRANTS, OVERRIDDEN).delete, false);
  assert.deepEqual(materialCardGestures(ACCENT, GRANTS, null).locks, { rename: 'shipped', delete: 'shipped' });
  // ...and the site's own under the developer gate.
  assert.equal(materialCardGestures(ACCENT, GRANTS, GATED).rename, true);
  assert.equal(materialCardGestures(FADE, GRANTS, GATED).delete, true);
  assert.deepEqual(materialCardGestures(TEST_SANS, GRANTS, GATED).locks, NO_LOCKS);
});

test('materialCardGestures: revert follows the shipped state, the unset gesture on a consuming site', () => {
  assert.equal(materialCardGestures(ACCENT, GRANTS, CONSUMING).revert, false);
  assert.equal(materialCardGestures(ACCENT, GRANTS, OVERRIDDEN).revert, true);
  assert.equal(materialCardGestures(FADE, GRANTS, OVERRIDDEN).revert, true);
  assert.equal(materialCardGestures(ACCENT, { canDelete: true }, OVERRIDDEN).revert, false, 'the update grade, like the override itself');
  assert.equal(materialCardGestures(ACCENT, GRANTS, { ...GATED, shipped_state: 'modified' }).revert, true);
  assert.equal(materialCardGestures(FOUR, GRANTS, { ...MINTED, shipped_state: 'modified' }).revert, false, 'no disk file ships it');
  assert.equal(materialCardGestures(ACCENT, GRANTS, null).revert, false, 'the state is unknown while the read is in flight');
});

test('materialCardGestures: delete carries the two permission grades, the lock says which is missing', () => {
  const used = { ...MINTED, usages: 3 };
  assert.equal(materialCardGestures(FOUR, { canDelete: true }, used).delete, false);
  assert.deepEqual(materialCardGestures(FOUR, { canDelete: true }, used).locks, { rename: null, delete: 'in-use' });
  assert.equal(materialCardGestures(FOUR, { canDeleteInUse: true }, used).delete, true);
  assert.equal(materialCardGestures(FOUR, { canDelete: true }, MINTED).delete, true);
  assert.equal(materialCardGestures(FOUR, { canDeleteInUse: true }, MINTED).delete, true);
  assert.equal(materialCardGestures(FOUR, {}, MINTED).delete, false);
  assert.deepEqual(materialCardGestures(FOUR, {}, MINTED).locks, NO_LOCKS, 'no grant, nothing shown');
  // A shipped material under the in-use grade is still the package's.
  assert.deepEqual(materialCardGestures(FADE, { canDeleteInUse: true }, { ...CONSUMING, usages: 2 }).locks, { rename: null, delete: 'shipped' });
});

// ═════ Keyframes: the offset grammar, the track, the analysis. ═════

const SPIN = animationRecord('spin', 'spin', { from: { transform: 'rotate(0deg)' }, to: { transform: 'rotate(360deg)' } }, { origin: 'shipped' });

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
  assert.deepEqual(canonicalizeSteps({ from: { opacity: '0' }, '0%': { opacity: '0.2' } }), { '0%': { opacity: '0.2' } });
  assert.deepEqual(Object.keys(canonicalizeSteps({ weird: {}, '100%': {}, also: {}, '0%': {} })), ['0%', '100%', 'also', 'weird']);
  assert.deepEqual(canonicalizeSteps(null), {});
});

test('stepStops and stepsOf: the stored map and the track round-trip through canonical offsets', () => {
  const stops = stepStops(SPIN.value);
  assert.deepEqual(stops, [
    { position: '0%', declarations: { transform: 'rotate(0deg)' } },
    { position: '100%', declarations: { transform: 'rotate(360deg)' } },
  ]);
  assert.deepEqual(stepsOf(stops), { '0%': { transform: 'rotate(0deg)' }, '100%': { transform: 'rotate(360deg)' } });
  assert.deepEqual(stepStops({ '50%': null }), [{ position: '50%', declarations: {} }]);
  assert.deepEqual(stepStops(SEED_STEPS).map((stop) => stop.position), ['0%', '100%']);
});

test('keyframesSummary: the steps in one line, the card\'s closed anatomy', () => {
  assert.equal(keyframesSummary(SPIN.value), '0% { transform: rotate(0deg) } · 100% { transform: rotate(360deg) }');
  assert.equal(keyframesSummary(RISE_STEPS), '0% { opacity: 0; transform: translateY(1rem) } · 60% { opacity: 1 } · 100% { transform: none }');
  assert.equal(keyframesSummary({ '50%': {} }), '50% {  }');
  assert.equal(keyframesSummary(null), '');
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
  const crossed = moveStep(stops, 0, 80);
  assert.deepEqual(crossed.stops.map((stop) => stop.position), ['60%', '80%', '100%']);
  assert.deepEqual(crossed.stops[1].declarations, { opacity: '0', transform: 'translateY(1rem)' });
  assert.equal(moveStep(stops, 1, 100), null);
  assert.equal(moveStep(stops, 1, 130), null);
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
const STEP_GUIDE = {
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
const analyze = (text, materials = EMPTY_MATERIALS, extra = {}) => analyzeStep(text, { guide: { ...STEP_GUIDE, ...extra }, materials });
const errorsOf = (analysis) => analysis.diagnostics.filter((diagnostic) => diagnostic.severity === 'error');

test('stepDeclaration: the universal vocabulary, every served group plus the wide tier, no value spaces', () => {
  assert.deepEqual(stepDeclaration(STEP_GUIDE), {
    grants: {
      motion: ['animation-name', 'animation-duration', 'animation-timing-function', 'animation'],
      typography: ['color'],
      'wide:*': widePropertyNames(),
    },
  });
  assert.deepEqual(stepDeclaration({}), { grants: { 'wide:*': widePropertyNames() } });
  assert.ok(widePropertyNames().includes('transform'), 'the wide tier carries every csstree property');
});

test('analyzeStep: a wide-tier property validates inside a step on a strict site', () => {
  const analysis = analyze('transform: rotate(3deg);\nopacity: 0;', EMPTY_MATERIALS, { enforcement: 'strict' });
  assert.deepEqual(errorsOf(analysis), []);
  assert.deepEqual(analysis.declarations.map((record) => record.valid), [true, true]);
  assert.equal(errorsOf(analyze('transfrom: none;', EMPTY_MATERIALS, { enforcement: 'strict' }))[0].code, 'INVALID_STYLE_PROPERTY');
});

test('analyzeStep: declarations of any curated group validate under the universal vocabulary', () => {
  const analysis = analyze('color: #1a1a2e;\nanimation-timing-function: ease-out;\nopacity: 0;');
  assert.deepEqual(analysis.declarations.map((record) => [record.property, record.value, record.valid]), [
    ['color', '#1a1a2e', true],
    ['animation-timing-function', 'ease-out', true],
    ['opacity', '0', true],
  ]);
  assert.deepEqual(errorsOf(analysis), []);
  assert.deepEqual(settledStep(analysis.declarations), {
    declarations: { color: '#1a1a2e', 'animation-timing-function': 'ease-out', opacity: '0' },
    hasErrors: false,
  });
});

test('analyzeStep: the wearer\'s animation properties are refused inside a step', () => {
  const analysis = analyze('animation-duration: 1s;\nanimation: spin 1s;\ntimeline-trigger: --in-view view() entry 20%;\nanimation-trigger: --in-view play-once;\nanimation-timing-function: linear;');
  const refused = errorsOf(analysis).filter((diagnostic) => diagnostic.code === 'INVALID_ANIMATION_STEP_PROPERTY');
  assert.equal(refused.length, 4);
  assert.match(refused[0].message, /animation-timing-function only/);
  assert.deepEqual(analysis.declarations.map((record) => record.valid), [false, false, false, false, true]);
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
  assert.match(errorsOf(analyze('.x { color: red; }'))[0].message, /holds declarations only/);
  assert.match(errorsOf(analyze('@hover { color: red; }'))[0].message, /holds declarations only/);
  const materials = roster(tokenRecord('color', 'accent', '#1a1a2e'), tokenRecord('color', 'brand-red', '#b91c1c', 'color', { origin: 'user' }));
  assert.deepEqual(errorsOf(analyze('color: var(--color-accent);', materials)), []);
  assert.equal(analyze('color: var(--color-missing);', materials, { enforcement: 'strict', tokenScales: { color: { label: 'Color', shape: 'color', prefix: 'color' } } }).declarations[0].valid, false);
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
  assert.equal(animationPreviewCss(materials, new Map([['fade_in', { '0%': {} }]])), printKeyframes('motion-spin', SPIN.value));
  assert.equal(animationPreviewCss(EMPTY_MATERIALS), '');
});

// ═════ Fonts: faces, the name, the stack, the delivery trigger. ═════

const RANGE = fontRecord('inter_variable', 'inter_variable', 'Inter Variable', [{ weight: '100 900', style: 'normal', source: { file: 'f-vf' } }]);

test('faceLabel: the weight, the style only when it is not normal', () => {
  assert.equal(faceLabel(REGULAR), '400');
  assert.equal(faceLabel(ITALIC), '400 italic');
  assert.equal(faceLabel(BOLD), '700');
  assert.equal(faceLabel({ weight: '100 900', style: 'normal', source: {} }), '100 900');
  assert.equal(faceLabel({ source: {} }), '400');
});

test('fontName: the roster id a label derives', () => {
  assert.equal(fontName('Scope One'), 'scope_one');
  assert.equal(fontName('  Inter Tight '), 'inter_tight');
  assert.equal(fontName('D-DIN'), 'd_din');
  assert.equal(fontName('inter'), 'inter');
  assert.equal(fontName('???'), '');
  assert.equal(fontName(null), '');
});

test('fontOptions and fontSpelling: the roster projected, the quoted family the spelling', () => {
  const materials = roster(INTER, tokenRecord('color', 'brand-red', '#b91c1c'), TEST_SANS);
  assert.deepEqual(fontOptions(materials).map((entry) => [entry.name, entry.family, entry.spelling, entry.faces.length, entry.description]), [
    ['inter', 'Inter', '"Inter"', 3, 'The body face.'],
    ['test_sans', 'Test Sans', '"Test Sans"', 1, ''],
  ]);
  assert.equal(fontOptions(materials)[0].record, INTER);
  assert.equal(fontSpelling(INTER), '"Inter"');
  assert.equal(fontSpelling(tokenRecord('color', 'brand-red', '#b91c1c')), null);
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
  assert.equal(addFamilyToStack('Inter, sans-serif', '"Inter"'), 'Inter, sans-serif');
  assert.equal(addFamilyToStack("'Inter',serif", '"Inter"'), "'Inter', serif");
  assert.equal(addFamilyToStack('Georgia, MONOSPACE', '"Inter"'), 'Georgia, "Inter", MONOSPACE');
  GENERIC_FAMILIES.forEach((generic) => {
    assert.equal(addFamilyToStack(generic, '"Inter"'), `"Inter", ${generic}`, generic);
  });
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

test('faceDraft, storedFaceDraft and facesOf: uploads and stored faces become drafts, drafts become the op\'s faces', () => {
  const draft = faceDraft({ file: 'uuid-1', name: 'inter-bold-italic.woff2', format: 'woff2', url: '/files/inter-bold-italic.woff2' }, 7);
  assert.deepEqual(draft, { key: 7, file: 'uuid-1', name: 'inter-bold-italic.woff2', format: 'woff2', url: '/files/inter-bold-italic.woff2', weight: '700', style: 'italic' });
  assert.deepEqual(facesOf([draft, { ...draft, key: 8, file: 'uuid-2', weight: ' ', style: '' }]), [
    { weight: '700', style: 'italic', source: { file: 'uuid-1' } },
    { weight: '400', style: 'normal', source: { file: 'uuid-2' } },
  ]);
  assert.equal(faceDraft({ file: 'u', name: 'x.woff', url: '/x.woff' }, 1).format, null);
  // A stored face keeps its file and reads as its label (the stored name
  // is not kept); the round trip through facesOf is the face itself.
  assert.deepEqual(storedFaceDraft(ITALIC, 2), { key: 2, file: 'f-italic', name: '400 italic', format: null, url: null, weight: '400', style: 'italic' });
  assert.deepEqual(facesOf([REGULAR, ITALIC, BOLD].map(storedFaceDraft)), [REGULAR, ITALIC, BOLD]);
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
  assert.notEqual(fontsSignature(roster(fontRecord('inter', 'inter', 'Inter', [REGULAR]), TEST_SANS)), signature, 'a face left');
  assert.notEqual(fontsSignature(roster(fontRecord('inter', 'inter', 'Inter Display', [REGULAR, ITALIC, BOLD]), TEST_SANS)), signature, 'the family changed');
  assert.equal(fontsSignature(roster(fontRecord('inter', 'inter', 'Inter', [REGULAR, ITALIC, BOLD], { description: 'x' }), TEST_SANS)), signature);
  assert.equal(fontsSignature(roster(INTER, TEST_SANS, tokenRecord('color', 'brand-red', '#b91c1c'))), signature);
  assert.equal(fontsSignature(EMPTY_MATERIALS), '[]');
});

// The materialsFrom fixture grammar still feeds the sections.
test('skinSections over materialsFrom: the guide fixture grammar', () => {
  const materials = materialsFrom({
    tokenScales: GUIDE.tokenScales,
    tokens: { color: { accent: '#1a1a2e', paper: { value: '#fdfdf6', origin: 'user' } } },
  });
  const [colors] = skinSections(GUIDE, materials);
  assert.deepEqual(colors.rows.map((row) => [row.name, row.shipped]), [['accent', true], ['paper', false]]);
});

let failures = 0;
for (const [name, run] of tests) {
  try {
    run();
    console.log(`ok - ${name}`);
  } catch (error) {
    failures += 1;
    console.error(`not ok - ${name}`);
    console.error(error);
  }
}
if (failures > 0) {
  console.error(`${failures} of ${tests.length} skin-model tests failed.`);
  process.exit(1);
}
console.log(`${tests.length} skin-model tests passed.`);
