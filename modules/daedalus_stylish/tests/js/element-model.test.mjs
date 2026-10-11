/**
 * Self-asserting tests for the single-element islands view model.
 *
 * Run directly (`node tests/js/element-model.test.mjs`) or via
 * PrinterEquivalenceTest, which executes this file inside the suite so the
 * roster/cadence/restore contract has PHPUnit backpressure. Exits non-zero
 * on the first failure with a readable diff.
 */
import { strict as assert } from 'node:assert';
import {
  ancestorIslands, bracedSelector, composeUnder, conditionRemovalOps, draftRoster, elementIslands,
  islandConditions, nestUnder, nestedSpelling, occupiedRoster, ownIslandLines, partRemovalOps, posedRoster,
  restoredLines, ruleIslandLines, selectorGestureOptions, settleOps, shouldRestoreTinted,
} from '../../js/src/code/element-model.js';
import { TREE } from './selector-matcher-fixture.mjs';
import { analyzeIsland, islandOps, printIsland, stackLines } from '../../js/src/code/island.js';
import { ELEMENT_LAYER, variantLayerId } from '../../js/src/code/layers.js';
import { buildStack } from '../../js/src/style-stack.js';

// The island's lines: the key's stack at one condition, the map's layers
// alone (the roster and cadence reads never print the floor).
const linesOf = (map, key, condition, variants) =>
  stackLines(buildStack({ map, key, variants, floor: false }), condition);

const KEY = 'block:11111111-1111-1111-1111-111111111111';
const SUB = `${KEY}::.quote`;

const guide = {
  enforcement: 'guarded',
  groups: {
    spacing: {
      label: 'Spacing',
      properties: {
        padding: { label: 'Padding', type: 'dimension', repeat: 4 },
        margin: { label: 'Margin', type: 'dimension', repeat: 4, negative: true },
        gap: { label: 'Gap', type: 'dimension' },
      },
    },
    typography: {
      label: 'Typography',
      properties: {
        'font-size': { label: 'Font size', type: 'dimension' },
        color: { label: 'Text color', type: 'color' },
      },
    },
  },
  conditions: {
    hover: { label: 'Hover', wrapper: ':hover' },
    narrow: { label: 'Narrow', wrapper: '@container (max-width: 767px)' },
  },
};

const declaration = {
  grants: { spacing: ['padding', 'margin', 'gap'], typography: ['font-size', 'color'] },
  valueSpaces: { padding: { max: '4rem', units: ['rem', 'px'] } },
};

const VARIANTS = [{
  id: 'aqua',
  label: 'Aqua',
  value: {
    '': { default: { padding: '2rem', color: '#1a1a2e' }, hover: { color: 'var(--brand)' } },
    '::.quote': { default: { 'font-size': '1.25rem' } },
    'container(1)': { default: { gap: '1rem' } },
    'container(1)::.credit': { default: { color: 'teal' } },
  },
}];

const analyze = (text) => analyzeIsland(text, { guide, declaration });

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

// --- The roster: which islands exist ----------------------------------------

test('default island always exists; condition rows only for conditions with values', () => {
  assert.deepEqual(islandConditions(null, guide), ['default']);
  assert.deepEqual(islandConditions({ default: { gap: '1rem' } }, guide), ['default']);
  assert.deepEqual(
    islandConditions({ hover: { color: 'red' }, default: { gap: '1rem' } }, guide),
    ['default', 'hover'],
  );
});

test('conditions order by the guide vocabulary, unknown names last', () => {
  const entry = { zz_custom: { gap: '2px' }, narrow: { padding: '1rem' }, hover: { color: 'red' } };
  assert.deepEqual(islandConditions(entry, guide), ['default', 'hover', 'narrow', 'zz_custom']);
});

test('an all-null condition layer earns no condition row', () => {
  assert.deepEqual(islandConditions({ hover: { color: null } }, guide), ['default']);
});

test('the roster includes variant-supplied conditions (the resolved layers)', () => {
  const map = { [KEY]: { variants: [{ variant: 'aqua', fragment: '' }] } };
  const model = elementIslands(map, KEY, guide, VARIANTS);
  assert.deepEqual(model.conditions, ['default', 'hover']);
});

test('part rows are the stack\'s enumeration: worn references first, then own map entries, deduped', () => {
  // The roster is selectorSuffixes() verbatim (delivery order: the
  // element's worn fragments, then its map entries in map order), never
  // a walk of its own: the map lists .credit before .quote, the worn
  // .quote still rows first, and the own .quote entry deduplicates into
  // that row.
  const map = {
    [KEY]: { variants: [{ variant: 'aqua', fragment: '' }] },
    [`${KEY}::.credit`]: { hover: { color: 'teal' } },
    [SUB]: { default: { color: 'purple' } },
  };
  const model = elementIslands(map, KEY, guide, VARIANTS);
  assert.deepEqual(model.parts.map((part) => part.selector), ['.quote', '.credit']);
  assert.deepEqual(model.parts[0], { selector: '.quote', key: SUB, conditions: ['default'], ancestors: [] });
  assert.deepEqual(model.parts[1].conditions, ['default', 'hover']);
});

test('a distributed fragment reference supplies its own part rows', () => {
  const child = 'container:33333333-3333-3333-3333-333333333333';
  const map = { [child]: { variants: [{ variant: 'aqua', fragment: 'container(1)' }] } };
  const model = elementIslands(map, child, guide, VARIANTS);
  assert.deepEqual(model.parts.map((part) => part.selector), ['.credit']);
});

test('tag-tier keys make sub rows alongside the part rows', () => {
  // The element-scoped tag tier ('{key}::p') rides the roster's sub-row
  // list: a stored tag entry materializes its row exactly like a part
  // entry, in map order alongside them, conditions included.
  const map = {
    [SUB]: { default: { color: 'purple' } },
    [`${KEY}::p`]: { default: { 'font-size': '1.25rem' }, hover: { color: 'teal' } },
  };
  const model = elementIslands(map, KEY, guide, []);
  assert.deepEqual(model.parts.map((part) => part.selector), ['.quote', 'p']);
  assert.deepEqual(model.parts[1], {
    selector: 'p', key: `${KEY}::p`, conditions: ['default', 'hover'], ancestors: [],
  });
});

test('a generated box rows like a part: the stack enumerates the stored one, a draft mints the other, the island prints the seeded line', () => {
  const BOX = `${KEY}::&::before`;
  const map = {
    [SUB]: { default: { color: 'purple' } },
    [BOX]: { default: { content: '""', color: 'teal' }, hover: { color: 'gold' } },
  };
  const model = elementIslands(map, KEY, guide, []);
  assert.deepEqual(model.parts.map((part) => part.selector), ['.quote', '&::before']);
  assert.deepEqual(model.parts[1], { selector: '&::before', key: BOX, conditions: ['default', 'hover'], ancestors: [] });
  // The row's island prints the box's own lines, the birth seed first as
  // the map stores it; the chrome (its label, its code face) reads the
  // selector, never the island.
  assert.deepEqual(
    ownIslandLines(linesOf(map, BOX, 'default', [])).map((line) => `${line.property}: ${line.value}`),
    ['content: ""', 'color: teal'],
  );
  // A picked or braced `&::after` is a draft row at the box key until its
  // first write mints the entry (and the op spine seeds its content).
  const drafted = draftRoster(model, { parts: ['&::after'] }, KEY, guide);
  assert.deepEqual(drafted.parts.map((part) => part.selector), ['.quote', '&::before', '&::after']);
  assert.deepEqual(drafted.parts[2], { selector: '&::after', key: `${KEY}::&::after`, conditions: ['default'], ancestors: [] });
  // A part's box drafts under the owner too, as its own row.
  assert.deepEqual(
    draftRoster(model, { parts: ['.quote::after'] }, KEY, guide).parts.map((part) => part.selector),
    ['.quote', '&::before', '.quote::after'],
  );
});

