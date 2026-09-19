/**
 * Self-asserting tests for the page-tree code view model.
 *
 * Run directly (`node tests/js/page-model.test.mjs`) or via
 * PrinterEquivalenceTest, which executes this file inside the suite so the
 * tree/filter contract has PHPUnit backpressure. Exits non-zero on
 * the first failure with a readable diff.
 */
import { strict as assert } from 'node:assert';
import {
  pageNodes, findNode, subtreeNodes, scopeNodes, isStyledElement, visibleTree, reapplyRef,
} from '../../js/src/code/page-model.js';
import { holdsFragments } from '../../js/src/variant-scope.js';

const ROOT = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
const S1 = '11111111-1111-1111-1111-111111111111';
const S2 = '22222222-2222-2222-2222-222222222222';
const NESTED = '33333333-3333-3333-3333-333333333333';
const B1 = '44444444-4444-4444-4444-444444444444';
const B2 = '55555555-5555-5555-5555-555555555555';
const B3 = '66666666-6666-6666-6666-666666666666';

// The pared layoutTree() shape: a transitional multi-region section first
// (its children flatten under the section row — regions have no keys), then
// a container section holding a nested container beside nothing else.
const LAYOUT = [
  {
    uuid: S1,
    layout_id: 'daedalus_stylish_twocol',
    regions: {
      first: [{ uuid: B1, block_type: 'text' }],
      second: [{ uuid: B2, block_type: null }],
    },
  },
  {
    uuid: S2,
    layout_id: 'daedalus_stylish_container',
    children: [
      {
        uuid: NESTED,
        layout_id: 'daedalus_stylish_container',
        children: [{ uuid: B3, block_type: 'image' }],
      },
    ],
  },
];

const VARIANTS = [
  {
    id: 'card',
    label: 'Card',
    group: 'style',
    value: {
      '': { default: { padding: '2rem' } },
      'block(text,1)': { default: { color: 'teal' } },
    },
  },
  {
    id: 'glow',
    label: 'Glow',
    group: 'effect',
    value: { '': { default: { 'box-shadow': '0 0 8px gold' } } },
  },
  {
    id: 'plain',
    label: 'Plain',
    group: 'style',
    value: { '': { default: { margin: '0' } }, '::.quote': { default: { color: 'gray' } } },
  },
];

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

// --- pageNodes: the layout tree as ordered element nodes --------------------

test('pageNodes nests the whole layout under the entity root in layout order', () => {
  const nodes = pageNodes(LAYOUT, ROOT);
  assert.equal(nodes.length, 1);
  const page = nodes[0];
  assert.equal(page.key, `entity:${ROOT}`);
  assert.equal(page.kind, 'entity');
  assert.equal(page.depth, 0);
  assert.deepEqual(page.children.map((node) => node.key), [`container:${S1}`, `container:${S2}`]);
  const first = page.children[0];
  assert.equal(first.kind, 'container');
  assert.equal(first.layoutId, 'daedalus_stylish_twocol');
  assert.equal(first.depth, 1);
  // A multi-region section's children flatten directly under its row in
  // stored region order: regions have no keys, so no region rows.
  assert.deepEqual(first.children.map((node) => node.key), [
    `block:${B1}`,
    `block:${B2}`,
  ]);
  assert.equal(first.children[0].blockType, 'text');
  assert.equal(first.children[0].depth, 2);
  // A plugin block keeps its node with a null block type.
  assert.equal(first.children[1].blockType, null);
});

test('pageNodes recurses nested containers in place of components', () => {
  const nodes = pageNodes(LAYOUT, ROOT);
  const nested = nodes[0].children[1].children[0];
  assert.equal(nested.key, `container:${NESTED}`);
  assert.equal(nested.kind, 'container');
  assert.equal(nested.layoutId, 'daedalus_stylish_container');
  assert.deepEqual(nested.children.map((node) => node.key), [`block:${B3}`]);
});

test('pageNodes renders containers bare without a root uuid and skips uuid-less nodes', () => {
  const bare = pageNodes(LAYOUT, null);
  assert.deepEqual(bare.map((node) => node.key), [`container:${S1}`, `container:${S2}`]);
  assert.equal(bare[0].depth, 0);
  const withNoise = pageNodes([{ uuid: null, children: [] }, ...LAYOUT.slice(0, 1)], null);
  assert.deepEqual(withNoise.map((node) => node.key), [`container:${S1}`]);
  const noiseChild = pageNodes([{
    uuid: S1,
    layout_id: 'daedalus_stylish_container',
    children: [{ uuid: null, block_type: 'text' }],
  }], null);
  assert.deepEqual(noiseChild[0].children, []);
});

// --- findNode + subtreeNodes: the draft-session subtree rule's projection ---

