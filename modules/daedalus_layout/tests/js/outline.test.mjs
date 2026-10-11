/**
 * Self-asserting tests for the Add panel's fold outline
 * (js/src/model/outline.js): the sets in the collapse model's shape,
 * Favorites open by default and every other set folded, All's kinds
 * included, every heading foldable; and the outline driving
 * daedalus_ui's collapse model as it stands.
 *
 * Run with `node --test "modules/daedalus_layout/tests/js/*.test.mjs"`
 * from the daedalus root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';

const { CONTAINER_ID, KIND_BASIC, faceList } = await import('../../js/src/model/faces.js');
const { ALL_ID, FAVORITES_ID, buildSets, groupSetId, kindSetId } = await import('../../js/src/model/sets.js');
const { outlineOf } = await import('../../js/src/model/outline.js');
const { collapseAll, expandAll, foldableIds, isCollapsed, toggle } = await import(
  '../../../daedalus_ui/js/outline/collapse.js'
);

const block = (id, kind, label, groups = [], favorite = false) => ({ id, kind, label, description: '', icon: null, groups, favorite });
const record = (id, label, groups = []) => ({ kind: 'blueprint', id, label, description: '', icon: null, origin: 'user', updatable: true, groups, favorite: false });

const SETS = buildSets(
  faceList(
    [
      block(CONTAINER_ID, KIND_BASIC, 'Container', [], true),
      block('inline_block:text', KIND_BASIC, 'Text', ['marketing'], true),
      block('field_block:node:page:title', 'Content fields', 'Title'),
    ],
    [record('hero_band', 'Hero band', ['marketing'])],
  ),
  [{ id: 'marketing', label: 'Marketing', description: '' }],
  null,
);

test('the outline is the sets in the collapse model\'s shape', () => {
  const outline = outlineOf(SETS);
  assert.deepEqual(
    outline.map((node) => [node.id, node.foldable, node.folded, node.children.length]),
    [
      [FAVORITES_ID, true, false, 0],
      [groupSetId('marketing'), true, true, 0],
      [ALL_ID, true, true, 3],
    ],
  );
  const all = outline[2];
  assert.deepEqual(
    all.children.map((node) => [node.id, node.foldable, node.folded, node.children.length]),
    [
      [kindSetId(KIND_BASIC), true, true, 0],
      [kindSetId('blueprint'), true, true, 0],
      [kindSetId('Content fields'), true, true, 0],
    ],
  );
});

test('Favorites is open by default and every other set folded, under the model\'s rule', () => {
  const outline = outlineOf(SETS);
  const folds = null;
  assert.equal(isCollapsed(folds, outline[0]), false);
  assert.equal(isCollapsed(folds, outline[1]), true);
  assert.equal(isCollapsed(folds, outline[2]), true);
  outline[2].children.forEach((kind) => assert.equal(isCollapsed(folds, kind), true));
});

test('every heading is foldable, so the all-gestures reach all of them', () => {
  const outline = outlineOf(SETS);
  assert.deepEqual(foldableIds(outline), [
    FAVORITES_ID,
    groupSetId('marketing'),
    ALL_ID,
    kindSetId(KIND_BASIC),
    kindSetId('blueprint'),
    kindSetId('Content fields'),
  ]);
  const opened = expandAll(null, outline);
  foldableIds(outline).forEach((id) => assert.equal(opened[id], id === FAVORITES_ID ? undefined : false));
  const closed = collapseAll(null, outline);
  assert.deepEqual(closed, { [FAVORITES_ID]: true });
});

test('an explicit posture stands over the default both ways', () => {
  const outline = outlineOf(SETS);
  let folds = toggle(null, outline[0]);
  assert.deepEqual(folds, { [FAVORITES_ID]: true });
  assert.equal(isCollapsed(folds, outline[0]), true);
  folds = toggle(folds, outline[2]);
  assert.equal(isCollapsed(folds, outline[2]), false);
  // A posture for a set the outline no longer holds (a group emptied on
  // this canvas) is harmless: the model reads only the ids it is given.
  assert.equal(isCollapsed({ 'group:gone': false }, outline[1]), true);
});

test('an empty or absent list of sets is an empty outline', () => {
  assert.deepEqual(outlineOf([]), []);
  assert.deepEqual(outlineOf(undefined), []);
});
