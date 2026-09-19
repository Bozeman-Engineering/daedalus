/**
 * Self-asserting tests for the variant-document model.
 *
 * Run directly (`node tests/js/variant-document.test.mjs`) or via
 * PrinterEquivalenceTest, which executes this file inside the PHPUnit
 * suite. Locks the editing session's body contracts: the anchoring wearing,
 * the document's slot tree, the per-fragment carried-by read, the
 * variant-sheet baseline the buffer prints and diffs against, the
 * pending-session overlay, the wearer-cascade honesty reads, the
 * update-from-element pre-staged diff, and the save session's
 * capture-relative table. Exits non-zero on the first failure with a
 * readable diff.
 */
import { strict as assert } from 'node:assert';
import {
  captureRelativeTable,
  carriedBy,
  documentBaselineLines,
  documentLineWinner,
  documentSlotEntry,
  documentTree,
  documentValueWinner,
  overlaySessionLines,
  updateFromElementLines,
  wearerWinners,
  wearingOf,
} from '../../js/src/code/variant-document.js';
import { pendingLineId, stageLine, updateSession } from '../../js/src/pending.js';
import { islandLines } from '../../js/src/code/island.js';
import { ELEMENT_LAYER, variantLayerId } from '../../js/src/code/layers.js';

let current = null;
const test = (name, run) => {
  current = name;
  run();
  process.stdout.write(`ok - ${name}\n`);
};

process.on('uncaughtException', (error) => {
  process.stderr.write(`FAIL - ${current}\n${error.stack ?? error.message}\n`);
  process.exit(1);
});

const SEC = 'aaaaaaaa-1111-2222-3333-444444444444';
const TXT = 'bbbbbbbb-1111-2222-3333-444444444444';

const library = [
  {
    id: 'cta',
    label: 'CTA',
    group: 'style',
    value: {
      '': { default: { padding: '2rem', gap: '1rem' }, narrow: { padding: '1rem' } },
      '::.quote': { default: { color: 'teal' } },
      'block(text,1)': { default: { 'font-weight': 'bold' } },
    },
  },
  {
    id: 'loud',
    label: 'Loud',
    group: 'container_layout',
    value: { '': { default: { gap: '3rem' } } },
  },
];

const layout = [
  {
    uuid: SEC,
    layout_id: 'layout_onecol',
    children: [{ uuid: TXT, block_type: 'text' }],
  },
];

// --- wearingOf: which wearing anchors an element to the document -----------

test('wearingOf finds the wearing verbatim, or null', () => {
  const entry = { variants: [{ variant: 'cta', fragment: '' }, { variant: 'loud', fragment: '' }] };
  assert.deepEqual(wearingOf(entry, 'cta'), { variant: 'cta', fragment: '', via: null });
  const fragmentEntry = { variants: [{ variant: 'cta', fragment: 'block(text,1)' }] };
  assert.deepEqual(wearingOf(fragmentEntry, 'cta'), { variant: 'cta', fragment: 'block(text,1)', via: null });
  assert.equal(wearingOf(entry, 'other'), null);
  assert.equal(wearingOf(null, 'cta'), null);
});

// --- documentTree: the variant's key space as render nodes -----------------

test('the tree walks the whole key space: root, parts, then child slots', () => {
  assert.deepEqual(documentTree(library[0].value, null, 'container'), [
    {
      slot: '',
      depth: 0,
      kind: 'root',
      detail: null,
      keys: [
        { key: '', part: null, children: false },
        { key: '::.quote', part: '.quote', children: false },
      ],
    },
    {
      slot: 'block(text,1)',
      depth: 1,
      kind: 'block',
      detail: 'text',
      keys: [{ key: 'block(text,1)', part: null, children: false }],
    },
  ]);
});

