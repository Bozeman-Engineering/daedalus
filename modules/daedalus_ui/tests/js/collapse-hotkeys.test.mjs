/**
 * @file
 * The collapse hotkey registrar (outline/collapse-hotkeys.js) over the
 * real dispatcher.
 *
 * An outline registers `${id}.collapse_all` and `${id}.expand_all` as
 * remappable global bindings in the Panels section, labeled by the
 * outline's name; a missing default key registers unbound (code mode's
 * default), listed in the tray but never firing. Dispatching a bound key
 * runs the adapter's read and hands apply the model's next posture with
 * the gesture's name, an unchanged posture included (the identity rule
 * makes it a no-op for a state setter; a forwarding adapter judges
 * nothing here), and the stroke is handled. The returned unregister
 * removes both bindings, after which the key propagates.
 *
 * The dispatcher's pass rule is locked here too, over the same facade
 * (PASS is a facade entry, the road a binding registered outside the
 * bundle returns it by): the winner runs first; only an action returning
 * PASS hands the stroke to the next live candidate in winning order;
 * false or a handled verdict stops there; when every candidate passes
 * the app did not handle the key; and a hold-kind binding that passes is
 * never held, so its release never fires.
 *
 * The registrar reaches the dispatcher through the facade initHotkeys
 * publishes (Drupal.Daedalus.Hotkeys; js/outline/ never imports the
 * app's stateful modules, since other bundles compile it in), and the
 * dispatcher itself uses webpack-style extensionless imports and the
 * window, document, Element and Drupal globals, so the suite registers
 * the extension-adding resolve hook and stubs the globals before
 * dynamically importing, boots the real dispatcher, and captures the
 * document listeners it attaches so a synthetic keydown can be fed
 * through it (the picker.test.mjs idiom).
 *
 * Run: node --test modules/daedalus_ui/tests/js/collapse-hotkeys.test.mjs
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
globalThis.document = {
  addEventListener: (type, listener) => {
    listeners.set(type, [...(listeners.get(type) ?? []), listener]);
  },
  querySelector: () => null,
  getElementById: () => null,
  activeElement: null,
  documentElement: { addEventListener: () => {} },
};
// The typing guard asks `target instanceof Element`; a plain synthetic
// target is then "not typing", which is what a canvas keystroke is.
globalThis.Element = class Element {};
globalThis.drupalSettings = {};
globalThis.Drupal = {
  t: (string, args = {}) =>
    Object.entries(args).reduce(
      (text, [placeholder, value]) => text.replaceAll(placeholder, value),
      string,
    ),
  Daedalus: {},
};

const { default: store } = await import('../../js/edit_mode/store/store.js');
const { setHotKey } = await import('../../js/edit_mode/store/slices/hot-key-slice.js');
const { initHotkeys, getBindings, PASS } = await import('../../js/edit_mode/hotkeys/hotkeys.js');
const { parseBinding } = await import('../../js/edit_mode/hotkeys/binding.js');
const { registerCollapseHotkeys, collapseHotkeyIds } = await import(
  '../../js/outline/collapse-hotkeys.js'
);
const { collapseAll, expandAll } = await import('../../js/outline/collapse.js');
const { outlineOf } = await import(
  '../../js/edit_mode/components/page-elements/tree-outline.js'
);

initHotkeys();
store.dispatch(setHotKey(true));

/**
 * Feeds a keydown for a binding string through the dispatcher's document
 * listener, as the platform would produce it.
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

/**
 * Feeds the matching keyup, the release a held binding waits for.
 */
const keyup = (binding) => {
  const { key } = parseBinding(binding);
  const event = { key, code: key, metaKey: false, ctrlKey: false, altKey: false, shiftKey: false, target: {}, preventDefault() {}, stopPropagation() {} };
  (listeners.get('keyup') ?? []).forEach((listener) => listener(event));
};

/**
 * Registers bindings through the facade in the given order (the later
 * one wins a full tie) and runs the case, unregistering afterwards.
 */
const withBindings = (specs, run) => {
  const registry = Drupal.Daedalus.Hotkeys;
  specs.forEach((spec) => registry.register(spec));
  try {
    run();
  } finally {
    specs.forEach((spec) => registry.unregister(spec.id));
  }
};

/**
 * A two-level outline: a foldable root open by default over a foldable
 * unit collapsed by default and a leaf.
 */
const OUTLINE = [
  {
    id: 'root',
    foldable: true,
    folded: false,
    children: [
      { id: 'unit', foldable: true, folded: true, children: [{ id: 'leaf', foldable: false }] },
      { id: 'text', foldable: false },
    ],
  },
];

/**
 * The page elements tree's shape over the tree's own adapter
 * (tree-outline.js): a section over a merged text block whose rows are
 * a paragraph and a list of one item over its paragraph. The block and
 * the list rows start collapsed (rich text), the section open.
 */
