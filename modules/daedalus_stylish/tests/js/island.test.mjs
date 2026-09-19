// cspell:ignore paddding
/**
 * Self-asserting tests for the island engine (the islands code view core).
 *
 * Run directly (`node tests/js/island.test.mjs`) or via
 * PrinterEquivalenceTest, which executes this file inside the suite so the
 * parse/validate/print/diff contract has PHPUnit backpressure. Exits
 * non-zero on the first failure with a readable diff.
 */
import { strict as assert } from 'node:assert';
import { analyzeIsland, islandLines, islandOps, matchLines, printIsland } from '../../js/src/code/island.js';
import { DECLARED_LAYER, ELEMENT_LAYER, SKIN_LAYER, variantLayerId } from '../../js/src/code/layers.js';
import { validateValue } from '../../js/src/code/validate-value.js';
import { EMPTY_MATERIALS, animationRecord, roster } from './materials-fixture.mjs';

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
        'font-weight': { label: 'Font weight', type: 'number', keywords: ['normal', 'bold'] },
        color: { label: 'Text color', type: 'color' },
        'text-align': { label: 'Text align', type: 'keyword', keywords: ['left', 'center', 'right', 'justify'] },
      },
    },
  },
  conditions: {
    hover: { label: 'Hover', wrapper: ':hover' },
    narrow: { label: 'Narrow', wrapper: '@container (max-width: 767px)' },
  },
};

// The served node: the roster's grants (group id => the granted names)
// and value spaces at the element's address.
const SPACING = ['padding', 'margin', 'gap'];
const TYPOGRAPHY = ['font-size', 'font-weight', 'color', 'text-align'];
const declaration = {
  grants: { spacing: SPACING, typography: TYPOGRAPHY },
  valueSpaces: {
    padding: { max: '4rem', units: ['rem', 'px'] },
    color: { allowed: ['#1a1a2e', 'var(--brand)'] },
  },
  elements: { '.quote': { grants: { typography: TYPOGRAPHY } } },
};

const strictGuide = { ...guide, enforcement: 'strict' };

const analyze = (text, options = {}) => analyzeIsland(text, { guide, materials: EMPTY_MATERIALS, declaration, ...options });

const errorsOf = (result) => result.diagnostics.filter((diagnostic) => diagnostic.severity === 'error');

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

// --- Parse: the flat declaration list ---------------------------------------

test('declarations parse whole with offsets, !important folded into the value', () => {
  const text = 'padding: 2rem;\ncolor: #1a1a2e !important;';
  const { declarations } = analyze(text);
  assert.deepEqual(declarations.map(({ property, value, important }) => ({ property, value, important })), [
    { property: 'padding', value: '2rem', important: false },
    { property: 'color', value: '#1a1a2e !important', important: true },
  ]);
  assert.equal(text.slice(declarations[0].valueFrom, declarations[0].valueTo), '2rem');
  assert.equal(declarations[0].valid, true);
});

test('a half-typed chunk is a warning that holds its tokens, not an error', () => {
  const result = analyze('padding: 2rem;\ncolor');
  assert.equal(result.declarations.length, 1);
  assert.equal(errorsOf(result).length, 0);
  assert.ok(result.diagnostics.some((diagnostic) => diagnostic.severity === 'warning' && /will not save/.test(diagnostic.message)));
  assert.ok(result.held.has('color'));
});

test('pasted structure is an error: islands hold declarations only', () => {
  const result = analyze('&:hover { color: red; }\npadding: 1rem;');
  const errors = errorsOf(result);
  assert.equal(errors.length, 1);
  assert.match(errors[0].message, /island holds plain declarations/);
  // The rule's inner properties are held, never read as deletions.
  assert.ok(result.held.has('color'));
  // The declaration beside it still parses and stays valid.
  assert.deepEqual(result.declarations.map((declaration_) => declaration_.property), ['padding']);
  assert.equal(result.declarations[0].valid, true);
});

test('a lost semicolon merges lines into one invalid declaration that holds both', () => {
  const result = analyze('padding: 2rem\ngap: 1rem;');
  assert.equal(result.declarations.length, 1);
  assert.equal(result.declarations[0].valid, false);
  assert.ok(result.held.has('gap'));
});

// --- Validation: the server mirror at the :/; boundary ------------------------

test('constraint violations are error diagnostics with the guide hint', () => {
  const result = analyze('padding: 9rem;');
  const errors = errorsOf(result);
  assert.equal(errors.length, 1);
  assert.equal(errors[0].code, 'STYLE_VALUE_ABOVE_MAX');
  assert.match(errors[0].message, /at most 4rem/);
  assert.equal('padding: 9rem;'.slice(errors[0].from, errors[0].to), '9rem');
  assert.equal(result.declarations[0].valid, false);
});

