/**
 * Self-asserting tests for the armed edit session's item model
 * (edit_session/session-items.js).
 *
 * The session walks ONE ordered, keyed list: the canvas leaves in document
 * order, then the entity-form panel's revealed widgets in form order. The
 * model is pure — the session injects its presentation-boundary and
 * projection-gate rules, the engine hands the two raw lists — so these
 * tests drive it with plain objects and lock three contracts: the merge
 * order, the key rule per kind (canvas keyed through the gate, panel keyed
 * by the engine, one key one stop), and neighbor stepping across the seam
 * between the two halves.
 *
 * Run with `node --test tests/js/*.test.mjs` from the module root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { registerHooks } from 'node:module';
import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

registerHooks({
  resolve(specifier, context, nextResolve) {
    try {
      return nextResolve(specifier, context);
    } catch (error) {
      if (
        (specifier.startsWith('.') || specifier.startsWith('/')) &&
        existsSync(fileURLToPath(new URL(`${specifier}.js`, context.parentURL)))
      ) {
        return nextResolve(`${specifier}.js`, context);
      }
      throw error;
    }
  },
});

const { sessionItems, entryFor, neighborEntry } = await import(
  '../../js/edit_mode/edit_session/session-items.js'
);

const ROOT = '99999999-1111-2222-3333-444444444444';
const BLOCK = 'bbbbbbbb-1111-2222-3333-444444444444';

/** A stub item: identity plus a name for failure messages. */
const item = (name) => ({ name });

/**
 * The rules the session injects, driven by lookup tables: `boundaries`
 * maps a leaf to the item it edits as (itself unless it sits inside a
 * presentation root), `keys` maps a boundary item to its committed key
 * (null = outside the editing universe).
 */
const rules = (boundaries = new Map(), keys = new Map()) => ({
  boundary: (leaf) => boundaries.get(leaf) ?? leaf,
  keyFor: (leaf) => (keys.has(leaf) ? keys.get(leaf) : null),
});

/**
 * A page: a title leaf and two body deltas on the canvas (document
 * order), a merged block whose inner leaf edits as the block's boundary,
 * and two revealed panel widgets (form order).
 */
const page = () => {
  const title = item('title');
  const body0 = item('body delta 0');
  const body1 = item('body delta 1');
  const blockLeaf = item('merged block inner leaf');
  const blockRoot = item('merged block root');
  const related = item('related widget');
  const tags = item('tags widget');
  const keys = new Map([
    [title, `entity:${ROOT}`],
    [body0, `field:${ROOT}:body`],
    [body1, `field:${ROOT}:body`],
    [blockRoot, `block:${BLOCK}`],
  ]);
  const boundaries = new Map([[blockLeaf, blockRoot]]);
  return {
    title,
    body0,
    body1,
    blockLeaf,
    blockRoot,
    related,
    tags,
    raw: {
      canvas: [title, body0, body1, blockRoot, blockLeaf],
      panel: [
        { item: related, key: `field:${ROOT}:related` },
        { item: tags, key: `field:${ROOT}:tags` },
      ],
    },
    rules: rules(boundaries, keys),
  };
};

test('merge order: canvas leaves in document order, then panel widgets in form order', () => {
  const p = page();
  const entries = sessionItems(p.raw, p.rules);
  assert.deepEqual(
    entries.map((entry) => entry.item.name),
    ['title', 'body delta 0', 'body delta 1', 'merged block root', 'related widget', 'tags widget'],
  );
  assert.deepEqual(
    entries.map((entry) => entry.panel),
    [false, false, false, false, true, true],
    'the panel flag marks the seam',
  );
});

test('key rule, canvas: keyed through the gate at the presentation boundary', () => {
  const p = page();
  const entries = sessionItems(p.raw, p.rules);
  assert.equal(entryFor(entries, p.title).key, `entity:${ROOT}`, 'the root edit-target leaf commits the root key the gate gives it');
  assert.equal(entryFor(entries, p.body0).key, `field:${ROOT}:body`);
  assert.equal(entryFor(entries, p.body1).key, `field:${ROOT}:body`, 'two deltas share a key and are each a stop');
  assert.equal(entryFor(entries, p.blockRoot).key, `block:${BLOCK}`, 'the merged root is the one stop for its subtree');
  assert.equal(entryFor(entries, p.blockLeaf), null, 'a leaf inside a presentation root is no stop of its own');
});

