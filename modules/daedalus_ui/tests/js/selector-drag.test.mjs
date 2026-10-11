/**
 * Self-asserting tests for the canvas drag delegate's arming
 * (selector-drag.js syncArming).
 *
 * The seam is pure: store state in, the main entity wrapper's `draggable`
 * attribute out. Hover on a movable arms the wrapper in Edit Mode; an
 * open edit (selection.editingKey) disarms it whatever the hover says,
 * because WebKit lets a draggable ancestor win the mousedown over an
 * editable descendant (a text selection inside the typing surface would
 * drag the block); when the edit closes, arming resumes from the hover
 * key. No browser tier can state the rule this directly: Selenium is
 * Chromium, which never shows the Safari failure.
 *
 * Same harness as drag.test.mjs: the app modules import webpack-style
 * (extensionless relative specifiers) and touch document, window,
 * drupalSettings and BroadcastChannel at import time, so the test
 * registers an extension-adding resolve hook and stubs the globals before
 * dynamically importing them. The fake document answers two queries:
 * `[data-main-entity]` with a wrapper that records its attribute writes,
 * and `[data-selectable="..."]` with a minimal element per key, enough for
 * movableFor to resolve a block placement.
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
  };
};

/**
 * An element whose attributes are a map and whose writes are logged.
 */
const fakeElement = (attributes, log = null) => ({
  attributes: new Map(Object.entries(attributes)),
  isConnected: true,
  parentElement: null,
  hasAttribute(name) {
    return this.attributes.has(name);
  },
  getAttribute(name) {
    return this.attributes.get(name) ?? null;
  },
  setAttribute(name, value) {
    this.attributes.set(name, value);
    log?.push(`set:${name}=${value}`);
  },
  removeAttribute(name) {
    this.attributes.delete(name);
    log?.push(`remove:${name}`);
  },
});

globalThis.requestAnimationFrame = () => 1;
globalThis.cancelAnimationFrame = () => {};
globalThis.window = fakeTarget();
globalThis.Drupal = { t: (string) => string, behaviors: {} };
createRequire(import.meta.url)('../../js/drag.js');

const OWN = 'bbbbbbbb-1111-2222-3333-444444444444';
const OWN_BLOCK = 'dddddddd-1111-2222-3333-444444444444';
const BLOCK_KEY = `block:${OWN_BLOCK}`;
const SECTION_KEY = `container:${OWN}`;

const wrapperLog = [];
const wrapper = fakeElement({ 'data-main-entity': '' }, wrapperLog);
const selectables = new Map([
  [BLOCK_KEY, fakeElement({ 'data-selectable': BLOCK_KEY })],
  [SECTION_KEY, fakeElement({ 'data-selectable': SECTION_KEY })],
]);

globalThis.document = {
  addEventListener() {},
  removeEventListener() {},
  querySelector: (selector) => {
    if (selector === '[data-main-entity]') {
      return wrapper;
    }
    const match = /^\[data-selectable="(.+)"\]$/.exec(selector);
    return match ? (selectables.get(match[1]) ?? null) : null;
  },
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

const controller = await import('../../js/edit_mode/drag/drag-controller.js');
const { initSelectorDrag, movableFor } = await import('../../js/edit_mode/drag/selector-drag.js');
const { default: store } = await import('../../js/edit_mode/store/store.js');
const { blueprintBooted } = await import('../../js/edit_mode/store/slices/blueprint-slice.js');
const { setMode } = await import('../../js/edit_mode/store/slices/mode-slice.js');
const { setHover, setEditingKey } = await import('../../js/edit_mode/store/slices/selection-slice.js');

const armed = () => wrapper.getAttribute('draggable') === 'true';

/**
 * Boots the mirror with one section the page owns holding one text block,
 * as drag.test.mjs does, so the selection gate admits both keys as hover.
 */
const bootBlueprint = () => {
  const ROOT = '99999999-1111-2222-3333-444444444444';
  const open = { content: true, configure: true, style: true, arrange: true, insert: true };
  const named = (name) => ({ name, type: name });
  store.dispatch(blueprintBooted({
    entityType: 'node',
    entityId: '1',
    uuid: ROOT,
    label: 'Page',
    langcode: 'en',
    viewMode: 'full',
    verdict: { can: { ...open, insert: false }, refused: [] },
    blueprint: {
      bundle: 'page',
      fields: {},
      _element: { ...named('Page'), can: { ...open, insert: false } },
      layout: [{
        kind: 'container',
        uuid: OWN,
        layout_id: 'layout_onecol',
        _element: { ...named('Section'), can: open },
        children: [{
          kind: 'region',
          region: 'content',
          children: [{
            kind: 'block', uuid: OWN_BLOCK, block_type: 'text', label: 'Text', block_fields: {}, _element: { ...named('Text'), can: open },
          }],
        }],
      }],
    },
  }));
};

test('an open edit disarms the canvas drag delegate; closing it re-arms from the hover key', () => {
  controller.initDrag();
  initSelectorDrag();
  bootBlueprint();
  assert.equal(movableFor(BLOCK_KEY), selectables.get(BLOCK_KEY), 'the fake DOM resolves the block placement as its own movable');

  store.dispatch(setMode('edit'));
  store.dispatch(setHover(BLOCK_KEY));
  assert.equal(store.getState().selection.hoverKey, BLOCK_KEY, 'the gate admits the hover');
  assert.equal(armed(), true, 'hovering a movable in Edit Mode arms the wrapper');
  assert.equal(typeof wrapper.ondragstart, 'function', 'the wrapper is the delegated handle');
  assert.deepEqual(wrapperLog, ['set:draggable=true']);
  store.dispatch(setHover(BLOCK_KEY));
  assert.deepEqual(wrapperLog, ['set:draggable=true'], 'a dispatch changing none of the four memoized inputs touches the DOM not at all');

  store.dispatch(setEditingKey(BLOCK_KEY));
  assert.equal(armed(), false, 'an open edit disarms the wrapper while the pointer still rests on the block');

  // The rule is the open edit itself, not containment: hovering another
  // movable mid-edit (a press there commits the edit first) arms nothing.
  store.dispatch(setHover(SECTION_KEY));
  assert.equal(store.getState().selection.hoverKey, SECTION_KEY);
  assert.equal(armed(), false, 'no movable arms while any edit is open');
  store.dispatch(setHover(BLOCK_KEY));
  assert.equal(armed(), false);

  store.dispatch(setEditingKey(null));
  assert.equal(armed(), true, 'closing the edit re-arms from the current hover key');

  store.dispatch(setHover(null));
  assert.equal(armed(), false, 'and hover-only arming is back: nothing under the pointer disarms');
});
