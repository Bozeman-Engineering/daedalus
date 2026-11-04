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
  previewReach, renameDrafts, restoredLines, ruleIslandLines, ruleMovePlan, selectorGestureOptions, settleOps,
  shouldRestoreTinted,
} from '../../js/src/code/element-model.js';
import { REFUSED_CONTINUATION, REFUSED_TARGET_EXISTS } from '../../js/src/rule-move.js';
import { DOCUMENT } from './selector-matcher-fixture.mjs';
import { analyzeIsland, islandOps, printIsland, stackLines } from '../../js/src/code/island.js';
import { ELEMENT_LAYER, variantLayerId } from '../../js/src/code/layers.js';
import { buildStack } from '../../js/src/style-stack.js';
import { widePseudoElements } from '../../js/src/wide-selectors.js';

// cspell:ignore phover

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
  const rule = `${owner}::> d-text`;
  const map = { [rule]: { variants: [{ variant: 'aqua', fragment: '' }] } };
  const model = elementIslands(map, owner, guide, VARIANTS);
  assert.deepEqual(model.parts, [
    { selector: '> d-text .quote', key: `${rule} .quote`, conditions: ['default'], ancestors: [] },
    { selector: '> d-text', key: rule, conditions: ['default', 'hover'], ancestors: [] },
  ]);
  const pruned = occupiedRoster(model, map, owner, VARIANTS);
  assert.deepEqual(pruned.conditions, []);
  assert.deepEqual(pruned.parts.map((part) => [part.selector, part.conditions]), [
    ['> d-text .quote', ['default']],
    ['> d-text', ['default', 'hover']],
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
// renders an anchor. Section B wears the Section look, whose `::> *` fragment
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
const HOME_UUID = '99999999-0000-4000-8000-000000000009';
const section = (uuid, label, children) => ({
  kind: 'container', uuid, layout_id: 'daedalus_stylish_container', label, _element: { markup: { tag: 'section' } }, children,
});
// The document (source.getDocument()): the page, the host entity, above
// its two sections.
const PAGE = [{
  kind: 'entity',
  uuid: HOME_UUID,
  _element: { name: 'Home', type: 'Page', markup: {} },
  children: [
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
  ],
}];
const A_RULE = `${SEC_A}::> d-container > *`;
const B_RULE = `${SEC_B}::> *`;
const BLOCK_TYPES = { [T3_UUID]: 'text', [T8_UUID]: 'text' };
const ANCESTOR_GUIDE = {
  ...guide,
  declaredDefaults: { 'block_type:text': { '': { default: { color: 'black', padding: '0' } } } },
};
const SECTION = {
  id: 'section',
  label: 'Section',
  value: { '': { default: { display: 'flex' } }, '::> *': { default: { 'flex-grow': '1', color: 'green' } } },
};
const ANCESTOR_MAP = {
  [A_RULE]: { default: { gap: '1rem' }, hover: { color: 'red' } },
  [SEC_B]: { variants: [{ variant: 'section', fragment: '' }] },
  [B_RULE]: { default: { color: 'teal', padding: '1rem' } },
  [T3]: { default: { color: 'purple' } },
};
const roster = (map, key, address = null, facet = null) =>
  elementIslands(map, key, ANCESTOR_GUIDE, [SECTION], null, BLOCK_TYPES, {}, PAGE, address, facet);
const reachingT3 = (map) =>
  stackLines(buildStack({ guide: ANCESTOR_GUIDE, map, key: T3, variants: [SECTION], blockTypes: BLOCK_TYPES, document: PAGE }), 'default');

test('the roster lists the ancestor rules reaching the root nearest ancestor first, as islands of the rules\' own keys; no document, no rules', () => {
  const model = roster(ANCESTOR_MAP, T3);
  assert.deepEqual(model.ancestors, [
    { owner: SEC_B, selector: '> *', key: B_RULE, conditions: ['default'], existing: true },
    { owner: SEC_A, selector: '> d-container > *', key: A_RULE, conditions: ['default', 'hover'], existing: true },
  ]);
  assert.deepEqual(model.parts, []);
  // The document scopes' sparse rows pass no document: DevTools' rule, inherited
  // rules for the element you are inspecting alone.
  assert.deepEqual(elementIslands(ANCESTOR_MAP, T3, ANCESTOR_GUIDE, [SECTION], null, BLOCK_TYPES, {}).ancestors, []);
});

test('a seated rule the map lacks lists as a virtual island at its delivery place; a rule that only wears lists as existing', () => {
  const map = { ...ANCESTOR_MAP, [B_RULE]: { variants: [{ variant: 'section', fragment: '' }] } };
  const model = roster(map, T3, { key: SEC_B, selector: '> d-text' });
  assert.deepEqual(model.ancestors.map((rule) => [rule.selector, rule.existing, rule.conditions]), [
    ['> *', true, ['default']],
    ['> d-text', false, ['default']],
    ['> d-container > *', true, ['default', 'hover']],
  ]);
  // The address names the root's facet alone: no part row carries it.
  assert.deepEqual(
    ancestorIslands({ guide: ANCESTOR_GUIDE, map, key: `${T3}::em`, variants: [SECTION], blockTypes: BLOCK_TYPES, document: PAGE }),
    [],
  );
});

test('a rule reaching a part rows under the part, never the root', () => {
  const link = `${T8}::.text-block-link`;
  const rule = `${SEC_E}::d-text .text-block-link`;
  const map = { [link]: { default: { color: 'blue' } }, [rule]: { hover: { color: 'red' } } };
  const model = roster(map, T8);
  assert.deepEqual(model.ancestors, []);
  assert.deepEqual(
    model.parts.map((part) => [part.selector, part.ancestors.map((entry) => [entry.owner, entry.selector, entry.key, entry.conditions, entry.existing])]),
    [['.text-block-link', [[SEC_E, 'd-text .text-block-link', rule, ['default', 'hover'], true]]]],
  );
  // A write address under the PART facet (the spine's two facts: the
  // caller says which facet the address stands under, nothing derives
  // it from the rule) places its mintable rule under the part's row,
  // never the root's.
  const seated = roster({ [link]: { default: { color: 'blue' } } }, T8, { key: SEC_E, selector: '> d-text .text-block-link' }, '.text-block-link');
  assert.deepEqual(seated.ancestors, []);
  assert.deepEqual(
    seated.parts.map((part) => [part.selector, part.ancestors.map((entry) => [entry.selector, entry.existing])]),
    [['.text-block-link', [['> d-text .text-block-link', false]]]],
  );
  // The same address handed as the root's (no facet) rows under the
  // root: the facet is the caller's fact, not the matcher's inverse.
  const atRoot = roster({ [link]: { default: { color: 'blue' } } }, T8, { key: SEC_E, selector: '> d-text .text-block-link' });
  assert.deepEqual(atRoot.ancestors.map((entry) => [entry.selector, entry.existing]), [['> d-text .text-block-link', false]]);
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
  assert.deepEqual(atRoot.ancestors.map((entry) => [entry.owner, entry.selector]), [[SEC_B, '> *'], [SEC_A, '> d-container > *']]);
  assert.deepEqual(atRoot.parts, []);
});

test('an ancestor address under the same facet still places its rule row, existing false while the map lacks it', () => {
  const link = `${T8}::.text-block-link`;
  const held = { [link]: { default: { color: 'blue' } } };
  const seated = roster(held, T8, { key: SEC_E, selector: '> d-text .text-block-link' }, '.text-block-link');
  assert.deepEqual(seated.ancestors, []);
  assert.deepEqual(
    seated.parts.map((part) => [part.selector, part.ancestors.map((entry) => [entry.owner, entry.selector, entry.key, entry.existing])]),
    [['.text-block-link', [[SEC_E, '> d-text .text-block-link', `${SEC_E}::> d-text .text-block-link`, false]]]],
  );
  // Written, the same row reads existing: the placement rule is the
  // address's owner, not the map's holding.
  const written = roster({ ...held, [`${SEC_E}::> d-text .text-block-link`]: { hover: { color: 'red' } } }, T8, { key: SEC_E, selector: '> d-text .text-block-link' }, '.text-block-link');
  assert.deepEqual(
    written.parts.map((part) => [part.selector, part.ancestors.map((entry) => [entry.selector, entry.existing, entry.conditions])]),
    [['.text-block-link', [['> d-text .text-block-link', true, ['default', 'hover']]]]],
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
  assert.ok(lines.some((line) => line.layer === `ancestor:${B_RULE}|section`));
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
  const ruleStack = buildStack({ guide: ANCESTOR_GUIDE, map: ANCESTOR_MAP, key: B_RULE, variants: [SECTION], floor: false });
  const lines = ruleIslandLines(stackLines(ruleStack, 'default'), rule, reachingT3(ANCESTOR_MAP));
  assert.deepEqual(
    lines.map((line) => [line.layer, line.property, line.value, line.lost, line.winner?.layer ?? null, line.beaten?.layer ?? null]),
    [
      [variantLayerId('section'), 'flex-grow', '1', false, null, null],
      [variantLayerId('section'), 'color', 'green', true, ELEMENT_LAYER, null],
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
const TYPES = { 'd-text': 'Text', 'd-formatted-text': 'Formatted text', 'd-image': 'Image', 'd-container': 'Container', 'd-block': 'Block' };
const selectorsOf = (offer) => offer.options.map((option) => option.selector);
const gesture = (typed, extra = {}) =>
  selectorGestureOptions(typed, { key: OWNER, document: DOCUMENT, tags: TAGS, types: TYPES, ...extra });

test('after `>` the offer is the universal child, then the children\'s types, then their root tags', () => {
  // Types in document order (the plugin block is the block kind, the
  // nested section the container kind); tags in vocabulary order, only
  // the declared ones (div is declared here, section is not).
  const offer = gesture('>');
  assert.deepEqual(selectorsOf(offer), ['> *', '> d-text', '> d-formatted-text', '> d-container', '> d-block', '> p', '> h2', '> div']);
  assert.deepEqual(offer.options[0], { selector: '> *', kind: 'universal', name: '*', label: null });
  assert.deepEqual(offer.options[1], { selector: '> d-text', kind: 'type', name: 'd-text', label: 'Text' });
  assert.deepEqual(offer.options[5], { selector: '> p', kind: 'tag', name: 'p', label: 'Paragraph' });
  assert.equal(offer.create, null);
  // The same set whatever partial token follows the combinator (the
  // editor filters), spaced or not.
  assert.deepEqual(selectorsOf(gesture('> d-')), selectorsOf(offer));
  assert.deepEqual(selectorsOf(gesture('>p')), selectorsOf(offer));
});

test('a bare word at the owner offers the subtree\'s types and the tags it renders or contains', () => {
  // Descendants: every block and nested container under the owner (the
  // owner itself never); tags: their roots AND their inner prose, so the
  // formatted text block's paragraphs offer `p` on a container whose
  // direct children may all be headings.
  const offer = gesture('p');
  assert.deepEqual(selectorsOf(offer), ['d-text', 'd-formatted-text', 'd-container', 'd-image', 'd-block', 'p', 'h2', 'a', 'em', 'strong', 'div']);
  assert.deepEqual(selectorsOf(gesture('d-')), selectorsOf(offer));
});

test('on the page itself (the entity root) the offer reads the whole page: after `>` the sections, a bare word everything on it', () => {
  // The document's root is an owner like any other: its children are the
  // sections (containers all; of their root tags only the declared one,
  // div, offers), its descendants every block and section on the page,
  // their tags the page's. Nothing degrades to the declared universe,
  // and the root itself is never among its own descendants.
  const page = (typed) =>
    selectorsOf(selectorGestureOptions(typed, { key: 'entity:page', document: DOCUMENT, tags: TAGS, types: TYPES, present: [] }));
  assert.deepEqual(page('>'), ['> *', '> d-container', '> div']);
  // Types in document order: the first section comes before anything in
  // it, so the container kind leads from the root (from a section, its
  // blocks lead and the nested section follows them).
  assert.deepEqual(page('d-'), ['d-container', 'd-text', 'd-formatted-text', 'd-image', 'd-block', 'p', 'h2', 'a', 'em', 'strong', 'div']);
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
    selectorsOf(selectorGestureOptions(typed, { key: 'container:sec-e', document: DOCUMENT, tags: TAGS, types: TYPES, present: [] }));
  assert.deepEqual(links('a'), ['d-text', 'd-container', 'p', 'a', 'strong', 'div']);
  assert.deepEqual(links('>'), ['> *', '> d-text', '> d-container', '> div']);
});

test('the owner\'s own prose tags join the descendant offer; an empty fact hides them', () => {
  const block = (present) =>
    selectorsOf(selectorGestureOptions('a', { key: 'block:t1', document: DOCUMENT, tags: TAGS, types: TYPES, present }));
  // t1 has no child nodes: only its own prose (a, strong) offers.
  assert.deepEqual(block(['a', 'strong']), ['a', 'strong']);
  assert.deepEqual(block([]), []);
  // Unknown prose degrades to the whole vocabulary.
  assert.deepEqual(block(null), ['p', 'h2', 'a', 'em', 'strong', 'div']);
});

test('chains complete from the matched set: `> d-container >` lists the nested section\'s children', () => {
  const offer = gesture('> d-container > ');
  assert.deepEqual(selectorsOf(offer), ['> d-container > *', '> d-container > d-text', '> d-container > d-image', '> d-container > d-container', '> d-container > p', '> d-container > div']);
  // A descendant position after a type: the type\'s subtree plus its
  // declared parts.
  const partsFor = (type) => (type === 'd-text' ? [{ id: '.text-block-link', label: 'Link' }] : []);
  const chained = gesture('> d-text ', { partsFor });
  assert.deepEqual(selectorsOf(chained), ['> d-text .text-block-link']);
  assert.deepEqual(chained.options[0], { selector: '> d-text .text-block-link', kind: 'class', name: '.text-block-link', label: 'Link' });
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
  const partsFor = (type) => (type === 'd-text' ? [{ id: '.text-block-link', label: 'Link' }] : []);
  const typed = gesture('> d-text .', { partsFor, parts: [{ id: '.section', label: 'Section' }], presence: { '.section': false } });
  assert.deepEqual(selectorsOf(typed), ['> d-text .text-block-link']);
  assert.equal(gesture('> d-text .x', { partsFor }).create, '> d-text .x');
  // A tag declares no parts, so `.` after one offers nothing and mints
  // nothing: the roster's doing (parts are declared at the owner or a
  // type), not the grammar's, which admits a class after a tag as a
  // compound of its own (compose('p', '.x') below).
  assert.deepEqual(gesture('p .', { partsFor }), { options: [], create: null });
});

test('no document in reach offers every declared type and base tag (unknown never hides)', () => {
  const offer = selectorGestureOptions('> ', { key: OWNER, document: null, tags: TAGS, types: TYPES });
  assert.deepEqual(selectorsOf(offer), ['> *', '> d-text', '> d-formatted-text', '> d-image', '> d-container', '> d-block', '> p', '> h2', '> a', '> em', '> strong', '> div']);
});

test('text outside the grammar offers nothing: a declaration, a dangling combinator, an empty line', () => {
  assert.equal(gesture('color: red'), null);
  assert.equal(gesture('> > p'), null);
  assert.equal(gesture(''), null);
  assert.equal(gesture('   '), null);
  assert.equal(gesture('&hover'), null);
  assert.equal(gesture('p {'), null);
});

// The brace and the composition read through the digester with the
// fixture guide's condition table (hover a posable state, narrow a
// wrapper); `row` is the answer's shape.
const digestOptions = { conditions: guide.conditions };
const brace = (before, after = '', rule = null) => bracedSelector(before, after, rule, digestOptions);
const compose = (rule, text) => composeUnder(rule, text, digestOptions);
const row = (selector, condition = null, folded = false) => ({ selector, condition, folded });

test('a typed brace mints the whole selector before it, and only that', () => {
  assert.deepEqual(brace('> d-text '), row('> d-text'));
  assert.deepEqual(brace('  p'), row('p'));
  assert.deepEqual(brace('.quote'), row('.quote'));
  assert.deepEqual(brace('>d-container>p'), row('> d-container > p'));
  // A partial token, a dangling combinator, a declaration, text after
  // the cursor, an empty line and the sigil's shape are not selectors.
  assert.equal(brace('> d-'), null);
  assert.equal(brace('>'), null);
  assert.equal(brace('color: red'), null);
  assert.equal(brace('p', ' x'), null);
  assert.equal(brace(''), null);
  assert.equal(brace('&hover'), null);
  // Relations are typed whole and judged by the grammar.
  assert.deepEqual(brace('> d-text:has(a) '), row('> d-text:has(a)'));
  assert.deepEqual(brace('>p+p'), row('> p + p'));
  assert.deepEqual(brace('> p ~ p'), row('> p ~ p'));
  assert.deepEqual(brace('> *:not(d-image)'), row('> *:not(d-image)'));
  assert.deepEqual(brace('> d-text:not( .featured ):has( a )'), row('> d-text:has(a):not(.featured)'));
  assert.equal(brace('+ p'), null);
  assert.equal(brace('> d-text:has('), null);
});

test('a typed brace reads the WHERE through the digester: a state peels on the last compound and qualifies before it, classes and attributes ride, a list folds into one `:is()` rule, case is lenient', () => {
  // A state on the last compound is the row's condition (`a:hover {` is
  // the `a` row's hover island); on the owner it is the owner's own
  // condition row, the `&hover` pick's twin.
  assert.deepEqual(brace('a:hover'), row('a', 'hover'));
  assert.deepEqual(brace('> d-text:hover'), row('> d-text', 'hover'));
  assert.deepEqual(brace('&:hover'), row('', 'hover'));
  assert.deepEqual(brace(':hover'), row('', 'hover'));
  assert.deepEqual(brace('&:hover::before'), row('&::before', 'hover'));
  assert.deepEqual(brace('> d-text:hover:has(a)'), row('> d-text:has(a)', 'hover'), 'by name, not by the token\'s place');
  // A state on a compound before the last qualifies that compound: the
  // reveal patterns.
  assert.deepEqual(brace('li:hover > ul'), row('li:hover > ul'));
  assert.deepEqual(brace('&:hover > ul'), row('&:hover > ul'));
  assert.deepEqual(brace('> d-text:hover a'), row('> d-text:hover a'));
  // A class on any compound, an attribute, a position and an unpublished
  // pseudo-class are selector text (admission is the validator's).
  assert.deepEqual(brace('li.active > ul'), row('li.active > ul'));
  assert.deepEqual(brace('&.open > ul'), row('&.open > ul'));
  assert.deepEqual(brace('[aria-expanded="true"] > ul'), row('*[aria-expanded="true"] > ul'));
  assert.deepEqual(brace('> d-text:first-child'), row('> d-text:first-child'));
  assert.deepEqual(brace('> *:not(:last-child)'), row('> *:not(:last-child)'));
  assert.deepEqual(brace('a:visited'), row('a:visited'));
  assert.deepEqual(brace('Li:HOVER > UL'), row('li:hover > ul'));
  // A list is one rule: the digester folds it into ONE `:is()` row under
  // the one condition its members share and reports the fold (the host
  // says so once); members peeling different conditions and a list
  // sharing no shape refuse; a typed `:is()` is the grammar's own, no
  // fold; one member after collapsing is no list.
  assert.deepEqual(brace('h1, h2, h3'), row('*:is(h1, h2, h3)', null, true));
  assert.deepEqual(brace('a:hover, button:hover'), row('*:is(a, button)', 'hover', true));
  assert.deepEqual(brace('> d-text:hover a, > d-text:focus-within a'), row('> d-text:is(:focus-within, :hover) a', null, true));
  assert.equal(brace('a:hover, a:focus'), null);
  assert.equal(brace('h1, .x > a'), null);
  assert.deepEqual(brace(':is(h1, h2)'), row('&:is(h1, h2)'));
  assert.deepEqual(brace('a, a'), row('a'));
  // The anchor is nesting sugar: `& > p` is `> p`; `&` alone is the
  // island itself, nothing to mint; a published wrapper alone is the
  // island under that reference, any other query the condition grammar
  // reads the island under that LITERAL clause, canonical (the WHEN
  // landing: a typed `@…` line mints a condition row; its admission is
  // the validator's by mode), and text the grammar cannot read nothing.
  assert.deepEqual(brace('& > p'), row('> p'));
  assert.equal(brace('&'), null);
  assert.deepEqual(brace('@container (max-width: 767px)'), row('', 'narrow'));
  assert.deepEqual(brace('@CONTAINER (max-width:767px)'), row('', 'narrow'), 'a literal equal to a published wrapper is its reference');
  assert.deepEqual(brace('@supports (display: grid)'), row('', '@supports (display: grid)'));
  assert.deepEqual(brace('@container (min-width: 500px)'), row('', '@container (min-width: 500px)'));
  assert.deepEqual(brace('@media  ( Hover: hover )'), row('', '@media (hover: hover)'), 'a literal lands canonical');
  assert.equal(brace('@media screen'), null, 'a query with no feature test is nothing to mint');
  assert.equal(brace('@keyframes spin'), null);
  // An unpublished posable pseudo-class on the last compound peels as a
  // pseudo-class literal; a state no stamp stands in for stays a qualifier.
  assert.deepEqual(brace('input:checked'), row('input', ':checked'));
  assert.deepEqual(brace('&:checked'), row('', ':checked'));
  // Without the table a posable state still peels, as a literal.
  assert.deepEqual(bracedSelector('a:hover', ''), row('a', ':hover'));
  assert.deepEqual(bracedSelector('a:visited', ''), row('a:visited'));
});

const preview = (typed, rule = null) => previewReach(typed, { rule, ...digestOptions });

test('the mint line previews its reach as each token settles: the digested line\'s structural reach, null while the text does not digest', () => {
  // The line keystroke by keystroke: a settled token previews, an open
  // one clears, and the brace's reading is the preview's (one grammar).
  assert.equal(preview('>'), null, 'a dangling combinator');
  assert.equal(preview('> d'), '> d', 'a tag word settles as it is typed');
  assert.equal(preview('> d-'), null, 'an open type token');
  assert.equal(preview('> d-text'), '> d-text');
  assert.equal(preview('> d-text:'), null, 'an open pseudo-class');
  assert.equal(preview('> d-text:has('), null, 'an unclosed qualifier');
  assert.equal(preview('> d-text:has(a)'), '> d-text:has(a)');
  assert.equal(preview('> d-text:has(a) '), '> d-text:has(a)', 'a trailing space is the same line');
  assert.equal(preview('> d-text:has(a) a'), '> d-text:has(a) a', 'the reach moves to the link');
  assert.equal(preview('> d-text::before'), '> d-text::before');
  assert.equal(preview('p'), 'p');
  assert.equal(preview('Li:HOVER > UL'), 'li > ul', 'canonical, as the facet wants it');
  // Clearing: a declaration, the sigil's shape, a brace or semicolon
  // on the line, an empty line, the owner itself (the selected element
  // already shows), a wrapper alone (a condition, no selector), a reach
  // with nothing addressable left. A list previews the reach of the one
  // `:is()` rule it folds into.
  assert.equal(preview('color: red'), null);
  assert.equal(preview('color: red;'), null);
  assert.equal(preview('&hover'), null);
  assert.equal(preview('> d-text {'), null);
  assert.equal(preview(''), null);
  assert.equal(preview('   '), null);
  assert.equal(preview('&'), null);
  assert.equal(preview('&:hover'), null, 'the owner under hover is the owner');
  assert.equal(preview('h1, h2'), '*:is(h1, h2)', 'a list previews its fold');
  assert.equal(preview('> d-text:hover a, > d-text:focus-within a'), '> d-text:focus-within a', 'the posable state inside :is() strips per member (the fixture publishes hover alone, so focus-within rides as a qualifier) and the one member left merges');
  assert.equal(preview('h1, .x > a'), null, 'a list sharing no shape is refused');
  assert.equal(preview('@container (max-width: 767px)'), null);
  assert.equal(preview('*:hover > ul'), null, 'the peel leaves no address');
  assert.equal(preview(42), null);
});

test('the reach preview strips the posable states and keeps what the browser evaluates live', () => {
  // A state on any compound matches nothing in the DOM until it holds,
  // so the paint shows the structural reach; a peeled state (the row's
  // condition) is no reach either.
  assert.equal(preview('li:hover > ul'), 'li > ul');
  assert.equal(preview('> d-text:hover a'), '> d-text a');
  assert.equal(preview('a:hover'), 'a');
  assert.equal(preview('&:hover > ul'), '> ul');
  assert.equal(preview('li:has(*:hover)'), 'li');
  // Position, class and attribute qualifiers stay (the browser judges
  // them live), and so does a negated state (never stripped inside
  // `:not()`).
  assert.equal(preview('li:first-child > a'), 'li:first-child > a');
  assert.equal(preview('li.active > ul'), 'li.active > ul');
  assert.equal(preview('[aria-expanded="true"] > ul'), '*[aria-expanded="true"] > ul');
  assert.equal(preview('> d-text:not(:hover)'), '> d-text:not(:hover)');
  // Without the table nothing is a state: `:hover` stays as typed.
  assert.equal(previewReach('li:hover > ul'), 'li:hover > ul');
});

test('inside a rule island the mint line previews under the rule', () => {
  assert.equal(preview('&::before', 'p'), 'p::before');
  assert.equal(preview('> a', 'p'), 'p > a');
  assert.equal(preview('a', 'p'), 'p a');
  assert.equal(preview(':first-child', '> d-text'), '> d-text:first-child');
  assert.equal(preview('&:hover a', 'p'), 'p a');
  // The island's own row previews the rule's reach, its hover row the
  // same reach (the state is the row's condition).
  assert.equal(preview('&', 'p'), 'p');
  assert.equal(preview('&:hover', 'p'), 'p');
  assert.equal(preview('&hover', 'p'), null);
});

test('a typed brace mints a generated box: the owner\'s through the anchor, a subject\'s glued', () => {
  assert.deepEqual(brace('&::before'), row('&::before'));
  assert.deepEqual(brace('  &::after '), row('&::after'));
  assert.deepEqual(brace('> d-text::after '), row('> d-text::after'));
  assert.deepEqual(brace('.quote::before'), row('.quote::before'));
  assert.deepEqual(brace('p::after'), row('p::after'));
  assert.deepEqual(brace('>d-container>p::before'), row('> d-container > p::before'));
  assert.deepEqual(brace('> p + p::after'), row('> p + p::after'));
  assert.deepEqual(brace('> d-text:has( a )::before'), row('> d-text:has(a)::before'));
  assert.deepEqual(brace('::before'), row('&::before'), 'a leading `::` is the owner\'s own box');
  // Any pseudo-element the browser spells mints (the grammar is CSS's;
  // whether this site knows `&::bef` is the validator's verdict at the
  // write, as for a typed tag), the owner's through the anchor, a
  // subject's glued.
  assert.deepEqual(brace('&::marker'), row('&::marker'));
  assert.deepEqual(brace('> d-text::first-line'), row('> d-text::first-line'));
  assert.deepEqual(brace('li:hover::marker'), row('li::marker', 'hover'), 'a state before another pseudo-element peels as before a box');
  assert.deepEqual(brace('&::bef'), row('&::bef'));
  // A bare `::`, a functional pseudo-element, anything after a box, a box
  // on a box, a `::` behind a space and an anchor anywhere but first are
  // the digester's refusals.
  assert.equal(brace('&::'), null);
  assert.equal(brace('&::part(x)'), null);
  assert.equal(brace('&::before .x'), null);
  assert.equal(brace('> d-text::before::after'), null);
  assert.equal(brace('> d-text ::before'), null);
  assert.equal(brace('& ::before'), null);
  assert.equal(brace('> d-text &::before'), null);
  assert.equal(brace('&::before', ' x'), null);
});

test('the `:` lane: glued to a compound (or bare at the owner) it offers the published states, the positions and the open qualifiers to finish; nothing inside an open paren or bracket', () => {
  const lane = gesture('> d-text:ho', { conditions: guide.conditions });
  assert.deepEqual(lane.options.slice(0, 2), [
    { selector: '> d-text:hover', kind: 'state', name: 'hover', label: 'Hover' },
    { selector: '> d-text:first-child', kind: 'position', name: 'first-child', label: null },
  ]);
  assert.deepEqual(
    lane.options.filter((option) => option.kind === 'position').map((option) => option.name),
    ['first-child', 'last-child', 'only-child', 'empty', 'first-of-type', 'last-of-type', 'only-of-type'],
  );
  assert.deepEqual(
    lane.options.filter((option) => option.kind === 'qualifier'),
    ['nth-child', 'nth-last-child', 'nth-of-type', 'nth-last-of-type', 'not', 'has', 'is'].map((name) => ({
      insert: `> d-text:${name}(`,
      kind: 'qualifier',
      name,
      label: null,
    })),
  );
  assert.equal(lane.create, null);
  // A wrapper is no pseudo-class, so `narrow` is never a state option;
  // without a table only the positions and qualifiers offer.
  assert.deepEqual(lane.options.filter((option) => option.name === 'narrow'), []);
  assert.deepEqual(gesture('a:').options.filter((option) => option.kind === 'state'), []);
  // At the owner the lane is the anchor's: a bare `:` or `&:`.
  assert.equal(gesture(':ho', { conditions: guide.conditions }).options[0].selector, '&:hover');
  assert.equal(gesture('&:fi', { conditions: guide.conditions }).options.find((option) => option.kind === 'position').selector, '&:first-child');
  // After a closed qualifier the lane goes on; inside an open one, or an
  // open attribute, nothing; a space before the colon is no lane.
  assert.deepEqual(gesture('> d-text:has(a):ho', { conditions: guide.conditions }).options[0], {
    selector: '> d-text:has(a):hover', kind: 'state', name: 'hover', label: 'Hover',
  });
  assert.equal(gesture('> d-text:has(a'), null);
  assert.equal(gesture('> d-text:has('), null);
  // The lane goes on after a closed `:is()` too, a state inside it on
  // the compound before: the children of the revealed items.
  assert.deepEqual(gesture('.menu-item:is(:focus-within, :hover) > ul:ho', { conditions: guide.conditions }).options[0], {
    selector: '.menu-item:is(:focus-within, :hover) > ul:hover', kind: 'state', name: 'hover', label: 'Hover',
  });
  assert.equal(gesture('.menu-item:is(:focus-within, '), null);
  assert.equal(gesture('li[aria-current'), null);
  assert.deepEqual(gesture('> d-text :ho').options.filter((option) => option.kind === 'state'), []);
  // A settled state before a chain reads through the grammar: the
  // children of the hovered items.
  assert.deepEqual(selectorsOf(gesture('li:hover > ')).slice(0, 1), ['li:hover > *']);
});

test('a `.` glued to a tag offers the classes the completion can know: the declared parts of the owner the tag stands under; never a create', () => {
  const parts = [{ id: '.quote', label: 'Quote' }];
  const partsFor = (type) => (type === 'd-text' ? [{ id: '.text-block-link', label: 'Link' }] : []);
  assert.deepEqual(gesture('p.qu', { parts }).options, [{ selector: 'p.quote', kind: 'class', name: '.quote', label: 'Quote' }]);
  assert.deepEqual(selectorsOf(gesture('> d-text a.', { parts, partsFor })), ['> d-text a.text-block-link']);
  assert.equal(gesture('p.qu', { parts }).create, null);
  // Glued to a type there are no classes to know (a worn variant's class
  // is the matcher's fact, not the completion's).
  assert.deepEqual(gesture('> d-text.', { partsFor }).options.filter((option) => option.kind === 'class'), []);
});

// --- Composition under a rule island (CSS nesting's relative rule) ----------

test('composeUnder on the owner\'s island leaves the text as typed: no rule, the anchor the owner itself', () => {
  assert.deepEqual(compose(null, 'p'), row('p'));
  assert.deepEqual(compose('', '> d-text'), row('> d-text'));
  assert.deepEqual(compose(null, '&::before'), row('&::before'));
  assert.deepEqual(compose(null, '  .quote::after '), row('.quote::after'));
  // The bare anchor is the island itself, nothing to mint; a chain on it
  // is nesting sugar; a state on it is the owner's own condition row.
  assert.equal(compose(null, '&'), null);
  assert.deepEqual(compose('', '& > p'), row('> p'));
  assert.deepEqual(compose(null, '&:hover'), row('', 'hover'));
});

test('composeUnder under a tag rule: `&` is the rule, a bare line is relative to it, the digester judges the result', () => {
  assert.deepEqual(compose('p', '&::before'), row('p::before'));
  assert.deepEqual(compose('p', '&::after'), row('p::after'));
  assert.deepEqual(compose('p', '> a'), row('p > a'));
  assert.deepEqual(compose('p', 'a'), row('p a'));
  // A part class composes relative to the rule too (a class after a tag
  // by descent is a compound of its own).
  assert.deepEqual(compose('p', '.x'), row('p .x'));
  assert.deepEqual(compose('> d-text', '.x'), row('> d-text .x'));
  assert.deepEqual(compose('p', '& > a'), row('p > a'));
  assert.deepEqual(compose('p', '>a'), row('p > a'));
  assert.deepEqual(compose('p', '  > a '), row('p > a'));
  // `&` alone composes to the rule itself, the row the island already
  // is: nothing to mint.
  assert.equal(compose('p', '&'), null);
  assert.equal(compose('p', ' & '), null);
  // Another pseudo-element composes like a box; the digester's refusals
  // on the composed text: a bare `::`, a dangling combinator, a
  // functional pseudo-element.
  assert.deepEqual(compose('p', '&::marker'), row('p::marker'));
  assert.deepEqual(compose('p', '&::first-letter'), row('p::first-letter'));
  assert.equal(compose('p', '&::'), null);
  assert.equal(compose('p', '>'), null);
  assert.equal(compose('p', '&::part(x)'), null);
  // A state on the rule's own compound is the island's condition row
  // (`&:hover {` in the `p` island, the `&hover` pick's twin), a leading
  // `:` the same sugar, a published wrapper alone too; with a chain below
  // it qualifies the rule's compound, and a class on it is selector text.
  assert.deepEqual(compose('p', '&:hover'), row('p', 'hover'));
  assert.deepEqual(compose('p', ':hover'), row('p', 'hover'));
  assert.deepEqual(compose('p', '@container (max-width: 767px)'), row('p', 'narrow'));
  assert.deepEqual(compose('li', '&:hover > ul'), row('li:hover > ul'));
  assert.deepEqual(compose('li', '&.open > ul'), row('li.open > ul'));
  // The condition sigil's shape (the anchor glued to a word) never
  // composes: substituting the rule would spell a tag (`phover`). A
  // position is typed as `:first-child` through the `:` lane and
  // refines the rule into a row of its own (the fact-condition fixtures'
  // new spelling).
  assert.equal(compose('p', '&hover'), null);
  assert.equal(compose('p', '&first-child'), null);
  assert.deepEqual(compose('> d-text', '&:first-child'), row('> d-text:first-child'));
  assert.deepEqual(compose('> *', '&:not(:last-child)'), row('> *:not(:last-child)'));
  assert.equal(nestUnder('p', '&hover'), null);
  assert.equal(nestUnder(null, '&hover'), null);
});

test('composeUnder under a chain rule continues the chain, and a box rule takes no continuation', () => {
  assert.deepEqual(compose('> d-text .link', '&::after'), row('> d-text .link::after'));
  assert.deepEqual(compose('> d-text .link', '> a'), row('> d-text .link > a'));
  assert.deepEqual(compose('> d-text', '&::before'), row('> d-text::before'));
  assert.deepEqual(compose('> d-text', '.link'), row('> d-text .link'));
  assert.deepEqual(compose('> d-text:has(a)', '&::before'), row('> d-text:has(a)::before'));
  // A rule that is itself a box (`p::before`) is a terminal: a box on a
  // box and a descendant of a box are outside the grammar.
  assert.equal(compose('p::before', '&::after'), null);
  assert.equal(compose('p::before', '> a'), null);
  assert.equal(compose('p::before', '.x'), null);
});

test('nestUnder is the raw substitution the completion feeds: a partial line composes without judgement', () => {
  assert.equal(nestUnder('p', '&::'), 'p::');
  assert.equal(nestUnder('p', '&::bef'), 'p::bef');
  assert.equal(nestUnder('p', '> '), 'p > ');
  assert.equal(nestUnder('p', '> d-'), 'p > d-');
  assert.equal(nestUnder('p', '.'), 'p .');
  assert.equal(nestUnder('> d-text', 'a'), '> d-text a');
  assert.equal(nestUnder(null, '&::'), '&::');
  assert.equal(nestUnder('', '> d-'), '> d-');
  assert.equal(nestUnder('p', 42), null);
});

test('the completion fed the nested line offers the rule\'s position: its boxes behind `&::`, its children behind `>`', () => {
  // The owner's `p` island on section A: `&::` is the paragraph's boxes,
  // minting the composed selectors.
  // The two generated boxes first, then the five with a vocabulary of
  // their own, then every other pseudo-element the browser knows (the
  // wide tier), each once; the completion filters by the typed tail.
  const boxes = gesture(nestUnder('p', '&::'));
  assert.deepEqual(boxes.options.slice(0, 7), [
    { selector: 'p::before', kind: 'pseudo', name: 'before', label: null },
    { selector: 'p::after', kind: 'pseudo', name: 'after', label: null },
    { selector: 'p::marker', kind: 'pseudo', name: 'marker', label: null },
    { selector: 'p::placeholder', kind: 'pseudo', name: 'placeholder', label: null },
    { selector: 'p::selection', kind: 'pseudo', name: 'selection', label: null },
    { selector: 'p::first-letter', kind: 'pseudo', name: 'first-letter', label: null },
    { selector: 'p::first-line', kind: 'pseudo', name: 'first-line', label: null },
  ]);
  assert.equal(boxes.options.length, widePseudoElements().length);
  assert.equal(new Set(boxes.options.map((option) => option.name)).size, boxes.options.length);
  assert.ok(boxes.options.some((option) => option.selector === 'p::backdrop'));
  assert.equal(boxes.create, null);
  // `>` inside the `> d-container` island is the nested section's
  // children, the same set the owner's chain `> d-container >` reads.
  assert.deepEqual(
    selectorsOf(gesture(nestUnder('> d-container', '> '))),
    selectorsOf(gesture('> d-container > ')),
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
  assert.equal(nestedSpelling('p', '>d-', 'p > d-text'), '> d-text');
  assert.equal(nestedSpelling('p', '.', 'p .x'), '.x');
  assert.equal(nestedSpelling('> d-container', '> ', '> d-container > d-text'), '> d-text');
  assert.equal(nestedSpelling('> d-text .link', '&::', '> d-text .link::after'), '&::after');
  // On the owner's island the selector is its own spelling.
  assert.equal(nestedSpelling(null, '&::', '&::before'), '&::before');
  assert.equal(nestedSpelling('', '> ', '> d-text'), '> d-text');
});

test('a typed brace inside a rule island mints the composed selector', () => {
  assert.deepEqual(brace('&::before', '', 'p'), row('p::before'));
  assert.deepEqual(brace('  &::after ', '', 'p'), row('p::after'));
  assert.deepEqual(brace('> a', '', 'p'), row('p > a'));
  assert.deepEqual(brace('.x', '', '> d-text'), row('> d-text .x'));
  assert.deepEqual(brace('&::after', '', '> d-text .link'), row('> d-text .link::after'));
  assert.deepEqual(brace('&::before', '', '> d-text'), row('> d-text::before'));
  // The island's own condition row (`&:hover {`, `:hover {`), a state on
  // the rule's compound with a chain below, a position refining the rule.
  assert.deepEqual(brace('&:hover', '', 'p'), row('p', 'hover'));
  assert.deepEqual(brace(':hover', '', '> d-text'), row('> d-text', 'hover'));
  assert.deepEqual(brace('&:hover > ul', '', 'li'), row('li:hover > ul'));
  assert.deepEqual(brace('&:first-child', '', '> d-text'), row('> d-text:first-child'));
  // The same refusals as the owner's: a partial tail, text after the
  // cursor, a declaration, a box on a box, the rule itself.
  assert.equal(brace('&::', '', 'p'), null);
  assert.equal(brace('&::before', ' x', 'p'), null);
  assert.equal(brace('color: red', '', 'p'), null);
  assert.equal(brace('&::after', '', 'p::before'), null);
  assert.equal(brace('&', '', 'p'), null);
  // No rule: the owner's behavior, unchanged.
  assert.deepEqual(brace('&::before', '', null), row('&::before'));
  assert.deepEqual(brace('&::before', '', ''), row('&::before'));
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

test('a relation is typed whole: nothing after a sibling combinator or inside an open qualifier; a settled relation continues through the grammar', () => {
  assert.equal(gesture('> p + '), null);
  assert.equal(gesture('> p + p'), null);
  assert.equal(gesture('> p ~ '), null);
  assert.equal(gesture('> d-text:has('), null);
  // A closed qualifier is a settled compound: the offer is its matches'
  // descendants (the linked text blocks' anchors), parts and boxes.
  const settled = gesture('> d-text:has(a) ');
  assert.notEqual(settled, null);
  assert.ok(selectorsOf(settled).every((selector) => selector.startsWith('> d-text:has(a) ')));
  assert.deepEqual(gesture('> *:not(d-image) .'), { options: [], create: null });
  assert.deepEqual(selectorsOf(gesture('> p + p::')).slice(0, 2), ['> p + p::before', '> p + p::after']);
  assert.equal(selectorsOf(gesture('> p + p::')).length, widePseudoElements().length);
  assert.deepEqual(selectorsOf(gesture('> d-text:has(a)::b')).slice(0, 2), ['> d-text:has(a)::before', '> d-text:has(a)::after']);
});

test('the owner\'s anchor offers its pseudo-elements, the two generated boxes first, whatever tail is typed', () => {
  const boxes = [
    { selector: '&::before', kind: 'pseudo', name: 'before', label: null },
    { selector: '&::after', kind: 'pseudo', name: 'after', label: null },
  ];
  ['&', '&::', '&::a', '&::before', '  &::bef'].forEach((typed) => {
    const offer = gesture(typed);
    assert.deepEqual(offer.options.slice(0, 2), boxes, typed);
    assert.equal(offer.options.length, widePseudoElements().length, typed);
    assert.equal(offer.create, null, typed);
  });
  // The offer is universal: no document, no parts, no tags needed.
  assert.deepEqual(selectorGestureOptions('&::', { key: OWNER, document: null }).options.slice(0, 2), boxes);
  // A box behind a space, anything after a box, an anchor anywhere but
  // first and a box on a box are the digester's refusals; `& > p` is
  // nesting sugar for `> p` (the owner's children).
  assert.equal(gesture('& ::before'), null);
  assert.equal(gesture('&::before .x'), null);
  assert.deepEqual(selectorsOf(gesture('& > p')), selectorsOf(gesture('> p')));
  assert.equal(gesture('> d-text &::before'), null);
  assert.equal(gesture('&::before::'), null);
  // A lone colon is the `:` lane on the owner, never the start of a box.
  assert.ok(gesture('&:').options.every((option) => option.kind !== 'pseudo'));
  assert.ok(gesture('&:').options.some((option) => option.kind === 'position'));
  assert.equal(gesture('&:hover').options.filter((option) => option.kind === 'pseudo').length, 0);
});

test('a `::` tail offers the subject\'s pseudo-elements, the two boxes first, as continuations of the whole selector before it', () => {
  const expect = (typed, host) => {
    const offer = gesture(typed);
    assert.deepEqual(offer.options.slice(0, 2), [
      { selector: `${host}::before`, kind: 'pseudo', name: 'before', label: null },
      { selector: `${host}::after`, kind: 'pseudo', name: 'after', label: null },
    ], typed);
    assert.equal(offer.options.length, widePseudoElements().length, `${typed}: every pseudo-element the browser knows, once`);
    assert.ok(offer.options.every((option) => option.selector === `${host}::${option.name}` && option.kind === 'pseudo'), typed);
    assert.equal(offer.create, null, typed);
  };
  expect('> d-text::', '> d-text');
  expect('> d-text::b', '> d-text');
  expect('> d-text::after', '> d-text');
  expect('>d-text::', '> d-text');
  expect('.quote::a', '.quote');
  expect('p::', 'p');
  expect('> *::', '> *');
  expect('> d-container > p::', '> d-container > p');
  expect('> d-text .text-block-link::', '> d-text .text-block-link');
  // Glued to a whole selector only: a space before it is every
  // descendant's box in CSS, a partial or dangling head is no subject,
  // and a box takes no box; a bare `::` is the owner's own box (the
  // digester's leading-colon sugar, `&::`).
  assert.equal(gesture('> d-text ::'), null);
  expect('::', '&');
  expect('::before', '&');
  assert.equal(gesture('> ::'), null);
  assert.equal(gesture('> d-text::before::'), null);
  assert.equal(gesture('> d-text::before '), null);
  assert.equal(gesture('> d-text::before > '), null);
  // A single colon glued to a compound is the `:` lane, not a box tail;
  // a declaration's colon (followed by a space) is no selector at all.
  assert.ok(gesture('> d-text:hover').options.every((option) => option.kind !== 'pseudo'));
  assert.ok(gesture('> d-text:').options.every((option) => option.kind !== 'pseudo'));
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

// --- The rule move gesture: one op for a stored rule, view state for a draft --

test('a stored rule moves with its family as ONE move_style op, and the drafts on it rename', () => {
  const rule = `${KEY}::> d-text`;
  const link = `${KEY}::> d-text .link`;
  const map = {
    [rule]: { default: { color: 'red' } },
    [link]: { default: { gap: '1rem' } },
    [`${KEY}::> d-textual`]: { default: { gap: '2rem' } },
  };
  const drafts = { for: KEY, conditions: { [rule]: ['hover'], [link]: ['narrow'] }, parts: [], focus: `${rule}|hover` };
  const plan = ruleMovePlan(map, drafts, KEY, '> d-text', '> d-text:has(a)');
  assert.equal(plan.refusal, null);
  // The rule's own key first, then the continuation; `> d-textual` is no
  // continuation (the server's definition, rule-move.js).
  assert.deepEqual(plan.moves, { '> d-text': '> d-text:has(a)', '> d-text .link': '> d-text:has(a) .link' });
  assert.deepEqual(plan.operations, [{ op: 'move_style', key: rule, to: '> d-text:has(a)' }]);
  assert.deepEqual(renameDrafts(drafts, KEY, plan.moves), {
    for: KEY,
    conditions: { [`${KEY}::> d-text:has(a)`]: ['hover'], [`${KEY}::> d-text:has(a) .link`]: ['narrow'] },
    parts: [],
    focus: `${KEY}::> d-text:has(a)|hover`,
  });
});

test('a continuation alone is something stored: the op goes even when the rule itself has no entry', () => {
  const map = { [`${KEY}::> d-text::before`]: { default: { color: 'red' } } };
  const plan = ruleMovePlan(map, { for: KEY, conditions: {}, parts: [], focus: null }, KEY, '> d-text', '> d-image');
  assert.deepEqual(plan.moves, { '> d-text': '> d-image', '> d-text::before': '> d-image::before' });
  assert.deepEqual(plan.operations, [{ op: 'move_style', key: `${KEY}::> d-text`, to: '> d-image' }]);
  // A worn variant and nothing else is an entry too: the op moves the
  // entry whole, its variants list included.
  const worn = { [`${KEY}::p`]: { variants: [{ variant: 'aqua', fragment: '' }] } };
  assert.deepEqual(
    ruleMovePlan(worn, { for: KEY, conditions: {}, parts: [], focus: null }, KEY, 'p', 'h3').operations,
    [{ op: 'move_style', key: `${KEY}::p`, to: 'h3' }],
  );
});

test('a draft row with nothing stored sends nothing: its rename is the view\'s', () => {
  const drafts = { for: KEY, conditions: { [`${KEY}::.quote`]: ['hover'] }, parts: ['.quote', 'p'], focus: `${KEY}::.quote|default` };
  const plan = ruleMovePlan({ [KEY]: { default: { gap: '1rem' } } }, drafts, KEY, '.quote', '.note');
  assert.equal(plan.refusal, null);
  assert.deepEqual(plan.moves, { '.quote': '.note' });
  assert.deepEqual(plan.operations, []);
  assert.deepEqual(renameDrafts(drafts, KEY, plan.moves), {
    for: KEY,
    conditions: { [`${KEY}::.note`]: ['hover'] },
    parts: ['.note', 'p'],
    focus: `${KEY}::.note|default`,
  });
});

test('a landing on a stored key or on a drafted row refuses, naming it; a box takes no continuation', () => {
  const stored = { [`${KEY}::p`]: { default: { color: 'red' } }, [`${KEY}::h3`]: { default: { color: 'blue' } } };
  const none = { for: KEY, conditions: {}, parts: [], focus: null };
  assert.deepEqual(ruleMovePlan(stored, none, KEY, 'p', 'h3'), {
    refusal: { reason: REFUSED_TARGET_EXISTS, key: 'h3' },
    moves: null,
    operations: [],
  });
  const drafted = { for: KEY, conditions: {}, parts: ['.quote', '.note'], focus: null };
  assert.deepEqual(ruleMovePlan({}, drafted, KEY, '.quote', '.note').refusal, { reason: REFUSED_TARGET_EXISTS, key: '.note' });
  // A drafted condition row's key is a visible row too.
  const conditioned = { for: KEY, conditions: { [`${KEY}::h3`]: ['hover'] }, parts: [], focus: null };
  assert.deepEqual(ruleMovePlan(stored, conditioned, KEY, 'p', 'h3').refusal, { reason: REFUSED_TARGET_EXISTS, key: 'h3' });
  const boxed = { [`${KEY}::> d-text`]: { default: { color: 'red' } }, [`${KEY}::> d-text .link`]: { default: { gap: '1rem' } } };
  assert.deepEqual(ruleMovePlan(boxed, none, KEY, '> d-text', '> d-text::before').refusal, {
    reason: REFUSED_CONTINUATION,
    key: '> d-text .link',
  });
});

test('renameDrafts leaves other owners\' rows and unmoved selectors alone', () => {
  const other = 'container:22222222-2222-2222-2222-222222222222';
  const drafts = {
    for: KEY,
    conditions: { [`${other}::> d-text`]: ['hover'], [`${KEY}::p`]: ['narrow'] },
    parts: ['p', '.quote'],
    focus: `${KEY}|default`,
  };
  // The ancestor's rule moved: this element's drafts under it rename,
  // its own part rows do not (the parts are the drafts' element's).
  assert.deepEqual(renameDrafts(drafts, other, { '> d-text': '> d-text:has(a)' }), {
    ...drafts,
    conditions: { [`${other}::> d-text:has(a)`]: ['hover'], [`${KEY}::p`]: ['narrow'] },
  });
  // A move on this element touching neither draft changes nothing.
  assert.deepEqual(renameDrafts(drafts, KEY, { h3: 'h4' }), drafts);
});

process.stdout.write('# all element-model tests passed\n');
