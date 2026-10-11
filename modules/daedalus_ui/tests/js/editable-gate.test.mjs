/**
 * Self-asserting tests for the editing-universe gate (resolver.js,
 * keyForEditableItem).
 *
 * Attribution is host-blind: a theme-region layout block renders its inner
 * components fully attributed on every page, so a DOM attribute is an
 * address, never authorization. What the armed edit session may edit is
 * decided by membership in the root context's blueprint projection, the
 * same Map the selection grammar resolves within. keyForEditableItem is the one
 * seam every editing door (click, hover, editItem, Tab) passes through, so
 * these tests lock its contract: the key it would COMMIT (after merged-root
 * and root-edit-target promotion) must be in the projection index OR name
 * a known field of a known subject on the mirror (rendered evidence beats
 * display inference: a field the tree folds still edits where a theme or
 * a field block rendered it), else null; and before the mirror boots
 * nothing is editable. The pointer chain (representedChainKeys) applies
 * the root-edit-target promotion too, before its projection filter, so
 * the theme's H1 resolves to the root row at both doors.
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

// The gate keys an item from its wrapper chain and the projection alone:
// it never consults the engine's leaf list (which the session injects for
// pointer resolution and presentation boundaries), so none is injected.
const { keyForEditableItem, chainKeys, representedChainKeys, resolve, POLICY } = await import('../../js/edit_mode/selection/resolver.js');
globalThis.__projectionIndex = await import('../../js/edit_mode/components/page-elements/projection-index.js');
const { default: store } = await import('../../js/edit_mode/store/store.js');
const { blueprintBooted } = await import('../../js/edit_mode/store/slices/blueprint-slice.js');

const ROOT = '99999999-1111-2222-3333-444444444444';
const SECTION = 'aaaaaaaa-1111-2222-3333-444444444444';
const BLOCK = 'bbbbbbbb-1111-2222-3333-444444444444';
const TEXT = 'cccccccc-1111-2222-3333-444444444444';
const OPEN_BLOCK = 'dddddddd-1111-2222-3333-444444444444';
const FIELD_BLOCK = 'eeeeeeee-1111-2222-3333-444444444444';
const FOREIGN = 'ffffffff-1111-2222-3333-444444444444';

/**
 * The verdict every fixture node carries: the gate also asks whether the
 * committed key is SELECTABLE (its verdict allows anything), so a fixture
 * without a verdict would edit nothing. The not-selectable suite covers
 * the refused shapes.
 */
const open = { content: true, configure: true, style: true, arrange: true, insert: true };

/**
 * The page's components: one merged block (its inner text entity is what
 * the DOM actually attributes), one OPEN block with a display-hidden field
 * (annotated nonexistent, so the tree folds its row), and one Layout
 * Builder FIELD BLOCK presenting the root's non-preserved field_dropped
 * (merged into it, as the annotator delivers).
 */
const components = () => [
  {
    kind: 'block',
    uuid: BLOCK,
    block_type: 'text',
    label: 'Text',
    block_fields: { field_text: { uuid: TEXT, type: 'text', fields: { field_text_text: 'Body' } } },
    _element: { presentation: { mode: 'merged', edit_target: 'field_text' }, can: open },
  },
  {
    kind: 'block',
    uuid: OPEN_BLOCK,
    block_type: 'card',
    label: 'Card',
    block_fields: { field_card_text: 'Shown', field_card_note: 'Hidden on the display' },
    _element: { presentation: { mode: 'open' }, fields: { field_card_note: { element: false } }, can: open },
  },
  {
    kind: 'block',
    uuid: FIELD_BLOCK,
    plugin_id: 'field_block:node:page:field_dropped',
    label: 'Dropped',
    _element: {
      presentation: { mode: 'merged', label: 'Dropped', edit_target: 'field_dropped' },
      presented_field: `field:${ROOT}:field_dropped`,
      can: open,
    },
  },
];

