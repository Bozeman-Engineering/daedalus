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
 * uniformState over the model's stack, and uniformFieldText, the
 * shorthand field's face),
 * style-guide.js (propertyGranted, the served roster read) and
 * widgets/lib/write-layer.js (writeLayer / ownLayerAt, the own layer a
 * patch expands and collapses against, read per WRITE at the box the
 * write lands on: its set members at the model's landing, a removal
 * alone at the retired name's key); this file locks them under plain
 * node.
 *
 * Run directly: `node tests/js/padding-row.test.mjs`. Exits non-zero on
 * failure.
 */
import { strict as assert } from 'node:assert';
import {
  grantedSides,
  sidesPatch,
  subsetCommit,
  uniformCommit,
  uniformFieldText,
  uniformState,
} from '../../js/src/widgets/lib/box-model.js';
import {
  fieldState,
  openChrome,
} from '../../js/src/widgets/lib/dimension-value.js';
import {
  ownLayerAt,
  writeLayer,
} from '../../js/src/widgets/lib/write-layer.js';
import { propertyGranted } from '../../js/src/style-guide.js';
import { stackOf, stacksOf } from './stack-fixture.mjs';

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
    uniformState(stacksOf({ own: { 'padding-left': '3rem' } }), 'padding', ['top', 'bottom']),
    { set: false, uniform: null, text: null },
    'a withheld side\'s value never lights the subset row',
  );
});

test('subset uniformState lights the chip when the offered sides agree', () => {
  const own = { 'padding-top': '2rem', 'padding-bottom': '2rem', 'padding-left': '9rem' };
  const state = uniformState(stacksOf({ own }), 'padding', ['top', 'bottom']);
  assert.deepEqual(state, { set: true, uniform: '2rem', text: '2rem' });
});

test('subset uniformState reports differing offered sides per side', () => {
  const own = { 'padding-top': '2rem', 'padding-bottom': '4rem' };
  const state = uniformState(stacksOf({ own }), 'padding', ['top', 'bottom']);
  assert.equal(state.set, true);
  assert.equal(state.uniform, null);
  assert.equal(state.text, 'top 2rem, bottom 4rem', 'no shorthand spelling exists for a side subset');
});

test('subset uniformState reads variant-supplied sides like the full row', () => {
  const state = uniformState(stacksOf({ variants: [{ id: 'v', styles: { 'padding-block': 'x', 'padding-top': '1rem', 'padding-bottom': '1rem' } }] }), 'padding', ['top', 'bottom']);
  assert.deepEqual(state, { set: true, uniform: '1rem', text: '1rem' });
});

test('four-side uniformState behavior is unchanged by the default', () => {
  const state = uniformState(stacksOf({ own: { padding: '1rem 2rem' } }));
  assert.equal(state.set, true);
  assert.equal(state.uniform, null);
  assert.equal(state.text, '1rem 2rem');
});

// --- uniformFieldText (the shorthand field's face) -------------------------

test('uniformFieldText is empty when nothing styles the offered sides', () => {
  assert.equal(uniformFieldText(uniformState(stacksOf()), undefined), '');
});

test('uniformFieldText shows the one honest value in its committed spelling', () => {
  assert.equal(uniformFieldText(uniformState(stacksOf({ own: { padding: '1rem' } })), '1rem'), '1rem');
  assert.equal(
    uniformFieldText(uniformState(stacksOf({ own: { padding: 'var(--space-4)' } })), 'var(--space-4)'),
    'var(--space-4)',
    'a whole-reference shorthand keeps its ref spelling — the field maps ref to display itself',
  );
});

test('uniformFieldText degrades per-side padding to the honest text', () => {
  assert.equal(uniformFieldText(uniformState(stacksOf({ own: { padding: '1rem 2rem' } })), '1rem 2rem'), '1rem 2rem',
    'differing sides show the minimal shorthand; typing over it makes all sides equal');
  assert.equal(uniformFieldText(uniformState(stacksOf({ own: { 'padding-top': '2rem' } })), undefined), 'top 2rem',
    'partly-set sides list themselves');
});

