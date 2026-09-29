/**
 * Self-asserting tests for the styles clipboard's pure half (copy-styles.js).
 *
 * Run directly (`node tests/js/copy-styles.test.mjs`) or via JsSuitesTest
 * in the PHP tier. Locks what a copy reads out of the map (the target's
 * own entries and root wear, a rule's continuations, instance parts and
 * fragment references left behind), the key a target names, and what a
 * paste plans at another key (relatives rebased, one update_style per
 * condition and one apply_variant per reference, the strict-site grant
 * filter and the scope pairing counted as skipped). Exits non-zero on
 * failure.
 */
import { strict as assert } from 'node:assert';
import {
  documentOf,
  documentSize,
  pastePlan,
  targetKeyOf,
} from '../../js/src/copy-styles.js';

const A = 'block:11111111-1111-1111-1111-111111111111';
const B = 'block:22222222-2222-2222-2222-222222222222';
const SECTION = 'container:33333333-3333-3333-3333-333333333333';
const RULE = `${SECTION}::> pb-text`;

const map = {
  [A]: {
    variants: [
      { variant: 'hero', fragment: '' },
      { variant: 'cols', fragment: 'x', via: 'composition' },
    ],
    default: { color: '#0a55f0', padding: '2rem' },
    hover: { color: '#ff8800' },
  },
  [`${A}::.title`]: { default: { 'font-weight': '700' } },
  [`${A}::.s-ab12`]: { default: { color: 'red' } },
  [`${A}::> pb-text`]: { variants: [{ variant: 'link_style', fragment: '' }] },
  [B]: { default: { margin: '1rem' } },
  [RULE]: {
    variants: [{ variant: 'quiet', fragment: '' }],
    default: { 'font-size': '1rem' },
  },
  [`${RULE} .link`]: { default: { 'text-decoration': 'none' } },
  [`${SECTION}::> pb-image`]: { default: { opacity: '0.5' } },
};

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

// --- The target key ----------------------------------------------------------

test('the target key is the selection, or the seated target as a rule key', () => {
  assert.equal(targetKeyOf(null, null), null);
  assert.equal(targetKeyOf(A, null), A);
  assert.equal(targetKeyOf(A, { key: A, selector: null }), A);
  assert.equal(targetKeyOf(A, { key: A, selector: '.title' }), `${A}::.title`);
  assert.equal(targetKeyOf(A, { key: SECTION, selector: '> pb-text' }), RULE);
});

// --- Copy --------------------------------------------------------------------

test('an element copy carries its own entries, its parts and its root wear; instance parts and fragment refs stay', () => {
  const copied = documentOf(map, A);
  assert.deepEqual(copied.entries, {
    '': { default: { color: '#0a55f0', padding: '2rem' }, hover: { color: '#ff8800' } },
    '::.title': { default: { 'font-weight': '700' } },
  });
  assert.deepEqual(copied.wear, { '': ['hero'], '::> pb-text': ['link_style'] });
  assert.equal(documentSize(copied), 6);
});

test('the copy is a snapshot: later map edits do not reach it', () => {
  const copied = documentOf(map, A);
  map[A].default.color = 'changed';
  assert.equal(copied.entries[''].default.color, '#0a55f0');
  map[A].default.color = '#0a55f0';
});

test('a rule copy carries its entry, its wear and its continuations by descent only', () => {
  const copied = documentOf(map, RULE);
  assert.deepEqual(copied.entries, {
    '': { default: { 'font-size': '1rem' } },
    '::.link': { default: { 'text-decoration': 'none' } },
  });
  assert.deepEqual(copied.wear, { '': ['quiet'] });
});

test('an unstyled key copies as an empty document', () => {
  const copied = documentOf(map, 'block:44444444-4444-4444-4444-444444444444');
  assert.deepEqual(copied, { entries: {}, wear: {} });
  assert.equal(documentSize(copied), 0);
});

// --- Paste -------------------------------------------------------------------

test('a paste rebases every relative under the target and lands as one additive batch', () => {
  const plan = pastePlan(documentOf(map, A), B);
  assert.deepEqual(plan.operations, [
    { op: 'update_style', key: B, properties: { color: '#0a55f0', padding: '2rem' } },
    { op: 'update_style', key: B, condition: 'hover', properties: { color: '#ff8800' } },
    { op: 'update_style', key: `${B}::.title`, properties: { 'font-weight': '700' } },
    { op: 'apply_variant', key: B, variant: 'hero' },
    { op: 'apply_variant', key: `${B}::> pb-text`, variant: 'link_style' },
  ]);
  assert.equal(plan.count, 6);
  assert.deepEqual(plan.skipped, { properties: 0, variants: 0 });
});

test('a paste at a rule continues the relatives by descent', () => {
  const plan = pastePlan(documentOf(map, A), RULE);
  assert.deepEqual(
    plan.operations.map((operation) => operation.key),
    [RULE, RULE, `${RULE} .title`, RULE, `${RULE} > pb-text`],
  );
});

test('on an open site every property is sent, the server being the judge', () => {
  const guide = { enforcement: 'guarded', kinds: { block: { grants: { typography: ['color'] } } } };
  const plan = pastePlan(documentOf(map, A), B, { guide });
  assert.equal(plan.operations[0].properties.padding, '2rem');
  assert.equal(plan.skipped.properties, 0);
});

test('on a strict site a property the landing does not grant is skipped and counted', () => {
  const guide = {
    enforcement: 'strict',
    kinds: {
      block: {
        grants: { typography: ['color', 'font-weight'] },
        elements: { '.title': { grants: { typography: ['font-weight'] } } },
      },
    },
  };
  const plan = pastePlan(documentOf(map, A), B, { guide });
  assert.deepEqual(
    plan.operations.filter((operation) => operation.op === 'update_style'),
    [
      { op: 'update_style', key: B, properties: { color: '#0a55f0' } },
      { op: 'update_style', key: B, condition: 'hover', properties: { color: '#ff8800' } },
      { op: 'update_style', key: `${B}::.title`, properties: { 'font-weight': '700' } },
    ],
  );
  assert.equal(plan.skipped.properties, 1);
  assert.equal(plan.count, 5);
});

test('a variant whose scope does not pair with the new key is skipped; an unknown one is sent', () => {
  const variants = [
    { id: 'hero', scope: 'layout:container' },
    { id: 'link_style', scope: 'base:a' },
  ];
  const plan = pastePlan(documentOf(map, A), B, { variants });
  assert.deepEqual(
    plan.operations.filter((operation) => operation.op === 'apply_variant'),
    [{ op: 'apply_variant', key: `${B}::> pb-text`, variant: 'link_style' }],
  );
  assert.deepEqual(plan.skipped, { properties: 0, variants: 1 });
  assert.equal(plan.count, 5);
});

test('an empty document plans nothing', () => {
  const plan = pastePlan({ entries: {}, wear: {} }, B);
  assert.deepEqual(plan, { operations: [], count: 0, skipped: { properties: 0, variants: 0 } });
});

process.stdout.write('copy-styles tests passed.\n');