test('the root always renders, even in an empty document', () => {
  assert.deepEqual(documentTree({}, null, 'block'), [
    { slot: '', depth: 0, kind: 'root', detail: null, keys: [{ key: '', part: null, children: false }] },
  ]);
  assert.deepEqual(documentTree(null, null, null)[0].slot, '');
  // Empty entries carry nothing and drop; reference-only entries list.
  const sparse = documentTree({
    '::.empty': {},
    'block(text,1)': { variants: [{ variant: 'loud', fragment: '' }] },
  }, null, 'container');
  assert.deepEqual(sparse.map((node) => node.slot), ['', 'block(text,1)']);
  assert.deepEqual(sparse[0].keys, [{ key: '', part: null, children: false }]);
});

test("keys order: own entry, its '::>', then each part with its own '::>'", () => {
  const styles = {
    '::.b': { default: { color: 'red' } },
    '::.a::>': { default: { gap: '1rem' } },
    '::>': { default: { margin: '0' } },
    '::.a': { default: { padding: '0' } },
    '': { default: { display: 'grid' } },
  };
  const [root] = documentTree(styles, null, 'container');
  assert.deepEqual(root.keys, [
    { key: '', part: null, children: false },
    { key: '::>', part: null, children: true },
    { key: '::.b', part: '.b', children: false },
    { key: '::.a', part: '.a', children: false },
    { key: '::.a::>', part: '.a', children: true },
  ]);
});

test('slots order parents-first in slot-path order; nesting carries depth', () => {
  const styles = {
    'container(1)/block(text,1)': { default: { color: 'red' } },
    'block(text,2)': { default: { margin: '0' } },
    'block(text,1)': { default: { margin: '1rem' } },
    'container(1)': { default: { gap: '0' } },
  };
  const nodes = documentTree(styles, null, 'container');
  assert.deepEqual(nodes.map((node) => [node.slot, node.depth, node.kind, node.detail]), [
    ['', 0, 'root', null],
    ['container(1)', 1, 'container', null],
    ['container(1)/block(text,1)', 2, 'block', 'text'],
    ['block(text,1)', 1, 'block', 'text'],
    ['block(text,2)', 1, 'block', 'text'],
  ]);
});

test('session-staged lines surface new slots and parts in the tree', () => {
  let session = updateSession('cta', `container:${SEC}`);
  session = stageLine(session, { relative: '::.new', condition: 'default', property: 'color', value: 'red' });
  session = stageLine(session, { relative: 'block(text,2)', condition: 'default', property: 'margin', value: '0' });
  const nodes = documentTree(library[0].value, session, 'container');
  assert.deepEqual(nodes.map((node) => node.slot), ['', 'block(text,1)', 'block(text,2)']);
  assert.deepEqual(nodes[0].keys.map(({ key }) => key), ['', '::.quote', '::.new']);
  // A save session stages nothing here (its pending set is the capture).
  assert.deepEqual(
    documentTree(library[0].value, { kind: 'save', dismissed: [] }, 'container').map((node) => node.slot),
    ['', 'block(text,1)'],
  );
});

test('unparseable slots keep stored order after parsed ones, kind null', () => {
  // No root kind: path keys cannot parse but still render.
  const nodes = documentTree(library[0].value, null, null);
  assert.deepEqual(nodes.map((node) => [node.slot, node.kind]), [
    ['', 'root'],
    ['block(text,1)', null],
  ]);
});

// --- carriedBy: the per-fragment current-page carriers ---------------------

test('carriedBy names each fragment\'s carriers by record id, in map order', () => {
  const map = {
    [`container:${SEC}`]: { variants: [{ variant: 'cta', fragment: '' }] },
    'container:other': { variants: [{ variant: 'cta', fragment: '' }] },
    [`block:${TXT}`]: { variants: [{ variant: 'cta', fragment: 'block(text,1)' }] },
    // Other variants, the retired uuid spelling and part entries never
    // carry.
    'block:b2': { variants: [{ variant: 'loud', fragment: '' }] },
    'block:b3': { variants: [{ variant: '00000000-0000-0000-0000-000000000001', fragment: '' }] },
    [`container:${SEC}::.quote`]: { default: { color: 'red' } },
  };
  const carriers = carriedBy(map, 'cta');
  assert.deepEqual([...carriers.entries()], [
    ['', [`container:${SEC}`, 'container:other']],
    ['block(text,1)', [`block:${TXT}`]],
  ]);
  assert.deepEqual([...carriedBy(map, null).entries()], []);
  assert.deepEqual([...carriedBy(null, 'cta').entries()], []);
});

