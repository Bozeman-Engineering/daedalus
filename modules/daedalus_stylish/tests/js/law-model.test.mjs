/**
 * Self-asserting tests for the Law tab's pure model (law-model.js):
 * the scope the selection reads its law from, the type roster, the
 * enforcement reading, origins in words, the declaration tree, a
 * node's sections with the doorway's focus, and the scales with what
 * binds to them.
 *
 * Run directly (`node tests/js/law-model.test.mjs`) or with the rest
 * of the suite (`node --test tests/js/*.test.mjs`). Exits non-zero on
 * failure.
 */
import { strict as assert } from 'node:assert';
import {
  bindingWords,
  factWords,
  lawScopeFor,
  lawTree,
  lawTypes,
  nodeLabel,
  nodeSections,
  originTitle,
  originWords,
  readingSentences,
  scaleRows,
} from '../../js/src/law-model.js';
import { lawDocument, lawDocumentFocus } from '../../js/src/code/layers.js';

globalThis.Drupal = {
  t: (text, args = {}) =>
    Object.entries(args).reduce(
      (out, [key, value]) => out.split(key).join(String(value)),
      text,
    ),
};

const tests = [];
const test = (name, run) => tests.push([name, run]);

// --- fixtures -------------------------------------------------------------

const SUITE = { provider: 'daedalus_stylish', file: 'daedalus_stylish.page_builder.yml' };
const GUIDE = { provider: 'guide', file: 'guide.page_builder.yml' };
const TYPE = { provider: 'guide', file: 'guide.page_builder.block_type.text.yml' };
const ALTER = { provider: null, file: null };

const CONTAINER = {
  grants: { spacing: ['padding', 'padding-top'], layout: ['display'] },
  constraints: { padding: { max: '6rem', units: ['rem'] }, 'padding-top': { max: '6rem', units: ['rem'] } },
  origins: {
    grants: { spacing: [GUIDE, ALTER], layout: [] },
    constraints: {
      padding: { source: 'node', facts: { max: GUIDE, units: ALTER } },
      'padding-top': { source: 'node', facts: { max: GUIDE, units: ALTER } },
    },
    axes: null,
    birth: {},
    defaults: {},
  },
  children: {
    grants: { layout: ['display'] },
    constraints: {},
    origins: { grants: { layout: [GUIDE] }, constraints: {} },
  },
  axes: { style: { label: null, root_only: false, description: null, owns: [] } },
  birth: {},
  defaults: {},
};

const TEXT = {
  label: 'Text',
  grants: { typography: ['font-size'], spacing: ['gap'] },
  constraints: {
    'font-size': { scale: 'text', stops: ['sm', 'md'] },
    gap: { scale: 'space', allowed: ['0'] },
  },
  origins: {
    grants: { typography: [TYPE], spacing: [GUIDE] },
    constraints: {
      'font-size': { source: 'node', facts: { scale: TYPE, stops: TYPE } },
      gap: { source: 'site', facts: { scale: SUITE, allowed: SUITE } },
    },
    axes: TYPE,
    birth: { style: TYPE, width: TYPE },
    defaults: { '': { default: { display: TYPE } }, '::.text-body': { narrow: { 'max-width': TYPE } } },
  },
  axes: {
    style: { label: null, root_only: false, description: null, owns: [] },
    width: { label: 'Width', root_only: true, description: 'How wide the band runs.', owns: ['max-width'] },
  },
  birth: { style: { variant: 'text_default', depth: null }, width: { variant: 'wide', depth: 'root' } },
  defaults: { '': { default: { display: 'block' } }, '::.text-body': { narrow: { 'max-width': '40rem' } } },
  elements: {
    '.text-body': {
      label: 'Body',
      grants: { typography: ['font-size'] },
      constraints: { 'font-size': { max: '2rem' } },
      origins: { grants: { typography: [TYPE] }, constraints: { 'font-size': { source: 'node', facts: { max: TYPE } } } },
      pseudo: {
        before: {
          grants: { generated_content: ['content'] },
          constraints: {},
          origins: { grants: { generated_content: [] }, constraints: {} },
        },
      },
    },
  },
};

