/**
 * Self-asserting tests for the markup composition (compose.js) and its
 * seam adapter (markup-view.js toViewTree).
 *
 * Run with `node --test tests/js/*.test.mjs` from the module root (the bare
 * directory form would try to execute non-test helpers as tests).
 */
// cspell:ignore hieah Secundum
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { compose, fieldClass } from '../../js/edit_mode/components/page-elements/compose.js';
import { toViewTree, withInstanceRows } from '../../js/edit_mode/components/page-elements/markup-view.js';

const ROOT = '99999999-1111-2222-3333-444444444444';
const SECTION = 'aaaaaaaa-1111-2222-3333-444444444444';
const BLOCK = 'bbbbbbbb-1111-2222-3333-444444444444';
const BLOCK_2 = 'cccccccc-1111-2222-3333-444444444444';
const PARA = 'dddddddd-1111-2222-3333-444444444444';

/**
 * Renders a single-spine tree as the design sketches do: one line per row,
 * shell rows by selector (dot stripped), model/value rows by label. Fails
 * loudly when a level forks — the four-state example is a spine, and a
 * surprise sibling is a composition bug, not something to skip over.
 */
const spine = (node) => {
  const line = node.type === 'shell' ? node.selector.slice(1) : node.label;
  if (node.children.length === 0) {
    return [line];
  }
  assert.equal(node.children.length, 1, `spine forked under "${line}"`);
  return [line, ...spine(node.children[0])];
};

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

const root = { uuid: ROOT, label: 'Page' };

/**
 * The same block presenting OPEN (the site builder's "Show structure"
 * setting): no absorption, every identity keeps its own row.
 */
const withOpenBlock = (source) => ({
  ...source,
  layout_builder__layout: source.layout_builder__layout.map((section) => ({
    ...section,
    regions: Object.fromEntries(Object.entries(section.regions).map(([region, components]) => [
      region,
      components.map((component) => ({ ...component, _element: { ...component._element, presentation: { mode: 'open' } } })),
    ])),
  })),
});

// The test-block example from the design, verbatim: a one-field "test"
// block with merged presentation, its shells declared in the same grammar
// the declarations ship (block type shell contains fields, field kind
// shell's .field__item contains value).
const anatomy = {
  kinds: {
    field: { elements: { '.field__item': { contains: 'value' } } },
  },
  blockTypes: {
    test: { elements: { '.block__content': { contains: 'fields' } } },
  },
};

const blueprint = {
  bundle: 'page',
  fields: {},
  layout_builder__layout: [{
    uuid: SECTION,
    layout_id: 'daedalus_stylish_onecol',
    regions: {
      content: [{
        uuid: BLOCK,
        block_type: 'test',
        label: 'Test',
        label_display: '0',
        block_fields: { field_hieah: 'Secundum' },
        _element: { presentation: { mode: 'merged', label: 'Hieah', edit_target: 'field_hieah' } },
      }],
    },
  }],
};

const openBlueprint = withOpenBlock(blueprint);

// ---- The toggle states, verbatim. ------------------------------------------

test('targets OFF, merged: Onecol > Secundum (the data tree)', () => {
  const tree = compose(blueprint, anatomy, { root });
  assert.deepEqual(spine(tree), ['Page', 'Onecol', 'Secundum']);
  const merged = find(tree, (node) => node.label === 'Secundum');
  assert.equal(merged.type, 'model');
  assert.equal(merged.kind, 'block');
  assert.equal(merged.key, `block:${BLOCK}`);
  assert.equal(merged.presentation, 'merged');
  assert.equal(merged.editTarget, 'field_hieah');
});

test('targets OFF, open: Onecol > Test > Hieah (the data tree)', () => {
  const tree = compose(openBlueprint, anatomy, { root });
  assert.deepEqual(spine(tree), ['Page', 'Onecol', 'Test', 'Hieah']);
  const field = find(tree, (node) => node.label === 'Hieah');
  assert.equal(field.key, `block:${BLOCK}:field_hieah`);
  // The data tree carries no markup identities.
  assert.equal(field.markupClass, undefined);
});

test('targets ON, merged: the merged row lands at the innermost absorbed position', () => {
  const tree = compose(blueprint, anatomy, { root, targets: true });
  assert.deepEqual(spine(tree), [
    'Page', 'Onecol', 'block__content', 'field--name-field-hieah', 'field__item', 'Secundum',
  ]);
  // The absorbed field row keeps its key (the flat sub-element key survives
  // presentation) but presents under its markup class.
  const field = find(tree, (node) => node.label === 'field--name-field-hieah');
  assert.equal(field.type, 'model');
  assert.equal(field.key, `block:${BLOCK}:field_hieah`);
  assert.equal(field.absorbed, true);
  // The merged row IS the content: block-keyed, sitting where the value
  // element lives, wearing the edit target.
  const merged = find(tree, (node) => node.label === 'Secundum');
  assert.equal(merged.key, `block:${BLOCK}`);
  assert.equal(merged.presentation, 'merged');
  assert.equal(merged.editTarget, 'field_hieah');
  // Shell rows scope to their owning model key at any depth.
  const shell = find(tree, (node) => node.selector === '.field__item');
  assert.equal(shell.parentKey, `block:${BLOCK}:field_hieah`);
  assert.equal(find(tree, (node) => node.selector === '.block__content').parentKey, `block:${BLOCK}`);
  // An unlabeled part's label is the selector without its leading dot (the
  // sketch convention — class rows read as class names, not CSS syntax).
  assert.equal(shell.label, 'field__item');
});

test('targets ON, open: nothing absorbs', () => {
  const tree = compose(openBlueprint, anatomy, { root, targets: true });
  assert.deepEqual(spine(tree), [
    'Page', 'Onecol', 'Test', 'block__content', 'Hieah', 'field__item', 'Secundum',
  ]);
  // Every identity is present: the block row, the field row (one row — the
  // selectable element and the .field--name-X wrapper are one element),
  // and the editable value.
  const field = find(tree, (node) => node.label === 'Hieah');
  assert.equal(field.key, `block:${BLOCK}:field_hieah`);
  assert.equal(field.markupClass, 'field--name-field-hieah');
  assert.equal(field.absorbed, undefined);
  const value = find(tree, (node) => node.type === 'value');
  assert.equal(value.label, 'Secundum');
  assert.equal(value.key, null);
});

// ---- The residual rule. ----------------------------------------------------

