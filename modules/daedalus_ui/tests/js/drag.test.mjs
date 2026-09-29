/**
 * Self-asserting tests for the pointer-drag primitive's frame pacing
 * (drag.js).
 *
 * The spine coalesces onMove to at most one call per animation frame:
 * leading call immediate, trailing call with the latest event. The load
 * bearing property beyond the rate cap is WHERE deliveries run: during
 * continuous motion every delivery happens synchronously inside a
 * pointermove event, never from a frame callback, because per-frame
 * chrome (geometry-tracker.js) re-registers its rAF callback each frame
 * and therefore measures before any callback registered mid-stream; a
 * move applied from rAF would paint chrome one frame behind the drag
 * (see sidebar-resize.js's synchronous displace coupling). Only a
 * paused stream gets its resting position delivered from the clock.
 *
 * drag.js is a plain browser script over globals, so the tests stub
 * Drupal, window, and the rAF pair before requiring it, and drive frames
 * through a manual scheduler.
 *
 * The last case crosses to the drag controller (drag-controller.js, the
 * drop pipeline every placement gesture shares) for the one seam the
 * pointer primitive's frames never see: which drop door releases keyboard
 * focus. The controller imports the app store (webpack-style
 * extensionless imports; document, drupalSettings and BroadcastChannel
 * globals), so that case registers an extension-adding resolve hook and
 * stubs the globals before dynamically importing it.
 *
 * Run with `node --test tests/js/*.test.mjs` from the module root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { createRequire, registerHooks } from 'node:module';

registerHooks({
  resolve(specifier, context, nextResolve) {
    try {
      return nextResolve(specifier, context);
    } catch (error) {
      if (specifier.startsWith('.') || specifier.startsWith('/')) {
        return nextResolve(`${specifier}.js`, context);
      }
      throw error;
    }
  },
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

/**
 * An event target recording its listeners so tests can dispatch to them.
 */
const fakeTarget = () => {
  const listeners = new Map();
  return {
    addEventListener(type, listener) {
      if (!listeners.has(type)) {
        listeners.set(type, new Set());
      }
      listeners.get(type).add(listener);
    },
    removeEventListener(type, listener) {
      listeners.get(type)?.delete(listener);
    },
    dispatch(type, event = {}) {
      [...(listeners.get(type) ?? [])].forEach((listener) => listener(event));
    },
    listenerCount() {
      return [...listeners.values()].reduce((sum, set) => sum + set.size, 0);
    },
  };
};

/**
 * A drag handle: an event target that also honors pointer capture calls.
 */
const fakeHandle = () => ({
  ...fakeTarget(),
  captured: null,
  setPointerCapture(pointerId) {
    this.captured = pointerId;
  },
  hasPointerCapture(pointerId) {
    return this.captured === pointerId;
  },
  releasePointerCapture() {
    this.captured = null;
  },
});

const scheduler = manualScheduler();
globalThis.requestAnimationFrame = scheduler.schedule;
globalThis.cancelAnimationFrame = scheduler.cancel;
globalThis.window = fakeTarget();
globalThis.Drupal = {};
createRequire(import.meta.url)('../../js/drag.js');
const { drag } = globalThis.Drupal.Daedalus;

const down = (pointerId = 1) => ({
  button: 0,
  isPrimary: true,
  ctrlKey: false,
  pointerId,
  preventDefault() {},
});

const moveAt = (clientX, pointerId = 1) => ({ pointerId, clientX });

/**
 * Begins a drag on a fresh handle, recording deliveries into a log.
 */
const begunDrag = (log) => {
  const handle = fakeHandle();
  const began = drag.begin(handle, down(), {
    onMove: (event) => log.push(`move:${event.clientX}`),
    onEnd: ({ reason }) => log.push(`end:${reason}`),
    marks: [[{ classList: { add: () => log.push('mark-on'), remove: () => log.push('mark-off') } }, 'mark']],
  });
  assert.equal(began, true);
  return handle;
};

test('secondary buttons, secondary pointers, and ctrl-clicks are refused', () => {
  const handle = fakeHandle();
  assert.equal(drag.begin(handle, { ...down(), button: 2 }), false);
  assert.equal(drag.begin(handle, { ...down(), isPrimary: false }), false);
  assert.equal(drag.begin(handle, { ...down(), ctrlKey: true }), false);
  assert.equal(handle.listenerCount(), 0, 'a refused gesture attaches nothing');
  assert.equal(handle.captured, null, 'a refused gesture captures nothing');
});

test('the first move of a burst is delivered immediately', () => {
  const log = [];
  const handle = begunDrag(log);
  handle.dispatch('pointermove', moveAt(100));
  assert.deepEqual(log, ['mark-on', 'move:100'], 'the leading call runs inside the event');
  handle.dispatch('pointerup', {});
  scheduler.tick();
});