const LAW = {
  enforcement: 'guarded',
  properties: 'open',
  constraints: 'law',
  scales: {
    text: { label: 'Type', shape: 'dimension', prefix: 'text', contract: ['sm', 'md'], origins: { scale: [SUITE], contract: [GUIDE][0] } },
    space: { label: 'Space', shape: 'dimension', prefix: 'space', contract: [], origins: { scale: [SUITE, GUIDE], contract: null } },
  },
  bindings: {
    gap: { block: { scale: 'space', allowed: ['0'] }, origins: { scale: SUITE, allowed: SUITE } },
    'font-size': { block: { scale: 'text' }, origins: { scale: SUITE } },
  },
  nodes: {
    'kind:container': CONTAINER,
    'kind:block': { grants: {}, constraints: {}, origins: { grants: {}, constraints: {}, axes: null, birth: {}, defaults: {} }, axes: {}, birth: {}, defaults: {} },
    'block_type:text': TEXT,
    'block_plugin:system_menu_block': { grants: {}, constraints: {}, origins: { grants: {}, constraints: {}, axes: null, birth: {}, defaults: {} }, axes: {}, birth: {}, defaults: {} },
    'layout:layout_onecol': { grants: {}, constraints: {}, origins: { grants: {}, constraints: {}, axes: null, birth: {}, defaults: {} }, axes: {}, birth: {}, defaults: {} },
  },
};

const UUID = 'aaaaaaaa-1111-2222-3333-444444444444';
const OTHER = 'bbbbbbbb-1111-2222-3333-444444444444';
const SECTION = 'cccccccc-1111-2222-3333-444444444444';
const BLOCK_TYPES = { [UUID]: 'text', [OTHER]: 'image' };
const LAYOUTS = { [SECTION]: 'layout_onecol' };

// --- lawScopeFor ----------------------------------------------------------

test('lawScopeFor: a block reads its type\'s node, an undeclared type its kind\'s, a section its layout\'s, a part or rule key its root\'s', () => {
  assert.equal(lawScopeFor({ key: `block:${UUID}`, blockTypes: BLOCK_TYPES, law: LAW }), 'block_type:text');
  assert.equal(lawScopeFor({ key: `block:${OTHER}`, blockTypes: BLOCK_TYPES, law: LAW }), 'kind:block', 'the image type declares nothing: the kind is its effective declaration');
  assert.equal(lawScopeFor({ key: `container:${SECTION}`, sectionLayouts: LAYOUTS, law: LAW }), 'layout:layout_onecol');
  assert.equal(lawScopeFor({ key: `entity:${SECTION}`, law: LAW }), null, 'the entity kind is not served on this site');
  assert.equal(lawScopeFor({ key: `block:${UUID}::.text-body`, blockTypes: BLOCK_TYPES, law: LAW }), 'block_type:text');
  assert.equal(lawScopeFor({ key: `block:${UUID}::> p`, blockTypes: BLOCK_TYPES, law: LAW }), 'block_type:text');
});

test('lawScopeFor: null for an unparsable key, no law, or a scope no node and no kind carries', () => {
  assert.equal(lawScopeFor({ key: 'nonsense', law: LAW }), null);
  assert.equal(lawScopeFor({ key: null, law: LAW }), null);
  assert.equal(lawScopeFor({ key: `block:${UUID}`, blockTypes: BLOCK_TYPES, law: null }), null);
  assert.equal(lawScopeFor({ key: `block:${UUID}`, blockTypes: BLOCK_TYPES, law: { nodes: { 'kind:container': CONTAINER } } }), null, 'the block kind is not served either');
});

// --- lawTypes, nodeLabel --------------------------------------------------

test('lawTypes: every node in payload order, named by its label else its scope in words, filed by family', () => {
  assert.deepEqual(lawTypes(LAW), [
    { scope: 'kind:container', label: 'container kind', family: 'Kinds' },
    { scope: 'kind:block', label: 'block kind', family: 'Kinds' },
    { scope: 'block_type:text', label: 'Text', family: 'Block types' },
    { scope: 'block_plugin:system_menu_block', label: 'system_menu_block block plugin', family: 'Block plugins' },
    { scope: 'layout:layout_onecol', label: 'layout_onecol layout', family: 'Layouts' },
  ]);
  assert.deepEqual(lawTypes(null), []);
  assert.equal(nodeLabel('block_type:text', TEXT), 'Text');
  assert.equal(nodeLabel('block_type:quote', { label: '' }), 'quote block type');
});

// --- readingSentences -----------------------------------------------------

test('readingSentences: the mode, what it closes, what constraints do', () => {
  assert.deepEqual(readingSentences(LAW), [
    'Guarded enforcement.',
    'Undeclared properties stay open with recognition and grammar checks.',
    'Authored constraints are law: a value outside one is refused.',
  ]);
  const strict = readingSentences({ enforcement: 'strict', properties: 'closed', constraints: 'law' });
  assert.equal(strict[0], 'Strict enforcement.');
  assert.ok(strict[1].startsWith('Only the properties a declaration grants'));
  const open = readingSentences({ enforcement: 'open', properties: 'open', constraints: 'offer' });
  assert.equal(open[0], 'Open enforcement.');
  assert.ok(open[2].includes('shape the offer only'));
  assert.equal(readingSentences(null)[0], 'Guarded enforcement.');
});