test('specific claims bind named children; generic buckets take the residual; unclaimed mounts at root', () => {
  const cardAnatomy = {
    blockTypes: {
      card: {
        elements: {
          '.block__content': {
            contains: 'fields',
            elements: {
              '.card__header': { label: 'Header', contains: ['field:field_title'] },
            },
          },
        },
      },
    },
  };
  const cardBlueprint = {
    bundle: 'page',
    layout_builder__layout: [{
      uuid: SECTION,
      layout_id: 'daedalus_stylish_onecol',
      regions: {
        content: [{
          uuid: BLOCK,
          block_type: 'card',
          label: 'Card',
          label_display: 'visible',
          block_fields: { field_title: 'Title', field_summary: 'Summary' },
        }],
      },
    }],
  };
  const tree = compose(cardBlueprint, cardAnatomy, { root, targets: true });
  const block = find(tree, (node) => node.key === `block:${BLOCK}`);
  // Shell rows first (authored order), then the unclaimed children — the
  // rendered label row is not a field, so no slot can claim it.
  assert.deepEqual(
    block.children.map((node) => node.type === 'shell' ? node.selector : node.label),
    ['.block__content', 'Label'],
  );
  const content = block.children[0];
  // Inside a part: its nested shells, then its claimed children. field_title
  // is bound by the specific claim, field_summary is the fields residual.
  assert.deepEqual(
    content.children.map((node) => node.type === 'shell' ? node.selector : node.label),
    ['.card__header', 'Summary'],
  );
  assert.deepEqual(content.children[0].children.map((node) => node.label), ['Title']);
  assert.equal(find(tree, (node) => node.key === `block:${BLOCK}:label`).kind, 'field');
});

test('no declared slots degenerates to children at root (flat YAML compat)', () => {
  const flatAnatomy = {
    blockTypes: {
      test: { elements: { '.decor': { label: 'Decoration' } } },
    },
  };
  const tree = compose(openBlueprint, flatAnatomy, { root, targets: true });
  const block = find(tree, (node) => node.key === `block:${BLOCK}`);
  // The slotless part renders as an empty class row; the field mounts at
  // the block root exactly as the data tree does.
  assert.deepEqual(
    block.children.map((node) => node.type === 'shell' ? node.selector : node.label),
    ['.decor', 'Hieah'],
  );
  assert.deepEqual(block.children[0].children, []);
});

// ---- Row identity. ----------------------------------------------------------

test('a multi-value scalar field keeps one .field__item row and no value row', () => {
  const multi = {
    bundle: 'page',
    fields: { field_tags: ['One', 'Two', 'Three'] },
  };
  const tree = compose(multi, anatomy, { root, targets: true });
  const field = find(tree, (node) => node.key === `field:${ROOT}:field_tags`);
  assert.equal(field.markupClass, 'field--name-field-tags');
  assert.deepEqual(field.children.map((node) => node.selector), ['.field__item']);
  assert.equal(find(tree, (node) => node.type === 'value'), null);
});

test('individually-addressable deltas mount as model children at the value slot and compose recursively', () => {
  const paragraphs = {
    bundle: 'page',
    fields: {
      field_slides: [
        { uuid: PARA, type: 'slide', label: 'Slide', fields: { field_text: 'Hello' } },
      ],
    },
  };
  const tree = compose(paragraphs, anatomy, { root, targets: true });
  const item = find(tree, (node) => node.selector === '.field__item' && node.parentKey === `field:${ROOT}:field_slides`);
  assert.deepEqual(item.children.map((node) => node.key), [`entity:${PARA}`]);
  // The paragraph composes recursively: its own field gets the field-kind
  // shell and its value row.
  assert.deepEqual(spine(item.children[0]), ['Slide', 'Text', 'field__item', 'Hello']);
});

test('an entity-valued merge target has no value element: the merged row takes the field position', () => {
  const merged = {
    bundle: 'page',
    layout_builder__layout: [{
      uuid: SECTION,
      layout_id: 'daedalus_stylish_onecol',
      regions: {
        content: [{
          uuid: BLOCK_2,
          block_type: 'test',
          label: 'Slideshow',
          label_display: '0',
          block_fields: {
            field_slides: [{ uuid: PARA, type: 'slide', label: 'Slide', fields: {} }],
          },
          _element: { presentation: { mode: 'merged', label: 'Slides', edit_target: 'field_slides' } },
        }],
      },
    }],
  };
  const tree = compose(merged, anatomy, { root, targets: true });
  assert.deepEqual(spine(tree), [
    'Page', 'Onecol', 'block__content', 'Slides', 'field__item', 'Slide',
  ]);
  const row = find(tree, (node) => node.label === 'Slides');
  assert.equal(row.key, `block:${BLOCK_2}`);
  assert.equal(row.presentation, 'merged');
});

test('a merge absorbs the target\'s sibling identities: class rows with a wrapper, folded without', () => {
  // The data tree keeps a merged row's target descendants and nothing
  // else, so the composition absorbs every other identity of the block:
  // with shells on a sibling field's wrapper element is real markup and
  // its row stays as an absorbed class row (the target wrapper's rule,
  // its own descendants absorbed with it); collapsed, no wrapper stands
  // and the sibling folds outright. The selection index reads absorbed
  // rows as transparent, so what it selects is what the data tree shows.
  const withSibling = (extra) => ({
    bundle: 'page',
    layout_builder__layout: [{
      uuid: SECTION,
      layout_id: 'daedalus_stylish_onecol',
      regions: {
        content: [{
          uuid: BLOCK,
          block_type: 'test',
          label: 'Test',
          label_display: '0',
          block_fields: {
            field_hieah: 'Secundum',
            field_extra: [{ uuid: PARA, type: 'extra', label: 'Extra para', fields: { field_note: 'Note' } }],
          },
          _element: { presentation: { mode: 'merged', label: 'Hieah', edit_target: 'field_hieah' }, ...extra },
        }],
      },
    }],
  });
  const tree = compose(withSibling({}), anatomy, { root, targets: true });
  const sibling = find(tree, (node) => node.key === `block:${BLOCK}:field_extra`);
  assert.equal(sibling.absorbed, true);
  assert.equal(sibling.label, 'field--name-field-extra');
  assert.equal(find(tree, (node) => node.key === `entity:${PARA}`).absorbed, true);
  assert.equal(find(tree, (node) => node.key === `field:${PARA}:field_note`).absorbed, true);
  // The merged row and the target wrapper are untouched by the sibling pass.
  assert.equal(find(tree, (node) => node.key === `block:${BLOCK}`).absorbed, undefined);
  assert.equal(find(tree, (node) => node.key === `block:${BLOCK}:field_hieah`).absorbed, true);

  const collapsed = compose(withSibling({ composed: true }), anatomy, { root, targets: true });
  assert.equal(find(collapsed, (node) => node.key === `block:${BLOCK}:field_extra`), null);
  assert.equal(find(collapsed, (node) => node.key === `entity:${PARA}`), null);
  assert.equal(find(collapsed, (node) => node.key === `block:${BLOCK}`).presentation, 'merged');

  // Open presentation absorbs nothing: the sibling keeps its identity.
  const open = compose(withSibling({ presentation: { mode: 'open' } }), anatomy, { root, targets: true });
  assert.equal(find(open, (node) => node.key === `block:${BLOCK}:field_extra`).absorbed, undefined);
});

// ---- Collapsed = bare wrapper chain, not anatomy-free. -----------------------

const collapsedComponent = (presentation = { mode: 'merged', label: 'Text', edit_target: 'field_text' }) => ({
  bundle: 'page',
  layout_builder__layout: [{
    uuid: SECTION,
    layout_id: 'daedalus_stylish_onecol',
    regions: {
      content: [{
        uuid: BLOCK,
        block_type: 'plain',
        label: 'Plain',
        label_display: '0',
        block_fields: { field_text: 'Hi' },
        _element: {
          presentation,
          composed: true,
        },
      }],
    },
  }],
});