test('moves inside a consumed frame collapse to the latest, delivered synchronously by the next move', () => {
  const log = [];
  const handle = begunDrag(log);
  handle.dispatch('pointermove', moveAt(100));
  handle.dispatch('pointermove', moveAt(110));
  handle.dispatch('pointermove', moveAt(120));
  assert.deepEqual(log, ['mark-on', 'move:100'], 'same-frame moves are gated');
  scheduler.tick();
  assert.deepEqual(log, ['mark-on', 'move:100'], 'the frame boundary itself delivers nothing mid-stream');
  handle.dispatch('pointermove', moveAt(130));
  assert.deepEqual(
    log,
    ['mark-on', 'move:100', 'move:130'],
    'the fresher move supersedes the gated one, synchronously inside its own event',
  );
  handle.dispatch('pointerup', {});
  scheduler.tick();
});

test('a continuous stream delivers once per frame, always from pointermove events', () => {
  const log = [];
  const handle = begunDrag(log);
  let inFrameCallback = false;
  const deliveredFrom = [];
  const originalPush = log.push.bind(log);
  log.push = (entry) => {
    if (entry.startsWith('move:')) {
      deliveredFrom.push(inFrameCallback ? 'frame' : 'event');
    }
    return originalPush(entry);
  };
  [100, 110].forEach((x) => handle.dispatch('pointermove', moveAt(x)));
  inFrameCallback = true;
  scheduler.tick();
  inFrameCallback = false;
  [120, 130].forEach((x) => handle.dispatch('pointermove', moveAt(x)));
  inFrameCallback = true;
  scheduler.tick();
  inFrameCallback = false;
  [140, 150].forEach((x) => handle.dispatch('pointermove', moveAt(x)));
  assert.deepEqual(
    log.filter((entry) => entry.startsWith('move:')),
    ['move:100', 'move:120', 'move:140'],
    'one delivery per frame, latest event wins',
  );
  assert.deepEqual(deliveredFrom, ['event', 'event', 'event'], 'mid-stream deliveries never run from a frame callback');
  handle.dispatch('pointerup', {});
  scheduler.tick();
});

test('a paused stream lands its resting position from the clock', () => {
  const log = [];
  const handle = begunDrag(log);
  handle.dispatch('pointermove', moveAt(100));
  handle.dispatch('pointermove', moveAt(115));
  scheduler.tick();
  assert.deepEqual(log, ['mark-on', 'move:100'], 'the gated move is held one open frame first');
  scheduler.tick();
  assert.deepEqual(log, ['mark-on', 'move:100', 'move:115'], 'the trailing call delivers the latest event');
  handle.dispatch('pointerup', {});
  scheduler.tick();
});

test('an idle stream stops the clock and the next move is a fresh leading call', () => {
  const log = [];
  const handle = begunDrag(log);
  handle.dispatch('pointermove', moveAt(100));
  scheduler.tick();
  assert.equal(scheduler.pendingCount(), 0, 'nothing pending leaves no scheduled frame');
  handle.dispatch('pointermove', moveAt(200));
  assert.deepEqual(log, ['mark-on', 'move:100', 'move:200'], 'the post-idle move is delivered immediately');
  handle.dispatch('pointerup', {});
  scheduler.tick();
});

test('a release delivers the still-gated move before the marks come off and onEnd runs', () => {
  const log = [];
  const handle = begunDrag(log);
  handle.dispatch('pointermove', moveAt(100));
  handle.dispatch('pointermove', moveAt(140));
  handle.dispatch('pointerup', {});
  assert.deepEqual(
    log,
    ['mark-on', 'move:100', 'move:140', 'mark-off', 'end:up'],
    'commit order: gated move, then marks off, then onEnd',
  );
  assert.equal(scheduler.pendingCount(), 0, 'the clock is cancelled on the terminator');
});

test('an abort discards the gated move', () => {
  const log = [];
  const handle = begunDrag(log);
  handle.dispatch('pointermove', moveAt(100));
  handle.dispatch('pointermove', moveAt(140));
  globalThis.window.dispatch('keydown', { key: 'Escape' });
  assert.deepEqual(
    log,
    ['mark-on', 'move:100', 'mark-off', 'end:cancel'],
    'the gated move never lands on an abort',
  );
  assert.equal(scheduler.pendingCount(), 0);
});

