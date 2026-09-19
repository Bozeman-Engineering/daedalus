/**
 * Self-asserting tests for the spacing widget's grant-aware padding row.
 *
 * The guide's property allowlist (a group declaration's `properties` key)
 * can narrow a target's spacing grant to a side subset, and the row then
 * offers only the declared sides; the whole group (the shipped container)
 * offers all four at every placement depth. Grants are stated over
 * longhands and the SERVER grants the `padding` shorthand iff every side
 * is granted (the roster's family rule), so the row reads the shorthand
 * from the served names and never derives it. The padding row's pure
 * halves live in box-model.js (grantedSides, subsetCommit, the side-subset
 * uniformState, and uniformFieldText, the shorthand field's face) and
 * style-guide.js (propertyGranted, the served roster read); this file
 * locks them under plain node.
 *
 * Run directly: `node tests/js/padding-row.test.mjs`. Exits non-zero on
 * failure.
 */
import { strict as assert } from 'node:assert';
import {
  grantedSides,
  subsetCommit,
  uniformFieldText,
  uniformState,
} from '../../js/src/widgets/lib/box-model.js';
import { propertyGranted } from '../../js/src/style-guide.js';

const tests = [];
const test = (name, run) => tests.push([name, run]);

// A side-subset grant as the roster serves it (vertical rhythm plus the
// logical inline spelling; no `padding` shorthand because not every side
// is granted, no physical inline longhands; margin and gap whole, their
// shorthands derived): the narrowed-grant fixture. The shipped container
// grants the whole group.
const CONTAINER_GRANT = [
  'padding-top', 'padding-bottom', 'padding-inline',
  'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
  'gap', 'row-gap', 'column-gap',
];

