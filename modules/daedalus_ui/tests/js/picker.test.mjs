/**
 * @file
 * The element picker's toggle model (selection/picker.js) and the
 * Selector grammar's disarm rungs it relies on (selector-events.js), over
 * the real store.
 *
 * The toggle: a press arms, and a second press disarms; leaving Edit Mode
 * (the store's mode slice) drops the arming, since the grammar's handlers
 * early-return outside the mode. The `selector.picker` hotkey is a
 * remappable global binding on mod+shift+c that fires the same toggle
 * through the dispatcher, and Escape's first rung drops the armed picker
 * while keeping the selection and its styling target (the ladder
 * continues from the next press).
 *
 * The rest of the Selector's Escape ladder is locked here too, over the
 * same dispatcher: a standing target drops next with the selection kept,
 * and then ONE press clears the selection together with whatever drill
 * context stands, seated the way ArrowLeft seats it (the real traversal
 * binding over a booted blueprint's projection index: Left commits the
 * parent and writes the grandparent as the context) or the way the
 * facade's drill() seats it (a setDrill write). Escape never reads the
 * context as a rung to climb; a stray context with nothing selected clears
 * the same way, and with nothing at all standing the press propagates.
 *
 * The disarm on a handled canvas click is the FJS leg's (it needs real
 * canvas markup for the resolver); everything else about the picker is
 * locked here. The grammar imports the app store and the hotkey
 * dispatcher (webpack-style extensionless imports; window, document,
 * Element and Drupal globals), so the suite registers the extension-adding
 * resolve hook and stubs the globals before dynamically importing, and
 * captures the document listeners the dispatcher attaches so a synthetic
 * keydown can be fed through it.
 *
 * Run: node --test modules/daedalus_ui/tests/js/picker.test.mjs
 */

import { strict as assert } from 'node:assert';
import { test, beforeEach } from 'node:test';
import { registerHooks } from 'node:module';

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

const listeners = new Map();
globalThis.window = globalThis;
// The dispatcher's window blur listener (hold releases); nothing here holds.
globalThis.addEventListener = () => {};
// The page-elements mount and DOM focus, for the panel release
// (panel-focus.js): a case seats a focused row inside the mount, and the
// release reads both through the document.
let panelMount = null;
globalThis.document = {
  addEventListener: (type, listener) => {
    listeners.set(type, [...(listeners.get(type) ?? []), listener]);
  },
  querySelector: () => null,
  getElementById: (id) => (id === 'page-elements-tree' ? panelMount : null),
  activeElement: null,
  documentElement: { addEventListener: () => {} },
};
// The typing guard asks `target instanceof Element`; a plain synthetic
// target is then "not typing", which is what a canvas keystroke is.
globalThis.Element = class Element {};
globalThis.drupalSettings = {};
globalThis.Drupal = {
  t: (string) => string,
  Daedalus: {
    // The one reveal rule (daedalus_ui/scroll_reveal) is a root library;
    // node has no canvas to scroll.
    scrollReveal: () => {},
  },
};

const { default: store } = await import('../../js/edit_mode/store/store.js');
const { setMode } = await import('../../js/edit_mode/store/slices/mode-slice.js');
const { setHotKey } = await import('../../js/edit_mode/store/slices/hot-key-slice.js');
const { setSelected, setTarget, setDrill, setPickerArmed, clearSelection } = await import(
  '../../js/edit_mode/store/slices/selection-slice.js'
);
const { blueprintBooted } = await import('../../js/edit_mode/store/slices/blueprint-slice.js');
const { togglePicker, isPickerArmed } = await import('../../js/edit_mode/selection/picker.js');
const { initHotkeys, getBindings } = await import('../../js/edit_mode/hotkeys/hotkeys.js');
const { parseBinding } = await import('../../js/edit_mode/hotkeys/binding.js');
const { initSelectorEvents } = await import('../../js/edit_mode/selection/selector-events.js');
const { initKeyboardTraversal } = await import('../../js/edit_mode/selection/keyboard-traversal.js');

initHotkeys();
initSelectorEvents();
initKeyboardTraversal();
store.dispatch(setHotKey(true));

/**
 * A three-level page for the traversal-seated drill: the root entity, one
 * section, one merged text block. Like the delivered mirror it carries
 * both section shapes (the lean `_tree` the projection reads and the
 * classic layout_builder__layout the composition walks) from one
 * component list, so the index places the block under the section under
 * the root. ArrowLeft from the block then commits the section and seats
 * the drill context at the root: a non-null context with no double-click
 * involved, the shape the bug rode.
 */