test('a collapsed block mounts its declared parts, never the wrapper shells', () => {
  // The `_element.composed` annotation is the block-type declaration
  // delivered per instance (the one collapse channel: a declarer that
  // means "not collapsed after all" withdraws the flag with NULL in a
  // higher layer, and the annotation simply never arrives). Collapse
  // merges the WRAPPER chain into the root, so no field shells and no
  // value rows compose; the type's own declared elements are interior
  // parts of the single tag (the linked text block's anchor) and mount
  // as shell rows, the merged row keeping them as children (the block
  // root IS the content element, so the innermost absorbed position is
  // the block position itself).
  const collapsedAnatomy = {
    ...anatomy,
    kinds: { ...anatomy.kinds, block: { elements: { '.block__inner': { contains: 'fields' } } } },
    blockTypes: { plain: { elements: { '.plain-link': { label: 'Link' } } } },
  };
  const tree = compose(collapsedComponent(), collapsedAnatomy, { root, targets: true });
  assert.deepEqual(spine(tree), ['Page', 'Onecol', 'Hi', 'plain-link']);
  const merged = find(tree, (node) => node.label === 'Hi');
  assert.equal(merged.key, `block:${BLOCK}`);
  assert.equal(merged.presentation, 'merged');
  const shell = find(tree, (node) => node.selector === '.plain-link');
  assert.equal(shell.parentKey, `block:${BLOCK}`);
  assert.equal(shell.label, 'Link');
  // The block kind's fallback tree describes the wrapper sandwich collapse
  // merged away: it never mounts.
  assert.equal(find(tree, (node) => node.selector === '.block__inner'), null);
  // Open presentation absorbs nothing, and collapse still holds: the block
  // and field rows both stand, still wrapper-free (no markup class, no
  // field shells, no value row), the declared part still present.
  const open = compose(collapsedComponent({ mode: 'open' }), collapsedAnatomy, { root, targets: true });
  const block = find(open, (node) => node.key === `block:${BLOCK}`);
  assert.deepEqual(
    block.children.map((node) => node.type === 'shell' ? node.selector : node.label),
    ['.plain-link', 'Text'],
  );
  assert.equal(find(open, (node) => node.label === 'Text').markupClass, undefined);
  assert.equal(find(open, (node) => node.type === 'value'), null);
  assert.equal(find(open, (node) => node.selector === '.block__inner'), null);
});

test('an undeclared collapsed block composes bare: the kind fallback stays out', () => {
  // A collapsed bundle with no declaration of its own gets no tree at
  // all: falling back to the block kind's would sketch wrapper markup the
  // collapse contract removed.
  const kindOnly = {
    ...anatomy,
    kinds: { ...anatomy.kinds, block: { elements: { '.block__inner': { contains: 'fields' } } } },
    blockTypes: {},
  };
  const tree = compose(collapsedComponent(), kindOnly, { root, targets: true });
  assert.deepEqual(spine(tree), ['Page', 'Onecol', 'Hi']);
});

// ---- Placement gates the shell rows (the element projection). ---------------

const linkAnatomy = () => ({
  ...anatomy,
  blockTypes: { plain: { elements: { '.plain-link': { label: 'Link' } } } },
});

test('a part positively absent from the canvas mounts no row', () => {
  // The element-projection rule: the markup view projects the PAGE, so a
  // declared part the model positively says absent (the unlinked text
  // block's anchor, svg media's img) appears nowhere, styled or not, no
  // value-set exception. The model question rides the injected placement
  // channel (view.placement); compose itself stays model-blind.
  const asked = [];
  const placement = (key, selector) => {
    asked.push([key, selector]);
    return false;
  };
  const tree = compose(collapsedComponent(), linkAnatomy(), { root, targets: true, placement });
  assert.equal(find(tree, (node) => node.selector === '.plain-link'), null);
  assert.deepEqual(spine(tree), ['Page', 'Onecol', 'Hi']);
  // The channel is asked with the owning model key and the declared
  // selector, the same pair the shell row would have carried.
  assert.ok(asked.some(([key, selector]) => key === `block:${BLOCK}` && selector === '.plain-link'));
});

test('a root-hoisted part mounts no row; a part placed as its own element mounts', () => {
  // A 'root' placement means the part is real but coincided with the
  // owning element's root (the unlinked image block's img IS the block
  // root): not a distinct DOM node, so projecting the page mounts no row
  // for it. The same declared part placed as its own element ('element',
  // the linked image block's nested img) still mounts.
  const hoisted = compose(collapsedComponent(), linkAnatomy(), {
    root, targets: true, placement: () => 'root',
  });
  assert.equal(find(hoisted, (node) => node.selector === '.plain-link'), null);
  assert.deepEqual(spine(hoisted), ['Page', 'Onecol', 'Hi']);
  const nested = compose(collapsedComponent(), linkAnatomy(), {
    root, targets: true, placement: () => 'element',
  });
  const shell = find(nested, (node) => node.selector === '.plain-link');
  assert.ok(shell, 'the own-element part row mounts');
  assert.equal(shell.parentKey, `block:${BLOCK}`);
});

test('anything outside the hiding grammar mounts from declarations alone', () => {
  // Only 'root' and `false` hide. A null channel (node tests, data-only
  // callers), a model that does not speak for the key (null/undefined),
  // or an answer outside the placement grammar all mount: the sketch
  // degrades to the declarations, never to an emptier tree than they
  // promise.
  for (const placement of [null, () => null, () => undefined, () => true]) {
    const view = { root, targets: true, ...(placement ? { placement } : {}) };
    const tree = compose(collapsedComponent(), linkAnatomy(), view);
    const shell = find(tree, (node) => node.selector === '.plain-link');
    assert.ok(shell, 'the declared part row mounts');
    assert.equal(shell.parentKey, `block:${BLOCK}`);
  }
});

test('a hidden shell hides only its own row: mounted children hoist', () => {
  // Placement hides wrapper rows, never model identities: the children a
  // hidden shell claimed (and its nested shells, gated on their own
  // placement) take the shell's position. 'root' and `false` hoist the
  // same way; only the reason differs.
  for (const answer of [false, 'root']) {
    const placement = (key, selector) => (selector === '.block__content' ? answer : 'element');
    const tree = compose(openBlueprint, anatomy, { root, targets: true, placement });
    assert.equal(find(tree, (node) => node.selector === '.block__content'), null);
    // The field the wrapper claimed survives at the wrapper's position; the
    // .field__item shell under it, placed as its own element, still mounts.
    assert.deepEqual(spine(tree), [
      'Page', 'Onecol', 'Test', 'Hieah', 'field__item', 'Secundum',
    ]);
  }
});