test('findNode locates a node at any depth, null when the tree lacks it', () => {
  const nodes = pageNodes(LAYOUT, ROOT);
  assert.equal(findNode(nodes, `entity:${ROOT}`).kind, 'entity');
  assert.equal(findNode(nodes, `block:${B3}`).blockType, 'image');
  assert.equal(findNode(nodes, `container:${NESTED}`).children.length, 1);
  assert.equal(findNode(nodes, 'block:nope'), null);
  assert.equal(findNode(null, `block:${B1}`), null);
});

test('subtreeNodes roots the tree at the anchor with descendants intact', () => {
  const nodes = pageNodes(LAYOUT, ROOT);
  const subtree = subtreeNodes(nodes, `container:${S2}`);
  assert.equal(subtree.length, 1);
  assert.equal(subtree[0].key, `container:${S2}`);
  assert.deepEqual(subtree[0].children.map((node) => node.key), [`container:${NESTED}`]);
  assert.deepEqual(subtree[0].children[0].children.map((node) => node.key), [`block:${B3}`]);
  // The whole page is a subtree too: anchoring at the root IS the page tree.
  assert.deepEqual(subtreeNodes(nodes, `entity:${ROOT}`), nodes);
});

test('subtreeNodes answers a missing anchor with a synthetic childless node', () => {
  const nodes = pageNodes(LAYOUT, ROOT);
  // An element the layout walk does not carry (a field key) still renders
  // one honest row so its islands have a home.
  assert.deepEqual(subtreeNodes(nodes, 'field:abc:body'), [
    { key: 'field:abc:body', kind: 'field', depth: 0, children: [] },
  ]);
  assert.deepEqual(subtreeNodes(nodes, null), []);
});

// --- scopeNodes: the code scope's projection -------------------------------

test('scopeNodes page is the whole tree, selection or not', () => {
  const nodes = pageNodes(LAYOUT, ROOT);
  assert.deepEqual(scopeNodes(nodes, 'page', `container:${S2}`), nodes);
  assert.deepEqual(scopeNodes(nodes, 'page', null), nodes);
  assert.deepEqual(scopeNodes(null, 'page', null), []);
});

test('scopeNodes subtree is subtreeNodes\' rule: the selection with its descendants', () => {
  const nodes = pageNodes(LAYOUT, ROOT);
  const subtree = scopeNodes(nodes, 'subtree', `container:${S2}`);
  assert.deepEqual(subtree, subtreeNodes(nodes, `container:${S2}`));
  assert.equal(subtree.length, 1);
  // The entity root is not in the projection: nothing above the anchor prints.
  assert.equal(findNode(subtree, `entity:${ROOT}`), null);
  assert.equal(findNode(subtree, `container:${S1}`), null);
  assert.deepEqual(subtree[0].children[0].children.map((node) => node.key), [`block:${B3}`]);
  // A key the walk lacks still projects its synthetic childless row.
  assert.deepEqual(scopeNodes(nodes, 'subtree', 'field:abc:body'), [
    { key: 'field:abc:body', kind: 'field', depth: 0, children: [] },
  ]);
});

test('scopeNodes element is the selection alone, its children dropped', () => {
  const nodes = pageNodes(LAYOUT, ROOT);
  const one = scopeNodes(nodes, 'element', `container:${S2}`);
  assert.equal(one.length, 1);
  assert.equal(one[0].key, `container:${S2}`);
  assert.equal(one[0].layoutId, 'daedalus_stylish_container');
  assert.deepEqual(one[0].children, []);
  // The source tree is not mutated: the container keeps its children.
  assert.equal(findNode(nodes, `container:${S2}`).children.length, 1);
  // A leaf and the root both project one row.
  assert.deepEqual(scopeNodes(nodes, 'element', `block:${B3}`).map((node) => node.key), [`block:${B3}`]);
  const root = scopeNodes(nodes, 'element', `entity:${ROOT}`);
  assert.deepEqual(root.map((node) => node.key), [`entity:${ROOT}`]);
  assert.deepEqual(root[0].children, []);
});

test('scopeNodes element and subtree project nothing without a selection', () => {
  const nodes = pageNodes(LAYOUT, ROOT);
  assert.deepEqual(scopeNodes(nodes, 'element', null), []);
  assert.deepEqual(scopeNodes(nodes, 'subtree', null), []);
  assert.deepEqual(scopeNodes(nodes, 'element', ''), []);
});

test('visibleTree over a scoped projection keeps the selection and prunes the rest', () => {
  const nodes = pageNodes(LAYOUT, ROOT);
  const map = { [`block:${B3}`]: { default: { color: 'red' } } };
  // Subtree of an unstyled container over a styled descendant: the anchor
  // survives as the descendant's structural ancestor, S2's nested chain.
  const subtree = visibleTree(scopeNodes(nodes, 'subtree', `container:${S2}`), map, {
    keep: [`container:${S2}`],
  });
  assert.deepEqual(subtree.map((node) => node.key), [`container:${S2}`]);
  assert.equal(subtree[0].styled, false);
  assert.deepEqual(subtree[0].children.map((node) => node.key), [`container:${NESTED}`]);
  assert.deepEqual(subtree[0].children[0].children.map((node) => node.key), [`block:${B3}`]);
  // Element scope of an unstyled selection: one bare row, kept by selection.
  const one = visibleTree(scopeNodes(nodes, 'element', `container:${S2}`), map, {
    keep: [`container:${S2}`],
  });
  assert.deepEqual(one, [
    { key: `container:${S2}`, kind: 'container', layoutId: 'daedalus_stylish_container', depth: 1, styled: false, children: [] },
  ]);
});

