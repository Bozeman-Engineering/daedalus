/**
 * Self-asserting tests for the shared geometry-tracking seam.
 *
 * The tracker is pure mechanism (frame loop, stale-element cache,
 * measurement) with an injectable scheduler, so the contract the outline
 * and contrib consumers rely on is node-testable with stub elements: cache
 * reuse while a node stays connected and valid, re-resolution on subtree
 * swaps and validate rejections, zero-size and missing-element hiding,
 * prune, and restart-safe loop lifecycle.
 *
 * Run with `node --test tests/js/*.test.mjs` from the module root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { createGeometryTracker } from '../../js/edit_mode/utilities/geometry-tracker.js';

/**
 * A stub canvas element: connected, carrying a rect and an attribute map.
 */
const stubElement = (rect, attributes = {}) => ({
  isConnected: true,
  getAttribute: (name) => attributes[name] ?? null,
  getBoundingClientRect: () => ({ width: 10, height: 10, left: 0, top: 0, ...rect }),
});

/**
 * A manual frame scheduler: frames run only when tick() is called.
 */
const manualScheduler = () => {
  let nextId = 1;
  const pending = new Map();
  return {
    schedule: (callback) => {
      const id = nextId;
      nextId += 1;
      pending.set(id, callback);
      return id;
    },
    cancel: (id) => pending.delete(id),
    tick: () => {
      const callbacks = [...pending.values()];
      pending.clear();
      callbacks.forEach((callback) => callback());
    },
    pendingCount: () => pending.size,
  };
};

test('requires a resolver', () => {
  assert.throws(() => createGeometryTracker(), TypeError);
});

test('measure resolves, measures, and caches the element', () => {
  const element = stubElement({ left: 5, top: 7, width: 100, height: 50 });
  let resolves = 0;
  const tracker = createGeometryTracker({
    resolve: () => {
      resolves += 1;
      return element;
    },
  });

  const first = tracker.measure('box', 'key-1');
  assert.equal(first.element, element);
  assert.deepEqual(
    { left: first.rect.left, top: first.rect.top, width: first.rect.width, height: first.rect.height },
    { left: 5, top: 7, width: 100, height: 50 },
  );

  tracker.measure('box', 'key-1');
  tracker.measure('box', 'key-1');
  assert.equal(resolves, 1, 'a connected, valid element is not re-resolved');
});

test('measure re-resolves when the cached node leaves the document', () => {
  const stale = stubElement({});
  const fresh = stubElement({ left: 3 });
  const supply = [stale, fresh];
  const tracker = createGeometryTracker({ resolve: () => supply.shift() });

  assert.equal(tracker.measure('box', 'key-1').element, stale);
  stale.isConnected = false;
  const measured = tracker.measure('box', 'key-1');
  assert.equal(measured.element, fresh, 'a disconnected node forces re-resolution');
  assert.equal(measured.rect.left, 3);
});

test('measure re-resolves when validate rejects the cached node', () => {
  const wearsKey = (key) => stubElement({}, { 'data-selectable': key });
  const nodes = { 'key-1': wearsKey('key-1'), 'key-2': wearsKey('key-2') };
  let resolves = 0;
  const tracker = createGeometryTracker({
    resolve: (key) => {
      resolves += 1;
      return nodes[key];
    },
    validate: (element, key) => element.getAttribute('data-selectable') === key,
  });

  assert.equal(tracker.measure('box', 'key-1').element, nodes['key-1']);
  // Same box name, new key: the cached node no longer matches.
  assert.equal(tracker.measure('box', 'key-2').element, nodes['key-2']);
  assert.equal(resolves, 2);
  assert.equal(tracker.measure('box', 'key-2').element, nodes['key-2']);
  assert.equal(resolves, 2, 'the re-resolved node is cached in turn');
});

test('measure returns null for missing elements and keeps trying', () => {
  let element = null;
  const tracker = createGeometryTracker({ resolve: () => element });

  assert.equal(tracker.measure('box', 'key-1'), null);
  element = stubElement({});
  assert.equal(tracker.measure('box', 'key-1').element, element, 'a resolution miss is not cached');
});

test('measure returns null for a zero-size rect', () => {
  const tracker = createGeometryTracker({
    resolve: () => stubElement({ width: 0, height: 0 }),
  });
  assert.equal(tracker.measure('box', 'key-1'), null);
});

test('prune drops cache entries outside the live set', () => {
  const elements = { a: stubElement({}), b: stubElement({}) };
  let resolves = 0;
  const tracker = createGeometryTracker({
    resolve: (key) => {
      resolves += 1;
      return elements[key];
    },
  });

  tracker.measure('a', 'a');
  tracker.measure('b', 'b');
  tracker.prune(['a']);
  tracker.measure('a', 'a');
  tracker.measure('b', 'b');
  assert.equal(resolves, 3, 'only the pruned name re-resolves');
});

test('start runs the frame loop and stop cancels it', () => {
  const scheduler = manualScheduler();
  const tracker = createGeometryTracker({
    resolve: () => stubElement({}),
    schedule: scheduler.schedule,
    cancel: scheduler.cancel,
  });

  let frames = 0;
  tracker.start(() => {
    frames += 1;
  });
  assert.equal(frames, 1, 'the first frame runs synchronously');
  scheduler.tick();
  scheduler.tick();
  assert.equal(frames, 3);

  tracker.stop();
  assert.equal(scheduler.pendingCount(), 0, 'stop cancels the scheduled frame');
  scheduler.tick();
  assert.equal(frames, 3);
});

test('start is restart-safe: no doubled loops', () => {
  const scheduler = manualScheduler();
  const tracker = createGeometryTracker({
    resolve: () => stubElement({}),
    schedule: scheduler.schedule,
    cancel: scheduler.cancel,
  });

  let first = 0;
  let second = 0;
  tracker.start(() => {
    first += 1;
  });
  tracker.start(() => {
    second += 1;
  });
  scheduler.tick();
  assert.equal(scheduler.pendingCount(), 1, 'exactly one loop remains scheduled');
  assert.equal(first, 1, 'the replaced loop stopped after its synchronous frame');
  assert.equal(second, 2);
});