// --- originWords, originTitle, factWords ----------------------------------

test('originWords: a file by its basename with the provider as the title, the alter hook by name, nothing as built in', () => {
  assert.equal(originWords(TYPE), 'guide.page_builder.block_type.text.yml');
  assert.equal(originTitle(TYPE), 'Shipped by guide');
  assert.equal(originWords(ALTER), 'hook_page_builder_declarations_alter()');
  assert.equal(originTitle(ALTER), null);
  assert.equal(originWords(null), 'built in');
  assert.equal(originTitle(null), null);
  assert.equal(factWords('units', ['rem', 'px']), 'rem, px');
  assert.equal(factWords('max', '6rem'), '6rem');
});

// --- lawTree --------------------------------------------------------------

test('lawTree: the root first, then boxes, the children section and parts depth-first, paths in the key grammar', () => {
  assert.deepEqual(
    lawTree(TEXT, 'block_type:text').map(({ path, label, depth, root }) => [path, label, depth, root]),
    [
      ['', 'Text', 0, true],
      ['.text-body', 'Body (.text-body)', 1, false],
      ['.text-body::before', '.text-body::before', 2, false],
    ],
  );
  assert.deepEqual(
    lawTree(CONTAINER, 'kind:container').map(({ path, label }) => [path, label]),
    [['', 'container kind'], ['> *', '> *']],
  );
  const withBox = { ...CONTAINER, pseudo: { after: CONTAINER.children } };
  assert.deepEqual(lawTree(withBox, 'kind:container').map(({ path }) => path), ['', '&::after', '> *']);
});

// --- nodeSections ---------------------------------------------------------

test('nodeSections: a root\'s grants, constraints, axes, birth and defaults, every row with its origin in words', () => {
  const sections = nodeSections(TEXT, LAW);
  assert.deepEqual(sections.map((section) => [section.id, section.label]), [
    ['grants', 'Grants'],
    ['constraints', 'Constraints (law)'],
    ['axes', 'Variant axes'],
    ['birth', 'Born wearing'],
    ['defaults', 'Declared defaults'],
  ]);
  const [grants, constraints, axes, birth, defaults] = sections;
  assert.deepEqual(grants.rows.map((row) => [row.id, row.value, row.origins.map((o) => o.words)]), [
    ['typography', 'font-size', ['guide.page_builder.block_type.text.yml']],
    ['spacing', 'gap', ['guide.page_builder.yml']],
  ]);
  assert.deepEqual(constraints.rows.map((row) => [row.id, row.source, row.focus, row.facts.map((f) => [f.fact, f.value, f.curated, f.origin.words])]), [
    ['font-size', 'node', false, [['scale', 'text', false, 'guide.page_builder.block_type.text.yml'], ['stops', 'sm, md', true, 'guide.page_builder.block_type.text.yml']]],
    ['gap', 'site', false, [['scale', 'space', false, 'daedalus_stylish.page_builder.yml'], ['allowed', '0', false, 'daedalus_stylish.page_builder.yml']]],
  ]);
  assert.deepEqual(axes.rows.map((row) => [row.id, row.label, row.value, row.origin.words]), [
    ['style', 'style', '', 'built in'],
    ['width', 'Width', 'top-level sections only · owns max-width · How wide the band runs.', 'guide.page_builder.block_type.text.yml'],
  ]);
  assert.deepEqual(birth.rows.map((row) => [row.id, row.label, row.value, row.origin.words]), [
    ['style', 'style', 'text_default', 'guide.page_builder.block_type.text.yml'],
    ['width', 'Width', 'wide (top-level sections only)', 'guide.page_builder.block_type.text.yml'],
  ]);
  assert.deepEqual(defaults.rows.map((row) => [row.id, row.label, row.value, row.condition, row.origin.words]), [
    ['|default|display', 'display', 'block', 'default', 'guide.page_builder.block_type.text.yml'],
    ['::.text-body|narrow|max-width', '::.text-body max-width', '40rem', 'narrow', 'guide.page_builder.block_type.text.yml'],
  ]);
});

test('nodeSections: a grant across layers names every layer, an alter-written fact the hook, a derived grant nothing; an open site words the constraints as offer', () => {
  const [grants, constraints] = nodeSections(CONTAINER, { ...LAW, constraints: 'offer' });
  assert.deepEqual(grants.rows.map((row) => [row.id, row.origins.map((o) => [o.words, o.title])]), [
    ['spacing', [['guide.page_builder.yml', 'Shipped by guide'], ['hook_page_builder_declarations_alter()', null]]],
    ['layout', []],
  ]);
  assert.equal(constraints.label, 'Constraints (offer only)');
  assert.deepEqual(constraints.rows[0].facts.map((f) => [f.fact, f.origin.words]), [['max', 'guide.page_builder.yml'], ['units', 'hook_page_builder_declarations_alter()']]);
});