test('a map key outside the grammar still rows: the roster lists what the stack enumerates', () => {
  // Both twins of the enumeration (selectorSuffixes, partSuffixes) list
  // every '::' entry the map carries; the validator gates what a write
  // may store, the roster never second-guesses the map. Such a row
  // prints its lines as inert data (the stack builds it, the header
  // falls back to the raw text), the same honesty as an unknown
  // condition name.
  const map = {
    [KEY]: { default: { gap: '1rem' } },
    [`${KEY}::not a selector!`]: { default: { color: 'red' } },
  };
  const model = elementIslands(map, KEY, guide, []);
  assert.deepEqual(model.parts, [
    { selector: 'not a selector!', key: `${KEY}::not a selector!`, conditions: ['default'], ancestors: [] },
  ]);
});

test('a rule\'s worn continuation makes a part row of the owner, and an occupied island on an unselected row', () => {
  // The owner wears nothing and holds no entry at the continued
  // position; the stack still delivers Aqua's .quote entry there through
  // the rule's wear (the position continues by descent), so the owner
  // gains the row (continuations first, then the rule's own row) with
  // the conditions the delivered entry holds, and the sparse read keeps
  // it occupied while the owner's empty root island prunes.
  const owner = 'container:33333333-3333-3333-3333-333333333333';
  const rule = `${owner}::> pb-text`;
  const map = { [rule]: { variants: [{ variant: 'aqua', fragment: '' }] } };
  const model = elementIslands(map, owner, guide, VARIANTS);
  assert.deepEqual(model.parts, [
    { selector: '> pb-text .quote', key: `${rule} .quote`, conditions: ['default'], ancestors: [] },
    { selector: '> pb-text', key: rule, conditions: ['default', 'hover'], ancestors: [] },
  ]);
  const pruned = occupiedRoster(model, map, owner, VARIANTS);
  assert.deepEqual(pruned.conditions, []);
  assert.deepEqual(pruned.parts.map((part) => [part.selector, part.conditions]), [
    ['> pb-text .quote', ['default']],
    ['> pb-text', ['default', 'hover']],
  ]);
  assert.deepEqual(
    linesOf(map, `${rule} .quote`, 'default', VARIANTS).map((line) => [line.property, line.value, line.variant]),
    [['font-size', '1.25rem', 'aqua']],
  );
});

// --- Ancestor rules: the rule rows and the two line projections -------------

// A page built by hand (real uuids: the floor captures a scope through
// parseKey, which the matcher fixture's short keys never do): section A
// (Hero) holds section B, which holds the text block T3 (a paragraph
// root); section E (Links) holds section F holding T8, whose link part
// renders an anchor. Section B wears Band, whose `::> *` fragment
// reaches T3 through B's `> *` rule.
const A_UUID = 'aaaaaaaa-0000-4000-8000-00000000000a';
const B_UUID = 'bbbbbbbb-0000-4000-8000-00000000000b';
const E_UUID = 'eeeeeeee-0000-4000-8000-00000000000e';
const F_UUID = 'ffffffff-0000-4000-8000-00000000000f';
const T3_UUID = '33333333-0000-4000-8000-000000000003';
const T8_UUID = '88888888-0000-4000-8000-000000000008';
const SEC_A = `container:${A_UUID}`;
const SEC_B = `container:${B_UUID}`;
const SEC_E = `container:${E_UUID}`;
const T3 = `block:${T3_UUID}`;
const T8 = `block:${T8_UUID}`;
const section = (uuid, label, children) => ({
  kind: 'container', uuid, layout_id: 'daedalus_stylish_container', label, _element: { markup: { tag: 'section' } }, children,
});
const PAGE = [
  section(A_UUID, 'Hero', [
    section(B_UUID, null, [
      { kind: 'block', uuid: T3_UUID, block_type: 'text', _element: { markup: { tag: 'p', tags: ['em'] } } },
    ]),
  ]),
  section(E_UUID, 'Links', [
    section(F_UUID, null, [
      { kind: 'block', uuid: T8_UUID, block_type: 'text', _element: { markup: { tag: 'p', tags: ['strong'], parts: { '.text-block-link': { tag: 'a' } } } } },
    ]),
  ]),
];
const A_RULE = `${SEC_A}::> pb-container > *`;
const B_RULE = `${SEC_B}::> *`;
const BLOCK_TYPES = { [T3_UUID]: 'text', [T8_UUID]: 'text' };
const ANCESTOR_GUIDE = {
  ...guide,
  declaredDefaults: { 'block_type:text': { '': { default: { color: 'black', padding: '0' } } } },
};
const BAND = {
  id: 'band',
  label: 'Band',
  value: { '': { default: { display: 'flex' } }, '::> *': { default: { 'flex-grow': '1', color: 'green' } } },
};
const ANCESTOR_MAP = {
  [A_RULE]: { default: { gap: '1rem' }, hover: { color: 'red' } },
  [SEC_B]: { variants: [{ variant: 'band', fragment: '' }] },
  [B_RULE]: { default: { color: 'teal', padding: '1rem' } },
  [T3]: { default: { color: 'purple' } },
};
const roster = (map, key, address = null, facet = null) =>
  elementIslands(map, key, ANCESTOR_GUIDE, [BAND], null, BLOCK_TYPES, {}, PAGE, address, facet);
const reachingT3 = (map) =>
  stackLines(buildStack({ guide: ANCESTOR_GUIDE, map, key: T3, variants: [BAND], blockTypes: BLOCK_TYPES, tree: PAGE }), 'default');

test('the roster lists the ancestor rules reaching the root nearest ancestor first, as islands of the rules\' own keys; no tree, no rules', () => {
  const model = roster(ANCESTOR_MAP, T3);
  assert.deepEqual(model.ancestors, [
    { owner: SEC_B, selector: '> *', key: B_RULE, conditions: ['default'], existing: true },
    { owner: SEC_A, selector: '> pb-container > *', key: A_RULE, conditions: ['default', 'hover'], existing: true },
  ]);
  assert.deepEqual(model.parts, []);
  // The tree scopes' sparse rows pass no tree: DevTools' rule, inherited
  // rules for the element you are inspecting alone.
  assert.deepEqual(elementIslands(ANCESTOR_MAP, T3, ANCESTOR_GUIDE, [BAND], null, BLOCK_TYPES, {}).ancestors, []);
});

test('a seated rule the map lacks lists as a virtual island at its delivery place; a rule that only wears lists as existing', () => {
  const map = { ...ANCESTOR_MAP, [B_RULE]: { variants: [{ variant: 'band', fragment: '' }] } };
  const model = roster(map, T3, { key: SEC_B, selector: '> pb-text' });
  assert.deepEqual(model.ancestors.map((rule) => [rule.selector, rule.existing, rule.conditions]), [
    ['> *', true, ['default']],
    ['> pb-text', false, ['default']],
    ['> pb-container > *', true, ['default', 'hover']],
  ]);
  // The address names the root's facet alone: no part row carries it.
  assert.deepEqual(
    ancestorIslands({ guide: ANCESTOR_GUIDE, map, key: `${T3}::em`, variants: [BAND], blockTypes: BLOCK_TYPES, tree: PAGE }),
    [],
  );
});