test('moves from another pointer are ignored by the pacing', () => {
  const log = [];
  const handle = begunDrag(log);
  handle.dispatch('pointermove', moveAt(100, 2));
  assert.deepEqual(log, ['mark-on'], 'a foreign pointer neither delivers nor queues');
  handle.dispatch('pointermove', moveAt(120));
  assert.deepEqual(log, ['mark-on', 'move:120']);
  handle.dispatch('pointerup', {});
  scheduler.tick();
});

test('a pointer drop releases chrome focus; the keyboard door, the canvas and an open editor keep theirs', async () => {
  const saved = {
    document: globalThis.document,
    drupalSettings: globalThis.drupalSettings,
    BroadcastChannel: globalThis.BroadcastChannel,
    Blueprint: Drupal.Daedalus.Blueprint,
  };
  const documentListeners = new Map();
  globalThis.document = {
    addEventListener: (type, fn) => documentListeners.set(type, fn),
    removeEventListener() {},
    querySelector: () => null,
    querySelectorAll: () => [],
    getElementById: () => null,
    documentElement: { addEventListener() {}, getAttribute: () => null },
    body: {},
    activeElement: null,
  };
  globalThis.drupalSettings = { daedalusUi: {} };
  globalThis.BroadcastChannel = class {
    postMessage() {}
    close() {}
  };
  globalThis.scrollY = 0;
  globalThis.scrollTo = () => {};
  Drupal.t = (string) => string;
  Drupal.behaviors = {};
  try {
    const controller = await import('../../js/edit_mode/drag/drag-controller.js');
    controller.initDrag();
    // The page has a blueprint root, so a gap drop is one the controller
    // takes; the source's operations abort the commit (no ops, nothing to
    // enqueue), which keeps the case on the focus seam alone.
    Drupal.Daedalus.Blueprint = { rootContext: () => ({ entityType: 'node', entityId: '1', uuid: 'root' }) };
    controller.registerDragSource('probe', { operations: () => [] });

    // Focus holders: a sidebar control (a palette item), a sidebar input
    // (a panel widget's field, an open editor), a canvas element.
    const wrapper = {};
    const holder = ({ chrome, tagName }) => ({
      blurred: 0,
      tagName,
      isContentEditable: false,
      closest: (selector) => (chrome && selector === '.daedalus-ui-sidebar-wrapper' ? wrapper : null),
      blur() {
        this.blurred += 1;
      },
    });
    const gap = {
      kind: 'gap',
      gapId: 'gap',
      location: { anchor: 'anchor', placement: 'after' },
      insert: { parent: null, before: null },
      items: [],
    };
    const settled = () => new Promise((resolve) => setImmediate(resolve));

    // The pointer door: dragstart on a tray item, a target under the
    // cursor, the document's drop.
    const item = { setAttribute() {} };
    controller.makeDraggable(item, 'probe');
    const pointerDrop = async (focused, target = gap) => {
      item.ondragstart({ stopPropagation() {}, dataTransfer: { setData() {} } });
      if (target) {
        controller.setCurrentDrop(target);
      }
      document.activeElement = focused;
      documentListeners.get('drop')({ preventDefault() {}, dataTransfer: null });
      item.ondragend({ stopPropagation() {} });
      await settled();
    };

    const control = holder({ chrome: true, tagName: 'DIV' });
    await pointerDrop(control);
    assert.equal(control.blurred, 1, 'a pointer drop blurs the chrome control that held focus');

    const field = holder({ chrome: true, tagName: 'INPUT' });
    await pointerDrop(field);
    assert.equal(field.blurred, 0, 'an open editor field in the sidebar keeps focus');

    const canvas = holder({ chrome: false, tagName: 'DIV' });
    await pointerDrop(canvas);
    assert.equal(canvas.blurred, 0, 'focus inside the canvas is not the drop\'s to move');

    const untaken = holder({ chrome: true, tagName: 'DIV' });
    await pointerDrop(untaken, null);
    assert.equal(untaken.blurred, 0, 'a drop with no target commits nothing and releases nothing');

    // The keyboard door: an externally armed drag (the keyboard lift's
    // arming) dropped through commitCurrentDrop.
    const lifted = holder({ chrome: true, tagName: 'DIV' });
    assert.equal(controller.beginExternalDrag('probe', {}), true);
    controller.setCurrentDrop(gap);
    document.activeElement = lifted;
    assert.equal(controller.commitCurrentDrop(), true, 'the keyboard door commits the drop');
    await settled();
    assert.equal(lifted.blurred, 0, 'a keyboard placement keeps focus on the panel item');
  } finally {
    globalThis.document = saved.document;
    globalThis.drupalSettings = saved.drupalSettings;
    globalThis.BroadcastChannel = saved.BroadcastChannel;
    Drupal.Daedalus.Blueprint = saved.Blueprint;
  }
});