// The whole padding family as served: the shorthand rides the four sides.
const SPACING = ['padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'padding-inline', 'margin', 'gap'];

// --- grantedSides ---------------------------------------------------------

test('grantedSides is all four under the whole family', () => {
  assert.deepEqual(grantedSides(SPACING), ['top', 'right', 'bottom', 'left']);
  // The shorthand alone names no side: the sides come from the longhands
  // and the shorthand from the roster, never one from the other.
  assert.deepEqual(grantedSides(['padding']), []);
  assert.deepEqual(grantedSides([]), []);
});

test('grantedSides derives the side vocabulary from granted longhands', () => {
  assert.deepEqual(grantedSides(CONTAINER_GRANT), ['top', 'bottom']);
  // Logical longhands are not physical sides — padding-inline grants no
  // left/right cell (the physical grammar, propertySides' rule).
  assert.deepEqual(grantedSides(['padding-inline']), []);
  // SIDES order regardless of grant-list order.
  assert.deepEqual(grantedSides(['padding-left', 'padding-top']), ['top', 'left']);
  // Family-scoped: the margin family reads its own longhands.
  assert.deepEqual(grantedSides(CONTAINER_GRANT, 'margin'), ['top', 'right', 'bottom', 'left']);
});

// --- subsetCommit ---------------------------------------------------------

test('subsetCommit writes one value onto each offered side longhand', () => {
  assert.deepEqual(subsetCommit(null, ['top', 'bottom'], '2rem'), {
    'padding-top': '2rem',
    'padding-bottom': '2rem',
  });
});

test('subsetCommit null unsets the offered sides only', () => {
  const own = { 'padding-top': '2rem', 'padding-bottom': '2rem', 'padding-left': '1rem' };
  assert.deepEqual(subsetCommit(own, ['top', 'bottom'], null), {
    'padding-top': null,
    'padding-bottom': null,
  }, 'the withheld own longhand is untouched — the click said nothing about it');
});

test('subsetCommit unsets a legacy own shorthand in the same patch', () => {
  // An own `padding` from before the grant narrowed is un-respellable
  // under the subset (the shorthand is withheld); dropping it returns
  // the withheld sides to the variant/skin layer that owns them.
  assert.deepEqual(subsetCommit({ padding: '1rem' }, ['top', 'bottom'], '2rem'), {
    padding: null,
    'padding-top': '2rem',
    'padding-bottom': '2rem',
  });
});

// --- uniformState over a side subset -------------------------------------

test('subset uniformState judges set-ness over the offered sides only', () => {
  assert.deepEqual(
    uniformState({ 'padding-left': '3rem' }, { 'padding-left': '3rem' }, 'padding', ['top', 'bottom']),
    { set: false, uniform: null, text: null },
    'a withheld side\'s value never lights the subset row',
  );
});

test('subset uniformState lights the chip when the offered sides agree', () => {
  const own = { 'padding-top': '2rem', 'padding-bottom': '2rem', 'padding-left': '9rem' };
  const state = uniformState(own, own, 'padding', ['top', 'bottom']);
  assert.deepEqual(state, { set: true, uniform: '2rem', text: '2rem' });
});

test('subset uniformState reports differing offered sides per side', () => {
  const own = { 'padding-top': '2rem', 'padding-bottom': '4rem' };
  const state = uniformState(own, own, 'padding', ['top', 'bottom']);
  assert.equal(state.set, true);
  assert.equal(state.uniform, null);
  assert.equal(state.text, 'top 2rem, bottom 4rem', 'no shorthand spelling exists for a side subset');
});

test('subset uniformState reads variant-supplied sides like the full row', () => {
  const state = uniformState(null, { 'padding-block': 'x', 'padding-top': '1rem', 'padding-bottom': '1rem' }, 'padding', ['top', 'bottom']);
  assert.deepEqual(state, { set: true, uniform: '1rem', text: '1rem' });
});

test('four-side uniformState behavior is unchanged by the default', () => {
  const own = { padding: '1rem 2rem' };
  const state = uniformState(own, own);
  assert.equal(state.set, true);
  assert.equal(state.uniform, null);
  assert.equal(state.text, '1rem 2rem');
});

// --- uniformFieldText (the shorthand field's face) -------------------------

test('uniformFieldText is empty when nothing styles the offered sides', () => {
  assert.equal(uniformFieldText(uniformState(null, null), undefined), '');
});

test('uniformFieldText shows the one honest value in its committed spelling', () => {
  const shorthand = { padding: '1rem' };
  assert.equal(uniformFieldText(uniformState(shorthand, shorthand), '1rem'), '1rem');
  const reference = { padding: 'var(--space-4)' };
  assert.equal(
    uniformFieldText(uniformState(reference, reference), 'var(--space-4)'),
    'var(--space-4)',
    'a whole-reference shorthand keeps its ref spelling — the field maps ref to display itself',
  );
});

test('uniformFieldText degrades per-side padding to the honest text', () => {
  const axis = { padding: '1rem 2rem' };
  assert.equal(uniformFieldText(uniformState(axis, axis), '1rem 2rem'), '1rem 2rem',
    'differing sides show the minimal shorthand; typing over it makes all sides equal');
  const partial = { 'padding-top': '2rem' };
  assert.equal(uniformFieldText(uniformState(partial, partial), undefined), 'top 2rem',
    'partly-set sides list themselves');
});

test('uniformFieldText falls back to the raw spelling for the opaque case', () => {
  const opaque = { padding: 'calc(1rem + 2px)' };
  assert.equal(uniformFieldText(uniformState(opaque, opaque), 'calc(1rem + 2px)'), 'calc(1rem + 2px)',
    'a set value always stays visible, even one only text can carry');
  assert.equal(uniformFieldText({ set: true, uniform: null, text: null }, undefined), '',
    'no raw spelling to fall back to — honestly empty');
});

test('uniformFieldText judges a subset grant over its offered sides', () => {
  const own = { 'padding-top': '2rem', 'padding-bottom': '2rem', 'padding-left': '9rem' };
  assert.equal(uniformFieldText(uniformState(own, own, 'padding', ['top', 'bottom']), undefined), '2rem',
    'the withheld side never reaches the field');
});

// --- propertyGranted (the served roster's grant read) ---------------------

test('the shorthand is the roster\'s grant, never derived from the sides', () => {
  // Four sides served without the shorthand is not a grant the server
  // produces (its family rule adds `padding` over all four), and the
  // client does not second-guess the served names either way: the row's
  // shorthandGranted is propertyGranted's answer, nothing more.
  const sidesOnly = { grants: { spacing: ['padding-top', 'padding-right', 'padding-bottom', 'padding-left'] } };
  assert.equal(propertyGranted(sidesOnly, 'padding'), false, 'no derivation from the sides');
  assert.deepEqual(grantedSides(['padding-top', 'padding-right', 'padding-bottom', 'padding-left']), ['top', 'right', 'bottom', 'left']);
  const served = { grants: { spacing: SPACING } };
  assert.equal(propertyGranted(served, 'padding'), true, 'the served name is the grant');
  assert.equal(propertyGranted({ grants: { spacing: CONTAINER_GRANT } }, 'gap'), true, 'gap rides the names where the server derived it');
});

test('propertyGranted reads the names the node grants', () => {
  // A narrowed grant serves exactly its names (resolved server-side);
  // another group's whole grant rides beside it.
  const declaration = { grants: { spacing: CONTAINER_GRANT, layout: ['display'] } };
  assert.equal(propertyGranted(declaration, 'padding'), false);
  assert.equal(propertyGranted(declaration, 'padding-left'), false);
  assert.equal(propertyGranted(declaration, 'padding-top'), true);
  assert.equal(propertyGranted(declaration, 'padding-inline'), true);
  assert.equal(propertyGranted(declaration, 'display'), true);
});

test('propertyGranted binds at every placement depth', () => {
  // Placement curates offers and birth, never legality: the served node
  // carries no depth projection and the read takes no depth.
  const declaration = { grants: { spacing: CONTAINER_GRANT } };
  assert.equal(propertyGranted.length, 2, 'no depth argument exists');
  assert.equal(propertyGranted(declaration, 'padding'), false);
  assert.equal(propertyGranted(declaration, 'padding-left'), false);
  // The whole group (the shipped container): a page band's padding row
  // offers four sides, exactly like a nested card's.
  const whole = { grants: { spacing: SPACING } };
  const offered = SPACING.filter((property) => propertyGranted(whole, property));
  assert.deepEqual(grantedSides(offered), ['top', 'right', 'bottom', 'left'], 'root grants four sides');
});

test('propertyGranted is false wherever nothing grants', () => {
  assert.equal(propertyGranted({ grants: { spacing: SPACING } }, 'padding'), true);
  assert.equal(propertyGranted(null, 'padding'), false, 'no node, no grant');
  assert.equal(propertyGranted({}, 'padding'), false, 'an undeclared place grants nothing');
  assert.equal(propertyGranted({ grants: [] }, 'padding'), false, 'a PHP-empty grants map');
});

// --- the border families through the same seam ---------------------------

test('grantedSides reads a border family in its own spelling', () => {
  const stroke = [
    'border-width', 'border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width',
    'border-style', 'border-top-style',
  ];
  assert.deepEqual(grantedSides(stroke, 'border-width'), ['top', 'right', 'bottom', 'left']);
  assert.deepEqual(grantedSides(stroke, 'border-style'), ['top']);
  assert.deepEqual(grantedSides(stroke, 'border-color'), []);
  assert.deepEqual(grantedSides(stroke, 'padding'), [], 'a border grant offers no padding side');
});

test('subsetCommit, uniformState and uniformFieldText speak a border family', () => {
  assert.deepEqual(subsetCommit({ 'border-width': '1px' }, ['top', 'bottom'], '2px', 'border-width'), {
    'border-width': null, 'border-top-width': '2px', 'border-bottom-width': '2px',
  });
  const own = { 'border-top-width': '1px', 'border-bottom-width': '1px' };
  const state = uniformState(own, null, 'border-width', ['top', 'bottom']);
  assert.deepEqual(state, { set: true, uniform: '1px', text: '1px' });
  assert.equal(uniformFieldText(state, null), '1px');
  assert.deepEqual(uniformState(own, null, 'border-width'), { set: true, uniform: null, text: 'top 1px, bottom 1px' }, 'over all four sides the horizontal pair is unset');
});

// --- runner ---------------------------------------------------------------

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
  console.error(`${failures} failing`);
  process.exit(1);
}
console.log(`${tests.length} passing`);