test('a rule reaching a part rows under the part, never the root', () => {
  const link = `${T8}::.text-block-link`;
  const rule = `${SEC_E}::pb-text .text-block-link`;
  const map = { [link]: { default: { color: 'blue' } }, [rule]: { hover: { color: 'red' } } };
  const model = roster(map, T8);
  assert.deepEqual(model.ancestors, []);
  assert.deepEqual(
    model.parts.map((part) => [part.selector, part.ancestors.map((entry) => [entry.owner, entry.selector, entry.key, entry.conditions, entry.existing])]),
    [['.text-block-link', [[SEC_E, 'pb-text .text-block-link', rule, ['default', 'hover'], true]]]],
  );
  // A write address under the PART facet (the spine's two facts: the
  // caller says which facet the address stands under, nothing derives
  // it from the rule) places its mintable rule under the part's row,
  // never the root's.
  const seated = roster({ [link]: { default: { color: 'blue' } } }, T8, { key: SEC_E, selector: '> pb-text .text-block-link' }, '.text-block-link');
  assert.deepEqual(seated.ancestors, []);
  assert.deepEqual(
    seated.parts.map((part) => [part.selector, part.ancestors.map((entry) => [entry.selector, entry.existing])]),
    [['.text-block-link', [['> pb-text .text-block-link', false]]]],
  );
  // The same address handed as the root's (no facet) rows under the
  // root: the facet is the caller's fact, not the matcher's inverse.
  const atRoot = roster({ [link]: { default: { color: 'blue' } } }, T8, { key: SEC_E, selector: '> pb-text .text-block-link' });
  assert.deepEqual(atRoot.ancestors.map((entry) => [entry.selector, entry.existing]), [['> pb-text .text-block-link', false]]);
});

test('an own-key address places no rule row: the part row is the island, under the facet and at the root alike', () => {
  const link = `${T8}::.text-block-link`;
  const held = { [link]: { default: { color: 'blue' } } };
  // The chip's own tag tier for the link part ("Every link here",
  // `{T8}::a`) the map does not hold yet: no rule row under the facet,
  // no row anywhere; the view drafts the part row with the cursor.
  const mintable = roster(held, T8, { key: T8, selector: 'a' }, '.text-block-link');
  assert.deepEqual(mintable.ancestors, []);
  assert.deepEqual(
    mintable.parts.map((part) => [part.selector, part.ancestors]),
    [['.text-block-link', []]],
  );
  // The same address once the map holds it rows ONCE, as the part row
  // the stack enumerates, never again as a rule row under the facet.
  const written = roster({ ...held, [`${T8}::a`]: { default: { color: 'red' } } }, T8, { key: T8, selector: 'a' }, '.text-block-link');
  assert.deepEqual(written.ancestors, []);
  assert.deepEqual(
    written.parts.map((part) => [part.selector, part.ancestors]).sort(),
    [['.text-block-link', []], ['a', []]],
  );
  // At the root (no facet, "Every paragraph here" on a text block): the
  // ancestor rules reaching the root list as before, the own tier not
  // among them.
  const atRoot = roster(ANCESTOR_MAP, T3, { key: T3, selector: 'p' });
  assert.deepEqual(atRoot.ancestors.map((entry) => [entry.owner, entry.selector]), [[SEC_B, '> *'], [SEC_A, '> pb-container > *']]);
  assert.deepEqual(atRoot.parts, []);
});

test('an ancestor address under the same facet still places its rule row, existing false while the map lacks it', () => {
  const link = `${T8}::.text-block-link`;
  const held = { [link]: { default: { color: 'blue' } } };
  const seated = roster(held, T8, { key: SEC_E, selector: '> pb-text .text-block-link' }, '.text-block-link');
  assert.deepEqual(seated.ancestors, []);
  assert.deepEqual(
    seated.parts.map((part) => [part.selector, part.ancestors.map((entry) => [entry.owner, entry.selector, entry.key, entry.existing])]),
    [['.text-block-link', [[SEC_E, '> pb-text .text-block-link', `${SEC_E}::> pb-text .text-block-link`, false]]]],
  );
  // Written, the same row reads existing: the placement rule is the
  // address's owner, not the map's holding.
  const written = roster({ ...held, [`${SEC_E}::> pb-text .text-block-link`]: { hover: { color: 'red' } } }, T8, { key: SEC_E, selector: '> pb-text .text-block-link' }, '.text-block-link');
  assert.deepEqual(
    written.parts.map((part) => [part.selector, part.ancestors.map((entry) => [entry.selector, entry.existing, entry.conditions])]),
    [['.text-block-link', [['> pb-text .text-block-link', true, ['default', 'hover']]]]],
  );
});

test('draftRoster merges a drafted condition into a rule row by the rule\'s key', () => {
  const model = roster(ANCESTOR_MAP, T3);
  const merged = draftRoster(model, { conditions: { [B_RULE]: ['narrow'] } }, T3, ANCESTOR_GUIDE);
  assert.deepEqual(merged.ancestors.map((rule) => rule.conditions), [['default', 'narrow'], ['default', 'hover']]);
});

test('ownIslandLines drops the ancestor tier\'s lines and keeps the tier as a winner on the floor', () => {
  const lines = reachingT3(ANCESTOR_MAP);
  assert.ok(lines.some((line) => line.layer === `ancestor:${B_RULE}`));
  assert.ok(lines.some((line) => line.layer === `ancestor:${B_RULE}|band`));
  assert.deepEqual(
    ownIslandLines(lines).map((line) => [line.layer, line.property, line.value, line.lost, line.winner?.layer ?? null]),
    [
      ['declared:block_type:text', 'color', 'black', true, ELEMENT_LAYER],
      ['declared:block_type:text', 'padding', '0', true, `ancestor:${B_RULE}`],
      [ELEMENT_LAYER, 'color', 'purple', false, null],
    ],
  );
});

test('ruleIslandLines prints the rule\'s own document, struck where the element beats it, the diff blind to the strike', () => {
  const rule = { owner: SEC_B, selector: '> *', key: B_RULE };
  const ruleStack = buildStack({ guide: ANCESTOR_GUIDE, map: ANCESTOR_MAP, key: B_RULE, variants: [BAND], floor: false });
  const lines = ruleIslandLines(stackLines(ruleStack, 'default'), rule, reachingT3(ANCESTOR_MAP));
  assert.deepEqual(
    lines.map((line) => [line.layer, line.property, line.value, line.lost, line.winner?.layer ?? null, line.beaten?.layer ?? null]),
    [
      [variantLayerId('band'), 'flex-grow', '1', false, null, null],
      [variantLayerId('band'), 'color', 'green', true, ELEMENT_LAYER, null],
      [ELEMENT_LAYER, 'color', 'teal', false, null, ELEMENT_LAYER],
      [ELEMENT_LAYER, 'padding', '1rem', false, null, null],
    ],
  );
  // The rule's own cascade is the diff's baseline: its printed document
  // settles to nothing, the beaten line included.
  assert.deepEqual(islandOps(B_RULE, 'default', analyze(printIsland(lines)), lines), []);
  // A line the element does not beat, or the rule's own value elsewhere
  // (a fact-folded read), stays unmarked: the match is by value too.
  const other = ruleIslandLines(
    [{ property: 'color', value: 'navy', layer: ELEMENT_LAYER, variant: null, lost: false, winner: null }],
    rule,
    reachingT3(ANCESTOR_MAP),
  );
  assert.equal(other[0].beaten, undefined);
});

