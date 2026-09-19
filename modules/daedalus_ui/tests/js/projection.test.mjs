/**
 * Self-asserting tests for the page-elements projection.
 *
 * The projection consumes the uniform `_element` annotation (the element
 * model's delivery on every owning node, root included): the EXISTENCE axis
 * (`fields: {name: {element: false}}`) folds rows in every view, and the
 * PRESENTATION axis (merged/unit modes, the root's derived edit target)
 * shapes what the rows that exist look like. Collapse is an identity
 * merger.
 *
 * The second half locks the selection index (projection-index.js): one
 * index over BOTH tiers of the panel's projected tree, element keys and
 * `key::selector` part addresses, composed view-independently through the
 * markup view's own pipeline. The index reads the app store, so the suite
 * registers an extension-adding resolve hook, stubs the browser globals,
 * seeds the slice the way the app boots it, then dynamically imports the
 * module.
 *
 * Run with `node --test tests/js/*.test.mjs` from the module root (the bare
 * directory form would try to execute non-test helpers as tests).
 */
// cspell:ignore hieah Secundum
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { registerHooks } from 'node:module';
import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { projectTree } from '../../js/edit_mode/components/page-elements/projection.js';

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

const { projectionIndex, projectionRoot, partAddress } = await import('../../js/edit_mode/components/page-elements/projection-index.js');
const { default: store } = await import('../../js/edit_mode/store/store.js');
const { blueprintBooted, blueprintReplaced } = await import('../../js/edit_mode/store/slices/blueprint-slice.js');

const ROOT = '99999999-1111-2222-3333-444444444444';
const SECTION = 'aaaaaaaa-1111-2222-3333-444444444444';
const NESTED_SECTION = 'eeeeeeee-1111-2222-3333-444444444444';
const BLOCK = 'bbbbbbbb-1111-2222-3333-444444444444';

const root = { uuid: ROOT, label: 'Page' };

const find = (node, predicate) => {
  if (predicate(node)) {
    return node;
  }
  for (const child of node.children ?? []) {
    const found = find(child, predicate);
    if (found) {
      return found;
    }
  }
  return null;
};

const withComponent = (component) => ({
  bundle: 'page',
  fields: {},
  // The lean node tree, as delivered: kind-discriminated nodes, ONE child
  // axis (a multi-region layout's slots are region nodes), no regions map.
  _tree: [{
    kind: 'container',
    uuid: SECTION,
    layout_id: 'layout_onecol',
    children: [{ kind: 'region', region: 'content', children: [component] }],
  }],
});

test('nonexistent fields fold in every view', () => {
  const blueprint = withComponent({
    kind: 'block',
    uuid: BLOCK,
    block_type: 'photo',
    label: 'Photo',
    block_fields: {
      field_media: { target_id: 3, _label: 'A picture' },
      info: 'Admin label',
    },
    _element: {
      fields: { info: { element: false } },
    },
  });

  // Default view: the existent field has a row; the nonexistent one never
  // does: nothing on canvas answers to its key, so a row would be a
  // mirror-only selectable.
  const tree = projectTree(root, blueprint);
  assert.notEqual(find(tree, (node) => node.key === `block:${BLOCK}:field_media`), null);
  assert.equal(find(tree, (node) => node.key === `block:${BLOCK}:info`), null);
});

test('merged presentation absorbs into one row', () => {
  const blueprint = withComponent({
    kind: 'block',
    uuid: BLOCK,
    block_type: 'text',
    label: 'Text',
    block_fields: {
      field_text_text: 'Hello there',
      field_text_tag: 'h3',
    },
    _element: {
      presentation: { mode: 'merged', label: 'Text', edit_target: 'field_text_text' },
      fields: { field_text_tag: { element: false } },
    },
  });

  // Default view: one row, keyed by the block placement, labeled live from
  // the edit target's content, routing the Edit verb to it.
  const tree = projectTree(root, blueprint);
  const merged = find(tree, (node) => node.key === `block:${BLOCK}`);
  assert.equal(merged.presentation, 'merged');
  assert.equal(merged.label, 'Hello there');
  assert.equal(merged.editTarget, 'field_text_text');
  assert.deepEqual(merged.children, []);
});

