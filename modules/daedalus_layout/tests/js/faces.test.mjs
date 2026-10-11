/**
 * Self-asserting tests for the Add panel's face grammar
 * (js/src/model/faces.js): the two feeds projected onto one face, the
 * blueprint id grammar, the effective favorite (the face's default with
 * the editor's mark over it), the star's toggle over the marks, and the
 * row key by set and id.
 *
 * Run with `node --test "modules/daedalus_layout/tests/js/*.test.mjs"`
 * from the daedalus root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';

const {
  BLUEPRINT_PREFIX,
  CONTAINER_ID,
  KIND_BASIC,
  KIND_BLUEPRINT,
  blockFace,
  blueprintFace,
  blueprintFaceId,
  blueprintRecordId,
  faceList,
  isBlueprintId,
  isFavorite,
  newBlueprintIds,
  rowKey,
  toggledMarks,
} = await import('../../js/src/model/faces.js');

// The server's faces as AddPanelAnswer::toArray() carries them.
const CONTAINER = {
  id: CONTAINER_ID,
  kind: KIND_BASIC,
  label: 'Container',
  description: '',
  icon: '/modules/daedalus_layout/assets/container.svg',
  groups: [],
  favorite: true,
};
const TEXT = {
  id: 'inline_block:text',
  kind: KIND_BASIC,
  label: 'Text',
  description: 'A paragraph.',
  icon: null,
  groups: ['marketing'],
  favorite: true,
};
const TITLE = {
  id: 'field_block:node:page:title',
  kind: 'Content fields',
  label: 'Title',
  description: '',
  icon: null,
  groups: [],
  favorite: false,
};

// A materials record in the delivery's shape (MaterialRecord::toArray()).
const HERO = {
  kind: 'blueprint',
  id: 'hero_band',
  label: 'Hero band',
  description: 'A band with a headline.',
  icon: null,
  origin: 'user',
  updatable: true,
  groups: ['marketing', 'events'],
  favorite: true,
};

test('the blueprint id grammar: blueprint:ID, read both ways', () => {
  assert.equal(BLUEPRINT_PREFIX, 'blueprint:');
  assert.equal(blueprintFaceId('hero_band'), 'blueprint:hero_band');
  assert.equal(isBlueprintId('blueprint:hero_band'), true);
  assert.equal(isBlueprintId('inline_block:text'), false);
  assert.equal(blueprintRecordId('blueprint:hero_band'), 'hero_band');
  assert.equal(blueprintRecordId('inline_block:text'), null);
});

test('a block face is the server face, made safe to read', () => {
  assert.deepEqual(blockFace(TEXT), { ...TEXT, editable: false, blueprint: null });
  // Missing list and nullable keys read as empty, never as undefined.
  const bare = blockFace({ id: 'system_branding_block', kind: 'System', label: 'Site branding' });
  assert.deepEqual(bare.groups, []);
  assert.equal(bare.icon, null);
  assert.equal(bare.description, '');
  assert.equal(bare.favorite, false);
  assert.equal(bare.editable, false);
});

test('editable: learned from the answer for a block, from the record for a blueprint', () => {
  // A block type's face says whether the actor may write its default;
  // the server's word is read as a boolean and never assumed.
  assert.equal(blockFace({ ...TEXT, editable: true }).editable, true);
  assert.equal(blockFace({ ...TEXT, editable: 1 }).editable, true);
  assert.equal(blockFace(TEXT).editable, false);
  // A blueprint's face is editable when its record is updatable (a
  // content blueprint); a file-shipped one is not.
  assert.equal(blueprintFace(HERO).editable, true);
  assert.equal(blueprintFace({ ...HERO, updatable: false, origin: 'file' }).editable, false);
  assert.equal(blueprintFace({ kind: 'blueprint', id: 'x', label: 'X' }).editable, false);
});

test('a blueprint face carries the record under the face grammar', () => {
  const face = blueprintFace(HERO);
  assert.equal(face.id, 'blueprint:hero_band');
  assert.equal(face.kind, KIND_BLUEPRINT);
  assert.equal(face.label, 'Hero band');
  assert.equal(face.description, 'A band with a headline.');
  assert.equal(face.icon, null);
  assert.deepEqual(face.groups, ['marketing', 'events']);
  assert.equal(face.favorite, true);
  // The record rides along for the menu (origin, updatable) and the drop.
  assert.equal(face.blueprint, HERO);
  // A record without the two keys is no favorite and in no group.
  const plain = blueprintFace({ kind: 'blueprint', id: 'x', label: 'X' });
  assert.deepEqual(plain.groups, []);
  assert.equal(plain.favorite, false);
});

test('faceList: the blocks then the blueprints, each id once', () => {
  const faces = faceList([CONTAINER, TEXT, TITLE], [HERO]);
  assert.deepEqual(
    faces.map((face) => face.id),
    [CONTAINER_ID, 'inline_block:text', 'field_block:node:page:title', 'blueprint:hero_band'],
  );
  // A repeated id keeps its first face.
  const twice = faceList([TEXT, { ...TEXT, label: 'Text again' }], []);
  assert.equal(twice.length, 1);
  assert.equal(twice[0].label, 'Text');
  // Either feed may be absent.
  assert.deepEqual(faceList(undefined, undefined), []);
});

test('isFavorite: the editor\'s mark over the face\'s default', () => {
  const text = blockFace(TEXT);
  const title = blockFace(TITLE);
  // No marks: the default.
  assert.equal(isFavorite(text, null), true);
  assert.equal(isFavorite(title, undefined), false);
  assert.equal(isFavorite(title, {}), false);
  // A mark adds what the site did not and removes what it did.
  assert.equal(isFavorite(title, { 'field_block:node:page:title': true }), true);
  assert.equal(isFavorite(text, { 'inline_block:text': false }), false);
  // A mark on another id says nothing about this face.
  assert.equal(isFavorite(text, { 'field_block:node:page:title': true }), true);
  // A blueprint's mark is read by the same rule under its face id.
  const hero = blueprintFace(HERO);
  assert.equal(isFavorite(hero, { 'blueprint:hero_band': false }), false);
  // Marks are read as booleans whatever was stored.
  assert.equal(isFavorite(title, { 'field_block:node:page:title': 1 }), true);
  assert.equal(isFavorite(text, { 'inline_block:text': 0 }), false);
});

test('toggledMarks: a mark is written only where it differs from the default', () => {
  const text = blockFace(TEXT);
  const title = blockFace(TITLE);
  // Starring a non-default adds a TRUE mark; unstarring it again drops it.
  const starred = toggledMarks(title, {});
  assert.deepEqual(starred, { 'field_block:node:page:title': true });
  assert.deepEqual(toggledMarks(title, starred), {});
  // Unstarring a default adds a FALSE mark; starring it again drops it.
  const removed = toggledMarks(text, null);
  assert.deepEqual(removed, { 'inline_block:text': false });
  assert.deepEqual(toggledMarks(text, removed), {});
  // Other marks survive, and the given map is never mutated.
  const marks = { 'blueprint:hero_band': false };
  const next = toggledMarks(title, marks);
  assert.deepEqual(next, { 'blueprint:hero_band': false, 'field_block:node:page:title': true });
  assert.deepEqual(marks, { 'blueprint:hero_band': false });
});

test('newBlueprintIds: a blueprint record the store did not hold is new, under its face id', () => {
  const card = { ...HERO, id: 'card', label: 'Card', favorite: false };
  assert.deepEqual(newBlueprintIds([HERO], [HERO, card]), ['blueprint:card']);
  // Two minted in one delivery answer in the delivery's order.
  const band = { ...HERO, id: 'band', label: 'Band', favorite: false };
  assert.deepEqual(newBlueprintIds([HERO], [band, HERO, card]), ['blueprint:band', 'blueprint:card']);
  // A remove (a page-scope save, a deletion) is nothing new.
  assert.deepEqual(newBlueprintIds([HERO, card], [HERO]), []);
  assert.deepEqual(newBlueprintIds([HERO], []), []);
});

test('newBlueprintIds: an update upserts a held id and is not new', () => {
  const updated = { ...HERO, label: 'Hero band, renamed', description: 'Edited.', favorite: true };
  assert.deepEqual(newBlueprintIds([HERO], [updated]), []);
  // The same id held and delivered, whatever else moved on the record.
  assert.deepEqual(newBlueprintIds([{ ...HERO, favorite: false }], [{ ...HERO, favorite: true }]), []);
});

test('newBlueprintIds: a block face never counts, in either list', () => {
  const card = { ...HERO, id: 'card', label: 'Card' };
  // A block face in the delivery is a block, not a new blueprint.
  assert.deepEqual(newBlueprintIds([HERO], [HERO, TEXT, TITLE]), []);
  // A block face among the held records hides no blueprint.
  assert.deepEqual(newBlueprintIds([TEXT, HERO], [HERO, card]), ['blueprint:card']);
  // A block whose plugin id spells like a blueprint id is still a block.
  assert.deepEqual(newBlueprintIds([], [{ ...TEXT, id: 'blueprint:card' }]), []);
});

test('newBlueprintIds: against nothing held every record is new, so the component seeds its baseline from the records at mount and never asks this of a boot', () => {
  assert.deepEqual(newBlueprintIds([], [HERO]), ['blueprint:hero_band']);
  assert.deepEqual(newBlueprintIds(undefined, [HERO]), ['blueprint:hero_band']);
  // Seeded from what the mount read, the first delivery of the same set
  // is nothing new.
  assert.deepEqual(newBlueprintIds([HERO], [HERO]), []);
  assert.deepEqual(newBlueprintIds([], []), []);
  assert.deepEqual(newBlueprintIds(undefined, undefined), []);
});

test('rowKey: a row is keyed by set and id', () => {
  assert.equal(rowKey('favorites', 'inline_block:text'), 'favorites|inline_block:text');
  assert.equal(rowKey('kind:basic', 'inline_block:text'), 'kind:basic|inline_block:text');
  assert.notEqual(rowKey('favorites', 'blueprint:x'), rowKey('all', 'blueprint:x'));
});