test('a plugin block resolves its anatomy by base plugin id', () => {
  // A non-inline placement carries plugin_id, no block_type; its shells
  // come from anatomy.blockPlugins keyed by the BASE id (the :derivative
  // suffix names an instance of the same template, so it strips).
  const pluginBlueprint = {
    bundle: 'page',
    fields: {},
    layout_builder__layout: [{
      uuid: SECTION,
      layout_id: 'daedalus_stylish_onecol',
      regions: {
        content: [{
          uuid: BLOCK,
          plugin_id: 'system_menu_block:main',
          label: 'Main navigation',
          label_display: '0',
        }],
      },
    }],
  };
  const pluginAnatomy = {
    ...anatomy,
    kinds: { ...anatomy.kinds, block: { elements: { '.block__inner': {} } } },
    blockPlugins: { system_menu_block: { elements: { '.menu': { label: 'Menu' } } } },
  };
  const tree = compose(pluginBlueprint, pluginAnatomy, { root, targets: true });
  const shell = find(tree, (node) => node.selector === '.menu');
  assert.ok(shell, 'the base plugin id resolved the blockPlugins tree');
  assert.equal(shell.parentKey, `block:${BLOCK}`);
  assert.equal(shell.label, 'Menu');
  // An unlisted plugin falls back to the universal block kind's tree.
  const fallback = compose(pluginBlueprint, { ...pluginAnatomy, blockPlugins: {} }, { root, targets: true });
  assert.ok(find(fallback, (node) => node.selector === '.block__inner'));
  assert.equal(find(fallback, (node) => node.selector === '.menu'), null);
});

test('a nested section composes as a section row with its region rows', () => {
  // A region entry carrying the section node shape IS a nested section —
  // sections all the way down; the markup view presents it exactly like a
  // top-level section, its own regions as its children, in every view.
  const nested = {
    bundle: 'page',
    layout_builder__layout: [{
      uuid: SECTION,
      layout_id: 'daedalus_stylish_onecol',
      regions: {
        content: [{
          uuid: BLOCK_2,
          layout_id: 'daedalus_stylish_twocol',
          label: 'Columns',
          regions: {
            first: [],
            second: [],
          },
        }],
      },
    }],
  };
  const tree = compose(nested, anatomy, { root, targets: true });
  const section = find(tree, (node) => node.key === `container:${BLOCK_2}`);
  assert.equal(section.kind, 'container');
  assert.equal(section.label, 'Columns');
  assert.deepEqual(section.children.map((node) => `${node.kind}:${node.label}`), ['region:First', 'region:Second']);
});

test('nonexistent fields (element: false) fold in every view', () => {
  const ghost = {
    bundle: 'page',
    layout_builder__layout: [{
      uuid: SECTION,
      layout_id: 'daedalus_stylish_onecol',
      regions: {
        content: [{
          uuid: BLOCK,
          block_type: 'test',
          label: 'Test',
          label_display: '0',
          block_fields: { field_hieah: 'Secundum', field_url: { uri: 'https://example.com' } },
          _element: { fields: { field_url: { element: false } } },
        }],
      },
    }],
  };
  // A nonexistent field never renders as an attributed element, so no
  // canvas element answers to its key — its row is mirror-only noise. The
  // fold holds in the data tree and with shells on.
  const data = compose(ghost, anatomy, { root });
  assert.deepEqual(spine(data), ['Page', 'Onecol', 'Test', 'Hieah']);
  const shells = compose(ghost, anatomy, { root, targets: true });
  assert.equal(find(shells, (node) => node.key === `block:${BLOCK}:field_url`), null);
  assert.notEqual(find(shells, (node) => node.key === `block:${BLOCK}:field_hieah`), null);
});

// ---- The root rule. -----------------------------------------------------------

const rootBlueprint = () => ({
  bundle: 'landing_page',
  fields: { title: 'Hello there', daedalus_stylish_styles: '{}', body: 'Copy' },
  _element: {
    presentation: { mode: 'open', edit_target: 'title' },
    fields: { daedalus_stylish_styles: { element: false } },
  },
});

test('the root consumes its own annotation: nonexistent fields fold, the edit target absorbs', () => {
  // Data tree: the title row folds into the root row (the projection's
  // rule verbatim), daedalus_stylish_styles never exists, the root shows the live
  // label and carries the edit target.
  const tree = compose(rootBlueprint(), anatomy, { root });
  assert.equal(tree.label, 'Hello there');
  assert.equal(tree.editTarget, 'title');
  assert.deepEqual(tree.children.map((node) => node.label), ['Body']);
  assert.equal(find(tree, (node) => node.key === `field:${ROOT}:daedalus_stylish_styles`), null);
});

test('the markup view keeps the root target\'s wrapper as an absorbed class row, value folded', () => {
  const tree = compose(rootBlueprint(), anatomy, { root, targets: true });
  assert.equal(tree.label, 'Hello there');
  // The title field's wrapper element is real markup: its row stays,
  // absorbed, presenting under its markup class — but its value row folded
  // into the root row's live label.
  const wrapper = find(tree, (node) => node.key === `field:${ROOT}:title`);
  assert.equal(wrapper.absorbed, true);
  assert.equal(wrapper.label, 'field--name-title');
  assert.equal(find(wrapper, (node) => node.type === 'value'), null);
  // The sibling field keeps its value row; the nonexistent field has none.
  const body = find(tree, (node) => node.key === `field:${ROOT}:body`);
  assert.equal(find(body, (node) => node.type === 'value').label, 'Copy');
  assert.equal(find(tree, (node) => node.key === `field:${ROOT}:daedalus_stylish_styles`), null);
});

test('a root without a derived edit target keeps its label-field row, never the nonexistent field', () => {
  const source = rootBlueprint();
  const tree = compose({ ...source, _element: { ...source._element, presentation: { mode: 'open' } } }, anatomy, { root });
  assert.equal(tree.label, 'Page');
  assert.equal(tree.editTarget, null);
  assert.deepEqual(tree.children.map((node) => node.label), ['Title', 'Body']);
  assert.equal(find(tree, (node) => node.key === `field:${ROOT}:daedalus_stylish_styles`), null);
});

// ---- Sections and layouts. ---------------------------------------------------

test('a layout shell\'s regions slot claims region rows — and a one-region section\'s components directly', () => {
  const layoutAnatomy = {
    layouts: {
      daedalus_stylish_twocol: {
        elements: { '.layout-container': { contains: 'regions' } },
      },
      daedalus_stylish_onecol: {
        elements: { '.layout-container': { contains: 'regions' } },
      },
    },
  };
  const twoCol = {
    bundle: 'page',
    layout_builder__layout: [{
      uuid: SECTION,
      layout_id: 'daedalus_stylish_twocol',
      regions: { first: [], second: [] },
    }],
  };
  const tree = compose(twoCol, layoutAnatomy, { root, targets: true });
  const container = find(tree, (node) => node.selector === '.layout-container');
  assert.equal(container.parentKey, `container:${SECTION}`);
  assert.deepEqual(container.children.map((node) => node.key), [null, null]);
  assert.deepEqual(container.children.map((node) => `${node.kind}:${node.label}`), ['region:First', 'region:Second']);
  // One region: the components occupy the region position, so the same
  // slot claims them into the container.
  const oneCol = compose(blueprint, { ...anatomy, ...layoutAnatomy }, { root, targets: true });
  assert.deepEqual(spine(oneCol), [
    'Page', 'Onecol', 'layout-container', 'block__content',
    'field--name-field-hieah', 'field__item', 'Secundum',
  ]);
});