test('unit fields carry their mode', () => {
  const blueprint = withComponent({
    kind: 'block',
    uuid: BLOCK,
    block_type: 'cta',
    label: 'Call to action',
    block_fields: {
      field_link: { uri: 'https://example.com' },
    },
    _element: {
      fields: { field_link: { presentation: 'unit' } },
    },
  });

  const tree = projectTree(root, blueprint);
  const row = find(tree, (node) => node.key === `block:${BLOCK}:field_link`);
  assert.equal(row.presentation, 'unit');
});

test('a nested section projects as a section row with its subtree', () => {
  // A region entry carrying the section node shape IS a nested section —
  // sections all the way down; the tree presents it exactly like a
  // top-level section, its children nested under it at any depth.
  const blueprint = withComponent({
    kind: 'container',
    uuid: NESTED_SECTION,
    layout_id: 'layout_onecol',
    label: 'Inner',
    children: [{
      kind: 'region',
      region: 'content',
      children: [{
        kind: 'block',
        uuid: 'ffffffff-1111-2222-3333-444444444444',
        block_type: 'text',
        label: 'Inner text',
        block_fields: {},
      }],
    }],
  });

  const tree = projectTree(root, blueprint);
  const section = find(tree, (node) => node.key === `container:${NESTED_SECTION}`);
  assert.notEqual(section, null);
  assert.equal(section.label, 'Inner');
  assert.notEqual(find(section, (node) => node.key === 'block:ffffffff-1111-2222-3333-444444444444'), null);
});

test('a container section\'s children list projects like a one-region section', () => {
  // A container section carries `children: []` (ordered) in place of
  // `regions: {}` — the serializer's container shape. No region rows in
  // any view; the entries land directly under the section row, in order,
  // nested containers recursing as sections.
  const INNER_BLOCK = 'ffffffff-1111-2222-3333-444444444444';
  const blueprint = {
    bundle: 'page',
    fields: {},
    _tree: [{
      kind: 'container',
      uuid: SECTION,
      layout_id: 'daedalus_stylish_container',
      children: [
        {
          kind: 'block',
          uuid: BLOCK,
          block_type: 'text',
          label: 'Text',
          block_fields: {},
        },
        {
          kind: 'container',
          uuid: NESTED_SECTION,
          layout_id: 'daedalus_stylish_container',
          label: 'Inner',
          children: [{
            kind: 'block',
            uuid: INNER_BLOCK,
            block_type: 'text',
            label: 'Inner text',
            block_fields: {},
          }],
        },
      ],
    }],
  };

  {
    const tree = projectTree(root, blueprint);
    const section = find(tree, (node) => node.key === `container:${SECTION}`);
    assert.deepEqual(
      section.children.map((node) => node.key),
      [`block:${BLOCK}`, `container:${NESTED_SECTION}`],
    );
    assert.equal(find(tree, (node) => node.kind === 'region'), null);
    const nested = find(tree, (node) => node.key === `container:${NESTED_SECTION}`);
    assert.equal(nested.label, 'Inner');
    assert.notEqual(find(nested, (node) => node.key === `block:${INNER_BLOCK}`), null);
  }
});