// --- Sparse rows: only occupied islands render ------------------------------

test('occupiedRoster prunes the empty root island of a part-styled element', () => {
  const map = { [SUB]: { default: { color: 'purple' } } };
  const pruned = occupiedRoster(elementIslands(map, KEY, guide, []), map, KEY, []);
  assert.deepEqual(pruned.conditions, []);
  assert.deepEqual(pruned.parts.map((part) => part.selector), ['.quote']);
});

test('occupiedRoster keeps variant-delivered islands (root and parts)', () => {
  const map = { [KEY]: { variants: [{ variant: 'aqua', fragment: '' }] } };
  const pruned = occupiedRoster(elementIslands(map, KEY, guide, VARIANTS), map, KEY, VARIANTS);
  assert.deepEqual(pruned.conditions, ['default', 'hover']);
  assert.deepEqual(pruned.parts.map((part) => part.selector), ['.quote']);
});

test('occupiedRoster drops part rows whose islands all prune', () => {
  const map = {
    [KEY]: { default: { gap: '1rem' } },
    [SUB]: { default: { color: null } },
  };
  const pruned = occupiedRoster(elementIslands(map, KEY, guide, []), map, KEY, []);
  assert.deepEqual(pruned.conditions, ['default']);
  assert.deepEqual(pruned.parts, []);
});

test('draftRoster never resurrects a pruned default island', () => {
  const map = { [SUB]: { default: { color: 'purple' } } };
  const pruned = occupiedRoster(elementIslands(map, KEY, guide, []), map, KEY, []);
  const merged = draftRoster(pruned, { conditions: { [KEY]: ['hover'] }, parts: ['.credit'] }, KEY, guide);
  assert.deepEqual(merged.conditions, ['hover']);
  // A drafted NEW part keeps its default island: the type-here surface.
  assert.deepEqual(merged.parts.find((part) => part.selector === '.credit').conditions, ['default']);
});

// --- Shadowed values print in place (ghost rows are deleted) ------------------

test('a shadowed variant value prints as a lost line in the document itself', () => {
  const map = { [KEY]: { variants: [{ variant: 'aqua', fragment: '' }], default: { color: 'var(--x)', gap: '1rem' } } };
  const lines = linesOf(map, KEY, 'default', VARIANTS);
  assert.deepEqual(lines.filter((line) => line.property === 'color'), [
    { property: 'color', value: '#1a1a2e', layer: variantLayerId('aqua'), variant: 'aqua', lost: true, winner: { layer: ELEMENT_LAYER, variant: null } },
    { property: 'color', value: 'var(--x)', layer: ELEMENT_LAYER, variant: null, lost: false, winner: null },
  ]);
});

// --- The settle cadence -------------------------------------------------------

test('an unterminated declaration never commits', () => {
  const lines = [{ property: 'padding', value: '2rem', layer: ELEMENT_LAYER, variant: null }];
  const text = 'padding: 3rem';
  assert.deepEqual(settleOps(KEY, 'default', text, analyze(text), lines, { cursor: null }), []);
});

test('a terminated declaration commits once the cursor is off its line', () => {
  const lines = [{ property: 'padding', value: '2rem', layer: ELEMENT_LAYER, variant: null }];
  const text = 'padding: 3rem;\ngap: 1rem';
  const analysis = analyze(text);
  // Cursor on line 2 (mid-typing gap): padding settled, gap not terminated.
  assert.deepEqual(settleOps(KEY, 'default', text, analysis, lines, { cursor: text.length }), [
    { op: 'update_style', key: KEY, properties: { padding: '3rem' } },
  ]);
  // Cursor still on padding's line: it stays held.
  assert.deepEqual(settleOps(KEY, 'default', text, analysis, lines, { cursor: 4 }), []);
});

test('includeActive commits the cursor line too (";" typed, island blurred)', () => {
  const lines = [{ property: 'padding', value: '2rem', layer: ELEMENT_LAYER, variant: null }];
  const text = 'padding: 3rem;';
  assert.deepEqual(settleOps(KEY, 'default', text, analyze(text), lines, { cursor: text.length, includeActive: true }), [
    { op: 'update_style', key: KEY, properties: { padding: '3rem' } },
  ]);
});

test('whitespace between value and ";" still reads as terminated', () => {
  const text = 'padding: 3rem ;';
  assert.deepEqual(settleOps(KEY, 'default', text, analyze(text), [], { cursor: null }), [
    { op: 'update_style', key: KEY, properties: { padding: '3rem' } },
  ]);
});

test('deletion is an unset on the boundary, cursor position notwithstanding', () => {
  const lines = [
    { property: 'gap', value: '1rem', layer: ELEMENT_LAYER, variant: null },
    { property: 'padding', value: '2rem', layer: variantLayerId('aqua'), variant: 'aqua' },
  ];
  // The own line is gone (unset); the tinted line's deletion stays a no-op
  // inside islandOps; the half-typed margin holds itself only.
  const text = 'margin: 1re';
  assert.deepEqual(settleOps(KEY, 'default', text, analyze(text), lines, { cursor: 5 }), [
    { op: 'update_style', key: KEY, properties: { gap: null } },
  ]);
});

test('a half-retyped line neither commits nor unsets (held)', () => {
  const lines = [{ property: 'gap', value: '1rem', layer: ELEMENT_LAYER, variant: null }];
  const text = 'gap';
  assert.deepEqual(settleOps(KEY, 'default', text, analyze(text), lines, { cursor: 3 }), []);
});

test('invalid declarations stay out even when terminated', () => {
  const text = 'padding: 9rem;';
  assert.deepEqual(settleOps(KEY, 'default', text, analyze(text), [], { cursor: null, includeActive: true }), []);
});

test('the printed island settles to zero ops under every cadence', () => {
  const map = { [KEY]: { variants: [{ variant: 'aqua', fragment: '' }], default: { gap: '1rem' } } };
  const lines = linesOf(map, KEY, 'default', VARIANTS);
  const text = printIsland(lines);
  const analysis = analyze(text);
  assert.deepEqual(settleOps(KEY, 'default', text, analysis, lines, { cursor: 0 }), []);
  assert.deepEqual(settleOps(KEY, 'default', text, analysis, lines, { cursor: null, includeActive: true }), []);
});

test('condition islands stamp their condition on settled ops', () => {
  const text = 'color: teal;';
  assert.deepEqual(settleOps(KEY, 'hover', text, analyze(text), [], { cursor: null, includeActive: true }), [
    { op: 'update_style', key: KEY, condition: 'hover', properties: { color: 'teal' } },
  ]);
});

// --- The tinted-deletion restore gate -----------------------------------------

test('a deleted tinted line restores once the buffer holds nothing else', () => {
  const lines = [
    { property: 'padding', value: '2rem', layer: variantLayerId('aqua'), variant: 'aqua' },
    { property: 'gap', value: '1rem', layer: ELEMENT_LAYER, variant: null },
  ];
  // The tinted padding line is gone; the own gap line is untouched.
  const text = 'gap: 1rem;';
  assert.equal(shouldRestoreTinted(KEY, 'default', analyze(text), lines), true);
});