test('a container section\'s children list mounts into the regions slot with no region rows', () => {
  // A container section carries `children: []` (ordered) in place of
  // `regions: {}` — the serializer's container shape. Its entries occupy
  // the region position, so a layout shell's generic `regions` bucket
  // still claims them into the container part, and nested containers
  // recurse as sections.
  const layoutAnatomy = {
    layouts: {
      daedalus_stylish_container: {
        elements: { '.daedalus-stylish-layout-container': { contains: 'regions' } },
      },
    },
  };
  const containerSection = {
    bundle: 'page',
    layout_builder__layout: [{
      uuid: SECTION,
      layout_id: 'daedalus_stylish_container',
      children: [
        {
          uuid: BLOCK,
          block_type: 'test',
          label: 'Test',
          label_display: '0',
          block_fields: {},
        },
        {
          uuid: BLOCK_2,
          layout_id: 'daedalus_stylish_container',
          label: 'Inner',
          children: [],
        },
      ],
    }],
  };
  const tree = compose(containerSection, layoutAnatomy, { root, targets: true });
  const container = find(tree, (node) => node.selector === '.daedalus-stylish-layout-container');
  assert.equal(container.parentKey, `container:${SECTION}`);
  assert.deepEqual(container.children.map((node) => node.key), [
    `block:${BLOCK}`,
    `container:${BLOCK_2}`,
  ]);
  assert.equal(find(tree, (node) => node.kind === 'region'), null);
  // The nested container composes as a section row in the data tree too.
  const data = compose(containerSection, {}, { root });
  const section = find(data, (node) => node.key === `container:${SECTION}`);
  assert.deepEqual(section.children.map((node) => `${node.kind}:${node.label}`), ['block:Test', 'container:Inner']);
});

// ---- The root's section list mounts through slots. --------------------------

// A two-band page with one root field: the entity kind's sketch is
// daedalus_layout's layout root claiming the section list, the shape the shipped
// declaration authors (`.daedalus-layout-layout: {label: Layout, tag: div,
// contains: sections}`).
const SECTION_2 = 'eeeeeeee-1111-2222-3333-444444444444';
const bandedBlueprint = {
  bundle: 'page',
  fields: { body: 'Copy' },
  layout_builder__layout: [
    { uuid: SECTION, layout_id: 'daedalus_stylish_onecol', regions: { content: [] } },
    { uuid: SECTION_2, layout_id: 'daedalus_stylish_onecol', regions: { content: [] } },
  ],
};
const layoutRootAnatomy = {
  kinds: {
    entity: {
      elements: {
        '.daedalus-layout-layout': { label: 'Layout', tag: 'div', contains: 'sections' },
      },
    },
  },
};
const rowNames = (nodes) => nodes.map((node) => node.type === 'shell' ? node.selector : `${node.kind}:${node.label}`);

test('an entity shell claiming sections holds the section rows with shells on', () => {
  const tree = compose(bandedBlueprint, layoutRootAnatomy, { root, targets: true });
  // The Layout shell first (authored order), then the unclaimed field at
  // the root; no section row sits beside them.
  assert.deepEqual(rowNames(tree.children), ['.daedalus-layout-layout', 'field:Body']);
  const layout = tree.children[0];
  assert.equal(layout.label, 'Layout');
  assert.equal(layout.declaredTag, 'div');
  assert.equal(layout.parentKey, `entity:${ROOT}`);
  // The claim rides the row: the slot kinds the part claims, for whoever
  // reads the row (the drop resolver makes this row the root flow's drop
  // surface).
  assert.deepEqual(layout.claims, ['section']);
  // The section rows keep their identities and their blueprint order
  // wherever they mount: a claim relocates rows, never re-keys them.
  assert.deepEqual(layout.children.map((node) => node.key), [`container:${SECTION}`, `container:${SECTION_2}`]);
  assert.deepEqual(layout.children.map((node) => node.kind), ['container', 'container']);
});

test('shells off, the sections are root children after the fields, in order', () => {
  const tree = compose(bandedBlueprint, layoutRootAnatomy, { root });
  // The data tree ignores the claim: no shell row, fields first, then the
  // sections in blueprint order (the plain projection's tree).
  assert.deepEqual(rowNames(tree.children), ['field:Body', 'container:Onecol', 'container:Onecol']);
  assert.deepEqual(tree.children.slice(1).map((node) => node.key), [`container:${SECTION}`, `container:${SECTION_2}`]);
  assert.equal(find(tree, (node) => node.type === 'shell'), null);
});

test('no sections claim: the sections mount at the root after the shells', () => {
  // An entity sketch without daedalus_layout (a part claiming fields only): the
  // sections are the residual-of-residual and mount at the entity root,
  // after the shells, still in blueprint order.
  const fieldsOnlyAnatomy = {
    kinds: {
      entity: { elements: { '.node__content': { label: 'Content', contains: 'fields' } } },
    },
  };
  const tree = compose(bandedBlueprint, fieldsOnlyAnatomy, { root, targets: true });
  assert.deepEqual(rowNames(tree.children), ['.node__content', 'container:Onecol', 'container:Onecol']);
  assert.deepEqual(rowNames(tree.children[0].children), ['field:Body']);
  assert.deepEqual(tree.children.slice(1).map((node) => node.key), [`container:${SECTION}`, `container:${SECTION_2}`]);
  // A `fields` bucket never takes a section: the kinds are distinct.
  assert.equal(find(tree.children[0], (node) => node.kind === 'container'), null);
});

test('compose stamps claims on claiming shells only; toViewTree carries them', () => {
  // A layout root claiming sections wrapping a slot-less inner part, beside
  // a content shell claiming fields by bucket and one field by name.
  const claimingAnatomy = {
    kinds: {
      entity: {
        elements: {
          '.daedalus-layout-layout': {
            label: 'Layout',
            contains: 'sections',
            elements: { '.daedalus-layout-layout__inner': { label: 'Inner' } },
          },
          '.node__content': { label: 'Content', contains: ['field:body', 'fields'] },
        },
      },
    },
  };
  const tree = compose(bandedBlueprint, claimingAnatomy, { root, targets: true });
  const [layout, content] = tree.children;
  assert.deepEqual(layout.claims, ['section']);
  assert.equal(Object.hasOwn(layout.children[0], 'claims'), false, 'a slot-less part carries no claims key');
  assert.deepEqual(content.claims, ['field'], 'distinct kinds: a specific claim and its bucket are one kind');
  const view = toViewTree(tree);
  assert.deepEqual(view.children[0].claims, ['section']);
  assert.equal(view.children[0].pseudo, true);
  assert.equal(Object.hasOwn(view.children[0].children[0], 'claims'), false);
  assert.deepEqual(view.children[1].claims, ['field']);
});

