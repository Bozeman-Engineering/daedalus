/**
 * Self-asserting tests for the markup index (blueprint/markup-index.js).
 *
 * The index answers every client question about what an element IS
 * rendering as, from the `_element.markup` annotation the server stamps
 * on the blueprint mirror. These tests lock its two part-projection
 * questions and the tag accessors:
 * - partPlacement, the canonical WHERE answer ('element' | 'root' |
 *   false | null): a part in the resolved parts tree or a minted
 *   instance carrier is its own element; a part that coincided with the
 *   root form hoisted onto it (the unlinked image block's img IS the
 *   block root) is 'root';
 *   a resolver tree without the part positively says absent; no
 *   annotation (or an instances-only field annotation with no matching
 *   carrier) is the honest "the model does not speak".
 * - partPresence, a thin projection over placement (anything placed
 *   counts, hoisted included) for surfaces that style or complete
 *   against the part; it must never be a second parallel reader.
 * - rootTag / partTag, the resolved tags the server render printed
 *   (a 'root'-placed part answers with the root's tag).
 * - partLabel, the matched-form label a multi-form part resolved under
 *   (the form's own name, stamped by the resolver only when the form
 *   declares one); instance carriers answer their stamped label, and a
 *   'root'-placed part answers null (the hoist keeps no form facts, so
 *   consumers degrade to the declared part-level label).
 *
 * The index reads the app store's blueprint slice, so the suite registers
 * an extension-adding resolve hook, stubs the browser globals, seeds the
 * slice the way the app boots it, then dynamically imports the module.
 *
 * Run with `node --test tests/js/markup-index.test.mjs` from the module
 * root.
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
// BEFORE import.
globalThis.window = globalThis;
globalThis.document = { querySelector: () => null };
globalThis.Drupal = { t: (value) => value, behaviors: {} };
globalThis.drupalSettings = {};

const { partPlacement, partPresence, rootTag, partTag, partLabel } = await import('../../js/edit_mode/blueprint/markup-index.js');
const { default: store } = await import('../../js/edit_mode/store/store.js');
const { blueprintBooted } = await import('../../js/edit_mode/store/slices/blueprint-slice.js');

const ROOT = '99999999-1111-2222-3333-444444444444';
const SECTION = 'aaaaaaaa-1111-2222-3333-444444444444';
const LINKED = 'bbbbbbbb-1111-2222-3333-444444444444';
const UNLINKED = 'cccccccc-1111-2222-3333-444444444444';
const TEXT = 'dddddddd-1111-2222-3333-444444444444';
const PLUGIN = 'eeeeeeee-1111-2222-3333-444444444444';

// ---------------------------------------------------------------------------
// Before the mirror boots, the model speaks for nothing.
// ---------------------------------------------------------------------------

test('before boot every question answers null', () => {
  assert.equal(partPlacement(`block:${LINKED}`, '.image-block-img'), null);
  assert.equal(partPresence(`block:${LINKED}`, '.image-block-img'), null);
  assert.equal(rootTag(`block:${LINKED}`), null);
  assert.equal(partTag(`block:${LINKED}`, '.image-block-img'), null);
  assert.equal(partLabel(`block:${LINKED}`, '.image-block-img'), null);
});

/**
 * Boots the delivered mirror (idempotent; the tests run in definition
 * order, so the before-boot test above sees the empty slice): a root
 * whose annotation hoists a coinciding part and carries instance rows on
 * a field; one section (the one-box container's section root); a linked
 * image block (the anchor form won, the img is a real nested part), an
 * unlinked one (the img IS the block root), a text block with instance
 * carriers, and a plugin block the model does not speak for.
 */
