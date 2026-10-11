/**
 * Self-asserting tests for the desktop file-drag detector + source
 * (file-drag.js).
 *
 * file-drag.js is a detector plus ONE registered drag-layer source with two
 * faces: the gap face places the dropped file as a new media block, the
 * element face replaces a compatible [data-media-reference] element's file.
 * These tests cover the seams that need no real DOM: the source spec's
 * shape, the element face's mime-compatibility filter, and the detector's
 * arm/disarm behavior against stubbed document listeners.
 *
 * The module is browser code loaded via the registration queue seam — stub
 * window/document/Drupal/jQuery BEFORE import, then run the queued
 * registration callback with a fake app to capture the source spec.
 *
 * Run with `node --test tests/js/*.test.mjs` from the module root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';

// ---- Stubs the module reads at import or registration time. ----

const calls = {
  begin: [],
  end: 0,
  messages: [],
};

const documentListeners = new Map();
globalThis.document = {
  addEventListener: (type, fn) => documentListeners.set(type, fn),
  removeEventListener: (type, fn) => {
    if (documentListeners.get(type) === fn) {
      documentListeners.delete(type);
    }
  },
  // No conflicting uploader widgets on the page.
  querySelector: () => null,
  getElementById: () => null,
  body: {},
};

globalThis.MutationObserver = class {
  observe() {}
  disconnect() {}
};

globalThis.window = {
  innerWidth: 1024,
  innerHeight: 768,
  addEventListener: () => {},
};

globalThis.drupalSettings = {};

globalThis.Drupal = {
  t: (str) => str,
  Daedalus: {
    Drag: {
      beginExternalDrag: (type, data) => {
        calls.begin.push({ type, data });
        return true;
      },
      endExternalDrag: () => {
        calls.end += 1;
      },
    },
    ModeManager: {
      getPlugin: () => ({
        message: (text) => calls.messages.push(text),
      }),
    },
    ModePluginBase: { url: (path) => path },
  },
};

const $ = () => {};
$.fn = {};
globalThis.jQuery = $;

window.DaedalusUiQueue = [];

let stateCallback = null;
window.listenToMultipleStates = (selectors, callback) => {
  stateCallback = callback;
};

await import('../../js/edit_mode/not-tools/file_upload/file-drag.js');

// Drain the queue seam with a fake app, capturing the registered source.
const sources = new Map();
const app = {
  drag: {
    registerSource: (type, spec) => sources.set(type, spec),
    builders: { newBlock: (target, options) => [{ target, options }] },
  },
  // The one client gate, as the face asks it: content of the landing
  // element's key. The stub refuses one key.
  Selection: {
    capable: (key, capability) =>
      typeof key === 'string' && capability === 'content' && key !== 'block:refused',
  },
};
window.DaedalusUiQueue.forEach((callback) => callback(app));

const spec = sources.get('media_file');

// The mode slice holds the mode id itself ('edit' or null).
const enable = () => stateCallback('edit', { enabled: true });
const disable = () => stateCallback('edit', { enabled: false });

const filesDrag = (types) => ({
  dataTransfer: {
    types: ['Files'],
    items: types.map((type) => ({ type })),
  },
  preventDefault: () => {},
});

test('registration: ONE media_file source carries both faces', () => {
  assert.ok(spec, 'the media_file source registered through the queue seam');
  assert.equal(spec.targets, 'block');
  assert.equal(typeof spec.operations, 'function', 'the gap face (place new)');
  assert.equal(spec.elements.selector, '[data-media-reference]', 'the element face (replace)');
  assert.equal(typeof spec.elements.accepts, 'function');
  assert.equal(typeof spec.elements.operations, 'function');
});

// A media element inside a selectable element: closest() answers the
// element's key, as the canvas does.
const mediaIn = (key, dataset = { acceptedFiles: '.JPG,.jpeg,png' }) => ({
  dataset,
  closest: (selector) => (selector === '[data-selectable]' ? { getAttribute: () => key } : null),
});

test('element face accepts: extension compatibility against the media constraint', () => {
  const media = mediaIn('block:allowed');
  // Match is case-insensitive and dot-normalized on both sides.
  assert.equal(spec.elements.accepts(media, { fileExtensions: ['.png'] }), true);
  assert.equal(spec.elements.accepts(media, { fileExtensions: ['jpg'] }), true);
  assert.equal(spec.elements.accepts(media, { fileExtensions: ['.gif'] }), false);
  // A side without a declared constraint is compatible — the server
  // validates for real on upload.
  assert.equal(spec.elements.accepts(media, { fileExtensions: null }), true);
  assert.equal(spec.elements.accepts(mediaIn('block:allowed', {}), { fileExtensions: ['.gif'] }), true);
});

test('element face accepts: the one gate, content of the landing element', () => {
  // A compatible file still lands nowhere the actor may not write: the
  // face asks capable(key, "content") of the element the media belongs to.
  assert.equal(spec.elements.accepts(mediaIn('block:refused'), { fileExtensions: ['.png'] }), false);
  assert.equal(spec.elements.accepts(mediaIn('block:allowed'), { fileExtensions: ['.png'] }), true);
  // Media outside any selectable element has no verdict: refused.
  const stray = { dataset: { acceptedFiles: 'png' }, closest: () => null };
  assert.equal(spec.elements.accepts(stray, { fileExtensions: ['.png'] }), false);
});

test('gap face operations: a drop that carried no file aborts', async () => {
  assert.equal(await spec.operations({}, { files: [] }), null);
  assert.equal(await spec.operations({}, {}), null);
});

test('detector: a Files dragenter arms the source with the mime\'s extensions', () => {
  enable();
  assert.ok(documentListeners.has('dragenter'), 'the detector attached');
  documentListeners.get('dragenter')(filesDrag(['image/png']));
  assert.equal(calls.begin.length, 1);
  assert.equal(calls.begin[0].type, 'media_file');
  assert.deepEqual(calls.begin[0].data.fileExtensions, ['.png'], 'the element face\'s compat input rides the drag data');
  // Re-arming per dragenter is the self-healing guard; still one call per
  // event, the layer's own guard absorbs it.
  documentListeners.get('dragenter')(filesDrag(['image/png']));
  assert.equal(calls.begin.length, 2);
  disable();
});

test('detector: non-file drags pass through untouched', () => {
  enable();
  const before = calls.begin.length;
  documentListeners.get('dragenter')({
    dataTransfer: { types: ['text/plain'], items: [{ type: 'text/plain' }] },
  });
  assert.equal(calls.begin.length, before);
  disable();
});

test('detector: a multi-file drag messages once and never arms', () => {
  enable();
  const before = calls.begin.length;
  documentListeners.get('dragenter')(filesDrag(['image/png', 'image/png']));
  documentListeners.get('dragenter')(filesDrag(['image/png', 'image/png']));
  assert.equal(calls.begin.length, before, 'the drag layer never armed');
  assert.equal(calls.messages.length, 1, 'one message per drag session');
  disable();
});

test('detector: disable removes the listeners and disarms the layer', () => {
  enable();
  const endsBefore = calls.end;
  disable();
  assert.ok(!documentListeners.has('dragenter'));
  assert.ok(!documentListeners.has('dragover'));
  assert.ok(!documentListeners.has('drop'));
  assert.ok(!documentListeners.has('dragleave'));
  assert.ok(calls.end > endsBefore, 'a mid-drag disable ends the external drag');
});