test('the root absorbs its edit target and folds nonexistent fields', () => {
  const blueprint = {
    bundle: 'landing_page',
    fields: {
      title: 'Hello there',
      daedalus_stylish_styles: { map: {} },
      field_summary: 'A summary',
    },
    _tree: [],
    _element: {
      presentation: { mode: 'open', edit_target: 'title' },
      fields: { daedalus_stylish_styles: { element: false } },
    },
  };

  // Default view: the entity's label field presents AS the entity row: the
  // root shows its text live, routes editing to it, and the field's own row
  // folds. Infrastructure fields (element: false) never appear.
  const tree = projectTree(root, blueprint);
  assert.equal(tree.label, 'Hello there');
  assert.equal(tree.editTarget, 'title');
  assert.equal(find(tree, (node) => node.key === `field:${ROOT}:title`), null);
  assert.equal(find(tree, (node) => node.key === `field:${ROOT}:daedalus_stylish_styles`), null);
  assert.notEqual(find(tree, (node) => node.key === `field:${ROOT}:field_summary`), null);
});

test('a declared nonexistent title never rows; the root row stays its face', () => {
  // daedalus_ui's own universal declaration (`entity: fields: title:
  // {element: false}`): preprocess lifts the title out of the entity
  // render, so nothing inside the entity ever answers to its key. The
  // declaration folds the title's row on the existence axis while the
  // derived edit_target survives (a declaration does not veto it), so the
  // root row keeps showing the title live and routing editing to it.
  const blueprint = {
    bundle: 'page',
    fields: {
      title: 'Welcome to the Page Builder!',
      field_summary: 'A summary',
    },
    _tree: [],
    _element: {
      presentation: { mode: 'open', edit_target: 'title' },
      fields: { title: { element: false } },
    },
  };

  const tree = projectTree(root, blueprint);
  assert.equal(tree.label, 'Welcome to the Page Builder!');
  assert.equal(tree.editTarget, 'title');
  assert.equal(find(tree, (node) => node.key === `field:${ROOT}:title`), null);
  assert.notEqual(find(tree, (node) => node.key === `field:${ROOT}:field_summary`), null);
});

test('an unannotated blueprint projects everything open', () => {
  const blueprint = withComponent({
    kind: 'block',
    uuid: BLOCK,
    block_type: 'text',
    label: 'Text',
    block_fields: { field_text_text: 'Hello' },
  });

  const tree = projectTree(root, blueprint);
  assert.equal(tree.label, 'Page');
  assert.equal(tree.editTarget, null);
  const block = find(tree, (node) => node.key === `block:${BLOCK}`);
  assert.equal(block.presentation, 'open');
  assert.notEqual(find(tree, (node) => node.key === `block:${BLOCK}:field_text_text`), null);
});

test('a rendered label gets its selection row; a hidden label does not', () => {
  // label_rendered is the lean node's derived boolean (the classic
  // 'visible'/'0' label_display spelling never reaches the tree): present
  // only when the label renders as page content, where the canvas has a
  // block:{uuid}:label selection node to answer for the row.
  const rendered = projectTree(root, withComponent({
    kind: 'block',
    uuid: BLOCK,
    block_type: 'heading',
    label: 'A heading',
    label_rendered: true,
    block_fields: {},
  }));
  assert.notEqual(find(rendered, (node) => node.key === `block:${BLOCK}:label`), null);

  const hidden = projectTree(root, withComponent({
    kind: 'block',
    uuid: BLOCK,
    block_type: 'heading',
    label: 'A heading',
    block_fields: {},
  }));
  assert.equal(find(hidden, (node) => node.key === `block:${BLOCK}:label`), null);
});

// ---------------------------------------------------------------------------
// The selection index: both tiers, one map.
// ---------------------------------------------------------------------------

const LINKED = 'cccccccc-1111-2222-3333-444444444444';
const UNLINKED = 'dddddddd-1111-2222-3333-444444444444';
const TEXT = 'ffffffff-1111-2222-3333-444444444444';

/**
 * The image and text components the index cases share, with the markup
 * annotation the server stamps: the linked image's picture is its own
 * img inside the anchor root (parts tree, matched form 'Raster'); the
 * unlinked image's picture coincided with the root form and hoisted; the
 * text block (merged, collapsed) carries a nested list as instance
 * carriers and positively lacks its anchor part.
 */
