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
 * markup view's own pipeline (existence), each entry stamped with the
 * `source` its row was composed with ('element', 'instance', or the
 * Markup view's id for shells and boxes), the fact reach.js reads. The index reads the app store, so the suite
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

const { projectionIndex, projectionRoot, selectorAddress } = await import('../../js/edit_mode/components/page-elements/projection-index.js');
const { registerPartBehavior } = await import('../../js/edit_mode/components/page-elements/view-registry.js');
const { generatedRows, withGeneratedRows } = await import('../../js/edit_mode/components/page-elements/generated-rows.js');
const { withInstanceRows, MARKUP_VIEW_ID } = await import('../../js/edit_mode/components/page-elements/markup-view.js');
const { instanceRows } = await import('../../js/edit_mode/components/page-elements/instance-rows.js');
const { boxGlyph } = await import('../../js/edit_mode/components/page-elements/row-glyphs.js');
const { depictedRows, reachOver, seatAddress } = await import('../../js/edit_mode/components/page-elements/reach.js');
const { default: store } = await import('../../js/edit_mode/store/store.js');
const { blueprintBooted, blueprintReplaced } = await import('../../js/edit_mode/store/slices/blueprint-slice.js');
const { materialsDelta } = await import('../../js/edit_mode/store/slices/materials-slice.js');

const ROOT = '99999999-1111-2222-3333-444444444444';
const SECTION = 'aaaaaaaa-1111-2222-3333-444444444444';
const NESTED_SECTION = 'eeeeeeee-1111-2222-3333-444444444444';
const BLOCK = 'bbbbbbbb-1111-2222-3333-444444444444';

const root = { uuid: ROOT, label: 'Page' };

/**
 * The naming spine's two words as the annotator delivers them on every
 * owning node (`_element.name` and `_element.type`): the fixtures carry
 * what the server would mint, since the projection reads the words and
 * derives none.
 */
const named = (name, type = name) => ({ name, type });
const page = named('Page');
const section = named('Section');
// An open verdict: reach is gated on the actor (a row whose verdict allows
// nothing is never reachable), so the index fixture's linked image, whose
// part rows the seat cases read through reach, carries one.
const OPEN = { content: true, configure: true, style: true, arrange: true, insert: true };

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
  _element: page,
  // The lean node tree, as delivered: kind-discriminated nodes, ONE child
  // axis (a multi-region layout's slots are region nodes), no regions map.
  layout: [{
    kind: 'container',
    uuid: SECTION,
    layout_id: 'layout_onecol',
    _element: section,
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
      ...named('Photo'),
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
      // The annotator's excerpt of the edit target's content is the name.
      ...named('Hello there', 'Text'),
      presentation: { mode: 'merged', label: 'Text', edit_target: 'field_text_text' },
      fields: { field_text_tag: { element: false } },
    },
  });

  // Default view: one row, keyed by the block placement, wearing the
  // delivered words (the content excerpt as its name, the block type as
  // its type), routing the Edit verb to the target.
  const tree = projectTree(root, blueprint);
  const merged = find(tree, (node) => node.key === `block:${BLOCK}`);
  assert.equal(merged.presentation, 'merged');
  assert.equal(merged.label, 'Hello there');
  assert.equal(merged.elementType, 'Text');
  assert.equal(merged.editTarget, 'field_text_text');
  assert.deepEqual(merged.children, []);
  // The section and the root wear theirs too.
  assert.equal(find(tree, (node) => node.key === `container:${SECTION}`).label, 'Section');
  assert.equal(find(tree, (node) => node.key === `container:${SECTION}`).elementType, 'Section');
  assert.equal(tree.label, 'Page');
  assert.equal(tree.elementType, 'Page');
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
      ...named('Call to action', 'Cta'),
      fields: { field_link: { presentation: 'unit' } },
    },
  });

  const tree = projectTree(root, blueprint);
  const row = find(tree, (node) => node.key === `block:${BLOCK}:field_link`);
  assert.equal(row.presentation, 'unit');
  // A field is what it is called: one word for both reads.
  assert.equal(row.label, 'Link');
  assert.equal(row.elementType, 'Link');
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
    // Renamed and nested: the custom label is its name, Container its
    // type (the annotator's depth rule).
    _element: named('Inner', 'Container'),
    children: [{
      kind: 'region',
      region: 'content',
      children: [{
        kind: 'block',
        uuid: 'ffffffff-1111-2222-3333-444444444444',
        block_type: 'text',
        label: 'Inner text',
        block_fields: {},
        _element: named('Text'),
      }],
    }],
  });

  const tree = projectTree(root, blueprint);
  const nested = find(tree, (node) => node.key === `container:${NESTED_SECTION}`);
  assert.notEqual(nested, null);
  assert.equal(nested.label, 'Inner');
  assert.equal(nested.elementType, 'Container');
  assert.equal(nested.customLabel, 'Inner');
  const block = find(nested, (node) => node.key === 'block:ffffffff-1111-2222-3333-444444444444');
  assert.notEqual(block, null);
  // A block row carries its stored configuration label too (the rename
  // input's seed), beside the name the annotator minted.
  assert.equal(block.customLabel, 'Inner text');
  assert.equal(block.label, 'Text');
});