const instance = (id, children = []) => ({
  pseudo: true,
  id,
  parentKey: 'block:text',
  label: id,
  source: 'instance',
  children,
});
const TREE = {
  key: 'entity:page',
  kind: 'entity',
  label: 'Page',
  children: [
    {
      key: 'container:s1',
      kind: 'container',
      label: 'Section',
      children: [
        {
          key: 'block:text',
          kind: 'block',
          label: 'Text',
          children: [instance('.p'), instance('.ul', [instance('.li', [instance('.li-p')])])],
        },
      ],
    },
  ],
};

/**
 * An adapter recording what the registrar asked of it: read() answers
 * its current posture over OUTLINE (or the outline given), apply()
 * records the calls.
 */
const adapter = (folds = null, outline = OUTLINE) => {
  const record = {
    folds,
    reads: 0,
    applied: [],
    read() {
      record.reads += 1;
      return { folds: record.folds, outline };
    },
    apply(next, gesture) {
      record.applied.push({ next, gesture });
      record.folds = next;
    },
  };
  return record;
};

const bindingsOf = (id) => {
  const ids = collapseHotkeyIds(id);
  return getBindings().filter((binding) => Object.values(ids).includes(binding.id));
};

let unregister = () => {};
beforeEach(() => {
  unregister();
  unregister = () => {};
});

test('the ids are the outline id with the two gesture suffixes', () => {
  assert.deepEqual(collapseHotkeyIds('page_elements'), {
    collapseAll: 'page_elements.collapse_all',
    expandAll: 'page_elements.expand_all',
  });
});

test('the pair registers as remappable global bindings in Panels, labeled by the outline', () => {
  unregister = registerCollapseHotkeys({
    id: 'page_elements',
    label: 'Page elements',
    keys: { collapseAll: '3', expandAll: '4' },
    ...adapter(),
  });
  assert.deepEqual(bindingsOf('page_elements'), [
    {
      id: 'page_elements.collapse_all',
      key: '3',
      when: ['global'],
      kind: 'press',
      label: 'Collapse all in Page elements',
      section: 'Panels',
      remappable: true,
    },
    {
      id: 'page_elements.expand_all',
      key: '4',
      when: ['global'],
      kind: 'press',
      label: 'Expand all in Page elements',
      section: 'Panels',
      remappable: true,
    },
  ]);
});

test('a missing default key registers the binding unbound, listed but silent', () => {
  const outline = adapter();
  unregister = registerCollapseHotkeys({ id: 'daedalus_stylish.code', label: 'Code mode', ...outline });
  assert.deepEqual(
    bindingsOf('daedalus_stylish.code').map(({ key }) => key),
    ['', ''],
    'both bindings are listed with no key',
  );
  const event = keydown('3');
  assert.equal(event.handled, false, 'an unbound binding fires on nothing');
  assert.equal(outline.reads, 0, 'the adapter was never read');
});

test('the collapse key reads the adapter and applies the model\'s collapse-all, naming the gesture', () => {
  const outline = adapter();
  unregister = registerCollapseHotkeys({
    id: 'page_elements',
    label: 'Page elements',
    keys: { collapseAll: '3', expandAll: '4' },
    ...outline,
  });
  const event = keydown('3');
  assert.equal(event.handled, true, 'the stroke is swallowed');
  assert.equal(outline.reads, 1);
  assert.deepEqual(outline.applied, [
    { next: collapseAll(null, OUTLINE), gesture: 'collapse_all' },
  ]);
  assert.deepEqual(outline.folds, { root: true }, 'the root, open by default, was collapsed; the unit already was');
});

test('the expand key applies expand-all over the posture the adapter answers now', () => {
  const outline = adapter({ root: true });
  unregister = registerCollapseHotkeys({
    id: 'page_elements',
    label: 'Page elements',
    keys: { collapseAll: '3', expandAll: '4' },
    ...outline,
  });
  const event = keydown('4');
  assert.equal(event.handled, true);
  assert.deepEqual(outline.applied, [
    { next: expandAll({ root: true }, OUTLINE), gesture: 'expand_all' },
  ]);
  assert.deepEqual(outline.folds, { root: false, unit: false });
});

test('the expand key opens the tree\'s rich-text defaults: the text block, its list and the item', () => {
  const outline = adapter(null, outlineOf(TREE));
  unregister = registerCollapseHotkeys({
    id: 'page_elements',
    label: 'Page elements',
    keys: { collapseAll: '3', expandAll: '4' },
    ...outline,
  });
  assert.equal(keydown('4').handled, true);
  assert.deepEqual(outline.folds, {
    'block:text': false,
    'pseudo:block:text:.ul': false,
    'pseudo:block:text:.li': false,
  }, 'every row collapsed by default got the word; the section, open by default, none');
  // Collapse all from there closes the section too and the rich-text
  // rows again, by word now.
  assert.equal(keydown('3').handled, true);
  assert.deepEqual(outline.folds, {
    'container:s1': true,
    'block:text': true,
    'pseudo:block:text:.ul': true,
    'pseudo:block:text:.li': true,
  });
});