// --- documentBaselineLines: the variant's own sheet at one slot ------------

test('the baseline is the variant sheet at the slot, single-layer at rest', () => {
  assert.deepEqual(documentBaselineLines(library[0].value, '', 'default'), [
    { property: 'padding', value: '2rem', layer: ELEMENT_LAYER, variant: null, lost: false, winner: null },
    { property: 'gap', value: '1rem', layer: ELEMENT_LAYER, variant: null, lost: false, winner: null },
  ]);
  assert.deepEqual(documentBaselineLines(library[0].value, '::.quote', 'default'), [
    { property: 'color', value: 'teal', layer: ELEMENT_LAYER, variant: null, lost: false, winner: null },
  ]);
  // A slot or condition the variant does not hold is an empty sheet.
  assert.deepEqual(documentBaselineLines(library[0].value, '', 'wide'), []);
  assert.deepEqual(documentBaselineLines(library[0].value, 'block(text,2)', 'default'), []);
  // Null values (mid-flight unsets in a payload) never print.
  assert.deepEqual(documentBaselineLines({ '': { default: { gap: null } } }, '', 'default'), []);
});

// --- overlaySessionLines: staged lines rendered in place -------------------

test('staged sets replace in place, appends land last, unsets vanish', () => {
  const baseline = documentBaselineLines(library[0].value, '', 'default');
  let session = updateSession('cta', `container:${SEC}`);
  session = stageLine(session, { relative: '', condition: 'default', property: 'padding', value: '3rem' });
  session = stageLine(session, { relative: '', condition: 'default', property: 'color', value: 'red' });
  session = stageLine(session, { relative: '', condition: 'default', property: 'gap', value: null });
  assert.deepEqual(overlaySessionLines(baseline, session, '', 'default'), [
    {
      property: 'padding', value: '3rem', layer: ELEMENT_LAYER, variant: null, lost: false, winner: null,
      pending: true, pendingId: pendingLineId('', 'default', 'padding'),
    },
    {
      property: 'color', value: 'red', layer: ELEMENT_LAYER, variant: null, lost: false, winner: null,
      pending: true, pendingId: pendingLineId('', 'default', 'color'),
    },
  ]);
});

test('other islands\' staged lines pass through untouched', () => {
  const baseline = documentBaselineLines(library[0].value, '', 'default');
  let session = updateSession('cta', `container:${SEC}`);
  session = stageLine(session, { relative: '::.quote', condition: 'default', property: 'color', value: 'red' });
  session = stageLine(session, { relative: '', condition: 'narrow', property: 'padding', value: '0' });
  assert.deepEqual(overlaySessionLines(baseline, session, '', 'default'), baseline);
  // A save session never overlays (its pending set is the capture).
  assert.deepEqual(overlaySessionLines(baseline, { kind: 'save', dismissed: [] }, '', 'default'), baseline);
  assert.deepEqual(overlaySessionLines(baseline, null, '', 'default'), baseline);
});

// --- wearer-cascade honesty: the winner read -------------------------------

