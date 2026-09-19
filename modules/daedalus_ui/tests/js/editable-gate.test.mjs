/**
 * Self-asserting tests for the editing-universe gate (resolver.js,
 * keyForEditableItem).
 *
 * Attribution is host-blind: a theme-region layout block renders its inner
 * components fully attributed on every page, so a DOM attribute is an
 * address, never authorization. What the armed edit session may edit is
 * decided by membership in the root context's blueprint projection, the
 * same Map the Selector tool resolves within. keyForEditableItem is the one
 * seam every editing door (click, hover, editItem, Tab) passes through, so
 * these tests lock its contract: the key it would COMMIT (after merged-root
 * and root-edit-target promotion) must be in the projection index, else
 * null; and before the mirror boots nothing is editable.
 *
 * The module reaches the real store through the projection index, so the
 * suite seeds the blueprint slice the way the app boots it, then builds a
 * stub DOM around it.
 *
 * Run with `node --test tests/js/*.test.mjs` from the module root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { registerHooks } from 'node:module';
import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

registerHooks({
  resolve(specifier, context, nextResolve) {
    try {
      return nextResolve(specifier, context);
    } catch (error) {
      if (
        (specifier.startsWith('.') || specifier.startsWith('/')) &&
        existsSync(fileURLToPath(new URL(`${specifier}.js`, context.parentURL)))
      ) {
        return nextResolve(`${specifier}.js`, context);
      }
      throw error;
    }
  },
});

/**
 * A stub element: attributes, parent links, and the DOM surface the
 * resolver walks (getAttribute, hasAttribute, matches, closest,
 * parentElement).
 */
class El {
  constructor(attrs = {}) {
    this.attrs = attrs;
    this.parentElement = null;
    this.children = [];
  }

  add(child) {
    child.parentElement = this;
    this.children.push(child);
    return child;
  }

  getAttribute(name) {
    return this.attrs[name] ?? null;
  }

  hasAttribute(name) {
    return name in this.attrs;
  }

  matches(selector) {
    const presence = selector.match(/^\[([a-z-]+)\]$/);
    return presence ? this.hasAttribute(presence[1]) : false;
  }

  closest(selector) {
    for (let node = this; node; node = node.parentElement) {
      if (node.matches(selector)) {
        return node;
      }
    }
    return null;
  }
}

// The store module reads browser globals at load time; install stubs
// BEFORE import.
globalThis.Element = El;
globalThis.document = { querySelector: () => null };
globalThis.window = globalThis;
globalThis.Drupal = { t: (value) => value, behaviors: {} };
globalThis.drupalSettings = {};

const { keyForEditableItem, setEditableItemSelector } = await import('../../js/edit_mode/selection/resolver.js');
const { default: store } = await import('../../js/edit_mode/store/store.js');
const { blueprintBooted } = await import('../../js/edit_mode/store/slices/blueprint-slice.js');

setEditableItemSelector('[data-editable]');

const ROOT = '99999999-1111-2222-3333-444444444444';
const SECTION = 'aaaaaaaa-1111-2222-3333-444444444444';
const BLOCK = 'bbbbbbbb-1111-2222-3333-444444444444';
const TEXT = 'cccccccc-1111-2222-3333-444444444444';
const FOREIGN = 'ffffffff-1111-2222-3333-444444444444';

/**
 * The one merged block the page holds; its inner text entity is what the
 * DOM actually attributes.
 */
const components = () => [{
  kind: 'block',
  uuid: BLOCK,
  block_type: 'text',
  label: 'Text',
  block_fields: { field_text: { uuid: TEXT, type: 'text', fields: { field_text_text: 'Body' } } },
  _element: { presentation: { mode: 'merged', edit_target: 'field_text' } },
}];

/**
 * The route entity's delivered blueprint: a title (the root's edit target,
 * absorbed into the root row) and a body, plus one section holding the
 * merged block. Like the delivered mirror it carries BOTH section shapes,
 * the lean `_tree` and the classic layout_builder__layout, from one
 * component list: the projection index (the universe this gate consults)
 * composes through compose.js, which still walks the classic copy, while
 * projection.js reads the tree. A tree-only fixture would model a mirror
 * the server never delivers and empty the universe of the section.
 */
const boot = () =>
  store.dispatch(blueprintBooted({
    entityType: 'node',
    entityId: '1',
    uuid: ROOT,
    label: 'Page',
    langcode: 'en',
    viewMode: 'full',
    blueprint: {
      bundle: 'page',
      fields: { title: 'Hello', body: 'Original body text.' },
      layout_builder__layout: [{ uuid: SECTION, layout_id: 'layout_onecol', regions: { content: components() } }],
      _tree: [{
        kind: 'container',
        uuid: SECTION,
        layout_id: 'layout_onecol',
        children: [{ kind: 'region', region: 'content', children: components() }],
      }],
      _element: { presentation: { mode: 'merged', edit_target: 'title' } },
    },
  }));

/**
 * An editable leaf under a selectable wrapper, optionally inside a
 * presentation root; returns the leaf.
 */
const leafUnder = (wrapperAttrs, rootAttrs = null) => {
  const canvas = new El({ 'data-selectable': `entity:${ROOT}`, 'data-main-entity': '' });
  let host = canvas;
  if (rootAttrs) {
    host = host.add(new El(rootAttrs));
  }
  const wrapper = host.add(new El(wrapperAttrs));
  return wrapper.add(new El({ 'data-editable': '' }));
};

test('before the mirror boots nothing is editable (fails closed)', () => {
  assert.equal(store.getState().blueprint.blueprint, null, 'the suite starts pre-boot');
  const leaf = leafUnder({ 'data-selectable': `field:${ROOT}:body` });
  assert.equal(keyForEditableItem(leaf), null);
});

test('an in-universe leaf commits its wrapper key', () => {
  boot();
  const leaf = leafUnder({ 'data-selectable': `field:${ROOT}:body` });
  assert.equal(keyForEditableItem(leaf), `field:${ROOT}:body`);
});

test('a foreign host leaf (attributed, outside the root blueprint) is null', () => {
  boot();
  const leaf = leafUnder({ 'data-selectable': `block:${FOREIGN}` });
  assert.equal(keyForEditableItem(leaf), null);
  // Its whole subtree is foreign: a merged root outside the universe
  // promotes to a key that is equally absent.
  const nested = leafUnder(
    { 'data-selectable': `field:${FOREIGN}:field_text_text` },
    { 'data-selectable': `block:${FOREIGN}`, 'data-presentation': 'merged' },
  );
  assert.equal(keyForEditableItem(nested), null);
});

test('a merged root promotes to its own key, which passes even though the inner wrapper has no row', () => {
  boot();
  const leaf = leafUnder(
    { 'data-selectable': `field:${TEXT}:field_text_text` },
    { 'data-selectable': `block:${BLOCK}`, 'data-presentation': 'merged' },
  );
  assert.equal(keyForEditableItem(leaf), `block:${BLOCK}`);
});

test("the root's edit-target leaf promotes to the root entity key", () => {
  boot();
  const leaf = leafUnder({ 'data-selectable': `field:${ROOT}:title` });
  assert.equal(keyForEditableItem(leaf), `entity:${ROOT}`);
});

test('a leaf with no selectable wrapper, or only the canvas above it, is null', () => {
  boot();
  assert.equal(keyForEditableItem(new El({ 'data-editable': '' })), null);
  const canvas = new El({ 'data-selectable': `entity:${ROOT}`, 'data-main-entity': '' });
  assert.equal(keyForEditableItem(canvas.add(new El({ 'data-editable': '' }))), null);
});