// --- isStyledElement: the filter's predicate --------------------------------

test('own layers, part entries, and worn refs all read as styled', () => {
  const key = `block:${B1}`;
  assert.equal(isStyledElement({}, key), false);
  assert.equal(isStyledElement({ [key]: { default: { color: 'red' } } }, key), true);
  assert.equal(isStyledElement({ [`${key}::.quote`]: { default: { color: 'red' } } }, key), true);
  // A pure-reference wearing IS styling — distributed fragment refs included.
  assert.equal(isStyledElement({ [key]: { variants: [{ variant: 'card', fragment: 'block(text,1)' }] } }, key), true);
  // A malformed (retired string) ref reads as no styling at all.
  assert.equal(isStyledElement({ [key]: { variants: ['card'] } }, key), false);
});

test('a tag-tier entry reads as styled (the row hosts its tag island)', () => {
  // The element-scoped tag tier ('{key}::p') is an own sub layer like a
  // part entry: an element styled only at a tag key keeps its page-tree
  // row, where the tag row renders alongside the parts.
  const key = `block:${B1}`;
  const map = { [`${key}::p`]: { default: { 'font-size': '1.25rem' } } };
  assert.equal(isStyledElement(map, key), true);
  const tree = visibleTree(pageNodes(LAYOUT, ROOT), map);
  assert.equal(tree.length, 1);
});

// --- visibleTree: annotation + the always-on styled filter -------------------

test('visibleTree annotates styled flags and prunes to styled elements, keeping structural ancestors', () => {
  const map = { [`block:${B3}`]: { variants: [{ variant: 'glow', fragment: '' }] } };
  const tree = visibleTree(pageNodes(LAYOUT, ROOT), map);
  // The whole S1 branch is gone; the chain down to B3 survives, unstyled
  // ancestors annotated as such (they render bare header rows).
  assert.equal(tree.length, 1);
  assert.equal(tree[0].styled, false);
  assert.deepEqual(tree[0].children.map((node) => node.key), [`container:${S2}`]);
  const nested = tree[0].children[0].children[0];
  assert.equal(nested.key, `container:${NESTED}`);
  assert.equal(nested.styled, false);
  assert.deepEqual(nested.children.map((node) => node.key), [`block:${B3}`]);
  assert.equal(nested.children[0].styled, true);
});

test('a fully unstyled layout prunes to nothing', () => {
  assert.deepEqual(visibleTree(pageNodes(LAYOUT, ROOT), {}), []);
});

test('the keep set survives the filter (the selection stays visible)', () => {
  const tree = visibleTree(pageNodes(LAYOUT, ROOT), {}, { keep: [`block:${B1}`] });
  const firstSection = tree[0].children[0];
  assert.deepEqual(firstSection.children.map((node) => node.key), [`block:${B1}`]);
  // Unkept siblings are still pruned.
  assert.deepEqual(tree[0].children.map((node) => node.key), [`container:${S1}`]);
});

// --- reapplyRef: the row menu's Re-apply gate --------------------------------

test('reapplyRef offers only a worn composition ROOT', () => {
  const at = (entry) => reapplyRef({ k: entry }, 'k', VARIANTS);
  // A '' ref to a fragment-holding variant is the gesture's target.
  assert.deepEqual(at({ variants: [{ variant: 'card', fragment: '' }] }), { variant: 'card', fragment: '', via: null });
  // A distributed fragment ref never offers it (re-apply is the root's).
  assert.equal(at({ variants: [{ variant: 'card', fragment: 'block(text,1)' }] }), null);
  // A root ref to a fragment-less variant has nothing to distribute —
  // '::.class' part keys do not make a composition (holdsFragments).
  assert.equal(holdsFragments(VARIANTS[2]), false);
  assert.equal(at({ variants: [{ variant: 'plain', fragment: '' }] }), null);
  // An unresolved ref cannot answer; no gesture.
  assert.equal(at({ variants: [{ variant: 'gone', fragment: '' }] }), null);
  // Cross-axis wearing beside the composition root still finds the root.
  assert.deepEqual(
    at({ variants: [{ variant: 'glow', fragment: '' }, { variant: 'card', fragment: '' }] }),
    { variant: 'card', fragment: '', via: null },
  );
});

process.stdout.write('page-model suite passed\n');