let booted = false;
const boot = () => {
  if (booted) {
    return;
  }
  booted = true;
  store.dispatch(blueprintBooted({
    entityType: 'node',
    entityId: '1',
    uuid: ROOT,
    label: 'Page',
    langcode: 'en',
    viewMode: 'full',
    blueprint: {
      bundle: 'page',
      fields: { title: 'Hello', body: 'Body text.' },
      _element: {
        markup: {
          tag: 'article',
          classes: ['node', 'node-hoisted-part'],
          hoisted: ['.node-hoisted-part'],
          parts: {},
          slots: [],
        },
        fields: {
          body: {
            markup: {
              instances: [
                {
                  class: 's-ab',
                  tag: 'h2',
                  label: 'Heading',
                  children: [
                    { class: 's-cd', tag: 'em', label: 'Emphasis', children: [] },
                  ],
                },
              ],
            },
          },
        },
      },
      layout: [{
        kind: 'container',
        uuid: SECTION,
        layout_id: 'layout_onecol',
        _element: {
          markup: {
            tag: 'section',
            classes: [],
            hoisted: [],
            parts: {},
            slots: [],
          },
        },
        children: [{
          kind: 'region',
          region: 'content',
          children: [
            {
              kind: 'block',
              uuid: LINKED,
              block_type: 'image',
              _element: {
                markup: {
                  tag: 'a',
                  classes: ['image-block'],
                  hoisted: [],
                  parts: {
                    '.image-block-img': {
                      tag: 'img',
                      attributes: {},
                      label: 'Raster',
                      parts: {
                        '.image-block-badge': { tag: 'span', attributes: {}, parts: {} },
                      },
                    },
                  },
                  slots: [],
                },
              },
            },
            {
              kind: 'block',
              uuid: UNLINKED,
              block_type: 'image',
              _element: {
                markup: {
                  tag: 'img',
                  classes: ['image-block', 'image-block-img'],
                  hoisted: ['.image-block-img'],
                  parts: {},
                  slots: [],
                },
              },
            },
            {
              kind: 'block',
              uuid: TEXT,
              block_type: 'text',
              _element: {
                markup: {
                  tag: 'div',
                  classes: ['text-block'],
                  hoisted: [],
                  parts: {},
                  slots: [],
                  instances: [
                    { class: 's-ef', tag: 'p', label: 'Paragraph', children: [] },
                  ],
                },
              },
            },
            {
              kind: 'block',
              uuid: PLUGIN,
              block_type: 'plugin',
              _element: {},
            },
          ],
        }],
      }],
    },
  }));
};

// ---------------------------------------------------------------------------
// partPlacement: the canonical WHERE answer
// ---------------------------------------------------------------------------

test('a part in the resolved parts tree placed as its own element', () => {
  boot();
  assert.equal(partPlacement(`block:${LINKED}`, '.image-block-img'), 'element');
});

test('a nested part is found at any depth of the parts tree', () => {
  boot();
  assert.equal(partPlacement(`block:${LINKED}`, '.image-block-badge'), 'element');
});

test('a minted instance carrier placed as its own element, descendants included', () => {
  boot();
  assert.equal(partPlacement(`block:${TEXT}`, '.s-ef'), 'element');
  assert.equal(partPlacement(`field:${ROOT}:body`, '.s-ab'), 'element');
  assert.equal(partPlacement(`field:${ROOT}:body`, '.s-cd'), 'element');
});

test('a hoisted part placed on the root (the unlinked image: the img IS the block root)', () => {
  boot();
  assert.equal(partPlacement(`block:${UNLINKED}`, '.image-block-img'), 'root');
});

test('a resolver tree without the part positively says absent', () => {
  boot();
  assert.equal(partPlacement(`block:${LINKED}`, '.image-block-caption'), false);
  assert.equal(partPlacement(`block:${UNLINKED}`, '.image-block-caption'), false);
});

test('the model does not speak for unannotated keys or unknown keys', () => {
  boot();
  assert.equal(partPlacement(`block:${PLUGIN}`, '.anything'), null);
  assert.equal(partPlacement('block:not-a-real-uuid', '.anything'), null);
  assert.equal(partPlacement(null, '.anything'), null);
});

test('an instances-only field annotation vouches for carriers but cannot deny a part', () => {
  boot();
  assert.equal(partPlacement(`field:${ROOT}:body`, '.text-block-link'), null);
});

test('the root annotation serves entity keys, hoisted parts included', () => {
  boot();
  assert.equal(partPlacement(`entity:${ROOT}`, '.node-hoisted-part'), 'root');
});

// ---------------------------------------------------------------------------
// partPresence: a thin projection over placement, never a second reader
// ---------------------------------------------------------------------------

test('presence derives from placement: placed is present, hoisted included', () => {
  boot();
  const cases = [
    [`block:${LINKED}`, '.image-block-img'],
    [`block:${LINKED}`, '.image-block-badge'],
    [`block:${LINKED}`, '.image-block-caption'],
    [`block:${UNLINKED}`, '.image-block-img'],
    [`block:${TEXT}`, '.s-ef'],
    [`field:${ROOT}:body`, '.s-ab'],
    [`field:${ROOT}:body`, '.text-block-link'],
    [`block:${PLUGIN}`, '.anything'],
  ];
  for (const [key, selector] of cases) {
    const placement = partPlacement(key, selector);
    const expected = placement === null ? null : placement !== false;
    assert.equal(partPresence(key, selector), expected, `${key} ${selector}`);
  }
  assert.equal(partPresence(`block:${UNLINKED}`, '.image-block-img'), true);
  assert.equal(partPresence(`block:${LINKED}`, '.image-block-caption'), false);
  assert.equal(partPresence(`block:${PLUGIN}`, '.anything'), null);
});

