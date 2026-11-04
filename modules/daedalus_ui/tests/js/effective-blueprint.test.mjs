/**
 * Self-asserting tests for the effective blueprint
 * (blueprint/effective-blueprint.js) and the draft slot it reads
 * (store/slices/blueprint-slice.js).
 *
 * The slice keeps the mirror (the server's last word, never written by a
 * client) and the draft (the open gesture's resolved markup for one key)
 * apart; every reader reads the two as ONE tree through the selector.
 * These tests lock the selector's contract:
 * - no draft: the mirror reference itself (null before boot);
 * - a drafted root (`entity:` → the root's `_element`), a drafted block at
 *   nesting depth two and a drafted container (`block:` / `container:` →
 *   the lean tree at any depth, region nodes transparent): a PATH COPY in
 *   which only the drafted node's `_element.markup` is
 *   `{...committed, ...draft.markup}`, siblings and the untouched subtrees
 *   shared by reference, the mirror itself untouched;
 * - the merge is shallow over `_element.markup` only: committed rich-text
 *   facts (`instances`) survive a structural draft, `name` and `fields`
 *   are never touched;
 * - memo identity on the (mirror, draft) pair: an unchanged state answers
 *   the same reference, a new draft a new one;
 * - blueprintBooted / blueprintReplaced reset the draft, draftCleared too;
 * - a draft whose key the tree does not hold yields the mirror reference
 *   and warns once per (mirror, draft) pair.
 *
 * The selector is pure (it takes the root store state), so the suite
 * drives the slice's reducer directly and wraps its state the way the
 * store does; no browser globals are needed.
 *
 * Run with `node --test tests/js/effective-blueprint.test.mjs` from the
 * module root.
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

const { effectiveBlueprint } = await import(
  '../../js/edit_mode/blueprint/effective-blueprint.js'
);
const {
  default: reducer,
  blueprintBooted,
  blueprintReplaced,
  markupDrafted,
  draftCleared,
} = await import('../../js/edit_mode/store/slices/blueprint-slice.js');

const ROOT = '99999999-1111-2222-3333-444444444444';
const OUTER = 'aaaaaaaa-1111-2222-3333-444444444444';
const INNER = 'bbbbbbbb-1111-2222-3333-444444444444';
const TEXT = 'cccccccc-1111-2222-3333-444444444444';
const IMAGE = 'dddddddd-1111-2222-3333-444444444444';
const PLUGIN = 'eeeeeeee-1111-2222-3333-444444444444';

/**
 * A fresh mirror: a root, an outer section holding an image block and an
 * inner section, the inner section holding a text block (nesting depth
 * two) with committed instance carriers and a plugin block the model does
 * not speak for.
 */