test('allowed-list and unit violations mirror the server codes', () => {
  assert.equal(errorsOf(analyze('color: hotpink;'))[0].code, 'STYLE_VALUE_NOT_ALLOWED');
  assert.equal(errorsOf(analyze('padding: 2em;'))[0].code, 'STYLE_UNIT_NOT_ALLOWED');
});

test('an empty value is invalid and holds only itself', () => {
  const result = analyze('color:;\ngap: 1rem;');
  assert.equal(result.declarations[0].valid, false);
  assert.equal(result.declarations[1].valid, true);
  assert.equal(errorsOf(result).length, 1);
});

test('unrecognized properties cannot commit through an island', () => {
  const open = analyze('paddding: 2rem;');
  assert.equal(errorsOf(open)[0].code, 'INVALID_STYLE_PROPERTY');
  assert.match(errorsOf(open)[0].message, /Check the spelling/);
  const strict = analyze('paddding: 2rem;', { guide: strictGuide });
  assert.equal(errorsOf(strict)[0].code, 'INVALID_STYLE_PROPERTY');
  assert.match(errorsOf(strict)[0].message, /enforces its style guide/);
  // Custom properties keep their existing posture: not wide tier.
  assert.equal(errorsOf(analyze('--brand: #1a1a2e;'))[0].code, 'INVALID_STYLE_PROPERTY');
});

// --- The wide tier: properties outside the guide payload's groups ------------

test('wide tier: a standard property outside the guide groups is accepted on an open site', () => {
  const result = analyze('text-wrap: balance;\ntransition-duration: 0.2s, 1s;');
  assert.deepEqual(errorsOf(result), []);
  assert.equal(result.declarations[0].valid, true);
  assert.equal(result.declarations[1].valid, true);
});

test('wide tier: grammar misses are warnings, never errors (server sovereign)', () => {
  const result = analyze('transition-duration: red;');
  assert.deepEqual(errorsOf(result), []);
  assert.equal(result.declarations[0].valid, true);
  const warnings = result.diagnostics.filter((diagnostic) => diagnostic.severity === 'warning');
  assert.equal(warnings.length, 1);
  assert.match(warnings[0].message, /does not parse as a transition-duration value/);
});

test('wide tier: emit-gate hygiene still blocks', () => {
  const result = analyze('background-image: url(javascript:alert(1));');
  assert.equal(result.declarations[0].valid, false);
  assert.equal(errorsOf(result)[0].code, 'INVALID_STYLE_VALUE');
});

test('wide tier: strict without a granting generated group mirrors STYLE_PROPERTY_NOT_DECLARED', () => {
  const result = analyze('text-wrap: balance;', { guide: strictGuide });
  assert.equal(errorsOf(result)[0].code, 'STYLE_PROPERTY_NOT_DECLARED');
});

test('wide tier: strict with a generated group granting the name accepts, allowed constraint binds', () => {
  // The roster serves a generated group's granted names exactly like a
  // curated group's, so the closed-world gate is one read for both
  // tiers: a wide name the group does not grant is refused.
  const wideDeclaration = {
    grants: { typography: TYPOGRAPHY, 'wide:transitions': ['transition-duration', 'transition-property'] },
    valueSpaces: { 'transition-duration': { allowed: ['0.2s'] } },
  };
  const ok = analyze('transition-duration: 0.2s;', { guide: strictGuide, declaration: wideDeclaration });
  assert.deepEqual(errorsOf(ok), []);
  const constrained = analyze('transition-duration: 1s;', { guide: strictGuide, declaration: wideDeclaration });
  assert.equal(errorsOf(constrained)[0].code, 'STYLE_VALUE_NOT_ALLOWED');
  const ungranted = analyze('text-wrap: balance;', { guide: strictGuide, declaration: wideDeclaration });
  assert.equal(errorsOf(ungranted)[0].code, 'STYLE_PROPERTY_NOT_DECLARED');
});

test('strict: ungranted properties are blocking errors', () => {
  const strictDeclaration = { grants: { typography: TYPOGRAPHY } };
  const errors = errorsOf(analyze('padding: 2rem;', { guide: strictGuide, declaration: strictDeclaration }));
  assert.equal(errors[0].code, 'STYLE_PROPERTY_NOT_DECLARED');
});

test('var() and CSS-wide keywords pass constraint checks', () => {
  assert.deepEqual(errorsOf(analyze('color: var(--brand);\npadding: inherit;')), []);
});

test('csstree grammar misses are warnings, never errors', () => {
  const result = analyze('gap: bogus-word;');
  assert.equal(errorsOf(result).length, 1, 'the dimension type check still errors');
  const structural = analyze('font-weight: bold;');
  assert.deepEqual(errorsOf(structural), []);
  assert.equal(structural.declarations[0].valid, true);
});

// --- The resolved layers: lines with provenance -------------------------------

const VARIANTS = [{
  id: 'aqua',
  label: 'Aqua',
  value: {
    '': { default: { padding: '2rem', color: '#1a1a2e' }, hover: { color: 'var(--brand)' } },
    '::.quote': { default: { 'font-size': '1.25rem' } },
    'region(first)': { default: { gap: '1rem' } },
  },
}];

