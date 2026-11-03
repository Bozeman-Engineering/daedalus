/**
 * Self-asserting tests for the top bar menu's pure item composer
 * (js/edit_mode/tool_manager/top-bar-menu-items.js): the Plan's order
 * with its two submenus and separators, the rows absent when their fact
 * is off (no preview, no document verbs, no settings door, no panels),
 * the Panels rows checked from the open set with hints from the hotkey
 * map, the Editing chrome rows checked from the density, and each row's
 * run reaching the action it was composed with.
 *
 * The trigger (top-bar.js) hands the result to the menu primitive; the
 * primitive's own grammar is covered by tests/js/menu.test.mjs.
 *
 * Run with `node --test tests/js/top-bar-menu-items.test.mjs` from the
 * module root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';

globalThis.Drupal = {
  t: (string, args = {}) =>
    Object.entries(args).reduce(
      (text, [name, value]) => text.replace(name, value),
      string,
    ),
};

const { topBarMenuItems } = await import(
  '../../js/edit_mode/tool_manager/top-bar-menu-items.js'
);

const PANELS = [
  { id: 'page_elements', label: 'Page elements', side: 'left' },
  { id: 'place_block', label: 'Blocks', side: 'left' },
  { id: 'explode', label: 'Explode', side: 'right' },
];

const HOT_KEYS = {
  'sidebar.page_elements': { key: 'e', label: 'Toggle the Page elements panel', sidebar: 'page_elements' },
  'sidebar.place_block': { key: '', label: 'Toggle the Blocks panel', sidebar: 'place_block' },
  'sidebar.explode': { key: 'mod+x', label: 'Toggle the Explode panel', sidebar: 'explode' },
  'history.undo': { key: 'mod+z' },
};

const DOCUMENT_ITEMS = [
  { label: 'Save page as blueprint…', icon: null, run: () => {} },
];

const everything = (overrides = {}) => ({
  panels: PANELS,
  openPanelIds: ['page_elements'],
  hotKeys: HOT_KEYS,
  previewAvailable: true,
  documentItems: DOCUMENT_ITEMS,
  settingsUrl: '/admin/config/daedalus',
  density: 'minimal',
  ...overrides,
});

const calls = () => {
  const log = [];
  const record = (name) => (...args) => log.push([name, ...args]);
  return {
    log,
    actions: {
      togglePanel: record('togglePanel'),
      openPreview: record('openPreview'),
      refresh: record('refresh'),
      discard: record('discard'),
      setDensity: record('setDensity'),
      keyboardShortcuts: record('keyboardShortcuts'),
      openSettings: record('openSettings'),
      exit: record('exit'),
    },
  };
};

// The shape of the list: a label, '---' for a separator, '[label]' for a
// submenu row.
const shape = (items) =>
  items.map((item) =>
    item.separator ? '---' : item.items ? `[${item.label}]` : item.label,
  );

test('the Plan order, with every row present', () => {
  const items = topBarMenuItems(everything(), calls().actions);
  assert.deepEqual(shape(items), [
    '[Panels]',
    'Open preview window',
    '---',
    'Save page as blueprint…',
    '---',
    'Refresh the editor',
    'Discard changes…',
    '---',
    '[Editing chrome]',
    'Keyboard shortcuts',
    'Daedalus settings',
    '---',
    'Exit Edit Mode',
  ]);
});

test('the submenus carry items and no run; Discard is danger without a confirm', () => {
  const items = topBarMenuItems(everything(), calls().actions);
  const panels = items[0];
  assert.equal(panels.run, undefined);
  assert.equal(panels.checked, undefined);
  assert.equal(panels.items.length, 3);
  const chrome = items.find((item) => item.label === 'Editing chrome');
  assert.equal(chrome.run, undefined);
  assert.deepEqual(
    chrome.items.map(({ label }) => label),
    ['Everything', 'Minimal', 'Clean'],
  );
  const discard = items.find((item) => item.label === 'Discard changes…');
  assert.equal(discard.danger, true);
  assert.equal(discard.confirm, undefined);
});

test('the Panels rows: checked from the open set, hints from the hotkey map', () => {
  const items = topBarMenuItems(everything(), calls().actions);
  assert.deepEqual(
    items[0].items.map(({ label, checked, hint }) => ({ label, checked, hint })),
    [
      { label: 'Page elements', checked: true, hint: 'E' },
      { label: 'Blocks', checked: false, hint: null },
      { label: 'Explode', checked: false, hint: 'MOD+X' },
    ],
  );
});

test('a Panels row runs the toggle with the opposite of its checked state', () => {
  const { log, actions } = calls();
  const items = topBarMenuItems(everything(), actions);
  items[0].items[0].run();
  items[0].items[2].run();
  assert.deepEqual(log, [
    ['togglePanel', 'page_elements', false],
    ['togglePanel', 'explode', true],
  ]);
});

test('the Editing chrome rows: the current density checked, a row sets its value', () => {
  const { log, actions } = calls();
  const items = topBarMenuItems(everything({ density: 'clean' }), actions);
  const chrome = items.find((item) => item.label === 'Editing chrome');
  assert.deepEqual(
    chrome.items.map(({ checked }) => checked),
    [false, false, true],
  );
  chrome.items[1].run();
  assert.deepEqual(log, [['setDensity', 'minimal']]);
});

test('the plain rows reach their actions', () => {
  const { log, actions } = calls();
  const items = topBarMenuItems(everything(), actions);
  const run = (label) => items.find((item) => item.label === label).run();
  run('Open preview window');
  run('Refresh the editor');
  run('Discard changes…');
  run('Keyboard shortcuts');
  run('Daedalus settings');
  run('Exit Edit Mode');
  assert.deepEqual(log, [
    ['openPreview'],
    ['refresh'],
    ['discard'],
    ['keyboardShortcuts'],
    ['openSettings', '/admin/config/daedalus'],
    ['exit'],
  ]);
});

test('the document items pass through untouched, as the very objects', () => {
  const items = topBarMenuItems(everything(), calls().actions);
  assert.equal(items[3], DOCUMENT_ITEMS[0]);
});

test('no preview: the row is absent and the document group follows Panels', () => {
  const items = topBarMenuItems(
    everything({ previewAvailable: false }),
    calls().actions,
  );
  assert.deepEqual(shape(items).slice(0, 4), [
    '[Panels]',
    '---',
    'Save page as blueprint…',
    '---',
  ]);
});

test('no document verbs: no group and no doubled separator', () => {
  const items = topBarMenuItems(
    everything({ documentItems: [] }),
    calls().actions,
  );
  assert.deepEqual(shape(items).slice(0, 4), [
    '[Panels]',
    'Open preview window',
    '---',
    'Refresh the editor',
  ]);
  assert.equal(
    shape(items).some((entry, index, all) => entry === '---' && all[index + 1] === '---'),
    false,
  );
});

test('no settings door: the row is absent, Keyboard shortcuts leads to the exit group', () => {
  const items = topBarMenuItems(
    everything({ settingsUrl: null }),
    calls().actions,
  );
  assert.deepEqual(shape(items).slice(-4), [
    '[Editing chrome]',
    'Keyboard shortcuts',
    '---',
    'Exit Edit Mode',
  ]);
});

test('nothing above the actions: no panels, no preview, no verbs leaves no leading separator', () => {
  const items = topBarMenuItems(
    everything({ panels: [], previewAvailable: false, documentItems: [] }),
    calls().actions,
  );
  assert.deepEqual(shape(items), [
    'Refresh the editor',
    'Discard changes…',
    '---',
    '[Editing chrome]',
    'Keyboard shortcuts',
    'Daedalus settings',
    '---',
    'Exit Edit Mode',
  ]);
});

test('an absent hotkey map hints nothing', () => {
  const items = topBarMenuItems(everything({ hotKeys: {} }), calls().actions);
  assert.deepEqual(
    items[0].items.map(({ hint }) => hint),
    [null, null, null],
  );
});
