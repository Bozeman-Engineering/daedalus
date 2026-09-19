/**
 * Self-asserting tests for the keyboard-stitching model.
 *
 * Run directly (`node tests/js/stitching.test.mjs`) or via
 * PrinterEquivalenceTest, which executes this file inside the suite so the
 * tab-stop chain and island registry contract have PHPUnit backpressure.
 * Exits non-zero on the first failure with a readable diff.
 */
import { strict as assert } from 'node:assert';
import {
  tabStops, nextTabTarget, previousTabTarget, createStitching,
} from '../../js/src/code/stitching.js';

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

// A minimal document-order fake: compareDocumentPosition() only needs the
// FOLLOWING bit (4) and isConnected, which is all the registry reads.
const element = (order, connected = true) => ({
  order,
  isConnected: connected,
  compareDocumentPosition(other) {
    return other.order > this.order ? 4 : 2;
  },
});

// --- tabStops: the DevTools chain's positions in one island -----------------

test('tabStops yields a property and value stop per declaration line', () => {
  const text = 'color: red;\npadding: 2rem;';
  assert.deepEqual(tabStops(text), [
    { pos: 0, type: 'property' },
    { pos: 7, type: 'value' },
    { pos: 12, type: 'property' },
    { pos: 21, type: 'value' },
  ]);
});

test('tabStops respects indentation, skips blanks, and handles colon-less lines', () => {
  // An indented declaration: both stops shift with the indent.
  assert.deepEqual(tabStops('  gap: 1rem;'), [
    { pos: 2, type: 'property' },
    { pos: 7, type: 'value' },
  ]);
  // A blank line between declarations contributes nothing.
  assert.deepEqual(tabStops('color: red;\n\ngap: 1rem;').map((stop) => stop.pos), [0, 7, 13, 18]);
  // A half-typed property (no colon yet) has only its property stop.
  assert.deepEqual(tabStops('color'), [{ pos: 0, type: 'property' }]);
  // A colon with no value yet: the value stop sits at the line's end.
  assert.deepEqual(tabStops('color: '), [
    { pos: 0, type: 'property' },
    { pos: 7, type: 'value' },
  ]);
  assert.deepEqual(tabStops(''), []);
});

// --- nextTabTarget: value -> next declaration -> new declaration -> exit ----

test('Tab walks property -> value -> next declaration', () => {
  const text = 'color: red;\npadding: 2rem;';
  assert.deepEqual(nextTabTarget(text, 0), { type: 'stop', pos: 7 });
  assert.deepEqual(nextTabTarget(text, 7), { type: 'stop', pos: 12 });
  assert.deepEqual(nextTabTarget(text, 12), { type: 'stop', pos: 21 });
});

test('Tab past the last value offers a new declaration, then exits', () => {
  const text = 'color: red;\npadding: 2rem;';
  // No trailing blank line yet: Tab appends one (the view inserts '\n').
  assert.deepEqual(nextTabTarget(text, 21), { type: 'new-declaration', pos: text.length });
  // The buffer already ends blank: Tab moves onto the offer line...
  const offered = `${text}\n`;
  assert.deepEqual(nextTabTarget(offered, 21), { type: 'stop', pos: offered.length });
  // ...and from the offer line itself, the chain leaves the island.
  assert.deepEqual(nextTabTarget(offered, offered.length), { type: 'exit' });
});

test('an empty island is its own new-declaration offer: Tab exits immediately', () => {
  assert.deepEqual(nextTabTarget('', 0), { type: 'exit' });
  assert.deepEqual(nextTabTarget('   ', 3), { type: 'exit' });
});

// --- previousTabTarget: the chain, reversed ----------------------------------