test('a half-retyped tinted line is held, never restored over', () => {
  const lines = [{ property: 'padding', value: '2rem', layer: variantLayerId('aqua'), variant: 'aqua' }];
  const text = 'padding: 3r';
  assert.equal(shouldRestoreTinted(KEY, 'default', analyze(text), lines), false);
});

test('pending work blocks the restore: uncommitted diffs, own deletions', () => {
  const lines = [
    { property: 'padding', value: '2rem', layer: variantLayerId('aqua'), variant: 'aqua' },
    { property: 'gap', value: '1rem', layer: ELEMENT_LAYER, variant: null },
  ];
  // A changed own value is an uncommitted diff.
  assert.equal(shouldRestoreTinted(KEY, 'default', analyze('gap: 2rem;'), lines), false);
  // A deleted own line is an uncommitted unset.
  assert.equal(shouldRestoreTinted(KEY, 'default', analyze(''), lines), false);
});

test('a faithful buffer never re-prints (formatting is the user\'s)', () => {
  const lines = [{ property: 'padding', value: '2rem', layer: variantLayerId('aqua'), variant: 'aqua' }];
  assert.equal(shouldRestoreTinted(KEY, 'default', analyze('\npadding: 2rem;\n\n'), lines), false);
});

test('a deleted LOST line restores; the own winner keeps its buffer copy', () => {
  const map = { [KEY]: { variants: [{ variant: 'aqua', fragment: '' }], default: { padding: '3rem' } } };
  const lines = linesOf(map, KEY, 'default', VARIANTS);
  // The struck variant padding line was deleted; the own 3rem line stands
  // and must not be mistaken for the variant's copy.
  assert.equal(shouldRestoreTinted(KEY, 'default', analyze('color: #1a1a2e;\npadding: 3rem;'), lines), true);
  // The full printed pair is faithful: nothing to restore.
  assert.equal(shouldRestoreTinted(KEY, 'default', analyze(printIsland(lines)), lines), false);
});

// --- Materialization drafts ---------------------------------------------------

test('draft conditions slot into the vocabulary order, not last', () => {
  const model = elementIslands({ [KEY]: { narrow: { padding: '1rem' } } }, KEY, guide, []);
  const merged = draftRoster(model, { conditions: { [KEY]: ['hover'] }, parts: [] }, KEY, guide);
  assert.deepEqual(merged.conditions, ['default', 'hover', 'narrow']);
});

test('draft parts append after map parts, carrying their drafted conditions', () => {
  const model = elementIslands({ [SUB]: { default: { color: 'purple' } } }, KEY, guide, []);
  const creditKey = `${KEY}::.credit`;
  const merged = draftRoster(model, { conditions: { [creditKey]: ['hover'] }, parts: ['.credit'] }, KEY, guide);
  assert.deepEqual(merged.parts.map((part) => part.selector), ['.quote', '.credit']);
  assert.deepEqual(merged.parts[1], { selector: '.credit', key: creditKey, conditions: ['default', 'hover'], ancestors: [] });
});

test('drafts the map already surfaces absorb; malformed draft selectors drop', () => {
  const model = elementIslands({ [SUB]: { default: { color: 'purple' } } }, KEY, guide, []);
  const merged = draftRoster(model, { conditions: {}, parts: ['.quote', 'not a selector!', '::>'] }, KEY, guide);
  assert.deepEqual(merged.parts.map((part) => part.selector), ['.quote']);
});

test('a drafted tag row appends alongside the part rows (the tag gesture)', () => {
  // Tag rows materialize through the same draft lane as parts: picking a
  // tag in the completion drafts its sub, and the row appends with its
  // type-here default island until the first declaration commits.
  const model = elementIslands({ [SUB]: { default: { color: 'purple' } } }, KEY, guide, []);
  const merged = draftRoster(model, { conditions: {}, parts: ['ul'] }, KEY, guide);
  assert.deepEqual(merged.parts.map((part) => part.selector), ['.quote', 'ul']);
  assert.deepEqual(merged.parts[1], { selector: 'ul', key: `${KEY}::ul`, conditions: ['default'], ancestors: [] });
});

// --- The posed compound's ghost island ------------------------------------------

test('a posed compound with no stored island mints a ghost root row, in order', () => {
  const model = elementIslands({ [KEY]: { narrow: { padding: '1rem' } } }, KEY, guide, []);
  const ghosted = posedRoster(model, 'hover', guide);
  assert.deepEqual(ghosted.conditions, ['default', 'hover', 'narrow']);
  // Root only: parts materialize conditions through the & gesture.
  assert.deepEqual(ghosted.parts, model.parts);
});

test('an unknown compound name ghosts last, like stored inert data renders', () => {
  const model = elementIslands({}, KEY, guide, []);
  assert.deepEqual(posedRoster(model, 'narrow+dark', guide).conditions, ['default', 'narrow+dark']);
});

test('a resting pose or an already-real row mints nothing', () => {
  const model = elementIslands({ [KEY]: { hover: { color: 'red' } } }, KEY, guide, []);
  assert.equal(posedRoster(model, 'default', guide), model);
  assert.equal(posedRoster(model, null, guide), model);
  assert.equal(posedRoster(model, 'hover', guide), model);
});

// --- The selector gesture's completion set ------------------------------------

// The offer reads the element through the matcher fixture: section A
// holds two text blocks (a paragraph root, a heading root), a formatted
// text block (a div holding paragraphs and links), a nested section and
// a plugin block.
const OWNER = 'container:sec-a';
const TAGS = { p: 'Paragraph', h2: 'Heading 2', a: 'a', em: 'em', strong: 'strong', div: 'div' };
const TYPES = { 'pb-text': 'Text', 'pb-formatted-text': 'Formatted text', 'pb-image': 'Image', 'pb-container': 'Container', 'pb-block': 'Block' };
const selectorsOf = (offer) => offer.options.map((option) => option.selector);
const gesture = (typed, extra = {}) =>
  selectorGestureOptions(typed, { key: OWNER, tree: TREE, tags: TAGS, types: TYPES, ...extra });

test('after `>` the offer is the universal child, then the children\'s types, then their root tags', () => {
  // Types in document order (the plugin block is the block kind, the
  // nested section the container kind); tags in vocabulary order, only
  // the declared ones (div is declared here, section is not).
  const offer = gesture('>');
  assert.deepEqual(selectorsOf(offer), ['> *', '> pb-text', '> pb-formatted-text', '> pb-container', '> pb-block', '> p', '> h2', '> div']);
  assert.deepEqual(offer.options[0], { selector: '> *', kind: 'universal', name: '*', label: null });
  assert.deepEqual(offer.options[1], { selector: '> pb-text', kind: 'type', name: 'pb-text', label: 'Text' });
  assert.deepEqual(offer.options[5], { selector: '> p', kind: 'tag', name: 'p', label: 'Paragraph' });
  assert.equal(offer.create, null);
  // The same set whatever partial token follows the combinator (the
  // editor filters), spaced or not.
  assert.deepEqual(selectorsOf(gesture('> pb-')), selectorsOf(offer));
  assert.deepEqual(selectorsOf(gesture('>p')), selectorsOf(offer));
});

test('a bare word at the owner offers the subtree\'s types and the tags it renders or contains', () => {
  // Descendants: every block and nested container under the owner (the
  // owner itself never); tags: their roots AND their inner prose, so the
  // formatted text block's paragraphs offer `p` on a container whose
  // direct children may all be headings.
  const offer = gesture('p');
  assert.deepEqual(selectorsOf(offer), ['pb-text', 'pb-formatted-text', 'pb-container', 'pb-image', 'pb-block', 'p', 'h2', 'a', 'em', 'strong', 'div']);
  assert.deepEqual(selectorsOf(gesture('pb-')), selectorsOf(offer));
});

