/**
 * Self-asserting tests for the client twin of the self-provenance rule.
 *
 * A blueprint's own canvas is its own first placement: the capture strips
 * every stamp from the document, so the owner's canvas carried no
 * provenance and withheld the looks it had just adopted. The server
 * checker (BlueprintVariantOwnershipChecker::placementProvenance(), pinned
 * by BlueprintOwnedVariantTest::testOwnedVariantAppliesOnOwnCanvas)
 * answers the host's machine name for an element it FINDS unstamped on a
 * blueprint host; variant-scope.js blueprintProvenance() takes the
 * canvas's self-provenance as that fallback. These tests lock the twin to
 * the server's placement of the fallback: self substitutes for a
 * found-but-unstamped node, a stamped nested placement still shadows it,
 * and a miss (unknown uuid, no tree, no uuid) stays null with self in
 * hand, so degraded never over-offers.
 *
 * Run directly (`node tests/js/variant-scope.test.mjs`) or through
 * `npm test`. Exits non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import {
  applicableVariants,
  blueprintProvenance,
  continuedKey,
  elementEntries,
  relativeUnder,
} from '../../js/src/variant-scope.js';
import { EMPTY_MATERIALS, roster, blueprintRecord, variantRecord } from './materials-fixture.mjs';

const ROOT = 'aaaaaaaa-1111-2222-3333-444444444444';
const TEXT = 'bbbbbbbb-1111-2222-3333-444444444444';
const NESTED = 'cccccccc-1111-2222-3333-444444444444';
const NESTED_CHILD = 'dddddddd-1111-2222-3333-444444444444';
const INNER = 'eeeeeeee-1111-2222-3333-444444444444';
const UNKNOWN = 'ffffffff-1111-2222-3333-444444444444';

// A blueprint's own canvas after capture: the root container and its
// children carry no stamp (withoutProvenance()); one nested placement of
// another blueprint keeps its own stamp, as place_blueprint left it.
const ownCanvas = [
  {
    kind: 'container',
    uuid: ROOT,
    layout_id: 'layout_onecol',
    children: [
      { kind: 'block', uuid: TEXT, block_type: 'text' },
      {
        kind: 'container',
        uuid: INNER,
        layout_id: 'layout_onecol',
        children: [],
      },
      {
        kind: 'container',
        uuid: NESTED,
        layout_id: 'layout_onecol',
        blueprint: 'inner_section',
        children: [{ kind: 'block', uuid: NESTED_CHILD, block_type: 'basic' }],
      },
    ],
  },
];

const tests = [];
const test = (name, run) => tests.push([name, run]);

test('an unstamped node the tree carries answers the canvas\'s self-provenance', () => {
  // The root, a child block, a nested unstamped container and a part key
  // alike: every element the walk finds without a stamp of its own or an
  // ancestor's is the blueprint's own placement.
  assert.equal(blueprintProvenance(`container:${ROOT}`, ownCanvas, 'section'), 'section');
  assert.equal(blueprintProvenance(`block:${TEXT}`, ownCanvas, 'section'), 'section');
  assert.equal(blueprintProvenance(`container:${INNER}`, ownCanvas, 'section'), 'section');
  assert.equal(blueprintProvenance(`block:${TEXT}::.quote`, ownCanvas, 'section'), 'section');
  // Without a self (a page canvas) the same nodes stay unprovenanced:
  // inheritance and the null posture are unchanged.
  assert.equal(blueprintProvenance(`container:${ROOT}`, ownCanvas), null);
  assert.equal(blueprintProvenance(`block:${TEXT}`, ownCanvas, null), null);
  // An empty self is no self.
  assert.equal(blueprintProvenance(`container:${ROOT}`, ownCanvas, ''), null);
});

test('a stamped nested placement shadows the canvas\'s self-provenance', () => {
  // The same precedence the server walk keeps: the nearest stamp wins,
  // self only fills the gap where no stamp reaches.
  assert.equal(blueprintProvenance(`container:${NESTED}`, ownCanvas, 'section'), 'inner_section');
  assert.equal(blueprintProvenance(`block:${NESTED_CHILD}`, ownCanvas, 'section'), 'inner_section');
  assert.equal(blueprintProvenance(`block:${NESTED_CHILD}::.cls`, ownCanvas, 'section'), 'inner_section');
});

test('a miss stays null even with a self in hand: degraded never over-offers', () => {
  // A uuid the tree does not carry, no tree at all, a null tree (the
  // satellite before its first broadcast), and a key without a uuid.
  assert.equal(blueprintProvenance(`container:${UNKNOWN}`, ownCanvas, 'section'), null);
  assert.equal(blueprintProvenance(`container:${ROOT}`, [], 'section'), null);
  assert.equal(blueprintProvenance(`container:${ROOT}`, null, 'section'), null);
  assert.equal(blueprintProvenance(`container:${ROOT}`, undefined, 'section'), null);
  assert.equal(blueprintProvenance('bogus', ownCanvas, 'section'), null);
  assert.equal(blueprintProvenance(null, ownCanvas, 'section'), null);
});

test('applicableVariants offers an owned variant on the owner\'s own canvas and withholds another owner\'s', () => {
  const materials = roster(
    variantRecord('free_look', { scope: 'kind:container' }),
    variantRecord('section_look', { scope: 'kind:container', owned_by: 'section' }),
    variantRecord('section_text', { scope: 'kind:block', owned_by: 'section' }),
    variantRecord('other_look', { scope: 'kind:container', owned_by: 'other_blueprint' }),
    variantRecord('inner_look', { scope: 'kind:container', owned_by: 'inner_section' }),
    variantRecord('orphan_look', { scope: 'kind:container', owned_by: 'deleted_blueprint' }),
    blueprintRecord('section'),
    blueprintRecord('other_blueprint'),
    blueprintRecord('inner_section'),
  );
  const ids = (key, self, tree = ownCanvas) =>
    applicableVariants(materials, key, {}, {}, tree, null, self).map((v) => v.id);
  // On the owner's own canvas: the unowned look plus the owner's own, on
  // the root and on a nested unstamped element (Kris's step 7); another
  // owner's and an orphan withheld.
  assert.deepEqual(ids(`container:${ROOT}`, 'section'), ['free_look', 'section_look']);
  assert.deepEqual(ids(`container:${INNER}`, 'section'), ['free_look', 'section_look']);
  assert.deepEqual(ids(`block:${TEXT}`, 'section'), ['section_text']);
  // Another blueprint's canvas (its self is not the owner) withholds the
  // section's looks, as does a page canvas (no self).
  assert.deepEqual(ids(`container:${ROOT}`, 'other_blueprint'), ['free_look', 'other_look']);
  assert.deepEqual(ids(`container:${ROOT}`, null), ['free_look']);
  // The nested placement on the owner's canvas offers ITS owner's looks,
  // not the canvas's: the stamp shadows self in the offer as in the read.
  assert.deepEqual(ids(`container:${NESTED}`, 'section'), ['free_look', 'inner_look']);
  // Degraded with a self: an unknown uuid and no tree offer unowned only.
  assert.deepEqual(ids(`container:${UNKNOWN}`, 'section'), ['free_look']);
  assert.deepEqual(ids(`container:${ROOT}`, 'section', []), ['free_look']);
  assert.deepEqual(applicableVariants(EMPTY_MATERIALS, `container:${ROOT}`, {}, {}, ownCanvas, null, 'section'), []);
});

// relativeUnder() is the map-key adapter over rule-move.js
// continuationSuffix(), the client's one read of the server's
// RelativeSelector::continues(): under a rule both continuation forms
// answer, the descent (`{rule} {suffix}`) and the rule's own box
// (`{rule}::{pseudo}` as '::&::{pseudo}'), the latter the form the
// client used to miss while the CSS print at a rule carried it.
test('relativeUnder under a rule admits the rule\'s own box as ::&::pseudo, continues() read back', () => {
  const RULE = `block:${TEXT}::p`;
  const NESTED_RULE = `container:${NESTED}::> d-text`;
  assert.equal(relativeUnder(RULE, `${RULE}::before`), '::&::before');
  assert.equal(relativeUnder(RULE, `${RULE}::after`), '::&::after');
  assert.equal(relativeUnder(NESTED_RULE, `${NESTED_RULE}::before`), '::&::before');
  // The descent form is unchanged beside it, a class after a tag
  // included (a compound of its own, as under() reads it).
  assert.equal(relativeUnder(NESTED_RULE, `${NESTED_RULE} .x`), '::.x');
  assert.equal(relativeUnder(NESTED_RULE, `${NESTED_RULE} > d-image`), '::> d-image');
  assert.equal(relativeUnder(RULE, `${RULE} .x`), '::.x');
  // Not continuations: the rule itself, a longer tag, another root, a
  // sibling rule of the same owner.
  assert.equal(relativeUnder(RULE, RULE), null);
  assert.equal(relativeUnder(RULE, `block:${TEXT}::pre`), null);
  assert.equal(relativeUnder(RULE, `block:${NESTED}::p::before`), null);
  assert.equal(relativeUnder(RULE, `block:${TEXT}::a`), null);
  // A rule that is itself a box continues nothing (under() refuses a
  // pseudo rule), so its own key stands alone.
  assert.equal(relativeUnder(`${RULE}::before`, `${RULE}::before .x`), null);
  // The inverse the clipboard's paste rests on: the own-box relative
  // rebases under a rule target as that rule's box and under an element
  // target as the element's own box.
  assert.equal(continuedKey(RULE, '::&::before'), `${RULE}::before`);
  assert.equal(continuedKey(`block:${NESTED}::> d-text`, '::&::after'), `block:${NESTED}::> d-text::after`);
  assert.equal(continuedKey(`block:${NESTED}`, '::&::before'), `block:${NESTED}::&::before`);
});

test('relativeUnder under an element stays the key-plus-suffix read: a rule beneath it is ::selector', () => {
  const KEY = `block:${TEXT}`;
  assert.equal(relativeUnder(KEY, `${KEY}::p`), '::p');
  assert.equal(relativeUnder(KEY, `${KEY}::p::before`), '::p::before');
  assert.equal(relativeUnder(KEY, `${KEY}::&::before`), '::&::before');
  assert.equal(relativeUnder(KEY, `${KEY}::.x`), '::.x');
  assert.equal(relativeUnder(KEY, KEY), null);
  assert.equal(relativeUnder(KEY, `${KEY}:field_x`), null);
  assert.equal(relativeUnder(KEY, `block:${NESTED}::p`), null);
});

test('elementEntries at a rule carries the rule\'s own box, the family the server prints', () => {
  const RULE = `container:${NESTED}::> d-text`;
  const map = {
    [`container:${NESTED}`]: { default: { padding: '1rem' } },
    [RULE]: { default: { color: 'red' } },
    [`${RULE}::before`]: { default: { content: '"»"' } },
    [`${RULE} .x`]: { default: { 'font-style': 'italic' } },
    [`container:${NESTED}::> d-image`]: { default: { margin: '0' } },
  };
  assert.deepEqual(elementEntries(map, RULE), {
    '': { default: { color: 'red' } },
    '::&::before': { default: { content: '"»"' } },
    '::.x': { default: { 'font-style': 'italic' } },
  });
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
    console.error(error?.message ?? error);
  }
}
if (failures > 0) {
  console.error(`${failures} of ${tests.length} variant-scope tests failed.`);
  process.exit(1);
}
console.log(`${tests.length} variant-scope tests passed.`);
