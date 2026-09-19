/**
 * Self-asserting tests for the generic stops track (track-model.js): the
 * position math the gradient builder and the keyframes editor share, and
 * the shape-parameterized insert/remove with the consumer's floor.
 *
 * Run directly (`node tests/js/track-model.test.mjs`) or with the rest of
 * the suite (`node --test tests/js/*.test.mjs`). Exits non-zero on
 * failure.
 */
import { strict as assert } from 'node:assert';
import {
  insertStop,
  insertionIndex,
  moveStop,
  positionPercent,
  removeStop,
  trackPercent,
  trackPositions,
} from '../../js/src/track-model.js';

const tests = [];
const test = (name, run) => tests.push([name, run]);

// Two consumers' shapes on one track: a gradient stop and a keyframe step.
const stopsOf = (...pairs) => pairs.map(([color, position]) => ({ color, position }));
const stepsOf = (...pairs) => pairs.map(([position, declarations]) => ({ position, declarations }));

test('positionPercent: only the percent spelling maps onto the track math', () => {
  assert.equal(positionPercent('50%'), 50);
  assert.equal(positionPercent(' 12.5% '), 12.5);
  assert.equal(positionPercent('-10%'), -10);
  assert.equal(positionPercent('10px'), null);
  assert.equal(positionPercent('20% 80%'), null);
  assert.equal(positionPercent(null), null);
  assert.equal(positionPercent(''), null);
});

test('trackPercent: clamped to the track, one decimal at most', () => {
  assert.equal(trackPercent(130), 100);
  assert.equal(trackPercent(-4), 0);
  assert.equal(trackPercent(33.333), 33.3);
  assert.equal(trackPercent(30.04), 30);
});

test('trackPositions: the CSS default distribution, shape-blind', () => {
  assert.deepEqual(trackPositions(stopsOf(['a', null], ['b', null])), [0, 100]);
  assert.deepEqual(trackPositions(stopsOf(['a', '20%'], ['b', null], ['c', null], ['d', '80%'])), [20, 40, 60, 80]);
  // A keyframe step reads the same field; its declarations ride along.
  assert.deepEqual(trackPositions(stepsOf(['0%', {}], ['50%', {}], ['100%', {}])), [0, 50, 100]);
  assert.deepEqual(trackPositions([]), []);
});

test('trackPositions: display mirrors CSS render clamping', () => {
  assert.deepEqual(trackPositions(stopsOf(['a', '80%'], ['b', '20%'])), [80, 80]);
  assert.deepEqual(trackPositions(stopsOf(['a', '-20%'], ['b', '120%'])), [0, 100]);
});

test('insertionIndex: after every stop displayed at or before the percent', () => {
  const stops = stopsOf(['a', '0%'], ['b', '50%'], ['c', '100%']);
  assert.equal(insertionIndex(stops, 25), 1);
  assert.equal(insertionIndex(stops, 50), 2);
  assert.equal(insertionIndex(stops, 100), 3);
  assert.equal(insertionIndex([], 40), 0);
});

test('moveStop: an explicit percent, visual order kept, every other field verbatim', () => {
  const steps = stepsOf(['0%', { opacity: '0' }], ['50%', { opacity: '0.5' }], ['100%', { opacity: '1' }]);
  const moved = moveStop(steps, 1, 30.04);
  assert.deepEqual(moved.stops[1], { position: '30%', declarations: { opacity: '0.5' } });
  assert.equal(moved.selected, 1);
  // Crossing a neighbor reorders and the selection follows.
  const crossed = moveStop(steps, 0, 70);
  assert.deepEqual(crossed.stops.map((step) => step.declarations.opacity), ['0.5', '0', '1']);
  assert.equal(crossed.stops[1].position, '70%');
  assert.equal(crossed.selected, 1);
  // Untouched stops keep their raw (even null) positions.
  assert.deepEqual(moveStop(stopsOf(['a', null], ['b', null], ['c', null]), 1, 80).stops.map((stop) => stop.position), [null, '80%', null]);
});

test('insertStop: the consumer\'s shape lands sorted at the clamped percent, selected', () => {
  const steps = stepsOf(['0%', {}], ['100%', {}]);
  const added = insertStop(steps, { declarations: { opacity: '0.5' } }, 25.04);
  assert.deepEqual(added.stops, stepsOf(['0%', {}], ['25%', { opacity: '0.5' }], ['100%', {}]));
  assert.equal(added.selected, 1);
  // A gradient's shape is its color; the clamp holds either way.
  const stops = stopsOf(['#000', '0%'], ['#fff', '100%']);
  assert.deepEqual(insertStop(stops, { color: '#808080' }, 140).stops[2], { color: '#808080', position: '100%' });
});

test('removeStop: the consumer\'s floor and selection survival', () => {
  const steps = stepsOf(['0%', {}], ['50%', {}], ['100%', {}]);
  // A gradient's floor is two; an animation's is one.
  assert.equal(removeStop(stepsOf(['0%', {}], ['100%', {}]), 0, 0, 2), null);
  assert.deepEqual(removeStop(stepsOf(['0%', {}], ['100%', {}]), 0, 0, 1), { stops: stepsOf(['100%', {}]), selected: 0 });
  assert.equal(removeStop(stepsOf(['0%', {}]), 0, 0, 1), null);
  // Removing before the selection shifts it down; after leaves it; the
  // selection itself picks the nearest survivor.
  assert.equal(removeStop(steps, 0, 2, 1).selected, 1);
  assert.equal(removeStop(steps, 2, 0, 1).selected, 0);
  assert.equal(removeStop(steps, 2, 2, 1).selected, 1);
  assert.deepEqual(removeStop(steps, 1, 1, 1), { stops: stepsOf(['0%', {}], ['100%', {}]), selected: 1 });
});

let failures = 0;
for (const [name, run] of tests) {
  try {
    run();
    console.log(`ok - ${name}`);
  } catch (error) {
    failures += 1;
    console.error(`not ok - ${name}`);
    console.error(error.message);
  }
}
if (failures) {
  console.error(`${failures} of ${tests.length} track-model tests failed.`);
  process.exit(1);
}
console.log(`${tests.length} track-model tests passed.`);