test('a container section\'s children list projects like a one-region section', () => {
  // A container layout holds its element nodes directly, no region
  // nodes (the tree's one-flow shape). No region rows in any view; the
  // entries land directly under the section row, in order, nested
  // containers recursing as sections.
  const INNER_BLOCK = 'ffffffff-1111-2222-3333-444444444444';
  const blueprint = {
    bundle: 'page',
    fields: {},
    _element: page,
    layout: [{
      kind: 'container',
      uuid: SECTION,
      layout_id: 'daedalus_stylish_container',
      _element: section,
      children: [
        {
          kind: 'block',
          uuid: BLOCK,
          block_type: 'text',
          label: 'Text',
          block_fields: {},
          _element: named('Text'),
        },
        {
          kind: 'container',
          uuid: NESTED_SECTION,
          layout_id: 'daedalus_stylish_container',
          label: 'Inner',
          _element: named('Inner', 'Container'),
          children: [{
            kind: 'block',
            uuid: INNER_BLOCK,
            block_type: 'text',
            label: 'Inner text',
            block_fields: {},
            _element: named('Text'),
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
    layout: [],
    _element: {
      // The root names by its entity label (re-minted with every delivered
      // mirror, so a title edit shows live) and types by its bundle label.
      ...named('Hello there', 'Landing page'),
      presentation: { mode: 'open', edit_target: 'title' },
      fields: { daedalus_stylish_styles: { element: false } },
    },
  };

  // Default view: the entity's label field presents AS the entity row: the
  // root shows its delivered name, routes editing to the label field, and
  // the field's own row folds. Infrastructure fields (element: false)
  // never appear.
  const tree = projectTree(root, blueprint);
  assert.equal(tree.label, 'Hello there');
  assert.equal(tree.elementType, 'Landing page');
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
    layout: [],
    _element: {
      ...named('Welcome to the Page Builder!', 'Page'),
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

test('a blueprint with no presentation facts projects everything open', () => {
  // The two words are always delivered; presentation, existence and
  // markup facts are the optional part of the annotation.
  const blueprint = withComponent({
    kind: 'block',
    uuid: BLOCK,
    block_type: 'text',
    label: 'Text',
    block_fields: { field_text_text: 'Hello' },
    _element: named('Text'),
  });

  const tree = projectTree(root, blueprint);
  assert.equal(tree.label, 'Page');
  assert.equal(tree.editTarget, null);
  const block = find(tree, (node) => node.key === `block:${BLOCK}`);
  assert.equal(block.presentation, 'open');
  assert.notEqual(find(tree, (node) => node.key === `block:${BLOCK}:field_text_text`), null);
});

test('a root with no fields projects from its annotation alone, the sections its only children', () => {
  // A template host's canvas: the display hosts the document, has no
  // fields of its own, and its root annotation carries the two words
  // and an open presentation with no edit target.
  const blueprint = withComponent({
    kind: 'block',
    uuid: BLOCK,
    block_type: 'text',
    label: 'Text',
    block_fields: { field_text_text: 'Hello' },
    _element: named('Text'),
  });
  delete blueprint.fields;
  blueprint._element = { ...named('node.page.default', 'Template'), presentation: { mode: 'open' } };

  const tree = projectTree({ uuid: ROOT, label: 'node.page.default' }, blueprint);
  assert.equal(tree.key, `entity:${ROOT}`);
  assert.equal(tree.label, 'node.page.default');
  assert.equal(tree.elementType, 'Template');
  assert.equal(tree.editTarget, null);
  assert.deepEqual(tree.children.map((node) => node.kind), ['container']);
  assert.notEqual(find(tree, (node) => node.key === `block:${BLOCK}`), null);
  // A fixture without a verdict stamp projects none: the gate then
  // refuses, the closed default.
  assert.equal(tree.can, null);
  assert.equal(tree.children[0].can, null);
  assert.equal(find(tree, (node) => node.key === `block:${BLOCK}`).can, null);
});

test('rows carry the verdict the annotator copied (`_element.can`), the root included', () => {
  // A plain host's canvas: the page renders its display's template, so
  // every container and block is refused everything, the root keeps its
  // fields (content) and its own key (style).
  const refused = { content: false, configure: false, style: false, arrange: false, insert: false };
  const rootCan = { content: true, configure: false, style: true, arrange: false, insert: false };
  const blueprint = withComponent({
    kind: 'block',
    uuid: BLOCK,
    block_type: 'text',
    label: 'Text',
    block_fields: { field_text_text: 'Hello' },
    _element: { ...named('Hello', 'Text'), can: refused, presentation: { mode: 'merged', label: 'Text', edit_target: 'field_text_text' } },
  });
  blueprint.layout[0]._element = { ...section, can: refused };
  blueprint._element = { ...page, can: rootCan };
  blueprint.fields = { title: 'Own title' };

  const tree = projectTree(root, blueprint);
  assert.deepEqual(tree.can, rootCan, 'the root row carries the host\'s verdict');
  const container = find(tree, (node) => node.key === `container:${SECTION}`);
  assert.deepEqual(container.can, refused);
  const block = find(tree, (node) => node.key === `block:${BLOCK}`);
  assert.deepEqual(block.can, refused, 'a merged row carries the verdict too');
  // Field rows carry none of their own: the index lets them inherit.
  assert.equal(find(tree, (node) => node.key === `field:${ROOT}:title`).can, undefined);
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
    // A rendered label is content, never a name: the annotator names the
    // block by its excerpt rule (here, of the label) and types it Heading.
    _element: named('A heading', 'Heading'),
  }));
  assert.notEqual(find(rendered, (node) => node.key === `block:${BLOCK}:label`), null);

  const hidden = projectTree(root, withComponent({
    kind: 'block',
    uuid: BLOCK,
    block_type: 'heading',
    label: 'A heading',
    block_fields: {},
    _element: named('A heading', 'Heading'),
  }));
  assert.equal(find(hidden, (node) => node.key === `block:${BLOCK}:label`), null);
});

const FIELD_BLOCK = 'abababab-1111-2222-3333-444444444444';

/**
 * A Layout Builder field block presenting the root's field_summary, as the
 * annotator delivers it: a plugin component (no block_fields) merged into
 * the field, naming it by key, named by the field's value (the annotator's
 * excerpt; the field label when the value is empty) and typed by the
 * field's label.
 */
const fieldBlockComponent = (name = 'Placed summary') => ({
  kind: 'block',
  uuid: FIELD_BLOCK,
  plugin_id: 'field_block:node:page:field_summary',
  label: 'Summary',
  _element: {
    ...named(name, 'Summary'),
    presentation: { mode: 'merged', label: 'Summary', edit_target: 'field_summary' },
    presented_field: `field:${ROOT}:field_summary`,
  },
});

test('a field block placement folds with the field it presents into one row', () => {
  // The non-preserved case: the root's existence feeder folds the field's
  // own row, the placement presents AS the field. One row for one render,
  // keyed by the placement (move/trash/duplicate arm), wearing the
  // delivered words, routing the Edit verb to the field, no children (a
  // scalar value).
  const blueprint = withComponent(fieldBlockComponent());
  blueprint.fields = { field_summary: 'Placed summary' };
  blueprint._element = { ...page, fields: { field_summary: { element: false } } };

  const tree = projectTree(root, blueprint);
  const placement = find(tree, (node) => node.key === `block:${FIELD_BLOCK}`);
  assert.equal(placement.presentation, 'merged');
  assert.equal(placement.label, 'Placed summary');
  assert.equal(placement.elementType, 'Summary');
  assert.equal(placement.editTarget, 'field_summary');
  assert.deepEqual(placement.children, []);
  assert.equal(find(tree, (node) => node.key === `field:${ROOT}:field_summary`), null);
});

test('a field both preserved and placed keeps two rows, one per render', () => {
  // The duplicate-key case: the display renders the field beside the
  // layout (its row under the root stays) AND a field block places it
  // (the merged placement row). Two renders, two rows; the canvas resolves
  // the shared field key to the first element in document order.
  const blueprint = withComponent(fieldBlockComponent('Twice'));
  blueprint.fields = { field_summary: 'Twice' };

  const tree = projectTree(root, blueprint);
  assert.notEqual(find(tree, (node) => node.key === `field:${ROOT}:field_summary`), null);
  const placement = find(tree, (node) => node.key === `block:${FIELD_BLOCK}`);
  assert.equal(placement.label, 'Twice');
  assert.equal(placement.presentation, 'merged');
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
    block_fields: { field_image: { target_id: 3, _label: 'Pic' }, field_link: { uri: 'https://example.com' } },
    _element: {
      ...named('Image'),
      can: OPEN,
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
    block_fields: { field_image: { target_id: 4, _label: 'Other pic' } },
    _element: {
      ...named('Image'),
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
    block_fields: { field_text_text: '<ul><li>First</li></ul>' },
    _element: {
      ...named('<ul><li>First</li></ul>', 'Formatted text'),
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
 * The delivered mirror's lean `layout`: the one structure the built-in
 * projection and the composition (the index's source) both read, so the
 * two projections describe one page by construction.
 */
const indexBlueprint = (components = indexComponents()) => ({
  bundle: 'page',
  fields: {},
  _element: page,
  layout: [{
    kind: 'container',
    uuid: SECTION,
    layout_id: 'layout_onecol',
    _element: section,
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
      block_fields: { field_hieah: 'Secundum', field_stub: { _form_only: true, _label: 'Stub label' } },
      _element: { ...named('Secundum', 'Test'), presentation: { mode: 'merged', label: 'Hieah', edit_target: 'field_hieah' } },
    },
  ]);
  blueprint.fields = { title: 'Hello there', field_summary: 'A summary' };
  blueprint._element = { ...named('Hello there', 'Page'), presentation: { mode: 'open', edit_target: 'title' } };
  bootIndex(blueprint);
  const index = projectionIndex();
  const elements = [...index].filter(([, entry]) => entry.tier === 'element').map(([key]) => key);
  const expected = projectTree(root, blueprint);
  assert.deepEqual(elements.sort(), keyedKeys(expected).sort());
  for (const key of elements) {
    assert.equal(index.get(key).label, find(expected, (node) => node.key === key).label, key);
    assert.equal(index.get(key).elementType, find(expected, (node) => node.key === key).elementType, key);
  }
  // Every element entry carries the projection's facts: parent is the
  // element-tier parent (parts transparent), bundle rides bundle-bearing
  // rows, the two delivered words ride every row (the type resolver reads
  // `elementType` here, the label resolver `label`), and no part address
  // ever appears in `children`.
  assert.equal(index.get(`block:${LINKED}`).bundle, 'image');
  assert.equal(index.get(`block:${LINKED}`).parent, `container:${SECTION}`);
  assert.equal(index.get(`block:${LINKED}`).elementType, 'Image');
  assert.equal(index.get(`block:${BLOCK}`).label, 'Secundum');
  assert.equal(index.get(`block:${BLOCK}`).elementType, 'Test');
  assert.equal(index.get(`container:${SECTION}`).elementType, 'Section');
  assert.equal(index.get(`entity:${ROOT}`).bundle, 'page');
  assert.equal(index.get(`entity:${ROOT}`).label, 'Hello there');
  assert.equal(index.get(`entity:${ROOT}`).elementType, 'Page');
  assert.equal(index.get(`block:${LINKED}:field_link`).elementType, 'Link');
  for (const [, entry] of index) {
    assert.ok(entry.children.every((key) => !key.includes('::')));
  }
  // Every element-tier entry's source is 'element': model rows are
  // depicted in every view, so reach never gates them.
  for (const key of elements) {
    assert.equal(index.get(key).source, 'element', key);
  }
  assert.equal(projectionRoot(), `entity:${ROOT}`);
});

test('the index carries the verdict on every address: elements their own, fields and parts their element\'s', () => {
  const refused = { content: false, configure: false, style: false, arrange: false, insert: false };
  const open = { content: true, configure: true, style: true, arrange: true, insert: true };
  const rootCan = { content: true, configure: false, style: true, arrange: false, insert: false };
  const components = indexComponents().map((component) => ({
    ...component,
    _element: { ...component._element, can: refused },
  }));
  const blueprint = indexBlueprint(components);
  blueprint._element = { ...page, can: rootCan };
  blueprint.fields = { title: 'Own title' };
  blueprint.layout[0]._element = { ...section, can: refused };
  bootIndex(blueprint);
  const index = projectionIndex();
  assert.deepEqual(index.get(`entity:${ROOT}`).can, rootCan);
  assert.deepEqual(index.get(`container:${SECTION}`).can, refused);
  assert.deepEqual(index.get(`block:${LINKED}`).can, refused);
  // A field row inherits its element's verdict: the root's for a root
  // field, the block's for an inline block field.
  assert.deepEqual(index.get(`field:${ROOT}:title`).can, rootCan);
  assert.deepEqual(index.get(`block:${LINKED}:field_link`).can, refused);
  // A placed part answers its element's verdict.
  assert.deepEqual(index.get(selectorAddress(`block:${LINKED}`, '.image-block-image')).can, refused);
  // A fixture stamped nowhere: every entry answers null, which the gate
  // reads as refused.
  bootIndex(indexBlueprint(indexComponents().map((component) => {
    const { can, ...unstamped } = component._element;
    return { ...component, _element: unstamped };
  })));
  for (const [key, entry] of projectionIndex()) {
    assert.equal(entry.can, null, key);
  }
  // The host's own document, every verdict open: every entry open.
  const own = indexBlueprint(indexComponents().map((component) => ({
    ...component,
    _element: { ...component._element, can: open },
  })));
  own._element = { ...page, can: open };
  own.layout[0]._element = { ...section, can: open };
  bootIndex(own);
  for (const [key, entry] of projectionIndex()) {
    assert.deepEqual(entry.can, open, key);
  }
});

test('the index projects a root with no fields: the root entry, then the sections', () => {
  const blueprint = indexBlueprint();
  delete blueprint.fields;
  blueprint._element = { ...named('node.page.default', 'Template'), presentation: { mode: 'open' } };
  bootIndex(blueprint);
  const index = projectionIndex();
  assert.equal(projectionRoot(), `entity:${ROOT}`);
  assert.equal(index.get(`entity:${ROOT}`).elementType, 'Template');
  assert.deepEqual(index.get(`entity:${ROOT}`).children, [`container:${SECTION}`]);
  assert.equal([...index.keys()].some((key) => key.startsWith(`field:${ROOT}:`)), false);
});

test('the index folds a field block placement exactly as the built-in projection does', () => {
  // The composition (which the index derives from) and the projection
  // must agree on the fold: one merged row for the placement, labeled
  // live from the root field, and no field row of its own when the root
  // folds it; the root row keeps its own field row when the display also
  // renders it.
  const folded = indexBlueprint([...indexComponents(), fieldBlockComponent()]);
  folded.fields = { field_summary: 'Placed summary' };
  folded._element = { ...page, fields: { field_summary: { element: false } } };
  const index = bootIndex(folded);
  const elements = [...index].filter(([, entry]) => entry.tier === 'element').map(([key]) => key);
  assert.deepEqual(elements.sort(), keyedKeys(projectTree(root, folded)).sort());
  assert.equal(index.get(`block:${FIELD_BLOCK}`).label, 'Placed summary');
  assert.equal(index.get(`block:${FIELD_BLOCK}`).elementType, 'Summary');
  assert.equal(index.has(`field:${ROOT}:field_summary`), false);

  const twice = indexBlueprint([...indexComponents(), fieldBlockComponent('Twice')]);
  twice.fields = { field_summary: 'Twice' };
  const both = bootIndex(twice);
  assert.equal(both.has(`field:${ROOT}:field_summary`), true);
  assert.equal(both.get(`block:${FIELD_BLOCK}`).label, 'Twice');
  assert.deepEqual(
    [...both].filter(([, entry]) => entry.tier === 'element').map(([key]) => key).sort(),
    keyedKeys(projectTree(root, twice)).sort(),
  );
});

test('a linked image has its Raster row under the block; an unlinked one has none', () => {
  const index = bootIndex();
  const raster = index.get(selectorAddress(`block:${LINKED}`, '.image-block-image'));
  assert.deepEqual(raster, {
    label: 'Raster',
    kind: 'part',
    bundle: null,
    parent: `block:${LINKED}`,
    rowParent: `block:${LINKED}`,
    children: [`block:${LINKED}:field_image`],
    rows: [`block:${LINKED}:field_image`],
    tier: 'part',
    source: MARKUP_VIEW_ID,
    owner: `block:${LINKED}`,
    can: OPEN,
    tag: 'img',
  });
  // The block's rows lead with the part (shells first, the composition's
  // ordering convention); its children hoist the claimed field through
  // the transparent part, and the field's structure parent is the block
  // while its row parent is the shell it sits in.
  const block = index.get(`block:${LINKED}`);
  assert.deepEqual(block.rows, [selectorAddress(`block:${LINKED}`, '.image-block-image'), `block:${LINKED}:field_link`]);
  assert.deepEqual(block.children, [`block:${LINKED}:field_image`, `block:${LINKED}:field_link`]);
  const field = index.get(`block:${LINKED}:field_image`);
  assert.equal(field.parent, `block:${LINKED}`);
  assert.equal(field.rowParent, selectorAddress(`block:${LINKED}`, '.image-block-image'));

  // The hoisted part (placement 'root') is absent by construction: the
  // unlinked block's picture IS the block root, so nothing can select it,
  // and the field mounts at the block position.
  assert.equal(index.has(selectorAddress(`block:${UNLINKED}`, '.image-block-image')), false);
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
  const item = index.get(selectorAddress(`block:${LINKED}:field_image`, '.field__item'));
  assert.equal(item.tier, 'part');
  assert.equal(item.source, MARKUP_VIEW_ID);
  assert.equal(item.label, 'Item');
  assert.equal(item.tag, 'div');
  assert.equal(item.parent, `block:${LINKED}:field_image`);
  assert.deepEqual(index.get(`block:${LINKED}:field_image`).rows, [selectorAddress(`block:${LINKED}:field_image`, '.field__item')]);
});

test('list carriers nest: the li under the ul under its owner', () => {
  const index = bootIndex();
  const owner = `block:${TEXT}`;
  const ul = selectorAddress(owner, '.s-l1');
  const li = selectorAddress(owner, '.s-i1');
  assert.equal(index.get(ul).label, 'Bulleted list');
  assert.equal(index.get(ul).parent, owner);
  assert.equal(index.get(ul).owner, owner);
  assert.deepEqual(index.get(ul).rows, [li]);
  assert.deepEqual(index.get(ul).children, []);
  // A carrier is named by what it says (its excerpt); a list wrapper by
  // its word, since its text is only its items' (instance-rows.js).
  assert.equal(index.get(li).label, 'First');
  assert.equal(index.get(li).tag, 'li');
  // Carriers are content: their source is 'instance', never the markup
  // view's, so reach depicts them whatever the Show menu says.
  assert.equal(index.get(ul).source, 'instance');
  assert.equal(index.get(li).source, 'instance');
  assert.equal(index.get(li).parent, ul);
  assert.equal(index.get(li).owner, owner);
  assert.deepEqual(index.get(li).rows, []);
  // The merged block row carries the list as its one row; its element
  // children stay empty (the absorbed target's identity merged away), and
  // the part the model positively says absent has no row.
  assert.deepEqual(index.get(owner).rows, [ul]);
  assert.deepEqual(index.get(owner).children, []);
  assert.equal(index.get(owner).label, '<ul><li>First</li></ul>');
  assert.equal(index.has(selectorAddress(owner, '.text-block-link')), false);
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
  store.dispatch(blueprintReplaced({ blueprint: indexBlueprint(components) }));
  const replaced = projectionIndex();
  assert.notEqual(replaced, index);
  assert.equal(replaced.has(selectorAddress(`block:${LINKED}`, '.image-block-image')), false);
  assert.equal(replaced.has(`block:${LINKED}`), true);
});

// ---------------------------------------------------------------------------
// Generated boxes: the part behavior's cascade rows, both tiers.
// ---------------------------------------------------------------------------

const BEFORE_BOX = { id: '&::before', pseudo: 'before', label: 'Before' };
const AFTER_BOX = { id: '&::after', pseudo: 'after', label: 'After' };

/**
 * Registers a part behavior whose cascade delivers the given boxes per
 * key (T9's generated(key) shape) and none elsewhere; returns the keys
 * it was asked for, in order, so the memo can be read.
 */
const boxBehavior = (boxes = { [`block:${LINKED}`]: [BEFORE_BOX, AFTER_BOX] }) => {
  const asked = [];
  registerPartBehavior({
    generated: (key) => {
      asked.push(key);
      return boxes[key] ?? [];
    },
  });
  return asked;
};

test('a delivered box is a part-tier row under its element, addressed as the box\'s selector', () => {
  const asked = boxBehavior();
  const index = bootIndex();
  const owner = `block:${LINKED}`;
  const before = selectorAddress(owner, '&::before');
  const after = selectorAddress(owner, '&::after');
  // The row is a part entry like any shell or carrier row: the owner is
  // both its structure and row parent, it lists nothing, and it wears no
  // tag (a box is no element).
  assert.deepEqual(index.get(before), {
    label: 'Before',
    kind: 'part',
    bundle: null,
    parent: owner,
    rowParent: owner,
    children: [],
    rows: [],
    tier: 'part',
    source: MARKUP_VIEW_ID,
    owner,
    can: OPEN,
    tag: null,
  });
  assert.equal(index.get(after).label, 'After');
  // The owner's rows end with the boxes in grammar order, after the
  // anatomy's part and the fields; the element tier never sees them.
  assert.deepEqual(index.get(owner).rows, [
    selectorAddress(owner, '.image-block-image'),
    `${owner}:field_link`,
    before,
    after,
  ]);
  assert.deepEqual(index.get(owner).children, [`${owner}:field_image`, `${owner}:field_link`]);
  // A target at a delivered box seats on its row while the Markup view
  // draws boxes (traversal and the breadcrumb place it) and rides the
  // element with it off; at a box nothing delivers it rides the element
  // in every view, like the children or a type selector.
  assert.equal(seatAddress(index, reachOver(index, { [MARKUP_VIEW_ID]: true }), owner, '&::before'), before);
  assert.equal(seatAddress(index, reachOver(index, {}), owner, '&::before'), owner);
  assert.equal(index.has(selectorAddress(`block:${UNLINKED}`, '&::before')), false);
  assert.equal(seatAddress(index, reachOver(index, { [MARKUP_VIEW_ID]: true }), `block:${UNLINKED}`, '&::before'), `block:${UNLINKED}`);
  // Every element-tier key asked its cascade exactly once, no part
  // address ever did (a pseudo-element is terminal).
  const elements = [...index].filter(([, entry]) => entry.tier === 'element').map(([key]) => key);
  assert.deepEqual([...asked].sort(), elements.sort());
});

test('the tree composes the box rows under every tree and draws them only while the Markup view is on', () => {
  boxBehavior();
  bootIndex();
  const owner = `block:${LINKED}`;
  const blueprint = store.getState().blueprint.blueprint;
  // The built-in projection with its instance rows, composed as the
  // tree composes it with no provider active: the boxes come last under
  // the owner, the seam's pseudo shape with the box's selector as the
  // row id, so PartRow's click commits {parentKey: owner, id:
  // '&::before'}, and each stamped with the Markup view's source.
  const tree = withGeneratedRows(withInstanceRows(projectTree(root, blueprint), instanceRows), generatedRows);
  const block = find(tree, (node) => node.key === owner);
  assert.deepEqual(block.children.slice(-2), [
    { pseudo: true, id: '&::before', parentKey: owner, label: 'Before', icon: boxGlyph(), source: MARKUP_VIEW_ID, children: [] },
    { pseudo: true, id: '&::after', parentKey: owner, label: 'After', icon: boxGlyph(), source: MARKUP_VIEW_ID, children: [] },
  ]);
  assert.equal(block.children.length, 4);
  assert.equal(find(tree, (node) => node.pseudo && node.parentKey === `block:${UNLINKED}`), null);
  // A provider's tree gets the same rows: the wrapper reads keys, not the
  // view (the same call the tree makes on a provider's projection).
  const composed = withGeneratedRows(withInstanceRows(projectTree(root, blueprint), () => []), generatedRows);
  assert.deepEqual(
    find(composed, (node) => node.key === owner).children.filter((node) => node.pseudo).map((node) => node.id),
    ['&::before', '&::after'],
  );
  // The DRAW is reach: the tree renders depictedRows over the composed
  // tree, keyed to the view slice. With Markup off (the built-in tree,
  // daedalus_stylish's basic mode) the box rows are gone and the text
  // block's instance rows stand, at every depth, because their source is
  // 'instance', not because a projection appended them; with Markup on
  // the same composition draws the boxes too. Model rows never move: the
  // block's element children are the same list in both.
  const off = depictedRows(tree, {});
  const drawnOff = find(off, (node) => node.key === owner);
  assert.deepEqual(drawnOff.children.filter((node) => node.pseudo), []);
  assert.equal(find(off, (node) => node.pseudo && node.id.startsWith('&::')), null);
  const list = find(off, (node) => node.pseudo && node.id === '.s-l1');
  assert.equal(list.parentKey, `block:${TEXT}`);
  assert.equal(list.source, 'instance');
  assert.deepEqual(list.children.map((node) => node.id), ['.s-i1']);
  const drawnOn = find(depictedRows(tree, { [MARKUP_VIEW_ID]: true }), (node) => node.key === owner);
  assert.deepEqual(drawnOn.children.map((node) => node.pseudo ? node.id : node.key), block.children.map((node) => node.pseudo ? node.id : node.key));
  assert.deepEqual(
    drawnOff.children.filter((node) => !node.pseudo).map((node) => node.key),
    block.children.filter((node) => !node.pseudo).map((node) => node.key),
  );
  // Another view's toggle is not the Markup view's: a contrib view on
  // with Markup off draws its own rows and no boxes.
  assert.equal(find(depictedRows(tree, { contrib: true }), (node) => node.pseudo && node.id.startsWith('&::')), null);
});

test('an undepicted pseudo row hoists its children into its place when drawn', () => {
  // A row the panel does not draw still shows what sits inside it under
  // the nearest drawn ancestor, mirroring the index's transparent rows
  // and the reach-filtered parent climb: a field claimed into a shell
  // row renders under the block while the shell's view is off, and the
  // shell's own rows (source: its view) go with the shell.
  const tree = {
    key: 'entity:x',
    children: [{
      pseudo: true,
      id: '.shell',
      parentKey: 'entity:x',
      source: MARKUP_VIEW_ID,
      children: [
        { key: 'block:a', children: [{ pseudo: true, id: '.s-a1', parentKey: 'block:a', source: 'instance', children: [] }] },
        { pseudo: true, id: '.inner', parentKey: 'entity:x', source: MARKUP_VIEW_ID, children: [{ key: 'block:b', children: [] }] },
        { pseudo: true, id: '.own', parentKey: 'entity:x', source: 'contrib', children: [] },
      ],
    }],
  };
  const off = depictedRows(tree, {});
  assert.deepEqual(off.children.map((node) => node.pseudo ? node.id : node.key), ['block:a', 'block:b']);
  assert.deepEqual(off.children[0].children.map((node) => node.id), ['.s-a1']);
  const on = depictedRows(tree, { [MARKUP_VIEW_ID]: true, contrib: true });
  assert.deepEqual(on, tree);
  // The input is never mutated: the tree re-renders from the same
  // composition on every view flip.
  assert.equal(tree.children[0].children.length, 3);
});

test('the box answer is memoized on the mirror, the materials and the behavior', () => {
  const asked = boxBehavior();
  const owner = `block:${LINKED}`;
  const before = selectorAddress(owner, '&::before');
  const index = bootIndex();
  const asks = () => asked.filter((key) => key === owner).length;
  // The index build asked once; the tree's render reads the same answer.
  assert.equal(asks(), 1);
  assert.deepEqual(generatedRows(owner), [BEFORE_BOX, AFTER_BOX]);
  assert.deepEqual(generatedRows(owner), [BEFORE_BOX, AFTER_BOX]);
  assert.equal(asks(), 1);
  assert.equal(projectionIndex(), index);
  // A materials delta re-derives (a worn look's box fragment can flip a
  // box) and the index with it, though the mirror stands.
  store.dispatch(materialsDelta({ upsert: [{ kind: 'variant', id: 'glow', label: 'Glow' }], remove: [] }));
  assert.deepEqual(generatedRows(owner), [BEFORE_BOX, AFTER_BOX]);
  assert.equal(asks(), 2);
  const afterDelta = projectionIndex();
  assert.notEqual(afterDelta, index);
  assert.equal(afterDelta.has(before), true);
  assert.equal(asks(), 2);
  // A mirror replacement re-derives.
  store.dispatch(blueprintReplaced({ blueprint: indexBlueprint() }));
  assert.equal(projectionIndex().has(before), true);
  assert.equal(asks(), 3);
  // A behavior registered after the index was built replaces the index:
  // registration order between bundles is no guarantee, and the rows
  // must follow the behavior that answers now.
  const silent = boxBehavior({});
  const replaced = projectionIndex();
  assert.notEqual(replaced, afterDelta);
  assert.equal(replaced.has(before), false);
  assert.deepEqual(replaced.get(owner).rows, [selectorAddress(owner, '.image-block-image'), `${owner}:field_link`]);
  assert.ok(silent.includes(owner));
  assert.deepEqual(generatedRows(owner), []);
});

test('a behavior without generated() and an absorbed row list no boxes', () => {
  // No generated() on the behavior: nothing to ask, the index is the
  // shell-and-carrier index it was.
  registerPartBehavior({});
  const index = bootIndex();
  assert.deepEqual(generatedRows(`block:${LINKED}`), []);
  assert.deepEqual(index.get(`block:${LINKED}`).rows, [
    selectorAddress(`block:${LINKED}`, '.image-block-image'),
    `block:${LINKED}:field_link`,
  ]);
  // An absorbed row's key has left the universe: its cascade is not
  // asked, so no box row could name it.
  const asked = [];
  registerPartBehavior({ generated: (key) => { asked.push(key); return [BEFORE_BOX]; } });
  const tree = withGeneratedRows(
    { key: 'entity:x', children: [{ key: 'block:absorbed', absorbed: true, children: [] }, { key: null, children: [] }] },
    (key) => { asked.push(key); return [BEFORE_BOX]; },
  );
  assert.deepEqual(asked, ['entity:x']);
  assert.deepEqual(tree.children.map((node) => node.pseudo ? node.parentKey : node.key), ['block:absorbed', null, 'entity:x']);
});

// ---- A locked page's slot fills (slot-fills.js). --------------------------

const DOOR = '11111111-5107-4000-8000-444444444444';
const FILL = '22222222-5107-4000-8000-444444444444';
const FILL_BLOCK = '33333333-5107-4000-8000-444444444444';
const STRAY_FILL = '44444444-5107-4000-8000-444444444444';
const door = (slot) => ({
  kind: 'block',
  uuid: DOOR,
  plugin_id: `daedalus_slot:${slot}`,
  label: 'Hero',
  slot,
  _element: named('Hero', 'Slot'),
});
const fill = (uuid, slot) => ({
  kind: 'container',
  uuid,
  slot,
  layout_id: 'layout_onecol',
  _element: section,
  children: [{
    kind: 'region',
    region: 'content',
    children: [{ kind: 'block', uuid: FILL_BLOCK, block_type: 'text', label: 'Fill text', block_fields: {}, _element: named('Text') }],
  }],
});
/**
 * The lean tree of a locked page, as delivered: the template's section
 * holding the door (nested one level down, where a template may place
 * it), then the page's own fill tagged with the slot.
 */
const lockedBlueprint = (slot, fills) => ({
  bundle: 'locked',
  fields: {},
  _element: page,
  layout: [{
    kind: 'container',
    uuid: SECTION,
    layout_id: 'layout_onecol',
    _element: section,
    children: [{ kind: 'region', region: 'content', children: [{
      kind: 'container',
      uuid: NESTED_SECTION,
      layout_id: 'layout_onecol',
      _element: named('Section', 'Container'),
      children: [{ kind: 'region', region: 'content', children: [door(slot)] }],
    }] }],
  }, ...fills],
});

test('a slot fill projects under its door at any depth, leaving the root list', () => {
  const tree = projectTree(root, lockedBlueprint('hero', [fill(FILL, 'hero')]));
  // The root's only section is the template's: the fill left the list.
  assert.deepEqual(tree.children.map((node) => node.key), [`container:${SECTION}`]);
  const doorRow = find(tree, (node) => node.key === `block:${DOOR}`);
  assert.equal(doorRow.kind, 'block');
  // The fill is the door's child: a container row, its key unchanged, its
  // subtree projected as any section's.
  assert.deepEqual(doorRow.children.map((node) => [node.kind, node.key]), [['container', `container:${FILL}`]]);
  assert.equal(find(doorRow, (node) => node.key === `block:${FILL_BLOCK}`).label, 'Text');
});

test('an empty door projects as a leaf; a fill no door declares stays at the root', () => {
  const tree = projectTree(root, lockedBlueprint('hero', [fill(STRAY_FILL, 'aside')]));
  assert.deepEqual(find(tree, (node) => node.key === `block:${DOOR}`).children, []);
  assert.deepEqual(tree.children.map((node) => node.key), [`container:${SECTION}`, `container:${STRAY_FILL}`]);
});

// ---- The shown document under a door (shown-rows.js). ----------------------

/**
 * The spine's showing on a door (`_element.showing`, the annotator copying
 * VerdictStamper::showing() with the portal's url resolved).
 */
const showing = (shows) => ({
  shows,
  document: shows === 'default' ? { entity_type: 'blueprint', id: '1', label: 'Header' } : null,
  blueprint: shows === 'default' ? 'header' : null,
  portal: shows === 'default' ? { entity_type: 'blueprint', entity_id: '1', label: 'Header', url: '/blueprint/1' } : null,
  claimable: shows === 'default',
});
const showingDoor = (slot, shows) => ({
  ...door(slot),
  _element: { ...named('Header slot', 'Slot'), showing: showing(shows) },
});
const withDoor = (doorNode, fills = []) => ({
  ...lockedBlueprint('hero', fills),
  layout: [{
    kind: 'container',
    uuid: SECTION,
    layout_id: 'layout_onecol',
    _element: section,
    children: [{ kind: 'region', region: 'content', children: [doorNode] }],
  }, ...fills],
});

test('a door showing its default projects one shown-document row under it, after the fills', () => {
  const tree = projectTree(root, withDoor(showingDoor('hero', 'default')));
  const doorRow = find(tree, (node) => node.key === `block:${DOOR}`);
  // The door row reads the words the annotator minted for a slot.
  assert.equal(doorRow.label, 'Header slot');
  assert.equal(doorRow.elementType, 'Slot');
  // One child: the shown document, a row of its own kind (no key, so no
  // selection and no address), named by the blueprint, typed by reference.
  assert.equal(doorRow.children.length, 1);
  const [shown] = doorRow.children;
  assert.equal(shown.shown, true);
  assert.equal(shown.key, undefined);
  assert.equal(shown.label, 'Header');
  assert.equal(shown.elementType, 'Blueprint, by reference');
  assert.equal(shown.parentKey, `block:${DOOR}`);
  assert.equal(shown.showing.shows, 'default');
  assert.deepEqual(shown.children, []);
  // The composition order: the fills first, the shown row after them (the
  // spine shows FILL on a filled door, so the two never meet in one
  // delivery; the rule is pinned on a synthetic node carrying both).
  const both = projectTree(root, withDoor(showingDoor('hero', 'default'), [fill(FILL, 'hero')]));
  const bothDoor = find(both, (node) => node.key === `block:${DOOR}`);
  assert.deepEqual(bothDoor.children.map((node) => node.shown ? 'shown' : node.key), [`container:${FILL}`, 'shown']);
});

test('a door showing a fill, the route or nothing grows no shown row', () => {
  for (const shows of ['fill', 'route', 'nothing']) {
    const tree = projectTree(root, withDoor(showingDoor('hero', shows)));
    assert.deepEqual(find(tree, (node) => node.key === `block:${DOOR}`).children, [], shows);
  }
  // A filled door: its fill rows and nothing else.
  const filled = projectTree(root, withDoor(showingDoor('hero', 'fill'), [fill(FILL, 'hero')]));
  assert.deepEqual(find(filled, (node) => node.key === `block:${DOOR}`).children.map((node) => node.key), [`container:${FILL}`]);
});

test('the shown row is no address: the index and reach never see it', () => {
  const index = bootIndex(withDoor(showingDoor('hero', 'default')));
  assert.ok(index.has(`block:${DOOR}`), 'the door is an address');
  // The door's rows and children list nothing for the shown document (the
  // suite's registered part behavior delivers a generated box under every
  // key, so the door's rows are part addresses alone), and no entry names
  // it under any spelling.
  assert.deepEqual(index.get(`block:${DOOR}`).children, []);
  assert.ok(index.get(`block:${DOOR}`).rows.every((address) => address.includes('::')), 'part rows only');
  for (const [address, entry] of index) {
    assert.notEqual(entry.label, 'Header', `${address} names the shown document`);
    assert.ok(!address.startsWith('shown:'), address);
  }
  assert.equal(reachOver(index, {})(`shown:block:${DOOR}`), false);
});