test('a bare word offers the tag a child\'s part renders, though no prose carries it', () => {
  // The Links section: a heading-rooted text block whose link part
  // renders an anchor (its prose carries nothing), a nested section
  // holding a paragraph block whose prose emphasizes and whose link part
  // renders one too. The descendant offer reads the parts' tags through
  // the matcher's facts, so `a` is offered where the completion once
  // read rich text alone (the container's own prose is known empty, so
  // nothing degrades to the whole vocabulary); after `>` only the roots
  // offer.
  const links = (typed) =>
    selectorsOf(selectorGestureOptions(typed, { key: 'container:sec-e', tree: TREE, tags: TAGS, types: TYPES, present: [] }));
  assert.deepEqual(links('a'), ['pb-text', 'pb-container', 'p', 'a', 'strong', 'div']);
  assert.deepEqual(links('>'), ['> *', '> pb-text', '> pb-container', '> div']);
});

test('the owner\'s own prose tags join the descendant offer; an empty fact hides them', () => {
  const block = (present) =>
    selectorsOf(selectorGestureOptions('a', { key: 'block:t1', tree: TREE, tags: TAGS, types: TYPES, present }));
  // t1 has no child nodes: only its own prose (a, strong) offers.
  assert.deepEqual(block(['a', 'strong']), ['a', 'strong']);
  assert.deepEqual(block([]), []);
  // Unknown prose degrades to the whole vocabulary.
  assert.deepEqual(block(null), ['p', 'h2', 'a', 'em', 'strong', 'div']);
});

test('chains complete from the matched set: `> pb-container >` lists the nested section\'s children', () => {
  const offer = gesture('> pb-container > ');
  assert.deepEqual(selectorsOf(offer), ['> pb-container > *', '> pb-container > pb-text', '> pb-container > pb-image', '> pb-container > pb-container', '> pb-container > p', '> pb-container > div']);
  // A descendant position after a type: the type\'s subtree plus its
  // declared parts.
  const partsFor = (type) => (type === 'pb-text' ? [{ id: '.text-block-link', label: 'Link' }] : []);
  const chained = gesture('> pb-text ', { partsFor });
  assert.deepEqual(selectorsOf(chained), ['> pb-text .text-block-link']);
  assert.deepEqual(chained.options[0], { selector: '> pb-text .text-block-link', kind: 'class', name: '.text-block-link', label: 'Link' });
});

test('a `.` token offers the owner\'s declared parts, presence-gated, plus the open-site create', () => {
  const parts = [{ id: '.text-block-link', label: 'Link' }, { id: '.quote', label: 'Quote' }];
  const open = gesture('.cred', { parts, closed: false, presence: { '.text-block-link': false, '.quote': true } });
  // The absent link is not offered; the well-formed undeclared class
  // earns the explicit create entry, as a full selector.
  assert.deepEqual(open.options, [{ selector: '.quote', kind: 'class', name: '.quote', label: 'Quote' }]);
  assert.equal(open.create, '.cred');
  // Closed, malformed, or already declared: no create.
  assert.equal(gesture('.cred', { parts, closed: true }).create, null);
  assert.equal(gesture('.', { parts }).create, null);
  assert.equal(gesture('.quote', { parts }).create, null);
  // Declared-but-absent never falls through to the create entry.
  const gated = gesture('.text-block-link', { parts, presence: { '.text-block-link': false } });
  assert.deepEqual(gated.options, [{ selector: '.quote', kind: 'class', name: '.quote', label: 'Quote' }]);
  assert.equal(gated.create, null);
  // Unknown presence offers everything.
  assert.deepEqual(gesture('.', { parts, presence: null }).options.map((option) => option.name), ['.text-block-link', '.quote']);
});

test('a `.` after a type offers that type\'s parts (no presence gate); after a tag, nothing', () => {
  const partsFor = (type) => (type === 'pb-text' ? [{ id: '.text-block-link', label: 'Link' }] : []);
  const typed = gesture('> pb-text .', { partsFor, parts: [{ id: '.band', label: 'Band' }], presence: { '.band': false } });
  assert.deepEqual(selectorsOf(typed), ['> pb-text .text-block-link']);
  assert.equal(gesture('> pb-text .x', { partsFor }).create, '> pb-text .x');
  // The grammar admits a part only after the owner or a type.
  assert.deepEqual(gesture('p .', { partsFor }), { options: [], create: null });
});

test('no tree in reach offers every declared type and base tag (unknown never hides)', () => {
  const offer = selectorGestureOptions('> ', { key: OWNER, tree: null, tags: TAGS, types: TYPES });
  assert.deepEqual(selectorsOf(offer), ['> *', '> pb-text', '> pb-formatted-text', '> pb-image', '> pb-container', '> pb-block', '> p', '> h2', '> a', '> em', '> strong', '> div']);
});

test('text outside the grammar offers nothing: a declaration, a dangling combinator, an empty line', () => {
  assert.equal(gesture('color: red'), null);
  assert.equal(gesture('> > p'), null);
  assert.equal(gesture(''), null);
  assert.equal(gesture('   '), null);
  assert.equal(gesture('&hover'), null);
  assert.equal(gesture('p {'), null);
});

test('a typed brace mints the whole selector before it, and only that', () => {
  assert.equal(bracedSelector('> pb-text ', ''), '> pb-text');
  assert.equal(bracedSelector('  p', ''), 'p');
  assert.equal(bracedSelector('.quote', ''), '.quote');
  assert.equal(bracedSelector('>pb-container>p', ''), '> pb-container > p');
  // A partial token, a dangling combinator, a declaration, trailing text
  // after the cursor, an empty line: the brace stays text.
  assert.equal(bracedSelector('> pb-', ''), null);
  assert.equal(bracedSelector('>', ''), null);
  assert.equal(bracedSelector('color: red', ''), null);
  assert.equal(bracedSelector('p', ' x'), null);
  assert.equal(bracedSelector('', ''), null);
  assert.equal(bracedSelector('&hover', ''), null);
  // A relation mints through the grammar, canonical; a colon opens a
  // qualifier only, so a state or a declaration stays text.
  assert.equal(bracedSelector('> pb-text:has(a) ', ''), '> pb-text:has(a)');
  assert.equal(bracedSelector('>p+p', ''), '> p + p');
  assert.equal(bracedSelector('> p ~ p', ''), '> p ~ p');
  assert.equal(bracedSelector('> *:not(pb-image)', ''), '> *:not(pb-image)');
  assert.equal(bracedSelector('> pb-text:not( .featured ):has( a )', ''), '> pb-text:has(a):not(.featured)');
  assert.equal(bracedSelector('> pb-text:hover', ''), null);
  assert.equal(bracedSelector('+ p', ''), null);
  assert.equal(bracedSelector('> pb-text:has(', ''), null);
});

