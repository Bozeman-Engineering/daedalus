/**
 * Self-asserting tests for the canvas-highlight claim stack: latest
 * claim wins, releases fall back to the next claim instead of
 * clearing, re-claims update in place, unknown releases no-op, and the
 * per-source wiring clears every claim on any move of the styling
 * address (a target move under the same selection, a selection move),
 * heard on the source's one target-aware subscription.
 *
 * Run directly: `node tests/js/highlight-claims.test.mjs`. Exits
 * non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import { createHighlightClaims, highlightClaimsFor } from '../../js/src/highlight-claims.js';

const tests = [];
const test = (name, run) => tests.push([name, run]);

const spec = (properties) => ({ key: 'block:b1', selector: null, properties });

const harness = () => {
  const applied = [];
  const claims = createHighlightClaims((value) => applied.push(value));
  return { applied, claims };
};

test('a claim applies its spec; releasing the only claim clears', () => {
  const { applied, claims } = harness();
  const row = {};
  claims.claim(row, spec(['padding-top']));
  claims.release(row);
  assert.deepEqual(applied, [spec(['padding-top']), null]);
});

test('the latest claim wins over an earlier one', () => {
  const { applied, claims } = harness();
  claims.claim('a', spec(['margin']));
  claims.claim('b', spec(['gap']));
  assert.deepEqual(applied.at(-1), spec(['gap']));
});

test('a release falls back to the next claim, not to null', () => {
  const { applied, claims } = harness();
  claims.claim('focused-row', spec(['padding']));
  claims.claim('hovered-row', spec(['gap']));
  claims.release('hovered-row');
  assert.deepEqual(applied.at(-1), spec(['padding']));
});

test('re-claiming updates the owner\'s spec and moves it to the top', () => {
  const { applied, claims } = harness();
  claims.claim('diagram', spec(['padding-top']));
  claims.claim('row', spec(['gap']));
  claims.claim('diagram', spec(['padding-top', 'padding-bottom']));
  assert.deepEqual(applied.at(-1), spec(['padding-top', 'padding-bottom']));
  // The diagram owns ONE slot: releasing it falls back to the row.
  claims.release('diagram');
  assert.deepEqual(applied.at(-1), spec(['gap']));
  claims.release('row');
  assert.equal(applied.at(-1), null);
});

test('claiming null releases (the onHighlight(null) shape)', () => {
  const { applied, claims } = harness();
  claims.claim('diagram', spec(['padding-left']));
  claims.claim('diagram', null);
  assert.equal(applied.at(-1), null);
});

test('releasing an unknown owner applies nothing (safe unmount cleanup)', () => {
  const { applied, claims } = harness();
  claims.claim('a', spec(['padding']));
  claims.release('never-claimed');
  assert.equal(applied.length, 1);
});

test('clear drops every claim and applies null once', () => {
  const { applied, claims } = harness();
  claims.claim('a', spec(['padding']));
  claims.claim('b', spec(['gap']));
  claims.clear();
  assert.equal(applied.at(-1), null);
  const count = applied.length;
  claims.clear();
  assert.equal(applied.length, count, 'clearing an empty stack applies nothing');
});

test('highlightClaimsFor binds one stack per source and wipes it on a target move as on a selection move', () => {
  const applied = [];
  let targetListener = null;
  // The source's target-aware subscription (the selection facade's
  // onTargetChange shape: fires on a selection move OR a target move,
  // with ({selected, target}, previous)); the registry binds nothing
  // else, so a stub without onSelectionChange is the honest one.
  const source = {
    setCanvasHighlight: (value) => applied.push(value),
    onTargetChange: (callback) => {
      targetListener = callback;
      return () => {};
    },
  };
  const claims = highlightClaimsFor(source);
  assert.equal(highlightClaimsFor(source), claims, 'one stack per source');
  // A focus-held row claim keyed at the element; the switcher seats an
  // ancestor rule under the SAME selection: the claim is at the old
  // address now and goes.
  claims.claim('row', spec(['gap']));
  targetListener(
    { selected: 'block:b1', target: { key: 'container:c1', selector: '> pb-text' } },
    { selected: 'block:b1', target: null },
  );
  assert.equal(applied.at(-1), null);
  // The wiped claim cannot resurrect through a later release.
  claims.release('row');
  assert.equal(applied.at(-1), null);
  assert.equal(applied.length, 2);
  // A selection move arrives on the same subscription and wipes too.
  claims.claim('row', { key: 'container:c1', selector: '> pb-text', properties: ['gap'] });
  targetListener(
    { selected: 'block:other', target: null },
    { selected: 'block:b1', target: { key: 'container:c1', selector: '> pb-text' } },
  );
  assert.equal(applied.at(-1), null);
  assert.equal(applied.length, 4);
});

// --- runner ---------------------------------------------------------------

let failed = 0;
for (const [name, run] of tests) {
  try {
    run();
    console.log(`ok - ${name}`);
  } catch (error) {
    failed += 1;
    console.error(`not ok - ${name}`);
    console.error(error);
  }
}
if (failed > 0) {
  console.error(`${failed} of ${tests.length} tests failed.`);
  process.exit(1);
}
console.log(`${tests.length} tests passed.`);