const ROOT = '99999999-1111-2222-3333-444444444444';
const SECTION = 'aaaaaaaa-1111-2222-3333-444444444444';
const BLOCK = 'bbbbbbbb-1111-2222-3333-444444444444';
const ROOT_KEY = `entity:${ROOT}`;
const SECTION_KEY = `container:${SECTION}`;
const BLOCK_KEY = `block:${BLOCK}`;
const bootPage = () => {
  const components = [
    {
      kind: 'block',
      uuid: BLOCK,
      block_type: 'text',
      label: 'Text',
      block_fields: { field_text_text: 'Body' },
      _element: { presentation: { mode: 'merged', edit_target: 'field_text_text' } },
    },
  ];
  store.dispatch(blueprintBooted({
    entityType: 'node',
    entityId: '1',
    uuid: ROOT,
    label: 'Page',
    langcode: 'en',
    viewMode: 'full',
    blueprint: {
      bundle: 'page',
      fields: {},
      layout_builder__layout: [{ uuid: SECTION, layout_id: 'layout_onecol', regions: { content: components } }],
      _tree: [{
        kind: 'container',
        uuid: SECTION,
        layout_id: 'layout_onecol',
        children: [{ kind: 'region', region: 'content', children: components }],
      }],
    },
  }));
};

const selection = () => store.getState().selection;

/**
 * Feeds a keydown for a binding string through the dispatcher's document
 * listener, as the platform would produce it ('mod' resolved the way the
 * registry resolves it).
 */
const keydown = (binding) => {
  const { key, modifiers } = parseBinding(binding);
  const event = {
    key,
    code: /^[a-z]$/.test(key) ? `Key${key.toUpperCase()}` : key,
    metaKey: modifiers.meta,
    ctrlKey: modifiers.ctrl,
    altKey: modifiers.alt,
    shiftKey: modifiers.shift,
    target: {},
    handled: false,
    preventDefault() {
      this.handled = true;
    },
    stopPropagation() {},
  };
  (listeners.get('keydown') ?? []).forEach((listener) => listener(event));
  return event;
};

beforeEach(() => {
  store.dispatch(setMode('edit'));
  store.dispatch(clearSelection());
  store.dispatch(setPickerArmed(false));
  panelMount = null;
  document.activeElement = null;
});

/**
 * Seats DOM focus on a page-elements row (or elsewhere) for the release
 * cases; returns the focused stub, whose blur count the case reads.
 */
const focusRow = ({ inPanel }) => {
  const focused = {
    blurs: 0,
    blur() {
      this.blurs += 1;
    },
  };
  panelMount = { contains: (element) => inPanel && element === focused };
  document.activeElement = focused;
  return focused;
};

test('selector.picker registers as a remappable global binding on mod+shift+c under Selection', () => {
  const binding = getBindings().find(({ id }) => id === 'selector.picker');
  assert.deepEqual(binding, {
    id: 'selector.picker',
    key: 'mod+shift+c',
    when: ['global'],
    kind: 'press',
    label: 'Pick an element',
    section: 'Selection',
    remappable: true,
  });
});

test('the toggle arms, and a second press disarms', () => {
  togglePicker();
  assert.equal(isPickerArmed(), true);
  togglePicker();
  assert.equal(isPickerArmed(), false);
});

test('leaving Edit Mode drops the arming; the mode slice holds the mode id itself', () => {
  togglePicker();
  assert.equal(isPickerArmed(), true);
  window.setMode(null);
  assert.equal(store.getState().mode.mode, null, 'window.setMode stores the id, not an object');
  assert.equal(isPickerArmed(), false, 'an armed picker outside the mode is meaningless');

  window.setMode('edit');
  assert.equal(store.getState().mode.mode, 'edit');
  togglePicker();
  assert.equal(isPickerArmed(), true, 'arming works again once the mode is back');
});

test('the hotkey fires the same toggle through the dispatcher', () => {
  let event = keydown('mod+shift+c');
  assert.equal(event.handled, true);
  assert.equal(isPickerArmed(), true);
  event = keydown('mod+shift+c');
  assert.equal(event.handled, true);
  assert.equal(isPickerArmed(), false);
});

test('the hotkey is a strict combo: a plain c or mod+c does not toggle', () => {
  keydown('c');
  keydown('mod+c');
  assert.equal(isPickerArmed(), false);
});