test('a document line a higher layer beats names its winner', () => {
  const map = {
    [`container:${SEC}`]: {
      variants: [{ variant: 'cta', fragment: '' }, { variant: 'loud', fragment: '' }],
      default: { padding: '4rem' },
    },
  };
  const wearer = islandLines(map, `container:${SEC}`, 'default', library, ['style', 'container_layout']);
  const winners = wearerWinners(wearer);
  // The wearer's own override beats the sheet's padding.
  assert.deepEqual(documentLineWinner(winners, 'cta', 'padding'), { layer: ELEMENT_LAYER, variant: null });
  // The later-delivered axis beats the sheet's gap.
  assert.deepEqual(documentLineWinner(winners, 'cta', 'gap'), { layer: variantLayerId('loud'), variant: 'loud' });
  // From the loud document, its own gap wins.
  assert.equal(documentLineWinner(winners, 'loud', 'gap'), null);
  // A property nothing else defines is the sheet's to win.
  assert.equal(documentLineWinner(winners, 'cta', 'color'), null);
});

// --- updateFromElementLines: the pre-staged diff ---------------------------

test('own values differing from the variant pre-stage; equal ones do not', () => {
  const map = {
    [`container:${SEC}`]: {
      variants: [{ variant: 'cta', fragment: '' }],
      default: { padding: '4rem', gap: '1rem' },
      narrow: { margin: '0' },
    },
    [`container:${SEC}::.quote`]: { default: { color: 'navy' } },
  };
  assert.deepEqual(updateFromElementLines(map, `container:${SEC}`, 'cta', library, layout), [
    // padding differs; gap equals the variant's and stays out.
    { relative: '', condition: 'default', property: 'padding', value: '4rem' },
    // A condition the variant lacks entirely stages as new lines.
    { relative: '', condition: 'narrow', property: 'margin', value: '0' },
    // Part overrides map through the wearing's fragment.
    { relative: '::.quote', condition: 'default', property: 'color', value: 'navy' },
  ]);
});

test('a root wearing pre-stages the subtree; a fragment wearing only its slice', () => {
  const map = {
    [`container:${SEC}`]: {
      variants: [{ variant: 'cta', fragment: '' }],
      default: { padding: '2rem' },
    },
    [`block:${TXT}`]: {
      variants: [{ variant: 'cta', fragment: 'block(text,1)' }],
      default: { 'font-weight': '900' },
    },
  };
  // From the root wearer, the child's own override maps through its slot.
  assert.deepEqual(updateFromElementLines(map, `container:${SEC}`, 'cta', library, layout), [
    { relative: 'block(text,1)', condition: 'default', property: 'font-weight', value: '900' },
  ]);
  // From the fragment wearer, only its own slice maps (through its
  // fragment); deeper slots belong to the composition that distributed it.
  assert.deepEqual(updateFromElementLines(map, `block:${TXT}`, 'cta', library, layout), [
    { relative: 'block(text,1)', condition: 'default', property: 'font-weight', value: '900' },
  ]);
});

test('no wearing, no resolution, or no diff pre-stages nothing', () => {
  const bare = { [`container:${SEC}`]: { default: { padding: '4rem' } } };
  assert.deepEqual(updateFromElementLines(bare, `container:${SEC}`, 'cta', library, layout), []);
  const worn = {
    [`container:${SEC}`]: {
      variants: [{ variant: 'ghost', fragment: '' }],
      default: { padding: '4rem' },
    },
  };
  assert.deepEqual(updateFromElementLines(worn, `container:${SEC}`, 'ghost', library, layout), []);
  const identical = {
    [`container:${SEC}`]: {
      variants: [{ variant: 'cta', fragment: '' }],
      default: { padding: '2rem' },
    },
  };
  assert.deepEqual(updateFromElementLines(identical, `container:${SEC}`, 'cta', library, layout), []);
});

// --- captureRelativeTable: save marks address capture rows -----------------