const indexComponents = () => [
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

/**
 * The delivered mirror carries BOTH section shapes: the lean `_tree` the
 * built-in projection reads and the classic layout_builder__layout the
 * composition still walks. One component list feeds both so the two
 * projections describe one page.
 */
const indexBlueprint = (components = indexComponents()) => ({
  bundle: 'page',
  fields: {},
  layout_builder__layout: [{ uuid: SECTION, layout_id: 'layout_onecol', regions: { content: components } }],
  _tree: [{
    kind: 'container',
    uuid: SECTION,
    layout_id: 'layout_onecol',
    children: [{ kind: 'region', region: 'content', children: components }],
  }],
});

const bootIndex = (blueprint = indexBlueprint()) => {
  store.dispatch(blueprintBooted({
    entityType: 'node',
    entityId: '1',
    uuid: ROOT,
    label: 'Page',
    langcode: 'en',
    viewMode: 'full',
    blueprint,
  }));
  return projectionIndex();
};

const keyedKeys = (node) => [
  ...(node.key ? [node.key] : []),
  ...node.children.flatMap(keyedKeys),
];

test('the element tier is exactly the built-in projection\'s key set, in both structure modes', () => {
  // The riskiest parity paths ride along: a merged, NON-collapsed block
  // whose absorbed field wrapper keeps a row in the markup view (its key
  // must still leave the universe), the root's edit-target fold, and a
  // form-only stub row (label from its definition).
  const blueprint = indexBlueprint([
    ...indexComponents(),
    {
      kind: 'block',
      uuid: BLOCK,
      block_type: 'test',
      label: 'Test',
      label_display: '0',
      block_fields: { field_hieah: 'Secundum', field_stub: { _form_only: true, _label: 'Stub label' } },
      _element: { presentation: { mode: 'merged', label: 'Hieah', edit_target: 'field_hieah' } },
    },
  ]);
  blueprint.fields = { title: 'Hello there', field_summary: 'A summary' };
  blueprint._element = { presentation: { mode: 'open', edit_target: 'title' } };
  bootIndex(blueprint);
  const index = projectionIndex();
  const elements = [...index].filter(([, entry]) => entry.tier === 'element').map(([key]) => key);
  const expected = projectTree(root, blueprint);
  assert.deepEqual(elements.sort(), keyedKeys(expected).sort());
  for (const key of elements) {
    assert.equal(index.get(key).label, find(expected, (node) => node.key === key).label, key);
  }
  // Every element entry carries the projection's facts: parent is the
  // element-tier parent (parts transparent), bundle rides bundle-bearing
  // rows, and no part address ever appears in `children`.
  assert.equal(index.get(`block:${LINKED}`).bundle, 'image');
  assert.equal(index.get(`block:${LINKED}`).parent, `container:${SECTION}`);
  assert.equal(index.get(`entity:${ROOT}`).bundle, 'page');
  for (const [, entry] of index) {
    assert.ok(entry.children.every((key) => !key.includes('::')));
  }
  assert.equal(projectionRoot(), `entity:${ROOT}`);
});

test('a linked image has its Raster row under the block; an unlinked one has none', () => {
  const index = bootIndex();
  const raster = index.get(partAddress(`block:${LINKED}`, '.image-block-image'));
  assert.deepEqual(raster, {
    label: 'Raster',
    kind: 'part',
    bundle: null,
    parent: `block:${LINKED}`,
    rowParent: `block:${LINKED}`,
    children: [`block:${LINKED}:field_image`],
    rows: [`block:${LINKED}:field_image`],
    tier: 'part',
    owner: `block:${LINKED}`,
    tag: 'img',
  });
  // The block's rows lead with the part (shells first, the composition's
  // ordering convention); its children hoist the claimed field through
  // the transparent part, and the field's structure parent is the block
  // while its row parent is the shell it sits in.
  const block = index.get(`block:${LINKED}`);
  assert.deepEqual(block.rows, [partAddress(`block:${LINKED}`, '.image-block-image'), `block:${LINKED}:field_link`]);
  assert.deepEqual(block.children, [`block:${LINKED}:field_image`, `block:${LINKED}:field_link`]);
  const field = index.get(`block:${LINKED}:field_image`);
  assert.equal(field.parent, `block:${LINKED}`);
  assert.equal(field.rowParent, partAddress(`block:${LINKED}`, '.image-block-image'));

  // The hoisted part (placement 'root') is absent by construction: the
  // unlinked block's picture IS the block root, so nothing can select it,
  // and the field mounts at the block position.
  assert.equal(index.has(partAddress(`block:${UNLINKED}`, '.image-block-image')), false);
  const unlinked = index.get(`block:${UNLINKED}`);
  assert.deepEqual(unlinked.rows, [`block:${UNLINKED}:field_image`]);
  assert.equal(index.get(`block:${UNLINKED}:field_image`).rowParent, `block:${UNLINKED}`);
});

test('a sketch row for a key the model is silent on is a row like any other', () => {
  // The field kind's declared .field__item never hears a placement answer
  // (a field annotation carries no resolver tree), so compose mounts it
  // from the declaration alone, and the index carries it: `placed`
  // answers for sketch rows too, with no DOM degrade path anywhere.
  const index = bootIndex();
  const item = index.get(partAddress(`block:${LINKED}:field_image`, '.field__item'));
  assert.equal(item.tier, 'part');
  assert.equal(item.label, 'Item');
  assert.equal(item.tag, 'div');
  assert.equal(item.parent, `block:${LINKED}:field_image`);
  assert.deepEqual(index.get(`block:${LINKED}:field_image`).rows, [partAddress(`block:${LINKED}:field_image`, '.field__item')]);
});

test('list carriers nest: the li under the ul under its owner', () => {
  const index = bootIndex();
  const owner = `block:${TEXT}`;
  const ul = partAddress(owner, '.s-l1');
  const li = partAddress(owner, '.s-i1');
  assert.equal(index.get(ul).label, 'Bulleted list');
  assert.equal(index.get(ul).parent, owner);
  assert.equal(index.get(ul).owner, owner);
  assert.deepEqual(index.get(ul).rows, [li]);
  assert.deepEqual(index.get(ul).children, []);
  assert.equal(index.get(li).label, 'List item');
  assert.equal(index.get(li).tag, 'li');
  assert.equal(index.get(li).parent, ul);
  assert.equal(index.get(li).owner, owner);
  assert.deepEqual(index.get(li).rows, []);
  // The merged block row carries the list as its one row; its element
  // children stay empty (the absorbed target's identity merged away), and
  // the part the model positively says absent has no row.
  assert.deepEqual(index.get(owner).rows, [ul]);
  assert.deepEqual(index.get(owner).children, []);
  assert.equal(index.get(owner).label, '<ul><li>First</li></ul>');
  assert.equal(index.has(partAddress(owner, '.text-block-link')), false);
});

test('the index memoizes on the mirror reference and re-derives on replacement', () => {
  const index = bootIndex();
  assert.equal(projectionIndex(), index);
  // A mutation response replaces the mirror wholesale: the linked image
  // loses its link, its picture hoists, and the Raster row leaves.
  const components = indexComponents();
  components[0].block_fields = { field_image: { target_id: 3, _label: 'Pic' } };
  components[0]._element.markup = {
    tag: 'img',
    classes: ['image-block', 'image-block-image'],
    hoisted: ['.image-block-image'],
    parts: {},
    slots: [],
  };
  store.dispatch(blueprintReplaced(indexBlueprint(components)));
  const replaced = projectionIndex();
  assert.notEqual(replaced, index);
  assert.equal(replaced.has(partAddress(`block:${LINKED}`, '.image-block-image')), false);
  assert.equal(replaced.has(`block:${LINKED}`), true);
});