test('a typed brace mints a generated box: the owner\'s through the anchor, a subject\'s glued', () => {
  assert.equal(bracedSelector('&::before', ''), '&::before');
  assert.equal(bracedSelector('  &::after ', ''), '&::after');
  assert.equal(bracedSelector('> pb-text::after ', ''), '> pb-text::after');
  assert.equal(bracedSelector('.quote::before', ''), '.quote::before');
  assert.equal(bracedSelector('p::after', ''), 'p::after');
  assert.equal(bracedSelector('>pb-container>p::before', ''), '> pb-container > p::before');
  assert.equal(bracedSelector('> p + p::after', ''), '> p + p::after');
  assert.equal(bracedSelector('> pb-text:has( a )::before', ''), '> pb-text:has(a)::before');
  // The grammar's refusals stay text for the lint: the bare anchor, a
  // partial tail, another pseudo-element, a box continued or doubled, a
  // space before the `::`, the anchor inside a chain.
  assert.equal(bracedSelector('&', ''), null);
  assert.equal(bracedSelector('&::', ''), null);
  assert.equal(bracedSelector('&::bef', ''), null);
  assert.equal(bracedSelector('&::marker', ''), null);
  assert.equal(bracedSelector('> pb-text::first-line', ''), null);
  assert.equal(bracedSelector('&::before .x', ''), null);
  assert.equal(bracedSelector('> pb-text::before::after', ''), null);
  assert.equal(bracedSelector('> pb-text ::before', ''), null);
  assert.equal(bracedSelector('& ::before', ''), null);
  assert.equal(bracedSelector('& > p', ''), null);
  assert.equal(bracedSelector('> pb-text &::before', ''), null);
  assert.equal(bracedSelector('&::before', ' x'), null);
});

// --- Composition under a rule island (CSS nesting's relative rule) ----------

test('composeUnder on the owner\'s island leaves the text as typed: no rule, the anchor the owner\'s own box', () => {
  assert.equal(composeUnder(null, 'p'), 'p');
  assert.equal(composeUnder('', '> pb-text'), '> pb-text');
  assert.equal(composeUnder(null, '&::before'), '&::before');
  assert.equal(composeUnder(null, '  .quote::after '), '.quote::after');
  // The grammar still judges: the bare anchor and a chain on it refuse.
  assert.equal(composeUnder(null, '&'), null);
  assert.equal(composeUnder('', '& > p'), null);
});

test('composeUnder under a tag rule: `&` is the rule, a bare line is relative to it, the grammar judges the result', () => {
  assert.equal(composeUnder('p', '&::before'), 'p::before');
  assert.equal(composeUnder('p', '&::after'), 'p::after');
  assert.equal(composeUnder('p', '> a'), 'p > a');
  assert.equal(composeUnder('p', 'a'), 'p a');
  // A part class composes relative to the rule too, and the grammar
  // judges the composed text as it judges an authored key: a part is
  // admitted after the owner or a type, never after a tag.
  assert.equal(composeUnder('p', '.x'), null);
  assert.equal(composeUnder('> pb-text', '.x'), '> pb-text .x');
  assert.equal(composeUnder('p', '& > a'), 'p > a');
  assert.equal(composeUnder('p', '>a'), 'p > a');
  assert.equal(composeUnder('p', '  > a '), 'p > a');
  // `&` alone composes to the rule itself, the row the island already
  // is: refused, as the owner's island refuses `&`.
  assert.equal(composeUnder('p', '&'), null);
  assert.equal(composeUnder('p', ' & '), null);
  // The grammar's refusals on the composed text: a partial tail, a
  // dangling combinator, another pseudo, a state.
  assert.equal(composeUnder('p', '&::'), null);
  assert.equal(composeUnder('p', '&::bef'), null);
  assert.equal(composeUnder('p', '>'), null);
  assert.equal(composeUnder('p', '&::marker'), null);
  assert.equal(composeUnder('p', '&:hover'), null);
  // The condition sigil's shape (the anchor glued to a word) never
  // composes: substituting the rule would spell a tag (`phover`).
  assert.equal(composeUnder('p', '&hover'), null);
  assert.equal(composeUnder('p', '&first-child'), null);
  assert.equal(nestUnder('p', '&hover'), null);
  assert.equal(nestUnder(null, '&hover'), null);
});

test('composeUnder under a chain rule continues the chain, and a box rule takes no continuation', () => {
  assert.equal(composeUnder('> pb-text .link', '&::after'), '> pb-text .link::after');
  assert.equal(composeUnder('> pb-text .link', '> a'), '> pb-text .link > a');
  assert.equal(composeUnder('> pb-text', '&::before'), '> pb-text::before');
  assert.equal(composeUnder('> pb-text', '.link'), '> pb-text .link');
  assert.equal(composeUnder('> pb-text:has(a)', '&::before'), '> pb-text:has(a)::before');
  // A rule that is itself a box (`p::before`) is a terminal: a box on a
  // box and a descendant of a box are outside the grammar.
  assert.equal(composeUnder('p::before', '&::after'), null);
  assert.equal(composeUnder('p::before', '> a'), null);
  assert.equal(composeUnder('p::before', '.x'), null);
});

test('nestUnder is the raw substitution the completion feeds: a partial line composes without judgement', () => {
  assert.equal(nestUnder('p', '&::'), 'p::');
  assert.equal(nestUnder('p', '&::bef'), 'p::bef');
  assert.equal(nestUnder('p', '> '), 'p > ');
  assert.equal(nestUnder('p', '> pb-'), 'p > pb-');
  assert.equal(nestUnder('p', '.'), 'p .');
  assert.equal(nestUnder('> pb-text', 'a'), '> pb-text a');
  assert.equal(nestUnder(null, '&::'), '&::');
  assert.equal(nestUnder('', '> pb-'), '> pb-');
  assert.equal(nestUnder('p', 42), null);
});

test('the completion fed the nested line offers the rule\'s position: its boxes behind `&::`, its children behind `>`', () => {
  // The owner's `p` island on section A: `&::` is the paragraph's boxes,
  // minting the composed selectors.
  const boxes = gesture(nestUnder('p', '&::'));
  assert.deepEqual(boxes.options, [
    { selector: 'p::before', kind: 'pseudo', name: 'before', label: null },
    { selector: 'p::after', kind: 'pseudo', name: 'after', label: null },
  ]);
  // `>` inside the `> pb-container` island is the nested section's
  // children, the same set the owner's chain `> pb-container >` reads.
  assert.deepEqual(
    selectorsOf(gesture(nestUnder('> pb-container', '> '))),
    selectorsOf(gesture('> pb-container > ')),
  );
  // A bare word inside a tag island is a descendant of the tag's
  // matches: nothing under section A's paragraphs, so no offer.
  assert.deepEqual(gesture(nestUnder('p', 'a')), { options: [], create: null });
  // A box island takes no continuation: the nested line is outside the
  // grammar.
  assert.equal(gesture(nestUnder('p::before', '&::')), null);
  assert.equal(gesture(nestUnder('p::before', '> ')), null);
});

test('nestedSpelling reads a composed option back in the typed line\'s nesting: anchored lines keep the anchor, bare lines drop the rule', () => {
  assert.equal(nestedSpelling('p', '&::', 'p::before'), '&::before');
  assert.equal(nestedSpelling('p', '&::bef', 'p::after'), '&::after');
  assert.equal(nestedSpelling('p', '& > ', 'p > a'), '& > a');
  assert.equal(nestedSpelling('p', '> ', 'p > *'), '> *');
  assert.equal(nestedSpelling('p', '>pb-', 'p > pb-text'), '> pb-text');
  assert.equal(nestedSpelling('p', '.', 'p .x'), '.x');
  assert.equal(nestedSpelling('> pb-container', '> ', '> pb-container > pb-text'), '> pb-text');
  assert.equal(nestedSpelling('> pb-text .link', '&::', '> pb-text .link::after'), '&::after');
  // On the owner's island the selector is its own spelling.
  assert.equal(nestedSpelling(null, '&::', '&::before'), '&::before');
  assert.equal(nestedSpelling('', '> ', '> pb-text'), '> pb-text');
});

