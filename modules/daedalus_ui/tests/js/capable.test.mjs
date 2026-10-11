/**
 * Self-asserting tests for the capability gate's pure model
 * (js/edit_mode/selection/capable-model.js, re-exported by capable.js
 * beside the live index read): the spine's verdict on a projection
 * address (an element's own, a part's through its element), the closed
 * default for an address with no verdict, and the lean-tree read the
 * stylish satellite uses.
 *
 * Run with `node --test tests/js/capable.test.mjs` from the module root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import {
  CAPABILITIES,
  isCapability,
  capableIn,
  capableInTree,
  verdictIn,
  verdictInTree,
} from '../../js/edit_mode/selection/capable-model.js';

const SECTION = 'aaaaaaaa-1111-2222-3333-444444444444';
const BLOCK = 'bbbbbbbb-1111-2222-3333-444444444444';
const refused = { content: false, configure: false, style: false, arrange: false, insert: false };
const open = { content: true, configure: true, style: true, arrange: true, insert: true };
const contentOnly = { ...refused, content: true };

test('the vocabulary is the spine\'s five, in its order', () => {
  assert.deepEqual(CAPABILITIES, ['content', 'configure', 'style', 'arrange', 'insert']);
  assert.equal(isCapability('arrange'), true);
  assert.equal(isCapability('move'), false);
  assert.equal(isCapability(null), false);
});

test('capableIn reads the entry\'s verdict, a part address through its element, and refuses otherwise', () => {
  const index = new Map([
    [`container:${SECTION}`, { can: refused }],
    [`block:${BLOCK}`, { can: contentOnly }],
    ['block:unstamped', {}],
  ]);
  assert.equal(capableIn(index, `container:${SECTION}`, 'arrange'), false);
  assert.equal(capableIn(index, `block:${BLOCK}`, 'content'), true);
  assert.equal(capableIn(index, `block:${BLOCK}`, 'style'), false);
  // A part answers its owning element's verdict.
  assert.equal(capableIn(index, `block:${BLOCK}::> pb-text`, 'content'), true);
  assert.equal(capableIn(index, `block:${BLOCK}::> pb-text`, 'arrange'), false);
  // No verdict, no capability: an entry the annotator never stamped, an
  // address the index does not place, no key, no index.
  assert.equal(capableIn(index, 'block:unstamped', 'content'), false);
  assert.equal(capableIn(index, 'block:unknown', 'content'), false);
  assert.equal(capableIn(index, 'block:unknown::> a', 'content'), false);
  assert.equal(capableIn(index, null, 'content'), false);
  assert.equal(capableIn(null, `block:${BLOCK}`, 'content'), false);
  // Only a literal true opens the gate.
  assert.equal(capableIn(new Map([['block:x', { can: { content: 'yes' } }]]), 'block:x', 'content'), false);
});

test('a row-less field key answers its subject\'s verdict: the placement for a block field, the root for a root field', () => {
  const ROOT = 'cccccccc-1111-2222-3333-444444444444';
  const index = new Map([
    [`entity:${ROOT}`, { can: contentOnly }],
    [`block:${BLOCK}`, { can: contentOnly }],
    [`container:${SECTION}`, { can: refused }],
  ]);
  // The one field of a unit block (the media drop's target) has no row;
  // the op door judges a write to it as content of the block.
  assert.deepEqual(verdictIn(index, `block:${BLOCK}:field_photo`), contentOnly);
  assert.equal(capableIn(index, `block:${BLOCK}:field_photo`, 'content'), true);
  assert.equal(capableIn(index, `block:${BLOCK}:field_photo`, 'arrange'), false);
  assert.equal(capableIn(index, `block:${BLOCK}:field_photo::> img`, 'content'), true);
  // A folded root field the theme prints answers the root's.
  assert.deepEqual(verdictIn(index, `field:${ROOT}:body`), contentOnly);
  // A field of a subject the index does not place stays refused, as does
  // a field key of a kind that has no subject.
  assert.equal(verdictIn(index, 'block:unknown:field_photo'), null);
  assert.equal(verdictIn(index, `field:${BLOCK}:field_photo`), null);
  assert.equal(verdictIn(index, `container:${SECTION}:label`), null);
  // A placed field row keeps its own entry over the inheritance.
  index.set(`block:${BLOCK}:field_photo`, { can: refused });
  assert.deepEqual(verdictIn(index, `block:${BLOCK}:field_photo`), refused);
});

test('verdictIn answers the whole verdict, or null', () => {
  const index = new Map([[`block:${BLOCK}`, { can: open }]]);
  assert.deepEqual(verdictIn(index, `block:${BLOCK}`), open);
  assert.deepEqual(verdictIn(index, `block:${BLOCK}::&::before`), open);
  assert.equal(verdictIn(index, 'block:unknown'), null);
  assert.equal(verdictIn(null, `block:${BLOCK}`), null);
});

test('the lean-tree read finds the node by uuid at any depth and reads its _element.can', () => {
  const tree = [{
    kind: 'container',
    uuid: SECTION,
    _element: { name: 'Section', type: 'Section', can: refused },
    children: [{
      kind: 'region',
      region: 'content',
      children: [{ kind: 'block', uuid: BLOCK, _element: { name: 'Text', type: 'Text', can: contentOnly } }],
    }],
  }];
  assert.deepEqual(verdictInTree(tree, SECTION), refused);
  assert.deepEqual(verdictInTree(tree, BLOCK), contentOnly);
  assert.equal(verdictInTree(tree, 'missing'), null);
  assert.equal(verdictInTree(tree, null), null);
  assert.equal(verdictInTree(null, BLOCK), null);
  assert.equal(capableInTree(tree, BLOCK, 'content'), true);
  assert.equal(capableInTree(tree, BLOCK, 'style'), false);
  assert.equal(capableInTree(tree, SECTION, 'content'), false);
  assert.equal(capableInTree(tree, 'missing', 'content'), false);
  // A node the annotator did not stamp answers null, which reads refused.
  assert.equal(verdictInTree([{ kind: 'container', uuid: SECTION, _element: { name: 'S', type: 'S' } }], SECTION), null);
});