test('Shift-Tab walks the chain backwards and exits before the first property', () => {
  const text = 'color: red;\npadding: 2rem;';
  assert.deepEqual(previousTabTarget(text, text.length), { type: 'stop', pos: 21 });
  assert.deepEqual(previousTabTarget(text, 21), { type: 'stop', pos: 12 });
  assert.deepEqual(previousTabTarget(text, 12), { type: 'stop', pos: 7 });
  assert.deepEqual(previousTabTarget(text, 7), { type: 'stop', pos: 0 });
  assert.deepEqual(previousTabTarget(text, 0), { type: 'exit' });
  assert.deepEqual(previousTabTarget('', 0), { type: 'exit' });
});

// --- createStitching: the island chain and its focus memory ------------------

test('the registry orders islands by document position, registration order aside', () => {
  const stitching = createStitching();
  stitching.register('c', { element: element(3), focus: () => {} });
  stitching.register('a', { element: element(1), focus: () => {} });
  stitching.register('b', { element: element(2), focus: () => {} });
  assert.deepEqual(stitching.order(), ['a', 'b', 'c']);
});

test('focusNeighbor hands the placement to the adjacent island and reports the edge', () => {
  const stitching = createStitching();
  const calls = [];
  const track = (id) => (placement) => calls.push([id, placement]);
  stitching.register('a', { element: element(1), focus: track('a') });
  stitching.register('b', { element: element(2), focus: track('b') });
  stitching.register('c', { element: element(3), focus: track('c') });

  assert.equal(stitching.focusNeighbor('a', 1, { edge: 'firstStop' }), true);
  assert.equal(stitching.focusNeighbor('c', -1, { edge: 'lastLine', column: 4 }), true);
  assert.deepEqual(calls, [
    ['b', { edge: 'firstStop' }],
    ['b', { edge: 'lastLine', column: 4 }],
  ]);
  // The chain's ends: no neighbor, no focus theft, the caller keeps the
  // cursor (or lets the browser leave the surface).
  assert.equal(stitching.focusNeighbor('a', -1, { edge: 'end' }), false);
  assert.equal(stitching.focusNeighbor('c', 1, { edge: 'start' }), false);
  assert.equal(stitching.focusNeighbor('ghost', 1, {}), false);
  assert.equal(calls.length, 2);
});

test('disconnected islands drop out of the chain (a pruned row never traps Tab)', () => {
  const stitching = createStitching();
  const calls = [];
  const gone = element(2, false);
  stitching.register('a', { element: element(1), focus: () => calls.push('a') });
  stitching.register('b', { element: gone, focus: () => calls.push('b') });
  stitching.register('c', { element: element(3), focus: () => calls.push('c') });
  assert.deepEqual(stitching.order(), ['a', 'c']);
  assert.equal(stitching.focusNeighbor('a', 1, {}), true);
  assert.deepEqual(calls, ['c']);
});

test('unregister removes only its own registration', () => {
  const stitching = createStitching();
  const stale = stitching.register('a', { element: element(1), focus: () => {} });
  // A remount under the same id replaces the entry...
  stitching.register('a', { element: element(1), focus: () => {} });
  // ...and the STALE dispose (the unmounting predecessor's) must not
  // evict the live one — Preact runs the old cleanup around the new mount.
  stale();
  assert.deepEqual(stitching.order(), ['a']);
});

test('focus memory restores by exact id, once', () => {
  const stitching = createStitching();
  stitching.rememberFocus('block:b|default', 7);
  // Another island never inherits the parked cursor.
  assert.equal(stitching.claimFocus('block:a|default'), null);
  assert.deepEqual(stitching.claimFocus('block:b|default'), { head: 7 });
  // Claimed is gone: the next remount starts clean.
  assert.equal(stitching.claimFocus('block:b|default'), null);
  // A later park overwrites an unclaimed one; only the newest cursor
  // matters.
  stitching.rememberFocus('x', 1);
  stitching.rememberFocus('y', 2);
  assert.equal(stitching.claimFocus('x'), null);
  assert.deepEqual(stitching.claimFocus('y'), { head: 2 });
});

process.stdout.write('stitching suite passed\n');
