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
  conditionRemovalOps, draftRoster, elementIslands, islandConditions,
  occupiedRoster, partGestureOptions, partRemovalOps, posedRoster,
  restoredLines, settleOps, shouldRestoreTinted, tagGestureOptions,
} from '../../js/src/code/element-model.js';
import { analyzeIsland, islandLines, printIsland } from '../../js/src/code/island.js';
import { ELEMENT_LAYER, variantLayerId } from '../../js/src/code/layers.js';

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

test('default island always exists; WHEN rows only for conditions with values', () => {
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

test('an all-null condition layer earns no WHEN row', () => {
  assert.deepEqual(islandConditions({ hover: { color: null } }, guide), ['default']);
});

test('the roster includes variant-supplied conditions (the resolved layers)', () => {
  const map = { [KEY]: { variants: [{ variant: 'aqua', fragment: '' }] } };
  const model = elementIslands(map, KEY, guide, VARIANTS);
  assert.deepEqual(model.conditions, ['default', 'hover']);
});

test('part rows come from own map entries AND worn references, deduped', () => {
  const map = {
    [KEY]: { variants: [{ variant: 'aqua', fragment: '' }] },
    [SUB]: { default: { color: 'purple' } },
    [`${KEY}::.credit`]: { hover: { color: 'teal' } },
  };
  const model = elementIslands(map, KEY, guide, VARIANTS);
  assert.deepEqual(model.parts.map((part) => part.sub), ['.quote', '.credit']);
  assert.deepEqual(model.parts[0], { sub: '.quote', key: SUB, conditions: ['default'] });
  assert.deepEqual(model.parts[1].conditions, ['default', 'hover']);
});

test('a distributed fragment reference supplies its own part rows', () => {
  const child = 'container:33333333-3333-3333-3333-333333333333';
  const map = { [child]: { variants: [{ variant: 'aqua', fragment: 'container(1)' }] } };
  const model = elementIslands(map, child, guide, VARIANTS);
  assert.deepEqual(model.parts.map((part) => part.sub), ['.credit']);
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
  assert.deepEqual(model.parts.map((part) => part.sub), ['.quote', 'p']);
  assert.deepEqual(model.parts[1], {
    sub: 'p', key: `${KEY}::p`, conditions: ['default', 'hover'],
  });
});

test('malformed part selectors are skipped', () => {
  const map = {
    [KEY]: { default: { gap: '1rem' } },
    [`${KEY}::not a selector`]: { default: { color: 'red' } },
  };
  const model = elementIslands(map, KEY, guide, []);
  assert.deepEqual(model.parts, []);
});

// --- Sparse rows: only occupied islands render ------------------------------

test('occupiedRoster prunes the empty root island of a part-styled element', () => {
  const map = { [SUB]: { default: { color: 'purple' } } };
  const pruned = occupiedRoster(elementIslands(map, KEY, guide, []), map, KEY, []);
  assert.deepEqual(pruned.conditions, []);
  assert.deepEqual(pruned.parts.map((part) => part.sub), ['.quote']);
});

test('occupiedRoster keeps variant-delivered islands (root and parts)', () => {
  const map = { [KEY]: { variants: [{ variant: 'aqua', fragment: '' }] } };
  const pruned = occupiedRoster(elementIslands(map, KEY, guide, VARIANTS), map, KEY, VARIANTS);
  assert.deepEqual(pruned.conditions, ['default', 'hover']);
  assert.deepEqual(pruned.parts.map((part) => part.sub), ['.quote']);
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
  assert.deepEqual(merged.parts.find((part) => part.sub === '.credit').conditions, ['default']);
});

// --- Shadowed values print in place (ghost rows are deleted) ------------------

test('a shadowed variant value prints as a lost line in the document itself', () => {
  const map = { [KEY]: { variants: [{ variant: 'aqua', fragment: '' }], default: { color: 'var(--x)', gap: '1rem' } } };
  const lines = islandLines(map, KEY, 'default', VARIANTS);
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
  const lines = islandLines(map, KEY, 'default', VARIANTS);
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
  const lines = islandLines(map, KEY, 'default', VARIANTS);
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
  assert.deepEqual(merged.parts.map((part) => part.sub), ['.quote', '.credit']);
  assert.deepEqual(merged.parts[1], { sub: '.credit', key: creditKey, conditions: ['default', 'hover'] });
});

test('drafts the map already surfaces absorb; malformed draft selectors drop', () => {
  const model = elementIslands({ [SUB]: { default: { color: 'purple' } } }, KEY, guide, []);
  const merged = draftRoster(model, { conditions: {}, parts: ['.quote', 'not a selector'] }, KEY, guide);
  assert.deepEqual(merged.parts.map((part) => part.sub), ['.quote']);
});

test('a drafted tag row appends alongside the part rows (the tag gesture)', () => {
  // Tag rows materialize through the same draft lane as parts: picking a
  // tag in the completion drafts its sub, and the row appends with its
  // type-here default island until the first declaration commits.
  const model = elementIslands({ [SUB]: { default: { color: 'purple' } } }, KEY, guide, []);
  const merged = draftRoster(model, { conditions: {}, parts: ['ul'] }, KEY, guide);
  assert.deepEqual(merged.parts.map((part) => part.sub), ['.quote', 'ul']);
  assert.deepEqual(merged.parts[1], { sub: 'ul', key: `${KEY}::ul`, conditions: ['default'] });
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

// --- The part gesture's completion set -----------------------------------------

test('declared anatomy always completes; open sites add the explicit create', () => {
  const declared = [{ id: '.quote', label: 'Quote' }];
  const open = partGestureOptions('.credit', declared, { closed: false });
  assert.deepEqual(open.options, [{ sub: '.quote', label: 'Quote' }]);
  assert.equal(open.create, '.credit');
});

test('no create entry when closed, malformed, or already declared', () => {
  const declared = [{ id: '.quote', label: 'Quote' }];
  assert.equal(partGestureOptions('.credit', declared, { closed: true }).create, null);
  assert.equal(partGestureOptions('.', declared, {}).create, null);
  assert.equal(partGestureOptions('.quote', declared, {}).create, null);
});

test('a declared part ABSENT from the canvas is not offered, styled or not', () => {
  // The element-projection rule: the unlinked text block's anchor. Only a
  // positive absence fact hides — the store-projection rows (materialized
  // from existing declarations) never pass through this gate at all.
  const declared = [
    { id: '.text-block-link', label: 'Link' },
    { id: '.quote', label: 'Quote' },
  ];
  const presence = { '.text-block-link': false, '.quote': true };
  const gated = partGestureOptions('.', declared, { presence });
  assert.deepEqual(gated.options, [{ sub: '.quote', label: 'Quote' }]);
});

test('unknown presence degrades to offering (satellite pre-broadcast posture)', () => {
  const declared = [{ id: '.quote', label: 'Quote' }];
  // No facts at all, and a facts map that never mentions the selector,
  // both offer; only presence[id] === false hides.
  assert.deepEqual(partGestureOptions('.', declared, { presence: null }).options,
    [{ sub: '.quote', label: 'Quote' }]);
  assert.deepEqual(partGestureOptions('.', declared, { presence: {} }).options,
    [{ sub: '.quote', label: 'Quote' }]);
});

test('declared-but-absent never falls through to the create entry', () => {
  // Absence hides the part; it does not recast its class as undeclared,
  // so an open site offers NO way to mint a row for the missing target.
  const declared = [{ id: '.text-block-link', label: 'Link' }];
  const presence = { '.text-block-link': false };
  const gated = partGestureOptions('.text-block-link', declared, { closed: false, presence });
  assert.deepEqual(gated.options, []);
  assert.equal(gated.create, null);
});

test('the tag offer is the declared vocabulary, no create entry ever', () => {
  // The tag tier is only grammar for declared base tags (the server
  // rejects an undeclared tag in every enforcement mode), so unlike the
  // part gesture there is no confirm-materialize on any site — the offer
  // IS the site's base vocabulary (labelled by the identity lane where it
  // declares a label), and unknown presence degrades to offering.
  const tags = { p: 'Paragraph', h2: 'Heading 2', ul: 'Bulleted list' };
  assert.deepEqual(tagGestureOptions(tags, null), [
    { tag: 'p', label: 'Paragraph' },
    { tag: 'h2', label: 'Heading 2' },
    { tag: 'ul', label: 'Bulleted list' },
  ]);
  assert.deepEqual(tagGestureOptions({}, null), []);
  assert.deepEqual(tagGestureOptions(undefined, null), []);
});

test('the tag offer gates on the annotation\'s tags fact, minting or not', () => {
  // The annotation's `tags` fact names every tag the element's prose
  // contains, so a declared tag it lacks is positively absent and hides,
  // while a tag that never mints a carrier (the link) is offered the
  // moment the prose contains one: Motion on base:a reaches an element
  // through the code view, not only through typed keys.
  const tags = { p: 'Paragraph', h2: 'Heading 2', ul: 'Bulleted list', a: 'a' };
  assert.deepEqual(
    tagGestureOptions(tags, ['ul', 'p', 'a', 'strong']).map((option) => option.tag),
    ['p', 'ul', 'a'],
  );
  // An empty enumeration is a positive fact: nothing offers.
  assert.deepEqual(tagGestureOptions(tags, []), []);
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
