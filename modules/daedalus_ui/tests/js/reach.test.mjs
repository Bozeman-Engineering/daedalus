/**
 * Self-asserting tests for reach (reach.js), the predicate over the
 * projection index that says which existing addresses the user may
 * currently land on.
 *
 * The first half is the depicted table, pure: element and instance
 * sources are depicted in every view; a view id is depicted exactly while
 * the view slice has that view on. The second half drives reachable() and
 * reachPredicate() over the real index and store: the same fixture the
 * projection suite boots (a linked image with its Raster shell, a text
 * block with list carriers, a part behavior delivering a generated box),
 * with the Markup toggle flipped through the view slice, locks that a
 * flip changes what is reachable and never what exists.
 *
 * The index reads the app store, so the suite registers an
 * extension-adding resolve hook, stubs the browser globals, then
 * dynamically imports the modules.
 *
 * Run with `node --test tests/js/reach.test.mjs` from the module root.
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

// The store module reads browser globals at load time; install stubs
// BEFORE import. The anatomy payload is what the index composes shells
// from (drupalSettings.daedalusUi.anatomy).
globalThis.window = globalThis;
globalThis.document = { querySelector: () => null };
globalThis.Drupal = { t: (value) => value, behaviors: {} };
globalThis.drupalSettings = {
  daedalusUi: {
    anatomy: {
      kinds: {
        field: { elements: { '.field__item': { label: 'Item', tag: 'div', contains: 'value' } } },
      },
      blockTypes: {
        image: {
          elements: {
            '.image-block-image': {
              label: 'Image',
              contains: 'field:field_image',
              forms: [{ tag: 'img', label: 'Raster' }, { tag: 'svg', label: 'Vector' }],
            },
          },
        },
        text: { elements: { '.text-block-link': { label: 'Link', tag: 'a' } } },
      },
    },
  },
};

const { depicted, reachable, reachPredicate } = await import('../../js/edit_mode/components/page-elements/reach.js');
const { projectionIndex, selectorAddress } = await import('../../js/edit_mode/components/page-elements/projection-index.js');
const { MARKUP_VIEW_ID } = await import('../../js/edit_mode/components/page-elements/markup-view.js');
const { registerPartBehavior } = await import('../../js/edit_mode/components/page-elements/view-registry.js');
const { default: store } = await import('../../js/edit_mode/store/store.js');
const { blueprintBooted } = await import('../../js/edit_mode/store/slices/blueprint-slice.js');
const { setViewVisibility } = await import('../../js/edit_mode/store/slices/view-slice.js');

// ---------------------------------------------------------------------------
// The depicted table (pure).
// ---------------------------------------------------------------------------

test('element and instance sources are depicted in every view', () => {
  for (const visibility of [{}, { [MARKUP_VIEW_ID]: false }, { [MARKUP_VIEW_ID]: true }, { outline: true }]) {
    assert.equal(depicted('element', visibility), true);
    assert.equal(depicted('instance', visibility), true);
  }
});

test('a view id is depicted exactly while the view slice has that view on', () => {
  assert.equal(depicted(MARKUP_VIEW_ID, {}), false);
  assert.equal(depicted(MARKUP_VIEW_ID, { [MARKUP_VIEW_ID]: false }), false);
  assert.equal(depicted(MARKUP_VIEW_ID, { [MARKUP_VIEW_ID]: true }), true);
  // Another view's toggle says nothing about this one.
  assert.equal(depicted(MARKUP_VIEW_ID, { outline: true }), false);
  assert.equal(depicted('outline', { outline: true, [MARKUP_VIEW_ID]: false }), true);
  // The slice writes booleans only; anything else is not "on".
  assert.equal(depicted(MARKUP_VIEW_ID, { [MARKUP_VIEW_ID]: 1 }), false);
  assert.equal(depicted(MARKUP_VIEW_ID, { [MARKUP_VIEW_ID]: 'true' }), false);
});

// ---------------------------------------------------------------------------
// Reach over the real index and store.
// ---------------------------------------------------------------------------

const ROOT = '99999999-1111-2222-3333-444444444444';
const SECTION = 'aaaaaaaa-1111-2222-3333-444444444444';
const LINKED = 'cccccccc-1111-2222-3333-444444444444';
const UNLINKED = 'dddddddd-1111-2222-3333-444444444444';
const TEXT = 'ffffffff-1111-2222-3333-444444444444';

const BLOCK = `block:${LINKED}`;
const FIELD = `block:${LINKED}:field_image`;
const RASTER = selectorAddress(BLOCK, '.image-block-image');
const ITEM = selectorAddress(FIELD, '.field__item');
const BEFORE = selectorAddress(BLOCK, '&::before');
const HOISTED = selectorAddress(`block:${UNLINKED}`, '.image-block-image');
const LIST = selectorAddress(`block:${TEXT}`, '.s-l1');
const LIST_ITEM = selectorAddress(`block:${TEXT}`, '.s-i1');

const components = [
  {
    kind: 'block',
    uuid: LINKED,
    block_type: 'image',
    label: 'Image',
    label_display: '0',
    block_fields: { field_image: { target_id: 3, _label: 'Pic' }, field_link: { uri: 'https://example.com' } },
    _element: {
      markup: {
        tag: 'a',
        classes: ['image-block'],
        hoisted: [],
        parts: { '.image-block-image': { tag: 'img', attributes: {}, label: 'Raster', parts: {} } },
        slots: [],
      },
    },
  },
  {
    kind: 'block',
    uuid: UNLINKED,
    block_type: 'image',
    label: 'Image',
    label_display: '0',
    block_fields: { field_image: { target_id: 4, _label: 'Other pic' } },
    _element: {
      markup: {
        tag: 'img',
        classes: ['image-block', 'image-block-image'],
        hoisted: ['.image-block-image'],
        parts: {},
        slots: [],
      },
    },
  },
  {
    kind: 'block',
    uuid: TEXT,
    block_type: 'text',
    label: 'Formatted text',
    label_display: '0',
    block_fields: { field_text_text: '<ul><li>First</li></ul>' },
    _element: {
      presentation: { mode: 'merged', label: 'Formatted text', edit_target: 'field_text_text' },
      composed: true,
      markup: {
        tag: 'div',
        classes: ['text-block'],
        hoisted: [],
        parts: {},
        slots: [],
        instances: [
          {
            class: 's-l1',
            tag: 'ul',
            label: 'Bulleted list',
            children: [{ class: 's-i1', tag: 'li', label: 'List item', text: 'First', children: [] }],
          },
        ],
      },
    },
  },
];

const blueprint = {
  bundle: 'page',
  fields: {},
  layout_builder__layout: [{ uuid: SECTION, layout_id: 'layout_onecol', regions: { content: components } }],
  _tree: [{
    kind: 'container',
    uuid: SECTION,
    layout_id: 'layout_onecol',
    children: [{ kind: 'region', region: 'content', children: components }],
  }],
};

const markup = (visible) =>
  store.dispatch(setViewVisibility({ id: MARKUP_VIEW_ID, visible }));

test('before the mirror boots nothing is reachable', () => {
  assert.equal(projectionIndex(), null);
  assert.equal(reachable(`entity:${ROOT}`), false);
  assert.equal(reachable(RASTER), false);
  assert.equal(reachPredicate()(BLOCK), false);
});

test('with the Markup view off, elements and instance rows are reachable; shells and boxes exist but are not', () => {
  registerPartBehavior({
    generated: (key) => (key === BLOCK ? [{ id: '&::before', pseudo: 'before', label: 'Before' }] : []),
  });
  store.dispatch(blueprintBooted({
    entityType: 'node',
    entityId: '1',
    uuid: ROOT,
    label: 'Page',
    langcode: 'en',
    viewMode: 'full',
    blueprint,
  }));
  markup(false);
  const index = projectionIndex();
  for (const address of [RASTER, ITEM, BEFORE]) {
    assert.equal(index.has(address), true, `${address} exists`);
    assert.equal(reachable(address), false, `${address} is not reachable`);
  }
  for (const address of [`entity:${ROOT}`, `container:${SECTION}`, BLOCK, FIELD, `block:${TEXT}`, LIST, LIST_ITEM]) {
    assert.equal(reachable(address), true, `${address} is reachable`);
  }
  // What does not exist is not reachable in any view: the hoisted part,
  // a box nothing delivers, an address off the page.
  assert.equal(index.has(HOISTED), false);
  assert.equal(reachable(HOISTED), false);
  assert.equal(reachable(selectorAddress(`block:${UNLINKED}`, '&::before')), false);
  assert.equal(reachable('block:nowhere'), false);
});

test('flipping the Markup view on reaches the shells and boxes without changing what exists', () => {
  const before = projectionIndex();
  markup(true);
  assert.equal(projectionIndex(), before);
  for (const address of [RASTER, ITEM, BEFORE, BLOCK, FIELD, LIST, LIST_ITEM]) {
    assert.equal(reachable(address), true, `${address} is reachable`);
  }
  assert.equal(reachable(HOISTED), false);
  markup(false);
  assert.equal(projectionIndex(), before);
  assert.equal(reachable(RASTER), false);
  assert.equal(reachable(LIST), true);
});

test('reachPredicate closes over the visibility it was taken under', () => {
  markup(true);
  const whileOn = reachPredicate();
  assert.equal(whileOn(RASTER), true);
  markup(false);
  // The walk that took it keeps one answer; a fresh predicate reads now.
  assert.equal(whileOn(RASTER), true);
  assert.equal(reachPredicate()(RASTER), false);
  assert.equal(whileOn(BLOCK), true);
  assert.equal(reachPredicate()(BLOCK), true);
});