test('Escape drops the armed picker first, keeping the selection and its target; the next Escape continues the ladder', () => {
  store.dispatch(setSelected('block:a'));
  store.dispatch(setTarget({ key: 'block:a', selector: '.icon' }));
  togglePicker();

  let event = keydown('escape');
  assert.equal(event.handled, true);
  assert.equal(isPickerArmed(), false);
  assert.equal(store.getState().selection.selectedKey, 'block:a');
  assert.deepEqual(store.getState().selection.target, { key: 'block:a', selector: '.icon' });

  event = keydown('escape');
  assert.equal(event.handled, true);
  assert.equal(store.getState().selection.target, null);
  assert.equal(store.getState().selection.selectedKey, 'block:a');
});

test('Escape after ArrowLeft seated the drill context clears the selection and the context in one press', () => {
  bootPage();
  store.dispatch(setSelected(BLOCK_KEY));

  let event = keydown('arrowleft');
  assert.equal(event.handled, true);
  assert.equal(selection().selectedKey, SECTION_KEY, 'Left commits the parent');
  assert.equal(selection().drillKey, ROOT_KEY, 'Left seats the grandparent as the context');

  event = keydown('escape');
  assert.equal(event.handled, true);
  assert.equal(selection().selectedKey, null);
  assert.equal(selection().drillKey, null);
  assert.deepEqual(selection().selectedKeys, []);
});

test('Escape with a context seated through the facade\'s drill() (a setDrill write) deselects instead of climbing', () => {
  store.dispatch(setSelected('block:child'));
  // Selection.drill(key) is store.dispatch(setDrill(key)) verbatim
  // (selection-facade.js); the double-click drill-in writes the same pair.
  store.dispatch(setDrill('block:parent'));

  const event = keydown('escape');
  assert.equal(event.handled, true);
  assert.equal(selection().selectedKey, null, 'the context never becomes the selection');
  assert.equal(selection().drillKey, null);
});

test('with a target and a drill context standing, Escape drops the target first and the next press clears both selection and context', () => {
  store.dispatch(setSelected('block:child'));
  store.dispatch(setTarget({ key: 'block:child', selector: '.icon' }));
  store.dispatch(setDrill('block:parent'));

  let event = keydown('escape');
  assert.equal(event.handled, true);
  assert.equal(selection().target, null);
  assert.equal(selection().selectedKey, 'block:child', 'the target rung keeps the selection');
  assert.equal(selection().drillKey, 'block:parent', 'the target rung leaves the context alone');

  event = keydown('escape');
  assert.equal(event.handled, true);
  assert.equal(selection().selectedKey, null);
  assert.equal(selection().drillKey, null);
});

test('a stray drill context with nothing selected is still the Selector\'s: Escape clears it', () => {
  store.dispatch(setDrill('block:parent'));

  const event = keydown('escape');
  assert.equal(event.handled, true);
  assert.equal(selection().drillKey, null);
  assert.equal(selection().selectedKey, null);
});

test('Escape\'s clear releases the panel: the focused tree row is blurred with the selection', () => {
  bootPage();
  store.dispatch(setSelected(BLOCK_KEY));
  const row = focusRow({ inPanel: true });

  const event = keydown('escape');
  assert.equal(event.handled, true);
  assert.equal(selection().selectedKey, null);
  assert.equal(row.blurs, 1, 'the row of the gone selection no longer holds focus');
});

test('Escape\'s clear leaves focus outside the panel where it is', () => {
  bootPage();
  store.dispatch(setSelected(BLOCK_KEY));
  const control = focusRow({ inPanel: false });

  const event = keydown('escape');
  assert.equal(event.handled, true);
  assert.equal(selection().selectedKey, null);
  assert.equal(control.blurs, 0, 'a canvas or top-bar control is not the panel\'s to release');
});

test('the picker and target rungs keep the selection, so they keep the panel\'s focus', () => {
  bootPage();
  store.dispatch(setSelected(BLOCK_KEY));
  store.dispatch(setTarget({ key: BLOCK_KEY, selector: '.icon' }));
  store.dispatch(setPickerArmed(true));
  const row = focusRow({ inPanel: true });

  keydown('escape');
  assert.equal(isPickerArmed(), false);
  assert.equal(row.blurs, 0, 'the picker rung keeps the focused row');
  keydown('escape');
  assert.equal(selection().target, null);
  assert.equal(selection().selectedKey, BLOCK_KEY);
  assert.equal(row.blurs, 0, 'the target rung keeps the focused row');
  keydown('escape');
  assert.equal(selection().selectedKey, null);
  assert.equal(row.blurs, 1, 'the clear releases it');
});

test('Escape with nothing armed, targeted, drilled or selected is not the Selector\'s (the event propagates)', () => {
  const event = keydown('escape');
  assert.equal(event.handled, false);
});