// ---- Odds and ends. ------------------------------------------------------------

test('unit field presentation rides the annotation', () => {
  const unit = {
    bundle: 'page',
    fields: { field_gallery: 'x' },
    _element: { fields: { field_gallery: { presentation: 'unit' } } },
  };
  assert.equal(compose(unit, anatomy, { root }).children[0].presentation, 'unit');
});

test('empty anatomy payloads (PHP [] artifacts) are tolerated', () => {
  const tree = compose(blueprint, { kinds: [], blockTypes: [], layouts: [] }, { root, targets: true });
  // No declarations: no shells, but the markup identities are still honest —
  // the field wrapper element exists in core markup regardless.
  assert.deepEqual(spine(tree), ['Page', 'Onecol', 'field--name-field-hieah', 'Secundum']);
  assert.deepEqual(spine(compose(blueprint, null, { root })), ['Page', 'Onecol', 'Secundum']);
});

test('fieldClass hyphenates the way core stamps it', () => {
  assert.equal(fieldClass('field_hieah'), 'field--name-field-hieah');
  assert.equal(fieldClass('field_my_long_name'), 'field--name-field-my-long-name');
});

// ---- The seam adapter (markup-view.js toViewTree). ---------------------------

test('toViewTree maps the composed vocabulary onto the tree view seam', () => {
  const view = toViewTree(compose(openBlueprint, anatomy, { root, targets: true }));
  // Model rows pass through: same keys, no pseudo flag, no compose type.
  assert.equal(view.key, `entity:${ROOT}`);
  assert.equal(view.type, undefined);
  assert.equal(view.pseudo, undefined);
  const block = find(view, (node) => node.key === `block:${BLOCK}`);
  assert.equal(block.kind, 'block');
  assert.equal(block.presentation, 'open');
  // Shell rows become pseudo rows: id is the selector, parentKey the owner
  // compose stamped.
  const content = find(view, (node) => node.id === '.block__content');
  assert.equal(content.pseudo, true);
  assert.equal(content.parentKey, `block:${BLOCK}`);
  assert.equal(content.label, 'block__content');
  // A non-absorbed field row surfaces its markup identity as the seam's
  // sublabel (the row reads "Hieah · field--name-field-hieah").
  const field = find(view, (node) => node.key === `block:${BLOCK}:field_hieah`);
  assert.equal(field.markupClass, 'field--name-field-hieah');
  assert.equal(field.sublabel, 'field--name-field-hieah');
  const item = find(view, (node) => node.id === '.field__item');
  assert.equal(item.parentKey, `block:${BLOCK}:field_hieah`);
  // Value rows become keyless model rows — display-only, never selectable.
  const value = find(view, (node) => node.label === 'Secundum');
  assert.equal(value.key, null);
  assert.equal(value.kind, 'value');
  assert.deepEqual(value.children, []);
  // No composed vocabulary leaks into the seam.
  const walk = (node) => {
    assert.equal(node.type, undefined);
    assert.equal(node.selector, undefined);
    (node.children ?? []).forEach(walk);
  };
  walk(view);
});

test('toViewTree keeps the merged spine intact (targets ON, merged)', () => {
  const view = toViewTree(compose(blueprint, anatomy, { root, targets: true }));
  const labels = [];
  let node = view;
  while (node) {
    labels.push(node.pseudo ? node.id : node.label);
    assert.ok((node.children?.length ?? 0) <= 1, `spine forked under "${labels.at(-1)}"`);
    node = node.children?.[0];
  }
  assert.deepEqual(labels, [
    'Page', 'Onecol', '.block__content', 'field--name-field-hieah', '.field__item', 'Secundum',
  ]);
  const merged = find(view, (candidate) => candidate.label === 'Secundum');
  assert.equal(merged.key, `block:${BLOCK}`);
  assert.equal(merged.editTarget, 'field_hieah');
  // An absorbed row already presents AS its markup class — a sublabel
  // would just repeat the label.
  const absorbed = find(view, (candidate) => candidate.label === 'field--name-field-hieah');
  assert.equal(absorbed.absorbed, true);
  assert.equal(absorbed.sublabel, undefined);
});

test('toViewTree appends the instance rows to their owning model row', () => {
  // The instance source (instance-rows.js) reads ownership from the
  // annotation: here the composed text block's merged row owns two
  // carriers, the second with a minted paragraph nested in a blockquote.
  // Every other key owns nothing, the source is a function of the key,
  // so exactly one model row claims each carrier.
  const instances = (key) => (key === `block:${BLOCK}` ? [
    { selector: '.s-a1', label: 'Paragraph', sublabel: 'First words', children: [] },
    {
      selector: '.s-b2',
      label: 'Block quote',
      sublabel: 'Quoted words',
      children: [{ selector: '.s-c3', label: 'Paragraph', sublabel: 'Quoted words', children: [] }],
    },
  ] : []);
  const view = toViewTree(compose(blueprint, anatomy, { root, targets: true }), instances);
  const merged = find(view, (node) => node.key === `block:${BLOCK}`);
  const live = merged.children.filter((node) => node.pseudo);
  // Instance rows are seam pseudo rows: id is the identity-class selector,
  // parentKey the owning model key, label the semantic tag, sublabel the
  // excerpt — declared shell rows' shape exactly, with the annotation as
  // the source instead of anatomy.
  assert.deepEqual(live.map((node) => node.id), ['.s-a1', '.s-b2']);
  assert.equal(live[0].pseudo, true);
  assert.equal(live[0].parentKey, `block:${BLOCK}`);
  assert.equal(live[0].label, 'Paragraph');
  assert.equal(live[0].sublabel, 'First words');
  // Nested carriers nest as row children, same owner.
  assert.deepEqual(live[1].children.map((node) => node.id), ['.s-c3']);
  assert.equal(live[1].children[0].parentKey, `block:${BLOCK}`);
  // Composed children keep their position: live rows append after them.
  assert.equal(merged.children.at(-1), live[1]);
  // No other row grew instance rows.
  const strays = [];
  const walk = (node) => {
    if (node.pseudo && node.id?.startsWith('.s-') && node.parentKey !== `block:${BLOCK}`) {
      strays.push(node.id);
    }
    (node.children ?? []).forEach(walk);
  };
  walk(view);
  assert.deepEqual(strays, []);
});

// ---- Tag chips (the seam's `tag` stamp). -------------------------------------

test('model rows wear the resolved tag from the annotation accessor, or none', () => {
  const asked = [];
  const tags = {
    root: (key) => {
      asked.push(key);
      if (key === `entity:${ROOT}`) {
        return 'article';
      }
      return key === `block:${BLOCK}` ? 'img' : null;
    },
    part: () => null,
  };
  const view = toViewTree(compose(openBlueprint, anatomy, { root, targets: true }), null, tags);
  assert.equal(view.tag, 'article');
  assert.equal(find(view, (node) => node.key === `block:${BLOCK}`).tag, 'img');
  // A key the model does not speak for wears no chip: model rows have no
  // declared degrade path (an invented tag would be a guess, not markup).
  assert.equal(find(view, (node) => node.key === `block:${BLOCK}:field_hieah`).tag, undefined);
  // Keyless rows (value rows, region rows) never ask the accessor.
  assert.ok(!asked.includes(null));
  // No accessors at all (node tests, data-only callers): no model chips.
  const bare = toViewTree(compose(openBlueprint, anatomy, { root, targets: true }));
  assert.equal(bare.tag, undefined);
});