test('the table maps island keys to the capture\'s relative keys', () => {
  const map = {
    [`container:${SEC}`]: { default: { padding: '2rem' } },
    [`container:${SEC}::.band`]: { default: { gap: '0' } },
    [`block:${TXT}`]: { default: { margin: '0' } },
    // An instance part never captures, so it gets no marks.
    [`block:${TXT}::.s-a1b2`]: { default: { color: 'red' } },
  };
  const table = captureRelativeTable(map, `container:${SEC}`, layout);
  assert.equal(table.get(`container:${SEC}`), '');
  assert.equal(table.get(`container:${SEC}::.band`), '::.band');
  assert.equal(table.get(`block:${TXT}`), 'block(text,1)');
  assert.equal(table.has(`block:${TXT}::.s-a1b2`), false);
});

test('documentSlotEntry projects the slot with the session overlaid, marks pending', () => {
  const cta = library[0];
  let session = updateSession('cta', 'block:abc');
  session = stageLine(session, { relative: '', condition: 'default', property: 'gap', value: '2rem' });
  session = stageLine(session, { relative: '', condition: 'default', property: 'color', value: 'red' });
  session = stageLine(session, { relative: '', condition: 'narrow', property: 'padding', value: null });
  session = stageLine(session, { relative: '::.quote', condition: 'default', property: 'color', value: 'navy' });
  const { entry, pending } = documentSlotEntry(cta.value, '', session);
  // The sheet Push produces: the staged set replaced gap, the add landed,
  // the narrow unset removed padding (its condition emptied away), and
  // the untouched default padding rides verbatim.
  assert.deepEqual(entry, {
    default: { padding: '2rem', gap: '2rem', color: 'red' },
  });
  assert.deepEqual(pending.default, {
    gap: { id: pendingLineId('', 'default', 'gap'), unset: false },
    color: { id: pendingLineId('', 'default', 'color'), unset: false },
  });
  // The unset's property is absent from the entry but its mark survives —
  // the row still needs the pending-removal chrome and the dismiss.
  assert.deepEqual(pending.narrow, {
    padding: { id: pendingLineId('', 'narrow', 'padding'), unset: true },
  });
  // Other slots' staged lines belong to other targets.
  const quote = documentSlotEntry(cta.value, '::.quote', session);
  assert.deepEqual(quote.entry, { default: { color: 'navy' } });
  assert.deepEqual(Object.keys(quote.pending), ['default']);
});

test('documentSlotEntry without a session is the variant sheet at rest', () => {
  const { entry, pending } = documentSlotEntry(library[0].value, '', null);
  assert.deepEqual(entry, {
    default: { padding: '2rem', gap: '1rem' },
    narrow: { padding: '1rem' },
  });
  assert.deepEqual(pending, {});
  // An empty slot projects an empty entry (a fresh part the session can
  // still stage into).
  assert.deepEqual(documentSlotEntry(library[0].value, '::.missing', null), { entry: {}, pending: {} });
});

test('documentValueWinner: own beats, later suppliers beat, the document wins otherwise', () => {
  const own = { default: { gap: '89px' }, variants: [{ variant: 'cta', fragment: '' }] };
  const suppliers = { default: { gap: 'cta', padding: 'loud' } };
  // The element's own layer always wins delivery.
  assert.deepEqual(documentValueWinner(own, suppliers, 'cta', 'default', 'gap'), { variant: null });
  // Another worn reference winning delivery names itself.
  assert.deepEqual(documentValueWinner({}, suppliers, 'cta', 'default', 'padding'), { variant: 'loud' });
  // The document supplying the delivery winner — or nothing else defining
  // the property at all — means the document wins (null: previews are
  // honest).
  assert.equal(documentValueWinner({}, suppliers, 'cta', 'default', 'gap'), null);
  assert.equal(documentValueWinner({}, suppliers, 'cta', 'default', 'color'), null);
  assert.equal(documentValueWinner(null, null, 'cta', 'default', 'gap'), null);
  // An own value at another condition does not win this one.
  assert.equal(documentValueWinner({ dark: { gap: '1px' } }, {}, 'cta', 'default', 'gap'), null);
});

process.stdout.write('variant-document suite passed\n');