test('a typed brace inside a rule island mints the composed selector', () => {
  assert.equal(bracedSelector('&::before', '', 'p'), 'p::before');
  assert.equal(bracedSelector('  &::after ', '', 'p'), 'p::after');
  assert.equal(bracedSelector('> a', '', 'p'), 'p > a');
  assert.equal(bracedSelector('.x', '', '> pb-text'), '> pb-text .x');
  assert.equal(bracedSelector('&::after', '', '> pb-text .link'), '> pb-text .link::after');
  assert.equal(bracedSelector('&::before', '', '> pb-text'), '> pb-text::before');
  // The same refusals as the owner's: a partial tail, text after the
  // cursor, a declaration, a box on a box.
  assert.equal(bracedSelector('&::', '', 'p'), null);
  assert.equal(bracedSelector('&::before', ' x', 'p'), null);
  assert.equal(bracedSelector('color: red', '', 'p'), null);
  assert.equal(bracedSelector('&::after', '', 'p::before'), null);
  // No rule: the owner's behaviour, unchanged.
  assert.equal(bracedSelector('&::before', '', null), '&::before');
  assert.equal(bracedSelector('&::before', '', ''), '&::before');
});

test('a block typed or pasted inside an island is a door chunk, never an error: the door materializes or names it', () => {
  const errorsOf = (result) => result.diagnostics.filter((diagnostic) => diagnostic.severity === 'error');
  ['&::before { color: red; }', '&::after {}', '&:hover { color: red; }', '&::marker { color: red; }', '.quote::after { color: red; }'].forEach((text) => {
    const result = analyze(text);
    assert.deepEqual(errorsOf(result), [], text);
    assert.deepEqual(result.structural.map((chunk) => chunk.text), [text], text);
    assert.deepEqual(result.declarations, [], text);
  });
});

test('a relation line offers nothing: a relation is typed whole', () => {
  assert.equal(gesture('> p + '), null);
  assert.equal(gesture('> p + p'), null);
  assert.equal(gesture('> p ~ '), null);
  assert.equal(gesture('> pb-text:has('), null);
  assert.equal(gesture('> pb-text:has(a) '), null);
  assert.equal(gesture('> *:not(pb-image) .'), null);
  // A box on a relation is typed whole too (the brace mints it).
  assert.equal(gesture('> p + p::'), null);
  assert.equal(gesture('> pb-text:has(a)::b'), null);
});

test('the owner\'s anchor offers its two generated boxes, before then after, whatever tail is typed', () => {
  const boxes = [
    { selector: '&::before', kind: 'pseudo', name: 'before', label: null },
    { selector: '&::after', kind: 'pseudo', name: 'after', label: null },
  ];
  ['&', '&::', '&::a', '&::before', '  &::bef'].forEach((typed) => {
    assert.deepEqual(gesture(typed), { options: boxes, create: null }, typed);
  });
  // The offer is universal: no tree, no parts, no tags needed.
  assert.deepEqual(selectorGestureOptions('&::', { key: OWNER, tree: null }), { options: boxes, create: null });
  // The anchor is the whole head of its box and nothing else.
  assert.equal(gesture('& ::before'), null);
  assert.equal(gesture('&::before .x'), null);
  assert.equal(gesture('& > p'), null);
  assert.equal(gesture('> pb-text &::before'), null);
  assert.equal(gesture('&::before::'), null);
  // A lone colon is a state, never the start of a box.
  assert.equal(gesture('&:'), null);
  assert.equal(gesture('&:hover'), null);
});

test('a `::` tail offers the subject\'s two boxes as continuations of the whole selector before it', () => {
  const expect = (typed, host) =>
    assert.deepEqual(gesture(typed), {
      options: [
        { selector: `${host}::before`, kind: 'pseudo', name: 'before', label: null },
        { selector: `${host}::after`, kind: 'pseudo', name: 'after', label: null },
      ],
      create: null,
    }, typed);
  expect('> pb-text::', '> pb-text');
  expect('> pb-text::b', '> pb-text');
  expect('> pb-text::after', '> pb-text');
  expect('>pb-text::', '> pb-text');
  expect('.quote::a', '.quote');
  expect('p::', 'p');
  expect('> *::', '> *');
  expect('> pb-container > p::', '> pb-container > p');
  expect('> pb-text .text-block-link::', '> pb-text .text-block-link');
  // Glued to a whole selector only: a space before it is every
  // descendant's box in CSS, a bare `::` has no host, a partial or
  // dangling head is no subject, and a box takes no box.
  assert.equal(gesture('> pb-text ::'), null);
  assert.equal(gesture('::'), null);
  assert.equal(gesture('::before'), null);
  assert.equal(gesture('> ::'), null);
  assert.equal(gesture('> pb-text::before::'), null);
  assert.equal(gesture('> pb-text::before '), null);
  assert.equal(gesture('> pb-text::before > '), null);
  // The tail is the box gesture's token: a colon anywhere else stays a
  // declaration or a state, never a selector being typed.
  assert.equal(gesture('> pb-text:hover'), null);
  assert.equal(gesture('> pb-text:'), null);
  assert.equal(gesture('content: ""'), null);
});

// --- Removal gestures: unset + collapse, variant-restore honesty ---------------

test('condition removal unsets every own value in ONE op', () => {
  const map = { [KEY]: { hover: { color: 'red', gap: '1rem', padding: null } } };
  assert.deepEqual(conditionRemovalOps(map, KEY, 'hover'), [
    { op: 'update_style', key: KEY, condition: 'hover', properties: { color: null, gap: null } },
  ]);
});

test('removing a draft or variant-only row emits nothing (pure collapse)', () => {
  assert.deepEqual(conditionRemovalOps({}, KEY, 'hover'), []);
  const map = { [KEY]: { variants: [{ variant: 'aqua', fragment: '' }] } };
  assert.deepEqual(conditionRemovalOps(map, KEY, 'hover'), []);
});

test('part removal unsets all own condition layers', () => {
  const map = { [SUB]: { default: { color: 'purple' }, hover: { color: 'teal' } } };
  assert.deepEqual(partRemovalOps(map, SUB), [
    { op: 'update_style', key: SUB, properties: { color: null } },
    { op: 'update_style', key: SUB, condition: 'hover', properties: { color: null } },
  ]);
  assert.deepEqual(partRemovalOps({}, SUB), []);
});

test('restoredLines reports what survives removal: every variant line, lost included', () => {
  const map = { [KEY]: { variants: [{ variant: 'aqua', fragment: '' }], default: { padding: '4rem', gap: '1rem' } } };
  assert.deepEqual(restoredLines(map, KEY, 'default', VARIANTS), [
    { property: 'padding', value: '2rem', layer: variantLayerId('aqua'), variant: 'aqua', lost: true, winner: { layer: ELEMENT_LAYER, variant: null } },
    { property: 'color', value: '#1a1a2e', layer: variantLayerId('aqua'), variant: 'aqua', lost: false, winner: null },
  ]);
  assert.deepEqual(restoredLines({ [KEY]: { default: { gap: '1rem' } } }, KEY, 'default', VARIANTS), []);
});

process.stdout.write('# all element-model tests passed\n');