test('lines are the layered document: variant layers first, own additions last', () => {
  const map = { [KEY]: { variants: [{ variant: 'aqua', fragment: '' }], default: { gap: '1rem' } } };
  assert.deepEqual(islandLines(map, KEY, 'default', VARIANTS), [
    { property: 'padding', value: '2rem', layer: variantLayerId('aqua'), variant: 'aqua', lost: false, winner: null },
    { property: 'color', value: '#1a1a2e', layer: variantLayerId('aqua'), variant: 'aqua', lost: false, winner: null },
    { property: 'gap', value: '1rem', layer: ELEMENT_LAYER, variant: null, lost: false, winner: null },
  ]);
  assert.deepEqual(islandLines(map, KEY, 'hover', VARIANTS), [
    { property: 'color', value: 'var(--brand)', layer: variantLayerId('aqua'), variant: 'aqua', lost: false, winner: null },
  ]);
});

test('an own override prints AFTER the variant line it shadows, which is lost', () => {
  const map = { [KEY]: { variants: [{ variant: 'aqua', fragment: '' }], default: { color: 'var(--brand)' } } };
  assert.deepEqual(islandLines(map, KEY, 'default', VARIANTS), [
    { property: 'padding', value: '2rem', layer: variantLayerId('aqua'), variant: 'aqua', lost: false, winner: null },
    { property: 'color', value: '#1a1a2e', layer: variantLayerId('aqua'), variant: 'aqua', lost: true, winner: { layer: ELEMENT_LAYER, variant: null } },
    { property: 'color', value: 'var(--brand)', layer: ELEMENT_LAYER, variant: null, lost: false, winner: null },
  ]);
});

test('a part island resolves through the root wearing at its own suffix', () => {
  const map = { [KEY]: { variants: [{ variant: 'aqua', fragment: '' }] } };
  assert.deepEqual(islandLines(map, SUB, 'default', VARIANTS), [
    { property: 'font-size', value: '1.25rem', layer: variantLayerId('aqua'), variant: 'aqua', lost: false, winner: null },
  ]);
});

test('a distributed {variant, fragment} reference resolves its fragment', () => {
  const child = 'region:33333333-3333-3333-3333-333333333333';
  const map = { [child]: { variants: [{ variant: 'aqua', fragment: 'region(first)' }] } };
  assert.deepEqual(islandLines(map, child, 'default', VARIANTS), [
    { property: 'gap', value: '1rem', layer: variantLayerId('aqua'), variant: 'aqua', lost: false, winner: null },
  ]);
});

test('delivery order decides the winner when axes overlap; the loser is lost', () => {
  const library = [
    { id: 'aa_pop', group: 'emphasis', value: { '': { default: { color: '#1a1a2e' } } } },
    { id: 'zz_calm', group: 'style', value: { '': { default: { color: 'var(--brand)', padding: '1rem' } } } },
  ];
  const map = { [KEY]: { variants: [{ variant: 'aa_pop', fragment: '' }, { variant: 'zz_calm', fragment: '' }] } };
  const lines = islandLines(map, KEY, 'default', library, ['style', 'emphasis']);
  assert.deepEqual(lines, [
    { property: 'color', value: 'var(--brand)', layer: variantLayerId('zz_calm'), variant: 'zz_calm', lost: true, winner: { layer: variantLayerId('aa_pop'), variant: 'aa_pop' } },
    { property: 'padding', value: '1rem', layer: variantLayerId('zz_calm'), variant: 'zz_calm', lost: false, winner: null },
    { property: 'color', value: '#1a1a2e', layer: variantLayerId('aa_pop'), variant: 'aa_pop', lost: false, winner: null },
  ]);
  // Without the vocabulary the stored list decides.
  const stored = islandLines(map, KEY, 'default', library);
  assert.deepEqual(stored[0], { property: 'color', value: '#1a1a2e', layer: variantLayerId('aa_pop'), variant: 'aa_pop', lost: true, winner: { layer: variantLayerId('zz_calm'), variant: 'zz_calm' } });
  assert.deepEqual(stored[1], { property: 'color', value: 'var(--brand)', layer: variantLayerId('zz_calm'), variant: 'zz_calm', lost: false, winner: null });
});

// --- The defaults floor: the bottom layers ------------------------------------
// The caller hands the floor as the raw condition-keyed entries
// skinDefaultEntry() / declaredDefaultEntry() build; the engine prints
// them beneath every map line, declared under skin, at the exact
// condition. A floor line is display only: no op ever addresses it.

const FLOOR = {
  declaredEntry: { default: { margin: '0', gap: '0.5rem' }, hover: { color: 'gray' } },
  skinEntry: { default: { gap: 'var(--space-gutter)', padding: '1rem' }, narrow: { padding: '0' } },
};

