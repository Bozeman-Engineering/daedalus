/**
 * Self-asserting tests for the shared row menu's pure half
 * (js/src/widgets/lib/use-row-menu.js rowMenuSpec): the one dispatch of
 * a row's provenance doorways, over the origins a property row meets
 * (own over a variant, the skin, a variant, an ancestor's rule, a
 * variant an ancestor's rule wears, a session's winner) and the token
 * half, resolved on open. The hook itself (useRowMenu) is the menu
 * primitive's adapter and is exercised in the browser.
 *
 * Run directly (`node tests/js/use-row-menu.test.mjs`) or via the suite
 * glob. Exits non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import { rowMenuSpec } from '../../js/src/widgets/lib/use-row-menu.js';
import { originOf } from '../../js/src/provenance-model.js';
import { ancestorWearLayerId } from '../../js/src/code/layers.js';
import { buildStack, stackAt } from '../../js/src/style-stack.js';
import { variantRecord } from './materials-fixture.mjs';

// Drupal.t at call time, placeholders substituted the way
// Drupal.formatString does (`@` escapes, `!` passes through).
const checkPlain = (value) => String(value)
  .replaceAll('&', '&amp;')
  .replaceAll('<', '&lt;')
  .replaceAll('>', '&gt;')
  .replaceAll('"', '&quot;')
  .replaceAll("'", '&#039;');
globalThis.Drupal = {
  t: (text, args = {}) => Object.entries(args).reduce(
    (out, [key, value]) => out.split(key).join(key.startsWith('@') ? checkPlain(value) : String(value)),
    text,
  ),
};

const tests = [];
const test = (name, run) => tests.push([name, run]);

// --- fixtures -------------------------------------------------------------

const CONDITIONS = {
  medium: { label: 'Medium', axis: 'width' },
  narrow: { label: 'Narrow', axis: 'width' },
};
const UUID = 'aaaaaaaa-1111-2222-3333-444444444444';
const ROOT = `block:${UUID}`;
const BLOCK_TYPES = { [UUID]: 'text' };
const SECTION = 'container:cccccccc-1111-2222-3333-444444444444';
const WORN_RULE = `${SECTION}::> pb-text`;
const SKIN_URL = '/admin/appearance/skins/1';

const VARIANTS = [
  variantRecord('stuff', {
    label: 'Stuff',
    scope: 'block_type:text',
    value: { '': { default: { 'padding-top': '22px' }, narrow: { 'padding-top': '12px' } } },
  }),
];
const BARE = { conditions: CONDITIONS, blockTypes: { text: { label: 'Text' } } };
const SKIN = {
  ...BARE,
  skinDefaults: { 'block_type:text': { '': { default: { 'padding-top': '1.125rem' } } } },
};
const TREE = [{
  kind: 'container',
  uuid: SECTION.slice('container:'.length),
  layout_id: 'daedalus_stylish_container',
  _element: { name: 'Section', type: 'Section' },
  children: [{ kind: 'block', uuid: UUID, block_type: 'text', _element: { name: 'Text', type: 'Text' } }],
}];

const worn = (own = {}) => ({ [ROOT]: { variants: [{ variant: 'stuff', fragment: '' }], ...own } });

// A row's origin the way the rows derive it (choice-row-model.js
// originFor / the advanced row): the stack read at the pose, projected.
const originAt = ({ map = {}, guide = BARE, session = null, tree = null, editable = null }, pose, options = {}) => {
  const stack = buildStack({ guide, map, key: ROOT, variants: VARIANTS, axes: ['style'], blockTypes: BLOCK_TYPES, session, tree, editable });
  return originOf(stackAt(stack, 'padding-top', pose, CONDITIONS), { stack, property: 'padding-top', pose, conditions: CONDITIONS, guide, ...options });
};

// A recording of every handler the dispatch can reach.
const recorder = () => {
  const calls = [];
  return {
    calls,
    handlers: {
      source: {
        target: (key, selector) => calls.push(['target', key, selector]),
        select: (key) => calls.push(['select', key]),
      },
      onOpenDocument: (reference, condition, anchor) => calls.push(['document', reference, condition, anchor]),
      onPickCondition: (condition) => calls.push(['condition', condition]),
      onUnset: () => calls.push(['unset']),
      onWinner: (winner) => calls.push(['winner', winner]),
    },
  };
};
const labels = (spec) => spec.items.map((item) => (item.separator ? 'sep' : item.label));
// The doorway group alone: the items before the first separator (Stuff's
// Narrow block puts an "Also set at" group beneath every origin here).
const doorways = (spec) => {
  const all = labels(spec);
  const separator = all.indexOf('sep');
  return separator === -1 ? all : all.slice(0, separator);
};
const run = (spec, label) => spec.items.find((item) => item.label === label).run();

// --- the doorways ---------------------------------------------------------

test('own over a variant: Unset is the row\'s unset gesture, the document doorway opens the variant at its wearer (no anchor: the host\'s wear key)', () => {
  const origin = originAt({ map: worn({ default: { 'padding-top': '24px' } }) }, 'default');
  const { calls, handlers } = recorder();
  const spec = rowMenuSpec({ origin, ...handlers });
  assert.equal(spec.header, 'Your 24px overrides Stuff\'s 22px');
  assert.deepEqual(doorways(spec), ['Unset', 'Edit Stuff at Base…']);
  run(spec, 'Unset');
  run(spec, 'Edit Stuff at Base…');
  assert.deepEqual(calls, [['unset'], ['document', 'stuff', 'default', null]]);
});

test('the skin: its doorway opens the skin edit page in a new window', () => {
  const origin = originAt({ guide: SKIN }, 'default', { skinEditUrl: SKIN_URL });
  const opened = [];
  globalThis.window = { open: (...args) => opened.push(args) };
  try {
    const spec = rowMenuSpec({ origin, ...recorder().handlers });
    assert.deepEqual(labels(spec), ['Edit skin…']);
    run(spec, 'Edit skin…');
    assert.deepEqual(opened, [[SKIN_URL, '_blank', 'noopener']]);
  }
  finally {
    delete globalThis.window;
  }
});

test('a variant at another condition: drive poses the canvas, the document doorway opens the variant, an "Also set at" entry poses too', () => {
  const origin = originAt({ map: worn() }, 'medium');
  const { calls, handlers } = recorder();
  const spec = rowMenuSpec({ origin, ...handlers });
  assert.deepEqual(labels(spec), ['Edit Stuff at Base…', 'Go to Base', 'sep', 'Also set at', 'Narrow']);
  run(spec, 'Go to Base');
  run(spec, 'Edit Stuff at Base…');
  run(spec, 'Narrow');
  assert.deepEqual(calls, [['condition', 'default'], ['document', 'stuff', 'default', null], ['condition', 'narrow']]);
});

test('an ancestor\'s own rule: Edit at seats the rule as the styling target on the source', () => {
  const origin = originAt({ map: { [`${SECTION}::> *`]: { default: { 'padding-top': '8px' } } }, tree: TREE }, 'default');
  const { calls, handlers } = recorder();
  const spec = rowMenuSpec({ origin, ...handlers });
  assert.deepEqual(labels(spec), ['Edit at Section (> *)']);
  run(spec, 'Edit at Section (> *)');
  assert.deepEqual(calls, [['target', SECTION, '> *']]);
});

test('a variant an ancestor\'s rule wears: the document doorway opens the variant anchored at the CARRIER, Edit at seats the carrier rule', () => {
  const origin = originAt({ map: { [WORN_RULE]: { variants: [{ variant: 'stuff', fragment: '' }] } }, tree: TREE }, 'default');
  assert.equal(origin.layer, ancestorWearLayerId(SECTION, '> pb-text', 'stuff'));
  const { calls, handlers } = recorder();
  const spec = rowMenuSpec({ origin, ...handlers });
  assert.equal(spec.header, '22px from Stuff at Text blocks directly in Section');
  assert.deepEqual(doorways(spec), ['Edit Stuff at Base…', 'Edit at Text blocks directly in Section']);
  run(spec, 'Edit Stuff at Base…');
  run(spec, 'Edit at Text blocks directly in Section');
  assert.deepEqual(calls, [['document', 'stuff', 'default', WORN_RULE], ['target', SECTION, '> pb-text']]);
});

test('a row handed no source keeps an inert ancestor doorway: the menu opens, the item runs, nothing throws', () => {
  const origin = originAt({ map: { [WORN_RULE]: { variants: [{ variant: 'stuff', fragment: '' }] } }, tree: TREE }, 'default');
  const spec = rowMenuSpec({ origin });
  assert.deepEqual(doorways(spec), ['Edit Stuff at Base…', 'Edit at Text blocks directly in Section']);
  run(spec, 'Edit at Text blocks directly in Section');
  run(spec, 'Edit Stuff at Base…');
});

test('a session\'s winner: the doorway hands the winner to onWinner', () => {
  const origin = originAt({ map: worn({ default: { 'padding-top': '3rem' } }), session: { reference: 'stuff', pending: null } }, 'default');
  const { calls, handlers } = recorder();
  const spec = rowMenuSpec({ origin, ...handlers });
  assert.deepEqual(doorways(spec), ['Go to element']);
  run(spec, 'Go to element');
  assert.deepEqual(calls, [['winner', { variant: null }]]);
});

test('a write address: the element\'s own value above the rule\'s layer offers "Edit on the element", which re-selects the element on the source (dropping the target) with no sheet and no winner handler; a row with no source stays inert, nothing throws', () => {
  const origin = originAt(
    { map: { [ROOT]: { default: { 'padding-top': '24px' } } }, tree: TREE, editable: { key: SECTION, selector: '> *' } },
    'default',
  );
  const { calls, handlers } = recorder();
  // The advanced row outside a session and every basic row: the source
  // alone, no onWinner (inspector.js hands one only with a sheet).
  const spec = rowMenuSpec({ origin, source: handlers.source });
  assert.equal(spec.header, '24px from this element');
  assert.deepEqual(labels(spec), ['Edit on the element']);
  run(spec, 'Edit on the element');
  assert.deepEqual(calls, [['select', ROOT]]);
  run(rowMenuSpec({ origin }), 'Edit on the element');
});

// --- the token half and the empty menu -------------------------------------

test('tokens and face are resolved on open, thunks or values alike; a set-here origin heads its menu with its sentence, and with no doorways, roster or tokens there is no menu', () => {
  const own = originAt({ map: { [ROOT]: { default: { 'padding-top': '19px' } } } }, 'default');
  assert.deepEqual(own.doorways, []);
  assert.equal(own.header, '19px set here');
  assert.equal(rowMenuSpec({ origin: own }), null);
  assert.equal(rowMenuSpec({ origin: null, tokens: () => [] }), null);
  let built = 0;
  const tokens = () => {
    built += 1;
    return [{ label: 'Spacing · 5', checked: false, run: () => {} }];
  };
  assert.equal(built, 0);
  const thunked = rowMenuSpec({ origin: own, tokens, face: () => '1.5rem' });
  assert.equal(built, 1);
  assert.equal(thunked.header, '19px set here', 'the origin\'s sentence, never the face question');
  assert.deepEqual(labels(thunked), ['Spacing · 5']);
  const plain = rowMenuSpec({ origin: null, tokens: tokens(), face: '2rem' });
  assert.equal(plain.header, 'Where should 2rem come from?');
  assert.deepEqual(labels(plain), ['Spacing · 5']);
});

// --- the address rule's second box ----------------------------------------

test('a read carrying alsoOn (controls.js resolveCandidates: a second authored value on another of the binding\'s boxes) puts an inert "Also set on @candidate" line beside "Also set at", the value as its hint in the row\'s face', () => {
  const stack = buildStack({ guide: BARE, map: worn(), key: ROOT, variants: VARIANTS, axes: ['style'], blockTypes: BLOCK_TYPES });
  const read = { ...stackAt(stack, 'padding-top', 'default', CONDITIONS), alsoOn: { label: 'element', value: '8px' } };
  const origin = originOf(read, { stack, property: 'padding-top', pose: 'default', conditions: CONDITIONS, guide: BARE, faceOf: (value) => `${value}!` });
  assert.deepEqual(origin.alsoOn, { label: 'element', value: '8px!' }, 'the origin speaks the face, never the raw value');
  const spec = rowMenuSpec({ origin });
  assert.deepEqual(labels(spec), ['Edit Stuff at Base…', 'sep', 'Also set at', 'Narrow', 'Also set on element']);
  const line = spec.items.find((item) => item.label === 'Also set on element');
  assert.equal(line.hint, '8px!');
  assert.equal(line.disabled, true, 'the way to the other box is advanced mode: nothing to run');
  assert.equal(line.reason, 'The element is styled in Advanced or code mode.');
  // Without a roster the line stands alone in its group; without the
  // fact there is no line, and a read with no address fact is the shape
  // every single-box row hands in.
  const plain = originOf(stackAt(stack, 'padding-top', 'default', CONDITIONS), { stack, property: 'padding-top', pose: 'default', conditions: CONDITIONS, guide: BARE });
  assert.equal(plain.alsoOn, null);
  assert.deepEqual(labels(rowMenuSpec({ origin: plain })), ['Edit Stuff at Base…', 'sep', 'Also set at', 'Narrow']);
  const lone = originOf({ winner: null, beneath: [], own: null, alsoOn: { label: 'link', value: 'gold' } }, { stack, property: 'color', pose: 'default', conditions: CONDITIONS, guide: BARE });
  assert.deepEqual(labels(rowMenuSpec({ origin: lone })), ['Also set on link']);
  assert.equal(rowMenuSpec({ origin: lone }).header, 'Not set');
});

// --- runner -----------------------------------------------------------------

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
console.log(`\n${tests.length - failures} passed, ${failures} failed`);
process.exit(failures ? 1 : 0);