// ---------------------------------------------------------------------------
// Tag accessors: the resolved tags the server render printed
// ---------------------------------------------------------------------------

test('rootTag reads the resolved root tag, null when the model does not speak', () => {
  boot();
  assert.equal(rootTag(`block:${LINKED}`), 'a');
  assert.equal(rootTag(`block:${UNLINKED}`), 'img');
  assert.equal(rootTag(`container:${SECTION}`), 'section');
  assert.equal(rootTag(`entity:${ROOT}`), 'article');
  assert.equal(rootTag(`block:${PLUGIN}`), null);
  assert.equal(rootTag('block:not-a-real-uuid'), null);
});

test('partTag answers with the part element\'s own resolved tag', () => {
  boot();
  assert.equal(partTag(`block:${LINKED}`, '.image-block-img'), 'img');
  assert.equal(partTag(`block:${LINKED}`, '.image-block-badge'), 'span');
});

test('partTag answers with the carrier tag for instance classes', () => {
  boot();
  assert.equal(partTag(`block:${TEXT}`, '.s-ef'), 'p');
  assert.equal(partTag(`field:${ROOT}:body`, '.s-ab'), 'h2');
  assert.equal(partTag(`field:${ROOT}:body`, '.s-cd'), 'em');
});

test('a root-placed part answers with the root tag: the part IS the root', () => {
  boot();
  assert.equal(partTag(`block:${UNLINKED}`, '.image-block-img'), 'img');
});

test('partTag is null when the part is absent or the model does not speak', () => {
  boot();
  assert.equal(partTag(`block:${LINKED}`, '.image-block-caption'), null);
  assert.equal(partTag(`block:${PLUGIN}`, '.anything'), null);
  assert.equal(partTag(`field:${ROOT}:body`, '.text-block-link'), null);
});

// ---------------------------------------------------------------------------
// partLabel: the matched-form label a multi-form part resolved under
// ---------------------------------------------------------------------------

test('partLabel answers the matched-form label on the resolved part node', () => {
  boot();
  assert.equal(partLabel(`block:${LINKED}`, '.image-block-img'), 'Raster');
});

test('partLabel is null for a part whose node carries no label', () => {
  // A plain tag-part or a nameless form: the resolver stamps no label
  // key, so consumers fall back to the declared part-level label.
  boot();
  assert.equal(partLabel(`block:${LINKED}`, '.image-block-badge'), null);
});

test('partLabel answers the stamped label for instance carriers', () => {
  boot();
  assert.equal(partLabel(`block:${TEXT}`, '.s-ef'), 'Paragraph');
  assert.equal(partLabel(`field:${ROOT}:body`, '.s-ab'), 'Heading');
  assert.equal(partLabel(`field:${ROOT}:body`, '.s-cd'), 'Emphasis');
});

test('partLabel is null for a root-placed part: the hoist keeps no form facts', () => {
  boot();
  assert.equal(partLabel(`block:${UNLINKED}`, '.image-block-img'), null);
});

test('partLabel is null when the part is absent or the model does not speak', () => {
  boot();
  assert.equal(partLabel(`block:${LINKED}`, '.image-block-caption'), null);
  assert.equal(partLabel(`block:${PLUGIN}`, '.anything'), null);
  assert.equal(partLabel('block:not-a-real-uuid', '.anything'), null);
  assert.equal(partLabel(null, '.anything'), null);
});

test('a root with no fields indexes its own markup and no field carriers', () => {
  boot();
  const state = store.getState().blueprint;
  const { fields, ...fieldless } = state.blueprint;
  const { fields: rootFields, ...element } = fieldless._element;
  store.dispatch(blueprintBooted({
    entityType: 'entity_view_display',
    entityId: 'node.page.default',
    uuid: ROOT,
    label: 'node.page.default',
    langcode: 'en',
    viewMode: 'default',
    blueprint: { ...fieldless, _element: element },
  }));
  assert.equal(rootTag(`entity:${ROOT}`), 'article');
  assert.equal(partPlacement(`field:${ROOT}:body`, '.s-ab'), null);
  assert.equal(partLabel(`field:${ROOT}:body`, '.s-ab'), null);
  assert.equal(partPlacement(`block:${LINKED}`, '.image-block-img'), 'element');
  // Restore the fielded mirror for any later reader.
  store.dispatch(blueprintBooted({
    entityType: 'node',
    entityId: '1',
    uuid: ROOT,
    label: 'Page',
    langcode: 'en',
    viewMode: 'full',
    blueprint: state.blueprint,
  }));
});