test('the floor prints at the bottom: declared under skin, both under every map line', () => {
  const map = { [KEY]: { variants: [{ variant: 'aqua', fragment: '' }], default: { gap: '1rem' } } };
  const lines = islandLines(map, KEY, 'default', VARIANTS, null, FLOOR);
  assert.deepEqual(lines.map((line) => [line.layer, line.property, line.lost]), [
    [DECLARED_LAYER, 'margin', false],
    [DECLARED_LAYER, 'gap', true],
    [SKIN_LAYER, 'gap', true],
    [SKIN_LAYER, 'padding', true],
    [variantLayerId('aqua'), 'padding', false],
    [variantLayerId('aqua'), 'color', false],
    [ELEMENT_LAYER, 'gap', false],
  ]);
  assert.deepEqual(lines[0], { property: 'margin', value: '0', layer: DECLARED_LAYER, variant: null, lost: false, winner: null });
  // Both floor gaps lose to the element's own; the skin's padding loses
  // to the worn variant's.
  assert.deepEqual(lines[1].winner, { layer: ELEMENT_LAYER, variant: null });
  assert.deepEqual(lines[2].winner, { layer: ELEMENT_LAYER, variant: null });
  assert.deepEqual(lines[3].winner, { layer: variantLayerId('aqua'), variant: 'aqua' });
});

test('a declared line the skin beats strikes with the skin as its winner', () => {
  assert.deepEqual(islandLines({}, KEY, 'default', [], null, FLOOR), [
    { property: 'margin', value: '0', layer: DECLARED_LAYER, variant: null, lost: false, winner: null },
    { property: 'gap', value: '0.5rem', layer: DECLARED_LAYER, variant: null, lost: true, winner: { layer: SKIN_LAYER, variant: null } },
    { property: 'gap', value: 'var(--space-gutter)', layer: SKIN_LAYER, variant: null, lost: false, winner: null },
    { property: 'padding', value: '1rem', layer: SKIN_LAYER, variant: null, lost: false, winner: null },
  ]);
});

test('the floor is read at the exact condition, never the cascade', () => {
  assert.deepEqual(islandLines({}, KEY, 'hover', [], null, FLOOR), [
    { property: 'color', value: 'gray', layer: DECLARED_LAYER, variant: null, lost: false, winner: null },
  ]);
  assert.deepEqual(islandLines({}, KEY, 'narrow', [], null, FLOOR), [
    { property: 'padding', value: '0', layer: SKIN_LAYER, variant: null, lost: false, winner: null },
  ]);
  // No floor handed (a sparse tree row, the roster's occupancy read): the
  // map's lines only.
  assert.deepEqual(islandLines({}, KEY, 'default', []), []);
  assert.deepEqual(islandLines({}, KEY, 'default', [], null, { skinEntry: null, declaredEntry: null }), []);
});

test('the printed floor round-trips to zero ops', () => {
  const lines = islandLines({}, KEY, 'default', [], null, FLOOR);
  const text = printIsland(lines);
  assert.equal(text, 'margin: 0;\ngap: 0.5rem;\ngap: var(--space-gutter);\npadding: 1rem;');
  assert.deepEqual(errorsOf(analyze(text)), []);
  assert.deepEqual(islandOps(KEY, 'default', analyze(text), lines), []);
});

// --- Round trip: print → analyze → diff to nothing ----------------------------

test('the printed island round-trips to zero ops, variant lines included', () => {
  const map = { [KEY]: { variants: [{ variant: 'aqua', fragment: '' }], default: { gap: '1rem' } } };
  const lines = islandLines(map, KEY, 'default', VARIANTS);
  const text = printIsland(lines);
  assert.equal(text, 'padding: 2rem;\ncolor: #1a1a2e;\ngap: 1rem;');
  const analysis = analyze(text);
  assert.deepEqual(errorsOf(analysis), []);
  assert.deepEqual(islandOps(KEY, 'default', analysis, lines), []);
});

test('a shadowed pair round-trips to zero ops (the cascade reads last-wins)', () => {
  const map = { [KEY]: { variants: [{ variant: 'aqua', fragment: '' }], default: { color: 'var(--brand)' } } };
  const lines = islandLines(map, KEY, 'default', VARIANTS);
  const text = printIsland(lines);
  assert.equal(text, 'padding: 2rem;\ncolor: #1a1a2e;\ncolor: var(--brand);');
  assert.deepEqual(islandOps(KEY, 'default', analyze(text), lines), []);
});

test('formatting-only edits diff to nothing (whitespace, blank lines, reorder)', () => {
  const lines = [
    { property: 'padding', value: '2rem', layer: ELEMENT_LAYER, variant: null },
    { property: 'gap', value: '1rem', layer: ELEMENT_LAYER, variant: null },
  ];
  const reformatted = analyze('\n\ngap: 1rem;\n\n  padding:   2rem  ;\n');
  assert.deepEqual(islandOps(KEY, 'default', reformatted, lines), []);
});