test('nodeSections: a part carries grants and constraints alone; empty sections are omitted', () => {
  const part = nodeSections(TEXT.elements['.text-body'], LAW, { root: false });
  assert.deepEqual(part.map((section) => section.id), ['grants', 'constraints']);
  const box = nodeSections(TEXT.elements['.text-body'].pseudo.before, LAW, { root: false });
  assert.deepEqual(box.map((section) => section.id), ['grants']);
  assert.deepEqual(box[0].rows[0].origins, [], 'the pseudo-only group is derived: no layer wrote it');
  assert.deepEqual(nodeSections(LAW.nodes['kind:block'], LAW), []);
});

test('nodeSections: the doorway\'s focus marks the property\'s rows on the root only', () => {
  const focus = { scope: 'block_type:text', property: 'display' };
  const sections = nodeSections(TEXT, LAW, { focus });
  const defaults = sections.find((section) => section.id === 'defaults');
  assert.deepEqual(defaults.rows.map((row) => [row.id, row.focus]), [['|default|display', true], ['::.text-body|narrow|max-width', false]]);
  const gap = nodeSections(TEXT, LAW, { focus: { scope: 'block_type:text', property: 'gap' } });
  assert.deepEqual(gap.find((section) => section.id === 'constraints').rows.map((row) => [row.id, row.focus]), [['font-size', false], ['gap', true]]);
  const part = nodeSections(TEXT.elements['.text-body'], LAW, { focus: { scope: 'block_type:text', property: 'font-size' }, root: false });
  assert.equal(part[1].rows[0].focus, false, 'a part row is never the focus: the doorway names the root\'s declaration');
  const none = nodeSections(TEXT, LAW, { focus: { scope: 'block_type:text', property: null } });
  assert.ok(none.every((section) => section.rows.every((row) => !row.focus)));
});

// --- scaleRows, bindingWords ----------------------------------------------

test('scaleRows: each scale with its facts, contract and origins, and everything binding to it, site-wide first then per node', () => {
  const rows = scaleRows(LAW);
  assert.deepEqual(rows.map((row) => [row.scale, row.label, row.shape, row.prefix, row.contract, row.origins.map((o) => o.words), row.contractOrigin?.words ?? null]), [
    ['text', 'Type', 'dimension', 'text', ['sm', 'md'], ['daedalus_stylish.page_builder.yml'], 'guide.page_builder.yml'],
    ['space', 'Space', 'dimension', 'space', [], ['daedalus_stylish.page_builder.yml', 'guide.page_builder.yml'], null],
  ]);
  assert.deepEqual(rows[0].bindings, [
    { property: 'font-size', site: true, scope: null, path: '' },
    { property: 'font-size', site: false, scope: 'block_type:text', path: '' },
  ]);
  assert.deepEqual(rows[1].bindings, [{ property: 'gap', site: true, scope: null, path: '' }], 'a node inheriting the site binding is not a binding of its own');
  assert.deepEqual(bindingWords(rows[0].bindings[0]), 'font-size (site-wide)');
  assert.deepEqual(bindingWords(rows[0].bindings[1]), 'font-size on text block type');
  assert.deepEqual(bindingWords({ property: 'max-width', site: false, scope: 'layout:layout_onecol', path: '.band-inner' }), 'max-width on layout_onecol layout .band-inner');
  assert.deepEqual(scaleRows(null), []);
});

// --- the law document reference (code/layers.js) --------------------------

test('lawDocument: the declared layer id, optionally at a property; the focus reads back', () => {
  assert.equal(lawDocument('block_type:text'), 'declared:block_type:text');
  assert.equal(lawDocument('block_type:text', 'display'), 'declared:block_type:text#display');
  assert.deepEqual(lawDocumentFocus('declared:block_type:text#display'), { scope: 'block_type:text', property: 'display' });
  assert.deepEqual(lawDocumentFocus('declared:kind:container'), { scope: 'kind:container', property: null });
  assert.equal(lawDocumentFocus('skin:block_type:text'), null, 'a skin document is a session, not the law');
  assert.equal(lawDocumentFocus('stuff'), null, 'a variant reference is a session');
  assert.equal(lawDocumentFocus('declared:'), null);
  assert.equal(lawDocumentFocus(null), null);
});

// --- runner ---------------------------------------------------------------

let failed = 0;
for (const [name, run] of tests) {
  try {
    run();
    console.log(`ok - ${name}`);
  } catch (error) {
    failed += 1;
    console.log(`not ok - ${name}`);
    console.log(error.stack ?? String(error));
  }
}
console.log(`\n${tests.length - failed} passed, ${failed} failed`);
process.exitCode = failed ? 1 : 0;