test('uniformFieldText falls back to the raw spelling for the opaque case', () => {
  assert.equal(uniformFieldText(uniformState(stacksOf({ own: { padding: 'calc(1rem + 2px)' } })), 'calc(1rem + 2px)'), 'calc(1rem + 2px)',
    'a set value always stays visible, even one only text can carry');
  assert.equal(uniformFieldText({ set: true, uniform: null, text: null }, undefined), '',
    'no raw spelling to fall back to — honestly empty');
});

test('a listing or an unsplittable face is text to the field, and text wears no dimension chrome', () => {
  // The seam between the shorthand field's face and the DimensionField's
  // chrome (dimension-value.js fieldState, openChrome): "left 1.41rem"
  // beside a live rem well read as a number with a unit; a listing is
  // neither, so the field renders it as plain text, no unit, no steppers.
  const listing = uniformFieldText(uniformState(stacksOf({ own: { 'padding-left': '1.41rem' } })), undefined);
  assert.equal(listing, 'left 1.41rem');
  assert.equal(fieldState(listing).mode, 'text');
  assert.deepEqual(openChrome({ spaceUnit: 'rem', mode: fieldState(listing).mode }), { unit: 'none', steppers: false });
  const opaque = uniformFieldText(uniformState(stacksOf({ own: { padding: 'calc(1rem + 2px)' } })), 'calc(1rem + 2px)');
  assert.deepEqual(openChrome({ spaceUnit: 'rem', mode: fieldState(opaque).mode }), { unit: 'none', steppers: false });
  assert.deepEqual(openChrome({ spaceUnit: 'rem', mode: fieldState('1rem').mode }), { unit: 'label', steppers: true },
    'the one honest value wears the space\'s unit as a label');
});