// --- The diff: per-declaration ops --------------------------------------------

test('each changed declaration is its own single-property op', () => {
  const lines = [
    { property: 'padding', value: '2rem', layer: ELEMENT_LAYER, variant: null },
    { property: 'gap', value: '1rem', layer: ELEMENT_LAYER, variant: null },
  ];
  const analysis = analyze('padding: 3rem;\ngap: 2rem;\nmargin: 1rem;');
  assert.deepEqual(islandOps(KEY, 'default', analysis, lines), [
    { op: 'update_style', key: KEY, properties: { padding: '3rem' } },
    { op: 'update_style', key: KEY, properties: { gap: '2rem' } },
    { op: 'update_style', key: KEY, properties: { margin: '1rem' } },
  ]);
});

test('a named condition rides every op; default stays bare', () => {
  const ops = islandOps(KEY, 'hover', analyze('color: #1a1a2e;'), []);
  assert.deepEqual(ops, [
    { op: 'update_style', key: KEY, condition: 'hover', properties: { color: '#1a1a2e' } },
  ]);
});

test('deleting an own line unsets it; deleting a variant line honestly no-ops', () => {
  const lines = [
    { property: 'padding', value: '2rem', layer: variantLayerId('aqua'), variant: 'aqua', lost: false, winner: null },
    { property: 'gap', value: '1rem', layer: ELEMENT_LAYER, variant: null, lost: false, winner: null },
  ];
  assert.deepEqual(islandOps(KEY, 'default', analyze(''), lines), [
    { op: 'update_style', key: KEY, properties: { gap: null } },
  ]);
});

test('editing a variant line commits an override; the reference stays untouched', () => {
  const map = { [KEY]: { variants: [{ variant: 'aqua', fragment: '' }] } };
  const lines = islandLines(map, KEY, 'default', VARIANTS);
  const text = printIsland(lines).replace('color: #1a1a2e;', 'color: var(--brand);');
  const ops = islandOps(KEY, 'default', analyze(text), lines);
  assert.deepEqual(ops, [
    { op: 'update_style', key: KEY, properties: { color: 'var(--brand)' } },
  ]);
});

test('deleting a shadowed pair entirely unsets the override', () => {
  const map = { [KEY]: { variants: [{ variant: 'aqua', fragment: '' }], default: { color: 'var(--brand)' } } };
  const lines = islandLines(map, KEY, 'default', VARIANTS);
  const text = printIsland(lines.filter((line) => line.property !== 'color'));
  assert.deepEqual(islandOps(KEY, 'default', analyze(text), lines), [
    { op: 'update_style', key: KEY, properties: { color: null } },
  ]);
});

test('deleting only the own winner lifts the override; the struck variant text stands', () => {
  // The follow-the-variant-again gesture: the buffer shrank to exactly the
  // variant's line, so the diff reads an unset, never an override set to
  // the variant's own value.
  const map = { [KEY]: { variants: [{ variant: 'aqua', fragment: '' }], default: { color: 'var(--brand)' } } };
  const lines = islandLines(map, KEY, 'default', VARIANTS);
  const text = printIsland(lines.filter((line) => !(line.property === 'color' && line.layer === ELEMENT_LAYER)));
  assert.equal(text, 'padding: 2rem;\ncolor: #1a1a2e;');
  assert.deepEqual(islandOps(KEY, 'default', analyze(text), lines), [
    { op: 'update_style', key: KEY, properties: { color: null } },
  ]);
});

test('editing only a LOST line diffs to nothing: the cascade still shows the winner', () => {
  const map = { [KEY]: { variants: [{ variant: 'aqua', fragment: '' }], default: { padding: '3rem' } } };
  const lines = islandLines(map, KEY, 'default', VARIANTS);
  const text = printIsland(lines).replace('padding: 2rem;', 'padding: 1rem;');
  assert.equal(text, 'padding: 1rem;\ncolor: #1a1a2e;\npadding: 3rem;');
  assert.deepEqual(islandOps(KEY, 'default', analyze(text), lines), []);
});

test('editing a floor line is a SET on the own layer; its text vanishing is a no-op; never an unset', () => {
  const lines = islandLines({}, KEY, 'default', [], null, FLOOR);
  const text = printIsland(lines);
  assert.deepEqual(islandOps(KEY, 'default', analyze(text.replace('padding: 1rem;', 'padding: 2rem;')), lines), [
    { op: 'update_style', key: KEY, properties: { padding: '2rem' } },
  ]);
  assert.deepEqual(islandOps(KEY, 'default', analyze(text.replace('padding: 1rem;', '')), lines), [], 'nothing in the map holds the skin line');
  assert.deepEqual(islandOps(KEY, 'default', analyze(''), lines), [], 'the whole floor vanishing unsets nothing');
});

