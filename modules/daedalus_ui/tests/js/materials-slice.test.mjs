/**
 * Self-asserting tests for the materials store's slice and its seams.
 *
 * The slice half locks the reducer's contract: a boot replaces the record
 * set wholesale in delivery order; an upsert of a known id whose (weight,
 * label) did not change keeps its seat (a value edit never reorders
 * anything), a rename or a new record is seated by (weight, label) among
 * its kind; a remove drops the record and a remove of an unknown id is a
 * no-op; a delta of the wrong shape or an unknown kind is refused loudly
 * (a delta built from the roster can never carry one). The selector half
 * locks that the roster index is rebuilt exactly when the records change.
 * The envelope half locks the idempotent string-equality boot the mirror
 * and the store share (settings-envelope.js).
 *
 * Run with `npm test` (node --test tests/js/*.test.mjs) from the module root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import reducer, {
  materialsBooted,
  materialsDelta,
  assertDelta,
  isEmptyDelta,
  selectRecords,
  selectRoster,
} from '../../js/edit_mode/store/slices/materials-slice.js';
import { createEnvelopeSync } from '../../js/edit_mode/utilities/settings-envelope.js';

const record = (fields) => ({
  kind: 'token', id: '', label: '', description: '', value: null, group: null, spellings: {}, scope: null, applies: [],
  owned_by: null, published: true, weight: 0, icon: null, origin: 'user', updatable: true, ...fields,
});

/**
 * A page-load payload in delivery order: tokens by declared scale (not
 * alphabetical), swatches by label, variants by weight then label.
 */
const RECORDS = [
  record({ kind: 'token', id: 'spacing/lg', label: 'lg', value: '2rem', group: 'spacing', spellings: { style: 'var(--spacing-lg)' }, origin: 'shipped' }),
  record({ kind: 'token', id: 'color/accent', label: 'accent', value: { default: '#1475ad', dark: '#52b3eb' }, group: 'color', spellings: { style: 'var(--color-accent)' }, origin: 'shipped' }),
  record({ kind: 'swatch', id: 'lime_green', label: 'Lime green', value: '#4dff8c', group: 'color', spellings: { style: 'var(--swatch-lime_green)' } }),
  record({ kind: 'variant', id: 'container_full', label: 'Full width', group: 'width', spellings: { 'attr:kind:container:width': 'full' }, scope: 'kind:container', applies: { depth: 'root' }, weight: -10, origin: 'shipped' }),
  record({ kind: 'variant', id: 'button', label: 'Button', group: 'style', spellings: { class: 'button' }, scope: 'block_type:text', origin: 'shipped' }),
  record({ kind: 'variant', id: 'card', label: 'Card', group: 'style', spellings: { class: 'card' }, scope: 'kind:block', origin: 'shipped' }),
];

const initial = reducer(undefined, { type: '@@INIT' });
const booted = () => reducer(initial, materialsBooted({ records: RECORDS }));
const ids = (state, kind) => state.records[kind].map((entry) => entry.id);
const rootState = (state) => ({ materials: state });

test('the initial state is empty and unbooted; every kind reads as an empty list', () => {
  assert.equal(initial.booted, false);
  assert.deepEqual(initial.records, { token: [], swatch: [], variant: [], blueprint: [], animation: [], font: [] });
  assert.deepEqual(selectRecords(rootState(initial)), []);
  assert.equal(selectRoster(rootState(initial)).isEmpty(), true);
});

test('a boot replaces the record set wholesale, per kind, in delivery order', () => {
  const state = booted();
  assert.equal(state.booted, true);
  assert.deepEqual(ids(state, 'token'), ['spacing/lg', 'color/accent']);
  assert.deepEqual(ids(state, 'swatch'), ['lime_green']);
  assert.deepEqual(ids(state, 'variant'), ['container_full', 'button', 'card']);
  assert.deepEqual(ids(state, 'blueprint'), []);
  const rebooted = reducer(state, materialsBooted({ records: [RECORDS[2]] }));
  assert.deepEqual(ids(rebooted, 'token'), []);
  assert.deepEqual(ids(rebooted, 'swatch'), ['lime_green']);
  assert.deepEqual(ids(rebooted, 'variant'), []);
});

test('boots counts the whole-set replacements; a delta leaves it', () => {
  // A subscriber tells a boot (the page's envelope, possibly carrying
  // other editors' records) from a delta (this session's own landing) by
  // the counter alone: the record set changes either way.
  assert.equal(initial.boots, 0);
  const state = booted();
  assert.equal(state.boots, 1);
  const minted = record({ kind: 'blueprint', id: 'hero', label: 'Hero', spellings: { blueprint: 'hero' } });
  const landed = reducer(state, materialsDelta({ upsert: [minted], remove: [] }));
  assert.equal(landed.boots, 1);
  assert.notEqual(landed.records, state.records);
  const rebooted = reducer(landed, materialsBooted({ records: RECORDS }));
  assert.equal(rebooted.boots, 2);
});

test('a boot refuses a record of an unknown kind', () => {
  assert.throws(
    () => reducer(initial, materialsBooted({ records: [record({ kind: 'texture', id: 'x' })] })),
    /material kind only; got "texture"/,
  );
});

test('an upsert of a known id with unchanged (weight, label) keeps its seat', () => {
  const state = booted();
  const edited = { ...RECORDS[0], value: '3rem' };
  const next = reducer(state, materialsDelta({ upsert: [edited], remove: [] }));
  assert.deepEqual(ids(next, 'token'), ['spacing/lg', 'color/accent']);
  assert.equal(next.records.token[0].value, '3rem');
  // Other kinds are untouched, identity included.
  assert.equal(next.records.variant, state.records.variant);
});