/**
 * The route entity's delivered blueprint: a title (the root's edit target,
 * absorbed into the root row) and a body, plus one section holding the
 * merged block, as the lean `layout` the delivered mirror carries (the
 * projection index, the universe this gate consults, composes it through
 * compose.js; projection.js reads the same tree).
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
      // field_dropped is a non-preserved root field on a Layout Builder
      // display: the feeder marks it nonexistent, so the tree gives it no
      // row of its own (it renders through the field block instead).
      fields: { title: 'Hello', body: 'Original body text.', field_dropped: 'Placed by a field block' },
      layout: [{
        kind: 'container',
        uuid: SECTION,
        layout_id: 'layout_onecol',
        _element: { can: open },
        children: [{ kind: 'region', region: 'content', children: components() }],
      }],
      _element: {
        presentation: { mode: 'merged', edit_target: 'title' },
        fields: { field_dropped: { element: false } },
        can: open,
      },
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

test("the pointer chain promotes the root's edit-target leaf the same way, before the projection filter", () => {
  boot();
  // The theme's H1: the label leaf OUTSIDE the entity wrapper (core prints
  // the title above the entity), attributed with the label field's key,
  // which has no row of its own. The raw chain is the bare field key; the
  // represented chain is the root row, so hover, click and double-click
  // land on the row that presents the title, and the LEAF policy (Cmd,
  // the picker) names the same row.
  const page = new El({});
  const h1 = page.add(new El({ 'data-selectable': `field:${ROOT}:title` }));
  page.add(new El({ 'data-selectable': `entity:${ROOT}`, 'data-main-entity': '' }));
  assert.deepEqual(chainKeys(h1), [`field:${ROOT}:title`]);
  assert.deepEqual(representedChainKeys(h1), [`entity:${ROOT}`]);
  assert.equal(resolve(h1), `entity:${ROOT}`);
  assert.equal(resolve(h1, { policy: POLICY.LEAF }), `entity:${ROOT}`);
  // A leaf that is not the edit target keeps its own key on the chain.
  const body = page.add(new El({ 'data-selectable': `field:${ROOT}:body` }));
  assert.deepEqual(representedChainKeys(body), [`field:${ROOT}:body`]);
});

test('a leaf with no selectable wrapper, or only the canvas above it, is null', () => {
  boot();
  assert.equal(keyForEditableItem(new El({ 'data-editable': '' })), null);
  const canvas = new El({ 'data-selectable': `entity:${ROOT}`, 'data-main-entity': '' });
  assert.equal(keyForEditableItem(canvas.add(new El({ 'data-editable': '' }))), null);
});

test('a field block placement folds its field: the inner leaf promotes to the placement', () => {
  boot();
  const { projectionIndex } = globalThis.__projectionIndex;
  // The tree has ONE row for the render: the placement (merged into the
  // field), no field_dropped row of its own.
  assert.equal(projectionIndex().has(`block:${FIELD_BLOCK}`), true);
  assert.equal(projectionIndex().has(`field:${ROOT}:field_dropped`), false);
  // The DOM: the placement wraps the field's own render, attributed exactly
  // like a preserved field's, the editable leaf inside.
  const leaf = leafUnder(
    { 'data-selectable': `field:${ROOT}:field_dropped` },
    { 'data-selectable': `block:${FIELD_BLOCK}`, 'data-presentation': 'merged', 'data-presented-field': `field:${ROOT}:field_dropped` },
  );
  assert.equal(keyForEditableItem(leaf), `block:${FIELD_BLOCK}`);
});

test('a rendered leaf of a known root field passes without a projection row (rendered evidence)', () => {
  boot();
  // A theme printing the non-preserved field directly: no presentation
  // root, no row in the tree, but the mirror knows the field is the
  // root's and the leaf proves it rendered.
  const leaf = leafUnder({ 'data-selectable': `field:${ROOT}:field_dropped` });
  assert.equal(keyForEditableItem(leaf), `field:${ROOT}:field_dropped`);
});

test('a rendered leaf of a known block field passes though the display hides it', () => {
  boot();
  const leaf = leafUnder({ 'data-selectable': `block:${OPEN_BLOCK}:field_card_note` });
  assert.equal(keyForEditableItem(leaf), `block:${OPEN_BLOCK}:field_card_note`);
});

test('a leaf whose key names no field of any subject is null', () => {
  boot();
  // An unknown field of the root, a field of a foreign placement, and a
  // field of a known placement the mirror does not carry.
  assert.equal(keyForEditableItem(leafUnder({ 'data-selectable': `field:${ROOT}:field_nope` })), null);
  assert.equal(keyForEditableItem(leafUnder({ 'data-selectable': `block:${FOREIGN}:field_card_text` })), null);
  assert.equal(keyForEditableItem(leafUnder({ 'data-selectable': `block:${OPEN_BLOCK}:field_nope` })), null);
});