test('deleting the own winner over a floor line lifts the override; the floor line stands', () => {
  const map = { [KEY]: { default: { gap: '1rem' } } };
  const lines = islandLines(map, KEY, 'default', [], null, FLOOR);
  const text = printIsland(lines.filter((line) => line.layer !== ELEMENT_LAYER));
  assert.equal(text, 'margin: 0;\ngap: 0.5rem;\ngap: var(--space-gutter);\npadding: 1rem;');
  assert.deepEqual(islandOps(KEY, 'default', analyze(text), lines), [
    { op: 'update_style', key: KEY, properties: { gap: null } },
  ]);
});

test('an invalid line holds only itself; settled neighbors still commit', () => {
  const lines = [{ property: 'padding', value: '2rem', layer: ELEMENT_LAYER, variant: null }];
  const analysis = analyze('padding: 9rem;\ngap: 1rem;');
  // padding's new value violates the max: it neither commits nor deletes;
  // gap settles fine.
  assert.deepEqual(islandOps(KEY, 'default', analysis, lines), [
    { op: 'update_style', key: KEY, properties: { gap: '1rem' } },
  ]);
});

test('a half-retyped line never reads as a deletion', () => {
  const lines = [
    { property: 'color', value: '#1a1a2e', layer: ELEMENT_LAYER, variant: null },
    { property: 'padding', value: '2rem', layer: ELEMENT_LAYER, variant: null },
  ];
  // The user deleted ": #1a1a2e;" and is retyping; padding was really
  // removed.
  assert.deepEqual(islandOps(KEY, 'default', analyze('color'), lines), [
    { op: 'update_style', key: KEY, properties: { padding: null } },
  ]);
});

test('duplicate properties settle to the last valid value (the cascade)', () => {
  const analysis = analyze('padding: 1rem;\npadding: 2rem;');
  assert.deepEqual(islandOps(KEY, 'default', analysis, []), [
    { op: 'update_style', key: KEY, properties: { padding: '2rem' } },
  ]);
});

test('an unrecognized property emits nothing: its island cannot carry it', () => {
  const analysis = analyze('paddding: 2rem;');
  assert.deepEqual(islandOps(KEY, 'default', analysis, []), []);
});

test('a wide-tier property commits like any recognized one', () => {
  const analysis = analyze('text-wrap: balance;');
  assert.deepEqual(islandOps(KEY, 'default', analysis, []), [
    { op: 'update_style', key: KEY, properties: { 'text-wrap': 'balance' } },
  ]);
});

// --- matchLines: the buffer <-> baseline pairing the view decorates from ------

test('matchLines pairs declarations with lines first-fit; edits pair with nothing', () => {
  const lines = [
    { property: 'padding', value: '1rem', layer: variantLayerId('aqua'), variant: 'aqua', lost: true, winner: { layer: ELEMENT_LAYER, variant: null } },
    { property: 'padding', value: '2rem', layer: ELEMENT_LAYER, variant: null, lost: false, winner: null },
  ];
  const analysis = analyze('padding: 1rem;\npadding: 2rem;\nmargin: 4px;');
  const matched = matchLines(analysis.declarations, lines);
  assert.equal(matched[0], lines[0]);
  assert.equal(matched[1], lines[1]);
  assert.equal(matched[2], null);
  // A duplicate pairs once: the second identical declaration is the edit.
  const doubled = analyze('padding: 2rem;\npadding: 2rem;');
  const twice = matchLines(doubled.declarations, lines);
  assert.equal(twice[0], lines[1]);
  assert.equal(twice[1], null);
});

// --- At-rules: honest errors, never island text ------------------------------

test('a known-condition at-rule errors toward its WHEN row', () => {
  const result = analyze('@hover { color: #1a1a2e; }');
  const errors = errorsOf(result);
  assert.equal(errors.length, 1);
  assert.match(errors[0].message, /pick "hover"/);
  assert.ok(result.held.has('color'));
});

test('an unknown at-rule is an honest error (no raw-CSS escape hatch)', () => {
  const result = analyze('@supports (display: grid) { gap: 1rem; }');
  const errors = errorsOf(result);
  assert.equal(errors.length, 1);
  assert.match(errors[0].message, /@supports.*not a condition/);
  assert.ok(result.held.has('gap'));
});

// --- validate-value parity spot checks ---------------------------------------
// validate-value.js is the server mirror analyzeIsland lints through; these
// pin its PropertyGroupBase semantics directly.