test('a new record is seated by (weight, label) among its kind', () => {
  const state = booted();
  const minted = record({ kind: 'variant', id: 'banner', label: 'Banner', group: 'style', spellings: { class: 'banner' }, scope: 'kind:block', origin: 'ai' });
  const heavy = record({ kind: 'variant', id: 'zed', label: 'Aardvark', group: 'style', spellings: { class: 'zed' }, scope: 'kind:block', weight: 5 });
  const next = reducer(state, materialsDelta({ upsert: [minted, heavy], remove: [] }));
  assert.deepEqual(ids(next, 'variant'), ['container_full', 'banner', 'button', 'card', 'zed']);
  const swatch = record({ kind: 'swatch', id: 'coral', label: 'Coral', value: '#ff7f50', group: 'color', spellings: { style: 'var(--swatch-coral)' } });
  const swatched = reducer(next, materialsDelta({ upsert: [swatch], remove: [] }));
  assert.deepEqual(ids(swatched, 'swatch'), ['coral', 'lime_green']);
});

test('a rename re-seats the record by its new label', () => {
  const state = booted();
  const renamed = { ...RECORDS[4], label: 'Zippy button' };
  const next = reducer(state, materialsDelta({ upsert: [renamed], remove: [] }));
  assert.deepEqual(ids(next, 'variant'), ['container_full', 'card', 'button']);
  assert.equal(next.records.variant[2].label, 'Zippy button');
});

test('the seat comparison is case-insensitive (the label sort of the roster query)', () => {
  const state = booted();
  const lower = record({ kind: 'variant', id: 'brick', label: 'brick', group: 'style', spellings: { class: 'brick' }, scope: 'kind:block' });
  const next = reducer(state, materialsDelta({ upsert: [lower], remove: [] }));
  assert.deepEqual(ids(next, 'variant'), ['container_full', 'brick', 'button', 'card']);
});

test('a remove drops the record; removing an unknown id is a no-op', () => {
  const state = booted();
  const next = reducer(state, materialsDelta({ upsert: [], remove: [{ kind: 'swatch', id: 'lime_green' }, { kind: 'blueprint', id: 'never' }] }));
  assert.deepEqual(ids(next, 'swatch'), []);
  assert.deepEqual(ids(next, 'blueprint'), []);
  assert.equal(next.records.variant, state.records.variant);
});

test('upserts land before removes within one delta', () => {
  const state = booted();
  const minted = record({ kind: 'swatch', id: 'coral', label: 'Coral', value: '#ff7f50', group: 'color', spellings: { style: 'var(--swatch-coral)' } });
  const next = reducer(state, materialsDelta({ upsert: [minted], remove: [{ kind: 'swatch', id: 'lime_green' }] }));
  assert.deepEqual(ids(next, 'swatch'), ['coral']);
});

test('isEmptyDelta: the empty shape every response carries, and nothing at all', () => {
  assert.equal(isEmptyDelta({ upsert: [], remove: [] }), true);
  assert.equal(isEmptyDelta(undefined), true);
  assert.equal(isEmptyDelta({ upsert: [RECORDS[2]], remove: [] }), false);
  assert.equal(isEmptyDelta({ upsert: [], remove: [{ kind: 'swatch', id: 'x' }] }), false);
});

test('assertDelta refuses the wrong shape and an unknown kind, and accepts the contract', () => {
  assert.throws(() => assertDelta({ upsert: [] }), /A materials delta is/);
  assert.throws(() => assertDelta({ upsert: [RECORDS[2]], remove: {} }), /A materials delta is/);
  assert.throws(() => assertDelta(null), /A materials delta is/);
  assert.throws(() => assertDelta({ upsert: [record({ kind: 'texture', id: 'x' })], remove: [] }), /names a material kind and id/);
  assert.throws(() => assertDelta({ upsert: [], remove: [{ kind: 'swatch' }] }), /names a material kind and id/);
  assert.doesNotThrow(() => assertDelta({ upsert: [RECORDS[2]], remove: [{ kind: 'blueprint', id: 'hero' }] }));
  assert.doesNotThrow(() => assertDelta({ upsert: [], remove: [] }));
});

test('selectRoster indexes the records and is rebuilt exactly when they change', () => {
  const state = booted();
  const roster = selectRoster(rootState(state));
  assert.equal(roster.bySpelling('style', 'var(--swatch-lime_green)').id, 'lime_green');
  assert.equal(roster.bySpelling('attr:kind:container:width', 'full').id, 'container_full');
  assert.deepEqual(roster.ofKind('variant').map((entry) => entry.id), ['container_full', 'button', 'card']);
  assert.equal(selectRoster(rootState(state)), roster);
  const next = reducer(state, materialsDelta({ upsert: [], remove: [{ kind: 'swatch', id: 'lime_green' }] }));
  const rebuilt = selectRoster(rootState(next));
  assert.notEqual(rebuilt, roster);
  assert.equal(rebuilt.bySpelling('style', 'var(--swatch-lime_green)'), null);
});

test('createEnvelopeSync boots on a new string only and lets a malformed envelope fail', () => {
  let encoded;
  const boots = [];
  const sync = createEnvelopeSync(() => encoded, (envelope) => boots.push(envelope));
  sync();
  assert.deepEqual(boots, []);
  encoded = JSON.stringify({ records: [] });
  sync();
  sync();
  assert.deepEqual(boots, [{ records: [] }]);
  encoded = JSON.stringify({ records: [RECORDS[2]] });
  sync();
  assert.equal(boots.length, 2);
  assert.equal(boots[1].records[0].id, 'lime_green');
  encoded = '{not json';
  assert.throws(sync, SyntaxError);
});