test('key rule, canvas: a leaf the gate refuses is no stop', () => {
  const foreign = item('foreign host leaf');
  const body = item('body');
  const entries = sessionItems(
    { canvas: [foreign, body], panel: [] },
    rules(new Map(), new Map([[body, `field:${ROOT}:body`]])),
  );
  assert.deepEqual(entries.map((entry) => entry.item), [body]);
});

test('key rule, panel: the engine keys a widget verbatim, no gate', () => {
  const widget = item('widget');
  const entries = sessionItems(
    { canvas: [], panel: [{ item: widget, key: `field:${ROOT}:related` }] },
    // The gate knows nothing (an unplaced Layout Builder field has no
    // projection row), and the widget is still a stop.
    rules(),
  );
  assert.deepEqual(entries, [{ item: widget, key: `field:${ROOT}:related`, panel: true }]);
});

test('key rule, panel: a widget the engine could not key is no stop', () => {
  const orphan = item('paragraph widget');
  const entries = sessionItems(
    { canvas: [], panel: [{ item: orphan, key: null }] },
    rules(),
  );
  assert.deepEqual(entries, []);
});

test('key rule, panel: a widget whose key a canvas leaf owns yields to the leaf', () => {
  const leaf = item('body leaf');
  const widget = item('body widget');
  const entries = sessionItems(
    { canvas: [leaf], panel: [{ item: widget, key: `field:${ROOT}:body` }] },
    rules(new Map(), new Map([[leaf, `field:${ROOT}:body`]])),
  );
  assert.deepEqual(entries.map((entry) => entry.item), [leaf], 'one key, one editor');
});

test('an empty engine yields no items', () => {
  assert.deepEqual(sessionItems({}, rules()), []);
  assert.deepEqual(sessionItems(undefined, rules()), []);
});

test('neighbor stepping crosses the seam in both directions', () => {
  const p = page();
  const entries = sessionItems(p.raw, p.rules);
  assert.equal(neighborEntry(entries, p.blockRoot, 1).item, p.related, 'Tab from the last canvas leaf lands on the first widget');
  assert.equal(neighborEntry(entries, p.related, -1).item, p.blockRoot, 'Shift+Tab from the first widget returns to the last leaf');
  assert.equal(neighborEntry(entries, p.related, 1).item, p.tags, 'Tab walks the panel in form order');
  assert.equal(neighborEntry(entries, p.body0, 1).item, p.body1, 'Tab walks the canvas in document order');
});

test('neighbor stepping: no reference point starts at either end, the ends yield null', () => {
  const p = page();
  const entries = sessionItems(p.raw, p.rules);
  assert.equal(neighborEntry(entries, null, 1).item, p.title, 'forward from nothing: the first item');
  assert.equal(neighborEntry(entries, null, -1).item, p.tags, 'backward from nothing: the last item, a panel widget');
  assert.equal(neighborEntry(entries, p.tags, 1), null, 'past the last widget');
  assert.equal(neighborEntry(entries, p.title, -1), null, 'before the first leaf');
  assert.equal(neighborEntry(entries, p.blockLeaf, 1).item, p.title, 'an item that is no stop is no reference point');
  assert.equal(neighborEntry([], p.title, 1), null, 'no items, no neighbor');
});

test('done when: a panel widget element with a field key is a Tab stop', () => {
  // The node model's statement of the task: with no canvas leaf for the
  // field, a revealed widget keyed by the engine is where Tab lands.
  const leaf = item('body leaf');
  const widget = item('related widget');
  const entries = sessionItems(
    { canvas: [leaf], panel: [{ item: widget, key: `field:${ROOT}:related` }] },
    rules(new Map(), new Map([[leaf, `field:${ROOT}:body`]])),
  );
  assert.equal(neighborEntry(entries, leaf, 1).item, widget);
  assert.equal(neighborEntry(entries, leaf, 1).key, `field:${ROOT}:related`);
});
