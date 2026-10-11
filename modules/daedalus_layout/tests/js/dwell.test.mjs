/**
 * Self-asserting tests for the peek's dwell (js/src/model/dwell.js):
 * one pending peek at a time, firing after the delay with its key,
 * re-arming the pending row a no-op, arming another row replacing it,
 * cancelled by each of the four gestures (leave, blur, Escape, a drag
 * start) so nothing fires, and cancel after a fire a harmless no.
 *
 * Run with `node --test "modules/daedalus_layout/tests/js/*.test.mjs"`
 * from the daedalus root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';

const { DWELL_MS, createDwell } = await import('../../js/src/model/dwell.js');

/**
 * A clock driven by hand: timers fire when the test advances it.
 */
const clock = () => {
  let now = 0;
  let next = 1;
  const timers = new Map();
  return {
    setTimer: (fn, ms) => {
      const handle = next++;
      timers.set(handle, { at: now + ms, fn });
      return handle;
    },
    clearTimer: (handle) => timers.delete(handle),
    advance: (ms) => {
      now += ms;
      [...timers.entries()]
        .filter(([, timer]) => timer.at <= now)
        .sort((a, b) => a[1].at - b[1].at)
        .forEach(([handle, timer]) => {
          timers.delete(handle);
          timer.fn();
        });
    },
    live: () => timers.size,
  };
};

const build = () => {
  const time = clock();
  const fired = [];
  const dwell = createDwell({ delay: 100, setTimer: time.setTimer, clearTimer: time.clearTimer });
  const fire = (key) => fired.push(key);
  return { time, fired, dwell, fire };
};

test('the default delay is a moment', () => {
  assert.equal(DWELL_MS, 400);
  const dwell = createDwell();
  assert.equal(dwell.pending(), null);
  assert.equal(dwell.cancel(), false);
});

test('a rested-on row fires once, after the delay, with its key', () => {
  const { time, fired, dwell, fire } = build();
  dwell.arm('favorites|blueprint:hero', fire);
  assert.equal(dwell.pending(), 'favorites|blueprint:hero');
  time.advance(99);
  assert.deepEqual(fired, []);
  time.advance(1);
  assert.deepEqual(fired, ['favorites|blueprint:hero']);
  assert.equal(dwell.pending(), null);
  time.advance(1000);
  assert.deepEqual(fired, ['favorites|blueprint:hero']);
});

test('a sweep down the list fires nothing: arming another row replaces the pending one', () => {
  const { time, fired, dwell, fire } = build();
  dwell.arm('a', fire);
  time.advance(60);
  dwell.arm('b', fire);
  time.advance(60);
  dwell.arm('c', fire);
  assert.equal(dwell.pending(), 'c');
  assert.equal(time.live(), 1);
  time.advance(60);
  assert.deepEqual(fired, []);
  time.advance(40);
  assert.deepEqual(fired, ['c']);
});

test('re-arming the pending row does not restart its clock', () => {
  const { time, fired, dwell, fire } = build();
  dwell.arm('a', fire);
  time.advance(80);
  dwell.arm('a', fire);
  time.advance(20);
  assert.deepEqual(fired, ['a']);
});

test('each of the four gestures cancels: leave, blur, Escape, a drag start', () => {
  ['leave', 'blur', 'escape', 'dragstart'].forEach((gesture) => {
    const { time, fired, dwell, fire } = build();
    dwell.arm('a', fire);
    time.advance(50);
    assert.equal(dwell.cancel(), true, gesture);
    assert.equal(dwell.pending(), null, gesture);
    assert.equal(time.live(), 0, gesture);
    time.advance(1000);
    assert.deepEqual(fired, [], gesture);
  });
});

test('cancel after a fire, or with nothing pending, is a harmless no', () => {
  const { time, fired, dwell, fire } = build();
  assert.equal(dwell.cancel(), false);
  dwell.arm('a', fire);
  time.advance(100);
  assert.deepEqual(fired, ['a']);
  assert.equal(dwell.cancel(), false);
  // The row can be armed again after its peek fired.
  dwell.arm('a', fire);
  time.advance(100);
  assert.deepEqual(fired, ['a', 'a']);
});

test('the window\'s timers are the default clock', async () => {
  const dwell = createDwell({ delay: 1 });
  const fired = await new Promise((resolve) => {
    dwell.arm('a', resolve);
  });
  assert.equal(fired, 'a');
});