const mirrorFixture = () => ({
  bundle: 'page',
  fields: { title: 'Hello', body: 'Body text.' },
  _element: {
    name: 'Hello',
    type: 'Page',
    markup: {
      tag: 'article',
      classes: ['node'],
      hoisted: [],
      parts: {},
      slots: [],
    },
  },
  layout: [
    {
      kind: 'container',
      uuid: OUTER,
      layout_id: 'layout_onecol',
      _element: {
        name: 'Section',
        markup: {
          tag: 'section',
          classes: [],
          hoisted: [],
          parts: {},
          slots: [],
        },
      },
      children: [
        {
          kind: 'region',
          region: 'content',
          children: [
            {
              kind: 'block',
              uuid: IMAGE,
              block_type: 'image',
              _element: {
                name: 'Image',
                markup: {
                  tag: 'img',
                  classes: ['image-block'],
                  hoisted: [],
                  parts: {},
                  slots: [],
                },
              },
            },
            {
              kind: 'container',
              uuid: INNER,
              layout_id: 'layout_onecol',
              _element: {
                name: 'Inner',
                markup: {
                  tag: 'div',
                  classes: ['inner'],
                  hoisted: [],
                  parts: {},
                  slots: [],
                },
              },
              children: [
                {
                  kind: 'region',
                  region: 'content',
                  children: [
                    {
                      kind: 'block',
                      uuid: TEXT,
                      block_type: 'text',
                      _element: {
                        name: 'Some words',
                        type: 'Text',
                        markup: {
                          tag: 'div',
                          classes: ['text-block'],
                          hoisted: [],
                          parts: {},
                          slots: [],
                          instances: [
                            {
                              class: 's-ef',
                              tag: 'p',
                              label: 'Paragraph',
                              children: [],
                            },
                          ],
                          tags: ['p'],
                        },
                        fields: {
                          field_text_text: { markup: { instances: [] } },
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
                },
              ],
            },
          ],
        },
      ],
    },
  ],
});

const booted = (blueprint = mirrorFixture()) =>
  reducer(
    undefined,
    blueprintBooted({
      entityType: 'node',
      entityId: '1',
      uuid: ROOT,
      label: 'Page',
      langcode: 'en',
      viewMode: 'full',
      blueprint,
    }),
  );

const wrap = (slice) => ({ blueprint: slice });

const LINK = {
  '.text-block-link': { tag: 'a', attributes: { href: '/x' }, parts: {} },
};

const textNode = (blueprint) =>
  blueprint.layout[0].children[0].children[1].children[0].children[0];
const pluginNode = (blueprint) =>
  blueprint.layout[0].children[0].children[1].children[0].children[1];
const innerNode = (blueprint) => blueprint.layout[0].children[0].children[1];
const imageNode = (blueprint) => blueprint.layout[0].children[0].children[0];

// Captures the warnings one call rethrows outside the stack
// (Drupal.throwError), as their messages.
const capturingWarn = (fn) => {
  const warnings = [];
  globalThis.Drupal = { throwError: (error) => warnings.push(error.message) };
  try {
    fn();
  } finally {
    delete globalThis.Drupal;
  }
  return warnings;
};

// ---------------------------------------------------------------------------
// No draft: the mirror reference itself
// ---------------------------------------------------------------------------

test('before boot the effective blueprint is null', () => {
  assert.equal(
    effectiveBlueprint(wrap(reducer(undefined, { type: '@@init' }))),
    null,
  );
});

test('with no draft the effective blueprint IS the mirror reference', () => {
  const slice = booted();
  assert.equal(slice.draft, null);
  assert.equal(effectiveBlueprint(wrap(slice)), slice.blueprint);
});

// ---------------------------------------------------------------------------
// The overlay: a path copy carrying the draft over one node's markup
// ---------------------------------------------------------------------------

test('a drafted root overlays the root _element.markup and shares the layout', () => {
  const slice = reducer(
    booted(),
    markupDrafted({ key: `entity:${ROOT}`, markup: { tag: 'main' } }),
  );
  const effective = effectiveBlueprint(wrap(slice));
  assert.notEqual(effective, slice.blueprint);
  assert.equal(effective._element.markup.tag, 'main');
  assert.deepEqual(effective._element.markup.classes, ['node']);
  assert.equal(effective._element.name, 'Hello');
  assert.equal(effective.fields, slice.blueprint.fields);
  assert.equal(effective.layout, slice.blueprint.layout);
  // The mirror itself is untouched: the server's last word stays legible.
  assert.equal(slice.blueprint._element.markup.tag, 'article');
});

test('a drafted block at nesting depth two is path-copied, siblings shared, the mirror untouched', () => {
  const slice = reducer(
    booted(),
    markupDrafted({ key: `block:${TEXT}`, markup: { tag: 'h2', parts: LINK } }),
  );
  const mirror = slice.blueprint;
  const effective = effectiveBlueprint(wrap(slice));
  const drafted = textNode(effective);
  assert.equal(drafted.block_type, 'text');
  assert.equal(drafted._element.markup.tag, 'h2');
  assert.deepEqual(drafted._element.markup.parts, LINK);
  assert.deepEqual(drafted._element.markup.classes, ['text-block']);
  // Every ancestor on the path is a copy; everything off the path is the
  // mirror's own object.
  assert.notEqual(effective, mirror);
  assert.notEqual(effective.layout, mirror.layout);
  assert.notEqual(innerNode(effective), innerNode(mirror));
  assert.equal(imageNode(effective), imageNode(mirror));
  assert.equal(pluginNode(effective), pluginNode(mirror));
  assert.equal(effective._element, mirror._element);
  assert.equal(effective.fields, mirror.fields);
  // The mirror did not move.
  assert.equal(textNode(mirror)._element.markup.tag, 'div');
  assert.equal(
    textNode(mirror)._element.markup.parts.hasOwnProperty('.text-block-link'),
    false,
  );
});

test('a drafted container overlays its own node and shares its children', () => {
  const slice = reducer(
    booted(),
    markupDrafted({ key: `container:${INNER}`, markup: { tag: 'aside' } }),
  );
  const mirror = slice.blueprint;
  const effective = effectiveBlueprint(wrap(slice));
  const drafted = innerNode(effective);
  assert.equal(drafted.kind, 'container');
  assert.equal(drafted._element.markup.tag, 'aside');
  assert.deepEqual(drafted._element.markup.classes, ['inner']);
  assert.equal(drafted.children, innerNode(mirror).children);
  assert.equal(innerNode(mirror)._element.markup.tag, 'div');
});

test('the merge is shallow over _element.markup only: committed instances, name and fields survive', () => {
  const slice = reducer(
    booted(),
    markupDrafted({ key: `block:${TEXT}`, markup: { tag: 'h2', parts: LINK } }),
  );
  const drafted = textNode(effectiveBlueprint(wrap(slice)));
  const committed = textNode(slice.blueprint);
  assert.deepEqual(
    drafted._element.markup.instances,
    committed._element.markup.instances,
  );
  assert.deepEqual(drafted._element.markup.tags, ['p']);
  assert.equal(drafted._element.name, 'Some words');
  assert.equal(drafted._element.type, 'Text');
  assert.equal(drafted._element.fields, committed._element.fields);
});

test('a node without an annotation takes the draft as its markup', () => {
  const slice = reducer(
    booted(),
    markupDrafted({ key: `block:${PLUGIN}`, markup: { tag: 'div' } }),
  );
  assert.deepEqual(pluginNode(effectiveBlueprint(wrap(slice)))._element, {
    markup: { tag: 'div' },
  });
});

// ---------------------------------------------------------------------------
// Memo identity on the (mirror, draft) pair
// ---------------------------------------------------------------------------

test('an unchanged (mirror, draft) pair answers the same reference; a new draft a new one', () => {
  const slice = reducer(
    booted(),
    markupDrafted({ key: `block:${TEXT}`, markup: { tag: 'h2' } }),
  );
  const first = effectiveBlueprint(wrap(slice));
  assert.equal(effectiveBlueprint(wrap(slice)), first);
  // A new root state object around the same slice references is the same
  // input (useSelector's strict equality sees no change).
  assert.equal(effectiveBlueprint({ blueprint: { ...slice } }), first);
  const next = reducer(
    slice,
    markupDrafted({ key: `block:${TEXT}`, markup: { tag: 'h3' } }),
  );
  const second = effectiveBlueprint(wrap(next));
  assert.notEqual(second, first);
  assert.equal(textNode(second)._element.markup.tag, 'h3');
  assert.equal(effectiveBlueprint(wrap(next)), second);
});

// ---------------------------------------------------------------------------
// The slot's lifecycle: cleared by the mirror, never by a gesture hook
// ---------------------------------------------------------------------------

test('blueprintReplaced clears the draft and the effective tree is the new mirror', () => {
  const drafted = reducer(
    booted(),
    markupDrafted({ key: `block:${TEXT}`, markup: { tag: 'h2' } }),
  );
  assert.deepEqual(drafted.draft, {
    key: `block:${TEXT}`,
    markup: { tag: 'h2' },
  });
  const replacement = mirrorFixture();
  const replaced = reducer(
    drafted,
    blueprintReplaced({ blueprint: replacement }),
  );
  assert.equal(replaced.draft, null);
  assert.equal(replaced.blueprint, replacement);
  assert.equal(effectiveBlueprint(wrap(replaced)), replacement);
});

test('blueprintBooted and draftCleared reset the draft', () => {
  const drafted = reducer(
    booted(),
    markupDrafted({ key: `block:${TEXT}`, markup: { tag: 'h2' } }),
  );
  const cleared = reducer(drafted, draftCleared());
  assert.equal(cleared.draft, null);
  assert.equal(effectiveBlueprint(wrap(cleared)), cleared.blueprint);
  const rebooted = reducer(
    drafted,
    blueprintBooted({
      entityType: 'node',
      entityId: '1',
      uuid: ROOT,
      label: 'Page',
      langcode: 'en',
      viewMode: 'full',
      blueprint: mirrorFixture(),
    }),
  );
  assert.equal(rebooted.draft, null);
});

test('a later draft on any key overwrites the earlier one', () => {
  const first = reducer(
    booted(),
    markupDrafted({ key: `block:${TEXT}`, markup: { tag: 'h2' } }),
  );
  const second = reducer(
    first,
    markupDrafted({ key: `container:${INNER}`, markup: { tag: 'aside' } }),
  );
  const effective = effectiveBlueprint(wrap(second));
  assert.equal(innerNode(effective)._element.markup.tag, 'aside');
  assert.equal(textNode(effective)._element.markup.tag, 'div');
});

// ---------------------------------------------------------------------------
// A draft the tree cannot place: the mirror, with a warning
// ---------------------------------------------------------------------------

test('a draft whose key the tree does not hold yields the mirror reference and warns once', () => {
  const slice = reducer(
    booted(),
    markupDrafted({ key: 'block:not-on-the-page', markup: { tag: 'h2' } }),
  );
  let effective;
  const warnings = capturingWarn(() => {
    effective = effectiveBlueprint(wrap(slice));
    effectiveBlueprint(wrap(slice));
  });
  assert.equal(effective, slice.blueprint);
  assert.equal(warnings.length, 1);
  assert.match(warnings[0], /block:not-on-the-page/);
});

test('a key family mismatch, a foreign root, or a field key is unknown too', () => {
  const unknown = [
    `block:${INNER}`,
    `container:${TEXT}`,
    'entity:not-the-root',
    `block:${TEXT}:field_text_text`,
    `field:${ROOT}:body`,
  ];
  for (const key of unknown) {
    const slice = reducer(
      booted(),
      markupDrafted({ key, markup: { tag: 'h2' } }),
    );
    let effective;
    const warnings = capturingWarn(() => {
      effective = effectiveBlueprint(wrap(slice));
    });
    assert.equal(effective, slice.blueprint, key);
    assert.equal(warnings.length, 1, key);
  }
});