test('validate-value mirrors PropertyGroupBase semantics', () => {
  const dimension = { label: 'Padding', type: 'dimension', repeat: 4 };
  assert.equal(validateValue('padding', '1rem 2rem', dimension, null).length, 0);
  assert.equal(validateValue('padding', '1rem 2rem 3rem 4rem 5rem', dimension, null)[0].code, 'INVALID_STYLE_VALUE');
  assert.equal(validateValue('padding', '-1rem', dimension, null)[0].code, 'INVALID_STYLE_VALUE');
  assert.equal(validateValue('margin', '-1rem', { type: 'dimension', negative: true }, null).length, 0);
  assert.equal(validateValue('padding', '32px', dimension, { max: '1rem' })[0].code, 'STYLE_VALUE_ABOVE_MAX');
  assert.equal(validateValue('padding', '2rem', dimension, { max: '32px' }).length, 0);
  assert.equal(validateValue('font-weight', '450', { type: 'number' }, { min: 100, max: 900 }).length, 0);
  // A definition's own range (opacity's 0..1) checks with no guide
  // constraint at all; a guide block layers over it per key.
  assert.equal(validateValue('opacity', '0.5', { type: 'number', max: 1 }, null).length, 0);
  assert.equal(validateValue('opacity', '1.5', { type: 'number', max: 1 }, null)[0].code, 'STYLE_VALUE_ABOVE_MAX');
  assert.equal(validateValue('opacity', '0.9', { type: 'number', max: 1 }, { max: 0.8 })[0].code, 'STYLE_VALUE_ABOVE_MAX');
  assert.equal(validateValue('text-align', 'middle', { type: 'keyword', keywords: ['left', 'center'] })[0].code, 'INVALID_STYLE_VALUE');
  assert.equal(validateValue('color', 'rebeccapurple', { type: 'color' }, null).length, 0);
  assert.equal(validateValue('box-shadow', 'expression(alert(1))', { type: 'text' }, null)[0].code, 'INVALID_STYLE_VALUE');
  assert.equal(validateValue('padding', 'red; } <script>', dimension, null)[0].code, 'INVALID_STYLE_VALUE');
});

test('validate-value mirrors the list, ident, and dimension|number shapes', () => {
  // A list dimension: comma and space separated repetition, per-component
  // validation and range checks; malformed comma segments are the list's
  // own error.
  const durations = { type: 'dimension', list: true };
  assert.equal(validateValue('transition-duration', '0.3s, 1s, 250ms', durations, null).length, 0);
  assert.equal(validateValue('transition-duration', '0.3s 1s', durations, null).length, 0);
  assert.equal(validateValue('transition-duration', 'fast, 1s', durations, null)[0].code, 'INVALID_STYLE_VALUE');
  assert.equal(validateValue('transition-duration', '0.3s,, 1s', durations, null)[0].code, 'INVALID_STYLE_VALUE');
  assert.equal(validateValue('transition-duration', '0.3s,', durations, null)[0].code, 'INVALID_STYLE_VALUE');
  assert.equal(validateValue('transition-duration', '0.2s, 1s', durations, { max: '0.5s' })[0].code, 'STYLE_VALUE_ABOVE_MAX');
  assert.equal(validateValue('transition-duration', 'var(--speed), 1s', durations, null).length, 0);
  // Function notation stays one component: one error, not per-argument.
  assert.equal(validateValue('transition-timing-function', 'cubic-bezier(0.4, 0, 0.2, 1)', { type: 'keyword', list: true, keywords: ['ease'] }, null).length, 1);
  // Ident: identifier grammar, reserved words, keyword rider.
  const name = { type: 'ident', keywords: ['none'] };
  assert.equal(validateValue('view-transition-name', 'hero-image', name, null).length, 0);
  assert.equal(validateValue('view-transition-name', 'none', name, null).length, 0);
  assert.equal(validateValue('view-transition-name', 'default', name, null)[0].code, 'INVALID_STYLE_VALUE');
  assert.equal(validateValue('view-transition-name', '2fast', name, null)[0].code, 'INVALID_STYLE_VALUE');
  assert.equal(validateValue('view-transition-name', '--custom', name, null)[0].code, 'INVALID_STYLE_VALUE');
  assert.equal(validateValue('will-change', 'opacity, transform', { type: 'ident', list: true, keywords: ['auto'] }, null).length, 0);
  // The dimension|number union: bare numbers pass, negativity still gated.
  const union = { type: 'dimension', number: true };
  assert.equal(validateValue('tab-size', '4', union, null).length, 0);
  assert.equal(validateValue('tab-size', '2rem', union, null).length, 0);
  assert.equal(validateValue('tab-size', '-3', union, null)[0].code, 'INVALID_STYLE_VALUE');
  assert.equal(validateValue('tab-size', '3', union, { max: '2' })[0].code, 'STYLE_VALUE_ABOVE_MAX');
});