test('a gesture that changes nothing still applies, handing back the same posture object', () => {
  const folds = { root: true };
  const outline = adapter(folds);
  unregister = registerCollapseHotkeys({
    id: 'page_elements',
    label: 'Page elements',
    keys: { collapseAll: '3', expandAll: '4' },
    ...outline,
  });
  const event = keydown('3');
  assert.equal(event.handled, true, 'a bound key is handled either way');
  assert.equal(outline.applied.length, 1);
  assert.equal(outline.applied[0].next, folds, 'the identity rule: the same object, a no-op for a setter');
});

test('the returned unregister removes both bindings and the keys propagate again', () => {
  const outline = adapter();
  const release = registerCollapseHotkeys({
    id: 'page_elements',
    label: 'Page elements',
    keys: { collapseAll: '3', expandAll: '4' },
    ...outline,
  });
  assert.equal(bindingsOf('page_elements').length, 2);
  release();
  assert.deepEqual(bindingsOf('page_elements'), []);
  assert.equal(keydown('3').handled, false);
  assert.equal(keydown('4').handled, false);
  assert.equal(outline.reads, 0);
});

test('the facade publishes the dispatcher\'s PASS value', () => {
  assert.equal(typeof PASS, 'symbol');
  assert.equal(Drupal.Daedalus.Hotkeys.PASS, PASS, 'a binding outside the bundle returns the same value');
});

test('PASS hands the stroke to the next candidate in winning order, whose verdict is the app\'s', () => {
  const ran = [];
  withBindings([
    { id: 'under', key: '7', action: () => { ran.push('under'); } },
    { id: 'over', key: '7', action: () => { ran.push('over'); return PASS; } },
  ], () => {
    const event = keydown('7');
    assert.deepEqual(ran, ['over', 'under'], 'the later registration wins the tie and passes; the earlier one takes it');
    assert.equal(event.handled, true, 'the taker handled the stroke');
  });
});

test('false is declined AND stop: the next candidate never runs and the key propagates', () => {
  const ran = [];
  withBindings([
    { id: 'under', key: '7', action: () => { ran.push('under'); } },
    { id: 'over', key: '7', action: () => { ran.push('over'); return false; } },
  ], () => {
    const event = keydown('7');
    assert.deepEqual(ran, ['over']);
    assert.equal(event.handled, false);
  });
});

test('a winner that handles the stroke is the end of it', () => {
  const ran = [];
  withBindings([
    { id: 'under', key: '7', action: () => { ran.push('under'); } },
    { id: 'over', key: '7', action: () => { ran.push('over'); } },
  ], () => {
    const event = keydown('7');
    assert.deepEqual(ran, ['over']);
    assert.equal(event.handled, true);
  });
});

test('when every candidate passes the app did not handle the key', () => {
  const ran = [];
  withBindings([
    { id: 'under', key: '7', action: () => { ran.push('under'); return PASS; } },
    { id: 'over', key: '7', action: () => { ran.push('over'); return PASS; } },
  ], () => {
    const event = keydown('7');
    assert.deepEqual(ran, ['over', 'under'], 'every candidate was asked');
    assert.equal(event.handled, false, 'the browser default goes through');
  });
});

test('a hold-kind binding that passes is not held: its release never fires, the taker\'s does', () => {
  const ran = [];
  const releases = [];
  withBindings([
    {
      id: 'under',
      key: '8',
      kind: 'hold',
      action: (stroke, pressed) => {
        if (pressed) {
          ran.push('under');
        } else {
          releases.push('under');
        }
      },
    },
    {
      id: 'over',
      key: '8',
      kind: 'hold',
      action: (stroke, pressed) => {
        if (pressed) {
          ran.push('over');
          return PASS;
        }
        releases.push('over');
      },
    },
  ], () => {
    const event = keydown('8');
    assert.deepEqual(ran, ['over', 'under']);
    assert.equal(event.handled, true);
    keyup('8');
    assert.deepEqual(releases, ['under'], 'only the binding that took the press was held');
  });
});

test('a per-user remap saved for the id wins over the default key', () => {
  drupalSettings.daedalusUi = { hotKeys: { 'page_elements.collapse_all': { key: 'mod+shift+k' } } };
  try {
    const outline = adapter();
    unregister = registerCollapseHotkeys({
      id: 'page_elements',
      label: 'Page elements',
      keys: { collapseAll: '3', expandAll: '4' },
      ...outline,
    });
    assert.deepEqual(
      bindingsOf('page_elements').map(({ key }) => key),
      ['mod+shift+k', '4'],
    );
    assert.equal(keydown('3').handled, false, 'the default key no longer fires');
    assert.equal(keydown('mod+shift+k').handled, true, 'the remapped one does');
    assert.equal(outline.applied[0].gesture, 'collapse_all');
  } finally {
    drupalSettings.daedalusUi = undefined;
  }
});
