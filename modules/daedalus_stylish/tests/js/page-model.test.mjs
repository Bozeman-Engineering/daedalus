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
  hasBody, outlineOf, foldSummary,
} from '../../js/src/code/page-model.js';
import { holdsFragments } from '../../js/src/variant-scope.js';
import { variantLayerId } from '../../js/src/code/layers.js';

const ROOT = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
const S1 = '11111111-1111-1111-1111-111111111111';
const S2 = '22222222-2222-2222-2222-222222222222';
const NESTED = '33333333-3333-3333-3333-333333333333';
const B1 = '44444444-4444-4444-4444-444444444444';
const B2 = '55555555-5555-5555-5555-555555555555';
const B3 = '66666666-6666-6666-6666-666666666666';

// The lean node tree (`layout`): a transitional multi-region section first
// (its region nodes unwrap under the section row — regions have no keys),
// then a container section holding a nested container beside nothing
// else. Each node carries its three minted words on its annotation (the
// annotator's `_element.type`, `_element.name` and `_element.icon`): S1 is
// renamed, S2 and the image are not (an unrenamed element's name IS its
// type word), the text block is named by its content excerpt; the icon is
// a URL, null where nothing answered the icon collector (the nested
// container here).
const named = (type, name = type, icon = `/icons/${type.toLowerCase()}.svg`) => ({ _element: { type, name, icon } });
const LAYOUT = [
  {
    kind: 'container',
    uuid: S1,
    layout_id: 'daedalus_stylish_twocol',
    ...named('Section', 'Welcome'),
    children: [
      { kind: 'region', region: 'first', children: [{ kind: 'block', uuid: B1, block_type: 'text', ...named('Text', 'Welcome!') }] },
      { kind: 'region', region: 'second', children: [{ kind: 'block', uuid: B2, plugin_id: 'system_menu_block:main', ...named('Main navigation') }] },
    ],
  },
  {
    kind: 'container',
    uuid: S2,
    layout_id: 'daedalus_stylish_container',
    ...named('Section'),
    children: [
      {
        kind: 'container',
        uuid: NESTED,
        layout_id: 'daedalus_stylish_container',
        ...named('Container', 'Hero band', null),
        children: [{ kind: 'block', uuid: B3, block_type: 'image', ...named('Image') }],
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
  // The row title's two words ride the node from the pared layout; no
  // machine id (layout id, block type) rides a node at all.
  assert.equal(first.type, 'Section');
  assert.equal(first.name, 'Welcome');
  assert.equal('layoutId' in first, false);
  assert.equal(first.depth, 1);
  // A multi-region section's region nodes unwrap directly under its row
  // in stored region order: regions have no keys, so no region rows.
  assert.deepEqual(first.children.map((node) => node.key), [
    `block:${B1}`,
    `block:${B2}`,
  ]);
  assert.equal(first.children[0].type, 'Text');
  assert.equal(first.children[0].name, 'Welcome!');
  assert.equal('blockType' in first.children[0], false);
  assert.equal(first.children[0].depth, 2);
  // A plugin block keeps its node with its own words.
  assert.equal(first.children[1].type, 'Main navigation');
  assert.equal(first.children[1].name, 'Main navigation');
  // A node the spine never named carries null words, never undefined.
  const unnamed = pageNodes([{ kind: 'container', uuid: S1, layout_id: 'x', children: [{ kind: 'block', uuid: B1 }] }], null);
  assert.equal(unnamed[0].type, null);
  assert.equal(unnamed[0].name, null);
  assert.equal(unnamed[0].children[0].name, null);
});

// --- The three minted words ride every node --------------------------------

test('pageNodes carries the icon beside the type and the name, null where nothing answered', () => {
  const nodes = pageNodes(LAYOUT, ROOT);
  // A renamed section: its glyph, its type word and its name.
  const section = findNode(nodes, `container:${S1}`);
  assert.deepEqual([section.type, section.name, section.icon], ['Section', 'Welcome', '/icons/section.svg']);
  // A block named by its content excerpt carries its type's glyph.
  const text = findNode(nodes, `block:${B1}`);
  assert.deepEqual([text.type, text.name, text.icon], ['Text', 'Welcome!', '/icons/text.svg']);
  // The icon collector answered nothing for the nested container: null,
  // never a client-side guess (the row draws no icon zone then).
  const nested = findNode(nodes, `container:${NESTED}`);
  assert.deepEqual([nested.type, nested.name, nested.icon], ['Container', 'Hero band', null]);
  // A node without an annotation carries three nulls.
  const [unnamed] = pageNodes([{ kind: 'container', uuid: S1, children: [] }]);
  assert.deepEqual([unnamed.type, unnamed.name, unnamed.icon], [null, null, null]);
});

test('pageNodes words the page row from the root\'s minted words when the source carries them', () => {
  // The lean tree holds the layout elements alone; the page row's words
  // arrive beside it (the leader reads the blueprint root's `_element`,
  // the satellite the broadcast's `rootElement`).
  const [page] = pageNodes(LAYOUT, ROOT, { type: 'Basic page', name: 'Welcome to Daedalus', icon: '/icons/page.svg' });
  assert.equal(page.key, `entity:${ROOT}`);
  assert.deepEqual([page.type, page.name, page.icon], ['Basic page', 'Welcome to Daedalus', '/icons/page.svg']);
  // Without them (a satellite before its first broadcast) the page row
  // has no words of its own; the view falls back to its vocabulary.
  const [bare] = pageNodes(LAYOUT, ROOT);
  assert.deepEqual([bare.type, bare.name, bare.icon], [null, null, null]);
  // The sections render bare without a root uuid, whatever the root words.
  assert.deepEqual(pageNodes(LAYOUT, null, { type: 'Basic page', name: 'x', icon: null }).map((node) => node.key), [`container:${S1}`, `container:${S2}`]);
});

test('pageNodes recurses nested containers in place of components', () => {
  const nodes = pageNodes(LAYOUT, ROOT);
  const nested = nodes[0].children[1].children[0];
  assert.equal(nested.key, `container:${NESTED}`);
  assert.equal(nested.kind, 'container');
  assert.equal(nested.type, 'Container');
  assert.equal(nested.name, 'Hero band');
  assert.deepEqual(nested.children.map((node) => node.key), [`block:${B3}`]);
});

test('pageNodes renders containers bare without a root uuid and skips uuid-less nodes', () => {
  const bare = pageNodes(LAYOUT, null);
  assert.deepEqual(bare.map((node) => node.key), [`container:${S1}`, `container:${S2}`]);
  assert.equal(bare[0].depth, 0);
  const withNoise = pageNodes([{ kind: 'container', uuid: null, children: [] }, ...LAYOUT.slice(0, 1)], null);
  assert.deepEqual(withNoise.map((node) => node.key), [`container:${S1}`]);
  const noiseChild = pageNodes([{
    kind: 'container',
    uuid: S1,
    layout_id: 'daedalus_stylish_container',
    children: [{ kind: 'block', uuid: null, block_type: 'text' }],
  }], null);
  assert.deepEqual(noiseChild[0].children, []);
});

// --- findNode + subtreeNodes: the draft-session subtree rule's projection ---

test('findNode locates a node at any depth, null when the tree lacks it', () => {
  const nodes = pageNodes(LAYOUT, ROOT);
  assert.equal(findNode(nodes, `entity:${ROOT}`).kind, 'entity');
  assert.equal(findNode(nodes, `block:${B3}`).type, 'Image');
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
  assert.equal(one[0].type, 'Section');
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

test('visibleTree over a scoped projection annotates every row the scope projects', () => {
  const nodes = pageNodes(LAYOUT, ROOT);
  const map = { [`block:${B3}`]: { default: { color: 'red' } } };
  // Subtree of an unstyled container over a styled descendant: the anchor
  // prints bare over S2's nested chain, the styled leaf at its end.
  const subtree = visibleTree(scopeNodes(nodes, 'subtree', `container:${S2}`), map);
  assert.deepEqual(subtree.map((node) => node.key), [`container:${S2}`]);
  assert.equal(subtree[0].styled, false);
  assert.deepEqual(subtree[0].children.map((node) => node.key), [`container:${NESTED}`]);
  assert.equal(subtree[0].children[0].styled, false);
  assert.deepEqual(subtree[0].children[0].children.map((node) => node.key), [`block:${B3}`]);
  assert.equal(subtree[0].children[0].children[0].styled, true);
  // Element scope of an unstyled selection: one bare row.
  const one = visibleTree(scopeNodes(nodes, 'element', `container:${S2}`), map);
  assert.deepEqual(one, [
    { key: `container:${S2}`, kind: 'container', type: 'Section', name: 'Section', icon: '/icons/section.svg', depth: 1, styled: false, children: [] },
  ]);
});

// --- isStyledElement: the row body's predicate -------------------------------

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
  // part entry: an element styled only at a tag key prints its islands
  // under its row, where the tag row renders alongside the parts.
  const key = `block:${B1}`;
  const map = { [`${key}::p`]: { default: { 'font-size': '1.25rem' } } };
  assert.equal(isStyledElement(map, key), true);
  const tree = visibleTree(pageNodes(LAYOUT, ROOT), map);
  assert.equal(findNode(tree, key).styled, true);
});

// --- visibleTree: annotation alone, every element a row ----------------------

test('visibleTree annotates styled flags and keeps every element as a row', () => {
  const map = { [`block:${B3}`]: { variants: [{ variant: 'glow', fragment: '' }] } };
  const tree = visibleTree(pageNodes(LAYOUT, ROOT), map);
  // The unstyled S1 branch prints bare, row for row; the chain down to
  // B3 prints its unstyled ancestors bare and the styled leaf with islands.
  assert.equal(tree.length, 1);
  assert.equal(tree[0].styled, false);
  assert.deepEqual(tree[0].children.map((node) => node.key), [`container:${S1}`, `container:${S2}`]);
  const [first, second] = tree[0].children;
  assert.equal(first.styled, false);
  assert.deepEqual(first.children.map((node) => [node.key, node.styled]), [[`block:${B1}`, false], [`block:${B2}`, false]]);
  const nested = second.children[0];
  assert.equal(nested.key, `container:${NESTED}`);
  assert.equal(nested.styled, false);
  assert.deepEqual(nested.children.map((node) => node.key), [`block:${B3}`]);
  assert.equal(nested.children[0].styled, true);
});

test('a fully unstyled layout prints every row bare', () => {
  const tree = visibleTree(pageNodes(LAYOUT, ROOT), {});
  const keys = [];
  const walk = (list) => list.forEach((node) => {
    keys.push(node.key);
    assert.equal(node.styled, false);
    walk(node.children);
  });
  walk(tree);
  assert.deepEqual(keys, [
    `entity:${ROOT}`, `container:${S1}`, `block:${B1}`, `block:${B2}`,
    `container:${S2}`, `container:${NESTED}`, `block:${B3}`,
  ]);
  // The node shape is pageNodes' plus the flag; nothing else changes.
  assert.deepEqual(findNode(tree, `block:${B1}`), {
    ...findNode(pageNodes(LAYOUT, ROOT), `block:${B1}`), styled: false,
  });
});

// --- hasBody / outlineOf: which rows a chevron can collapse -----------------

test('hasBody reads a styled row, a row with children, or the selection', () => {
  const tree = visibleTree(pageNodes(LAYOUT, ROOT), { [`block:${B3}`]: { default: { color: 'red' } } });
  assert.equal(hasBody(findNode(tree, `entity:${ROOT}`)), true);
  assert.equal(hasBody(findNode(tree, `container:${NESTED}`)), true);
  assert.equal(hasBody(findNode(tree, `block:${B3}`)), true);
  // A bare leaf has no body; selecting it mounts one.
  assert.equal(hasBody(findNode(tree, `block:${B1}`)), false);
  assert.equal(hasBody(findNode(tree, `block:${B1}`), `block:${B1}`), true);
  assert.equal(hasBody(findNode(tree, `block:${B1}`), `block:${B2}`), false);
});

test('outlineOf projects the rendered tree into the collapse model\'s shape over hasBody', () => {
  const tree = visibleTree(pageNodes(LAYOUT, ROOT), { [`block:${B3}`]: { default: { color: 'red' } } });
  const flat = (outline) => outline.flatMap((row) => [
    { id: row.id, foldable: row.foldable, folded: row.folded }, ...flat(row.children),
  ]);
  // Every rendered row is a row of the outline, open by default; a bare
  // leaf is not foldable (no chevron, out of the all-gestures' reach).
  assert.deepEqual(flat(outlineOf(tree)), [
    { id: `entity:${ROOT}`, foldable: true, folded: false },
    { id: `container:${S1}`, foldable: true, folded: false },
    { id: `block:${B1}`, foldable: false, folded: false },
    { id: `block:${B2}`, foldable: false, folded: false },
    { id: `container:${S2}`, foldable: true, folded: false },
    { id: `container:${NESTED}`, foldable: true, folded: false },
    { id: `block:${B3}`, foldable: true, folded: false },
  ]);
  // The nesting is the tree's own, so the model's ancestor walk answers
  // from it.
  assert.deepEqual(outlineOf(tree)[0].children.map((row) => row.id), [`container:${S1}`, `container:${S2}`]);
  // The selected bare leaf joins the foldable rows (selection mounts a body).
  assert.equal(flat(outlineOf(tree, `block:${B2}`)).find((row) => row.id === `block:${B2}`).foldable, true);
  assert.deepEqual(outlineOf(null), []);
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

test('foldSummary counts the element\'s own declarations and lists its worn layers', () => {
  const key = `block:${B1}`;
  const map = {
    [key]: {
      default: { color: 'red', padding: '1rem' },
      hover: { color: 'blue' },
      variants: [
        { variant: 'card', fragment: '' },
        { variant: 'glow', fragment: '' },
        { variant: 'card', fragment: 'block(text,1)' },
      ],
    },
    // A part entry and a tag entry count with the element's own lines.
    [`${key}::.quote`]: { default: { color: 'gray' } },
    [`${key}::p`]: { default: { 'font-size': '1.25rem', 'line-height': '1.5' } },
    // Another element's lines never count here.
    [`block:${B2}`]: { default: { margin: '0' } },
  };
  assert.deepEqual(foldSummary(map, key), {
    count: 6,
    // Each worn variant once, in the entry's order, as a layer id.
    worn: [variantLayerId('card'), variantLayerId('glow')],
  });
  // A pure-reference wearing: no lines, the dots alone.
  assert.deepEqual(foldSummary({ [key]: { variants: [{ variant: 'glow', fragment: '' }] } }, key), {
    count: 0,
    worn: [variantLayerId('glow')],
  });
  // An unstyled element (a bare row) summarizes to nothing.
  assert.deepEqual(foldSummary({}, key), { count: 0, worn: [] });
  assert.deepEqual(foldSummary(null, key), { count: 0, worn: [] });
  // A malformed (retired string) ref is no wearing.
  assert.deepEqual(foldSummary({ [key]: { variants: ['card'] } }, key), { count: 0, worn: [] });
});

process.stdout.write('page-model suite passed\n');