test('validate-value mirrors the keyframes-name shape', () => {
  // animation_name: the ident grammar with the keyword rider and its own
  // corrective (PropertyGroupBase::validateAnimationName); a list, so a
  // multi-name value validates per component. No materials context here:
  // the reference check defers to the server, only the shape speaks.
  const name = { type: 'animation_name', list: true, keywords: ['none'] };
  assert.equal(validateValue('animation-name', 'motion-fade_in', name, null).length, 0);
  assert.equal(validateValue('animation-name', 'none', name, null).length, 0);
  assert.equal(validateValue('animation-name', 'spin', name, null).length, 0, 'a theme keyframes name is a valid spelling');
  assert.equal(validateValue('animation-name', 'motion-fade_in, motion-rise', name, null).length, 0);
  const invalid = validateValue('animation-name', '2fast', name, null);
  assert.equal(invalid[0].code, 'INVALID_STYLE_VALUE');
  assert.match(invalid[0].message, /is not a keyframes name for animation-name/);
  assert.match(invalid[0].hint, /motion-\{name\}/);
  assert.equal(validateValue('animation-name', '--custom', name, null)[0].code, 'INVALID_STYLE_VALUE');
  assert.equal(validateValue('animation-name', 'default', name, null)[0].code, 'INVALID_STYLE_VALUE');
  // One bad component in a list reports once, for that component.
  const mixed = validateValue('animation-name', 'motion-fade_in, 2fast', name, null);
  assert.equal(mixed.length, 1);
  assert.match(mixed[0].message, /"2fast"/);
});

// --- The Motion section's lint: keyframes names against the roster -------

const motionGuide = (enforcement) => ({
  ...guide,
  enforcement,
  groups: {
    ...guide.groups,
    motion: {
      label: 'Motion',
      properties: {
        animation: { label: 'Animation', type: 'text', keywords: ['none'] },
        'animation-name': { label: 'Animation name', type: 'animation_name', list: true, keywords: ['none'] },
      },
    },
  },
});
const motionDeclaration = { grants: { spacing: SPACING, motion: ['animation', 'animation-name'] } };
const motionMaterials = roster(
  animationRecord('fade_in', 'Fade in', { '0%': { opacity: '0' }, '100%': { opacity: '1' } }),
);
const lintMotion = (text, enforcement) => analyzeIsland(text, { guide: motionGuide(enforcement), materials: motionMaterials, declaration: motionDeclaration });

test('a published animation and none are clean on both postures', () => {
  for (const enforcement of ['guarded', 'strict']) {
    const clean = lintMotion('animation-name: motion-fade_in;\nanimation: none;', enforcement);
    assert.deepEqual(clean.diagnostics, []);
    assert.equal(clean.declarations.every((declaration) => declaration.valid), true);
  }
});

test('an unpublished motion name warns permissive and errors strict', () => {
  const warned = lintMotion('animation-name: motion-missing;', 'guarded');
  assert.equal(warned.declarations[0].valid, true, 'the line still commits; the server accepts an inert reference');
  assert.equal(warned.diagnostics.length, 1);
  assert.equal(warned.diagnostics[0].severity, 'warning');
  assert.equal(warned.diagnostics[0].code, 'STYLE_ANIMATION_NOT_PUBLISHED');
  const blocked = lintMotion('animation-name: motion-missing;', 'strict');
  assert.equal(blocked.declarations[0].valid, false);
  assert.equal(blocked.diagnostics[0].severity, 'error');
  assert.equal(blocked.diagnostics[0].code, 'STYLE_ANIMATION_NOT_PUBLISHED');
});

test('a theme keyframes name passes permissive and is refused strict', () => {
  assert.deepEqual(lintMotion('animation-name: spin;', 'guarded').diagnostics, []);
  const blocked = lintMotion('animation-name: spin;', 'strict');
  assert.equal(blocked.declarations[0].valid, false);
  assert.match(blocked.diagnostics[0].message, /"spin" is not a site animation/);
});

test('the shorthand lints its name layers; the other layers stay grammar', () => {
  const clean = lintMotion('animation: motion-fade_in 600ms ease-out both;', 'strict');
  assert.deepEqual(clean.diagnostics, []);
  const layered = lintMotion('animation: motion-fade_in 600ms ease-out both, motion-missing 1s infinite;', 'guarded');
  assert.equal(layered.declarations[0].valid, true);
  assert.equal(layered.diagnostics.length, 1);
  assert.equal(layered.diagnostics[0].code, 'STYLE_ANIMATION_NOT_PUBLISHED');
  assert.match(layered.diagnostics[0].message, /"motion-missing"/);
});

test('a multi-name value is honest: each name reports on its own', () => {
  const mixed = lintMotion('animation-name: motion-fade_in, spin;', 'strict');
  assert.equal(mixed.declarations[0].valid, false);
  assert.equal(mixed.diagnostics.length, 1);
  assert.match(mixed.diagnostics[0].message, /"spin"/);
});

test('the motion check never reaches properties outside the motion vocabulary', () => {
  // A bare ident on another property is that property's own grammar (the
  // property-scoped slot rule): no animation diagnostic, whatever the
  // spelling looks like.
  const lint = analyzeIsland('gap: 1rem;', { guide: motionGuide('strict'), materials: motionMaterials, declaration: motionDeclaration });
  assert.deepEqual(lint.diagnostics, []);
});

process.stdout.write('# all island tests passed\n');
