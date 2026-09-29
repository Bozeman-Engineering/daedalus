/**
 * Locks the scroll-preserving helper's contract (preserve-scroll.js): the
 * window offsets read before the callback are written back after it, with
 * an instant scrollTo, unconditionally, and the callback's value passes
 * through.
 *
 * The engine behavior it exists for (WebKit's synchronous clamp inside a
 * DOM removal) has no node witness; the spec pins the helper's promise,
 * not the clamp.
 *
 * Run with `node --test tests/js/*.test.mjs` from the module root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { preserveScroll } from '../../js/edit_mode/ops/preserve-scroll.js';

/**
 * A window whose scrollTo records its calls and moves the fake offsets,
 * so a test can read both what was asked and where the window ended up.
 */
const fakeWindow = (scrollX, scrollY) => {
  const win = {
    scrollX,
    scrollY,
    calls: [],
    scrollTo(options) {
      win.calls.push(options);
      win.scrollX = options.left;
      win.scrollY = options.top;
    },
  };
  return win;
};

test('offsets moved by the callback are restored on both axes', () => {
  const win = fakeWindow(40, 1736);
  preserveScroll(() => {
    // The engine's clamp: a move the callback did not ask for.
    win.scrollX = 0;
    win.scrollY = 1;
  }, win);
  assert.equal(win.scrollX, 40);
  assert.equal(win.scrollY, 1736);
  assert.deepEqual(win.calls, [{ left: 40, top: 1736, behavior: 'instant' }]);
});

test("the callback's return value passes through", () => {
  const win = fakeWindow(0, 300);
  const root = { tag: 'replacement' };
  assert.equal(
    preserveScroll(() => root, win),
    root,
  );
});

test('the restore is issued once, instantly, even when nothing moved', () => {
  // The contract is unconditional: no compare-before-write, so the helper
  // never has to know which engine it runs on.
  const win = fakeWindow(12, 2026);
  preserveScroll(() => {}, win);
  assert.equal(win.calls.length, 1);
  assert.deepEqual(win.calls[0], { left: 12, top: 2026, behavior: 'instant' });
  assert.equal(win.scrollX, 12);
  assert.equal(win.scrollY, 2026);
});

test('an exception from the callback propagates', () => {
  // No restore is required on throw: a swap that failed part way has left
  // the canvas in a state the caller must recover, and the scroll is the
  // least of it. The spec pins the propagation, not the absence of a
  // restore, so a later `finally` would not break this test.
  const win = fakeWindow(0, 500);
  const failure = new Error('swap failed');
  assert.throws(
    () =>
      preserveScroll(() => {
        throw failure;
      }, win),
    (error) => error === failure,
  );
});
