/**
 * Self-asserting tests for the document tree (blueprint/document-tree.js).
 *
 * The derivation turns a delivered blueprint into the matcher's view of the
 * page: one `entity` node above the layout, keyed by the host's uuid (which
 * rides the envelope, not the blueprint) and carrying the root's `_element`
 * annotation as delivered, with the `layout` array itself as its children.
 * These cases lock the shape its PHP twin (NodeTreeBuilder::document(),
 * asserted key for key in NodeTreeBuilderTest::testDocument) answers too:
 * - a root with a uuid: `[{kind: 'entity', uuid, _element, children}]` in
 *   that key order, the children the very layout array;
 * - a keyless root: a host with no uuid (a template host, the site frame)
 *   still gets the node, `uuid` null, so the sections keep their ancestry;
 * - empty: a null blueprint (the mirror before boot) answers `[]`; a
 *   blueprint with no `layout` (a document with no sections) and no
 *   annotation answers a root with empty children and a null `_element`;
 * - the layout read back: layoutOf() answers the very `layout` array the
 *   document was derived from, `[]` for an empty document or a list whose
 *   root is not the entity node.
 *
 * Run with `node --test tests/js/document-tree.test.mjs` from the module
 * root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { documentTree, layoutOf } from '../../js/edit_mode/blueprint/document-tree.js';

const ROOT = '99999999-9999-4999-8999-999999999999';
const SECTION = 'aaaaaaaa-1111-2222-3333-444444444444';
const BLOCK = 'cccccccc-1111-2222-3333-444444444444';

const rootElement = {
  name: 'Welcome',
  type: 'Page',
  icon: '/icons/page.svg',
  presentation: { mode: 'open' },
  markup: {},
  fields: { title: { markup: { tags: ['h1'], instances: [] } } },
  can: { style: true },
};

const layout = [
  {
    kind: 'container',
    uuid: SECTION,
    layout_id: 'daedalus_stylish_container',
    _element: { name: 'Section', type: 'Section', icon: null, markup: { tag: 'd-container' } },
    children: [
      {
        kind: 'block',
        uuid: BLOCK,
        block_type: 'text',
        block_fields: {},
        _element: { name: 'Text', type: 'Text', icon: null, markup: { tag: 'div' } },
      },
    ],
  },
];

const blueprint = {
  entity_type: 'node',
  bundle: 'page',
  fields: { title: { value: 'Welcome' } },
  _element: rootElement,
  layout,
};

test('a root with a uuid: the entity node above the layout, as delivered', () => {
  const document = documentTree(blueprint, ROOT);

  assert.equal(document.length, 1);
  const [root] = document;
  assert.deepEqual(Object.keys(root), ['kind', 'uuid', '_element', 'children']);
  assert.equal(root.kind, 'entity');
  assert.equal(root.uuid, ROOT);
  // The annotation and the layout ride through untouched: the same
  // references the mirror holds, nothing copied, nothing folded.
  assert.equal(root._element, rootElement);
  assert.equal(root.children, layout);
  assert.equal(root.children[0].children[0].uuid, BLOCK);
  // The derivation reads the blueprint; it never writes an entity node into
  // the serializer's layout.
  assert.deepEqual(
    blueprint.layout.map((node) => node.kind),
    ['container'],
  );
});

test('a keyless root: no uuid still gets the node, so the sections keep their ancestry', () => {
  for (const missing of [null, undefined, '']) {
    const document = documentTree(blueprint, missing);
    assert.equal(document.length, 1);
    assert.deepEqual(Object.keys(document[0]), ['kind', 'uuid', '_element', 'children']);
    assert.equal(document[0].kind, 'entity');
    assert.equal(document[0].uuid, null);
    assert.equal(document[0]._element, rootElement);
    assert.equal(document[0].children, layout);
  }
  assert.equal(documentTree(blueprint).uuid, undefined);
  assert.equal(documentTree(blueprint)[0].uuid, null);
});

test('empty: a null blueprint answers [], a sectionless unannotated one a bare root', () => {
  assert.deepEqual(documentTree(null, ROOT), []);
  assert.deepEqual(documentTree(undefined, ROOT), []);

  assert.deepEqual(documentTree({ entity_type: 'node', bundle: 'page' }, ROOT), [
    { kind: 'entity', uuid: ROOT, _element: null, children: [] },
  ]);
  // A `layout` that is not a list (nothing the serializer emits, but the
  // matcher must never be handed a non-array child axis) reads as empty.
  assert.deepEqual(documentTree({ layout: null }, null)[0].children, []);
});

test('layoutOf reads the layout back out: the very array, [] for an empty document or a rootless list', () => {
  assert.equal(layoutOf(documentTree(blueprint, ROOT)), layout);
  assert.equal(layoutOf(documentTree(blueprint, null)), layout);
  assert.deepEqual(layoutOf(documentTree(null, ROOT)), []);
  assert.deepEqual(layoutOf(null), []);
  // The sections themselves are not a document: no entity root, no layout
  // to read (a depth read over it degrades to "root", never invents one).
  assert.deepEqual(layoutOf(layout), []);
});