test('shell rows resolve their tag first and degrade to the declared tag', () => {
  const taggedAnatomy = {
    ...anatomy,
    blockTypes: {
      test: { elements: { '.block__content': { contains: 'fields', tag: 'div' } } },
    },
  };
  const composed = compose(openBlueprint, taggedAnatomy, { root, targets: true });
  // compose carries the declaration's authored tag on the shell node.
  assert.equal(find(composed, (node) => node.selector === '.block__content').declaredTag, 'div');
  assert.equal(find(composed, (node) => node.selector === '.field__item').declaredTag, undefined);
  // Resolved wins over declared: the part accessor is asked with the same
  // owner/selector pair the shell row carries.
  const resolved = toViewTree(composed, null, {
    root: () => null,
    part: (key, selector) => (key === `block:${BLOCK}` && selector === '.block__content' ? 'a' : null),
  });
  assert.equal(find(resolved, (node) => node.id === '.block__content').tag, 'a');
  // The model not speaking (a null accessor answer, or no accessors at
  // all) degrades to the declared tag, never to a wrong chip.
  assert.equal(find(toViewTree(composed), (node) => node.id === '.block__content').tag, 'div');
  // Neither resolved nor declared: no chip, and the composed vocabulary
  // (`declaredTag`) never leaks into the seam.
  const item = find(toViewTree(composed), (node) => node.id === '.field__item');
  assert.equal(item.tag, undefined);
  assert.equal(item.declaredTag, undefined);
  const content = find(resolved, (node) => node.id === '.block__content');
  assert.equal(content.declaredTag, undefined);
});

test('shell rows resolve the matched-form label and degrade to the composed label', () => {
  const labeledAnatomy = {
    ...anatomy,
    blockTypes: {
      test: { elements: { '.block__content': { contains: 'fields', label: 'Content' } } },
    },
  };
  const composed = compose(openBlueprint, labeledAnatomy, { root, targets: true });
  // compose carries the declared part-level label on the shell node.
  assert.equal(find(composed, (node) => node.selector === '.block__content').label, 'Content');
  // Resolved wins over composed: the label accessor (markup-index.js
  // partLabel, the matched form's own name) is asked with the same
  // owner/selector pair the shell row carries.
  const resolved = toViewTree(composed, null, {
    root: () => null,
    part: () => null,
    label: (key, selector) => (key === `block:${BLOCK}` && selector === '.block__content' ? 'Vector' : null),
  });
  assert.equal(find(resolved, (node) => node.id === '.block__content').label, 'Vector');
  // The model not speaking (a null accessor answer, or no accessors at
  // all) degrades to the composed label: the part-level label, or the
  // dotless selector.
  assert.equal(find(toViewTree(composed), (node) => node.id === '.block__content').label, 'Content');
  assert.equal(find(resolved, (node) => node.id === '.field__item').label, 'field__item');
});

test('a multi-form part composes no declaredTag: only the annotation knows the form', () => {
  // A part declaring ordered `forms` in place of `tag` has no one authored
  // tag to degrade to, so compose carries no declaredTag and the chip
  // exists exactly when the model speaks. Its composed label is the
  // part-level label as usual; the matched-form label rides the
  // annotation and resolves at the seam.
  const formsAnatomy = {
    ...anatomy,
    blockTypes: {
      test: {
        elements: {
          '.block__content': {
            contains: 'fields',
            label: 'Image',
            forms: [
              { tag: 'img', present: { media: 'raster' }, label: 'Raster' },
              { tag: 'svg', present: { media: 'vector' }, label: 'Vector' },
            ],
          },
        },
      },
    },
  };
  const composed = compose(openBlueprint, formsAnatomy, { root, targets: true });
  const shell = find(composed, (node) => node.selector === '.block__content');
  assert.equal(shell.declaredTag, undefined);
  assert.equal(shell.label, 'Image');
  // Without the model, the seam mounts the row with no chip and the
  // part-level label: declarations promise the part, not its form.
  const bare = find(toViewTree(composed), (node) => node.id === '.block__content');
  assert.equal(bare.tag, undefined);
  assert.equal(bare.label, 'Image');
  // With the model speaking, the row wears the matched form: resolved tag
  // and the form's own name.
  const resolved = toViewTree(composed, null, {
    root: () => null,
    part: (key, selector) => (selector === '.block__content' ? 'svg' : null),
    label: (key, selector) => (selector === '.block__content' ? 'Vector' : null),
  });
  const row = find(resolved, (node) => node.id === '.block__content');
  assert.equal(row.tag, 'svg');
  assert.equal(row.label, 'Vector');
});

test('instance rows carry the annotation-resolved tag onto the seam row', () => {
  // The annotator resolved the carrier's tag server-side, so the source
  // row ships it and the seam stamps it as-is: no accessor is asked.
  const instances = (key) => (key === `block:${BLOCK}` ? [
    {
      selector: '.s-a1',
      label: 'Block quote',
      tag: 'blockquote',
      children: [{ selector: '.s-b2', label: 'Paragraph', tag: 'p', children: [] }],
    },
  ] : []);
  const view = toViewTree(compose(blueprint, anatomy, { root, targets: true }), instances);
  const live = find(view, (node) => node.id === '.s-a1');
  assert.equal(live.tag, 'blockquote');
  assert.equal(live.children[0].tag, 'p');
});

test('toViewTree without a live source composes declared anatomy only', () => {
  const view = toViewTree(compose(blueprint, anatomy, { root, targets: true }));
  const walk = (node) => {
    assert.ok(!(node.pseudo && node.id?.startsWith('.s-')), `unexpected live row ${node.id}`);
    (node.children ?? []).forEach(walk);
  };
  walk(view);
});

// ---- The model projection's instance rows (withInstanceRows). ----------------