test('uniformFieldText judges a subset grant over its offered sides', () => {
  const own = { 'padding-top': '2rem', 'padding-bottom': '2rem', 'padding-left': '9rem' };
  assert.equal(uniformFieldText(uniformState(stacksOf({ own }), 'padding', ['top', 'bottom']), undefined), '2rem',
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
  const state = uniformState(stacksOf({ own }), 'border-width', ['top', 'bottom']);
  assert.deepEqual(state, { set: true, uniform: '1px', text: '1px' });
  assert.equal(uniformFieldText(state, null), '1px');
  assert.deepEqual(uniformState(stacksOf({ own }), 'border-width'), { set: true, uniform: null, text: 'top 1px, bottom 1px' }, 'over all four sides the horizontal pair is unset');
});

// --- the write layer (write-layer.js) --------------------------------------

// A two-box binding as controlModels serves it: the shorthand's key names
// the anchor (padding-top and the others resolve there too), while the
// address rule lands any SET on the root, since the root wears the
// authored padding. The model's landing records what it was asked so a
// spec can pin that a removal never asks it.
const ROOT = 'block:1';
const ANCHOR = 'block:1::a';
const twoBox = () => {
  const asked = [];
  const model = {
    condition: 'default',
    keys: {
      padding: ANCHOR,
      'padding-top': ANCHOR,
      'padding-right': ANCHOR,
      'padding-bottom': ANCHOR,
      'padding-left': ANCHOR,
    },
    landing: (set) => {
      asked.push(set);
      return ROOT;
    },
  };
  return { model, asked };
};
const TWO_BOX_MAP = {
  [ROOT]: { default: { padding: '1rem 2rem', color: 'red' }, narrow: { padding: '0' } },
  [ANCHOR]: { default: { 'padding-top': '4px' } },
};

test('ownLayerAt reads one box\'s block at the editing compound, order kept', () => {
  assert.deepEqual(ownLayerAt(TWO_BOX_MAP, ROOT, 'default'), { padding: '1rem 2rem', color: 'red' });
  assert.deepEqual(Object.keys(ownLayerAt(TWO_BOX_MAP, ROOT, 'default')), ['padding', 'color']);
  assert.deepEqual(ownLayerAt(TWO_BOX_MAP, ROOT, 'narrow'), { padding: '0' });
  assert.equal(ownLayerAt(TWO_BOX_MAP, ANCHOR, 'narrow'), null, 'no block at the pose');
  assert.equal(ownLayerAt(TWO_BOX_MAP, 'block:9', 'default'), null, 'an unknown key');
  assert.equal(ownLayerAt(TWO_BOX_MAP, null, 'default'), null, 'no key');
  assert.equal(ownLayerAt(null, ROOT, 'default'), null, 'no map');
  assert.equal(ownLayerAt({ [ROOT]: { default: 'oops' } }, ROOT, 'default'), null, 'a malformed entry is no layer');
});

test('writeLayer asks the landing for a write with set members, nulls left out of the set', () => {
  const { model, asked } = twoBox();
  assert.deepEqual(writeLayer(TWO_BOX_MAP, model, { padding: '2rem' }), { padding: '1rem 2rem', color: 'red' }, 'the shorthand write reads the root the set lands on, not keys.padding');
  assert.deepEqual(asked, [['padding']]);
  assert.deepEqual(
    writeLayer(TWO_BOX_MAP, model, { 'padding-top': '3px', 'padding-bottom': null }),
    { padding: '1rem 2rem', color: 'red' },
  );
  assert.deepEqual(asked[1], ['padding-top'], 'a null member is a removal riding beside the set, never in it');
});

test('writeLayer reads a removal alone at the retired name\'s key and never asks the landing', () => {
  const { model, asked } = twoBox();
  assert.deepEqual(writeLayer(TWO_BOX_MAP, model, { 'padding-top': null }), { 'padding-top': '4px' }, 'the anchor, where the pipeline lands the null');
  assert.deepEqual(writeLayer(TWO_BOX_MAP, model, { padding: null, 'padding-top': null }), { 'padding-top': '4px' });
  assert.deepEqual(asked, []);
  assert.throws(() => writeLayer(TWO_BOX_MAP, model, {}), /empty write/, 'an empty write is the caller\'s probe to name');
  assert.throws(() => writeLayer(TWO_BOX_MAP, model, null), /empty write/);
});

test('the padding row\'s patches expand and collapse against the layer of each write', () => {
  const { model } = twoBox();
  const ownFor = (write) => writeLayer(TWO_BOX_MAP, model, write);
  // The field's shorthand write collapses the landing box's longhands
  // (none on the root: a plain shorthand write), never the anchor's.
  assert.deepEqual(uniformCommit(ownFor({ padding: '3rem' }), '3rem'), { padding: '3rem' });
  // The ring's side write expands the root's own shorthand once.
  const ringPatch = (bySide) => {
    const write = Object.fromEntries(
      Object.entries(bySide ?? {}).map(([side, value]) => [`padding-${side}`, value]),
    );
    const own = Object.keys(write).length
      ? ownFor(write)
      : ownLayerAt(TWO_BOX_MAP, model.landing(['padding-top', 'padding-right', 'padding-bottom', 'padding-left']), model.condition);
    return sidesPatch(own, bySide, 'padding');
  };
  assert.deepEqual(ringPatch({ top: '3rem' }), {
    padding: null, 'padding-top': '3rem', 'padding-right': '2rem', 'padding-bottom': '1rem', 'padding-left': '2rem',
  });
  // The ring's clear reads the anchor (the retired name's key), where a
  // plain longhand sits: a plain unset, no expansion.
  assert.deepEqual(ringPatch({ top: null }), { 'padding-top': null });
  // The empty probe asks at the longhands' landing: the root's splittable
  // shorthand does not block the ring.
  assert.deepEqual(ringPatch({}), {});
  assert.equal(
    sidesPatch(ownLayerAt({ [ROOT]: { default: { padding: 'calc(1rem + 2px)' } } }, ROOT, 'default'), {}, 'padding'),
    null,
    'an unsplittable shorthand at the landing still blocks',
  );
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