test('withInstanceRows appends chip-less instance rows to the model projection', () => {
  // Instance rows are content, so the DEFAULT projection (the markup view
  // off — daedalus_stylish's basic mode) carries them too: every keyed model row
  // asks the same source the markup view asks.
  const instances = (key) => (key === `block:${BLOCK}` ? [
    { selector: '.s-a1', label: 'Paragraph', tag: 'p', sublabel: 'First words', children: [] },
    {
      selector: '.s-b2',
      label: 'Block quote',
      tag: 'blockquote',
      children: [{ selector: '.s-c3', label: 'Paragraph', tag: 'p', sublabel: 'Quoted words', children: [] }],
    },
  ] : []);
  const model = {
    key: `entity:${ROOT}`,
    kind: 'entity',
    label: 'Page',
    presentation: 'open',
    children: [{
      key: null,
      kind: 'region',
      label: 'Content',
      presentation: 'open',
      children: [{
        key: `block:${BLOCK}`,
        kind: 'block',
        label: 'Secundum',
        presentation: 'merged',
        children: [],
      }],
    }],
  };
  const tree = withInstanceRows(model, instances);
  // Model rows pass through: same keys, same shape, no pseudo flag.
  assert.equal(tree.key, `entity:${ROOT}`);
  assert.equal(tree.pseudo, undefined);
  const block = find(tree, (node) => node.key === `block:${BLOCK}`);
  const live = block.children.filter((node) => node.pseudo);
  assert.deepEqual(live.map((node) => node.id), ['.s-a1', '.s-b2']);
  assert.equal(live[0].parentKey, `block:${BLOCK}`);
  assert.equal(live[0].label, 'Paragraph');
  assert.equal(live[0].sublabel, 'First words');
  // Nested carriers nest as row children, same owner.
  assert.deepEqual(live[1].children.map((node) => node.id), ['.s-c3']);
  assert.equal(live[1].children[0].parentKey, `block:${BLOCK}`);
  // Content, never markup vocabulary: NO tag chips outside the markup
  // view, at any depth.
  assert.equal(live[0].tag, undefined);
  assert.equal(live[1].tag, undefined);
  assert.equal(live[1].children[0].tag, undefined);
  // A keyless row (a region) owns nothing, and the input tree is not
  // mutated — the appender returns a copy, projection.js output stays
  // canonical for the projection index.
  const region = find(tree, (node) => node.kind === 'region');
  assert.equal(region.children.filter((node) => node.pseudo).length, 0);
  assert.equal(model.children[0].children[0].children.length, 0);
});

// ---- The outline tree at depth (universal minting: lists). -------------------

test('list carriers nest at depth with flat ownership in both projections', () => {
  // Universal minting makes every outline row a carrier row: a list is
  // ul > li > p (List > List item > Paragraph, the actual hierarchy shown
  // truthfully), and a nested list adds another ul > li below an item.
  // The seam recursion is depth-blind, so the five-level chain must nest
  // as-is in BOTH projections, and ownership must stay FLAT at every
  // depth: parentKey is always the owning model key, never the parent
  // row's id, because canvas deep-select resolves any carrier to
  // (owner key, selector) and the spine highlights by that same
  // {selectedKey, selectedPart} pair regardless of depth.
  const deepList = (key) => (key === `block:${BLOCK}` ? [
    {
      selector: '.s-l1',
      label: 'List',
      tag: 'ul',
      children: [
        {
          selector: '.s-i1',
          label: 'List item',
          tag: 'li',
          sublabel: 'First item',
          children: [
            { selector: '.s-p1', label: 'Paragraph', tag: 'p', sublabel: 'First item', children: [] },
            {
              selector: '.s-l2',
              label: 'List',
              tag: 'ul',
              children: [
                {
                  selector: '.s-i2',
                  label: 'List item',
                  tag: 'li',
                  sublabel: 'Nested item',
                  children: [
                    { selector: '.s-p2', label: 'Paragraph', tag: 'p', sublabel: 'Nested item', children: [] },
                  ],
                },
              ],
            },
          ],
        },
      ],
    },
  ] : []);
  const markup = toViewTree(compose(blueprint, anatomy, { root, targets: true }), deepList);
  const model = withInstanceRows(
    { key: `block:${BLOCK}`, kind: 'block', label: 'Secundum', presentation: 'merged', children: [] },
    deepList,
  );
  for (const tree of [markup, model]) {
    // The chain nests: List > List item > (Paragraph, List > List item >
    // Paragraph). An expanded item shows two rows (List item, Paragraph):
    // the actual hierarchy, shown truthfully.
    const list = find(tree, (node) => node.pseudo && node.id === '.s-l1');
    assert.deepEqual(list.children.map((node) => node.id), ['.s-i1']);
    const item = list.children[0];
    assert.deepEqual(item.children.map((node) => node.id), ['.s-p1', '.s-l2']);
    const nestedItem = item.children[1].children[0];
    assert.equal(nestedItem.id, '.s-i2');
    assert.deepEqual(nestedItem.children.map((node) => node.id), ['.s-p2']);
    // Labels ride the annotation (the declared tags map) at every depth.
    assert.equal(item.label, 'List item');
    assert.equal(nestedItem.label, 'List item');
    assert.equal(nestedItem.children[0].label, 'Paragraph');
    // Flat ownership at every depth: never the parent row's id. (Shell
    // rows are excluded: declared anatomy owns per its own composition.)
    const walk = (node) => {
      if (node.pseudo && node.id?.startsWith('.s-')) {
        assert.equal(node.parentKey, `block:${BLOCK}`);
      }
      (node.children ?? []).forEach(walk);
    };
    walk(tree);
  }
  // Selection sync at depth: the deepest row carries the identical
  // (parentKey, id) pair in both projections, so a Cmd+click on a
  // paragraph inside a nested list highlights the same row in basic
  // and advanced.
  const inMarkup = find(markup, (node) => node.pseudo && node.id === '.s-p2');
  const inModel = find(model, (node) => node.pseudo && node.id === '.s-p2');
  assert.equal(inModel.id, inMarkup.id);
  assert.equal(inModel.parentKey, inMarkup.parentKey);
  // Chips stay advanced-only at depth: the markup view wears them on
  // every level, the model projection on none.
  assert.equal(inMarkup.tag, 'p');
  assert.equal(find(markup, (node) => node.pseudo && node.id === '.s-i2').tag, 'li');
  assert.equal(find(markup, (node) => node.pseudo && node.id === '.s-l2').tag, 'ul');
  assert.equal(inModel.tag, undefined);
  assert.equal(find(model, (node) => node.pseudo && node.id === '.s-i2').tag, undefined);
  assert.equal(find(model, (node) => node.pseudo && node.id === '.s-l2').tag, undefined);
});

test('instance rows keep one identity across both projections (selection sync)', () => {
  // The spine highlights a part row by {selectedKey, selectedPart}
  // equality, so canvas part selection (Cmd+click resolving a carrier)
  // lands on the same row in basic and advanced exactly because the two
  // projections stamp the identical (parentKey, id) pair; only the chip
  // differs.
  const instances = (key) => (key === `block:${BLOCK}` ? [
    { selector: '.s-a1', label: 'Paragraph', tag: 'p', children: [] },
  ] : []);
  const markup = toViewTree(compose(blueprint, anatomy, { root, targets: true }), instances);
  const model = withInstanceRows(
    { key: `block:${BLOCK}`, kind: 'block', label: 'Secundum', presentation: 'merged', children: [] },
    instances,
  );
  const inMarkup = find(markup, (node) => node.pseudo && node.id === '.s-a1');
  const inModel = find(model, (node) => node.pseudo && node.id === '.s-a1');
  assert.equal(inModel.id, inMarkup.id);
  assert.equal(inModel.parentKey, inMarkup.parentKey);
  assert.equal(inModel.label, inMarkup.label);
  // The markup view alone wears the chip.
  assert.equal(inMarkup.tag, 'p');
  assert.equal(inModel.tag, undefined);
});
