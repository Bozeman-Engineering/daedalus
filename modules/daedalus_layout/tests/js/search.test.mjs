/**
 * Self-asserting tests for the Add panel's search
 * (js/src/model/search.js): the two-character threshold, not searching
 * against no hit, the flat list in label order with each face once and
 * the kind it lives under, matching on the label and on a group's label,
 * case-insensitively.
 *
 * Run with `node --test "modules/daedalus_layout/tests/js/*.test.mjs"`
 * from the daedalus root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';

const { CONTAINER_ID, KIND_BASIC, faceList } = await import('../../js/src/model/faces.js');
const { kindSetId } = await import('../../js/src/model/sets.js');
const { MIN_QUERY, SEARCH_ID, activeQuery, search } = await import('../../js/src/model/search.js');

const block = (id, kind, label, groups = []) => ({ id, kind, label, description: '', icon: null, groups, favorite: false });
const record = (id, label, groups = []) => ({ kind: 'blueprint', id, label, description: '', icon: null, origin: 'user', updatable: true, groups, favorite: false });

const GROUPS = [
  { id: 'marketing', label: 'Marketing', description: '' },
  { id: 'newsletter', label: 'Newsletter', description: '' },
];
const FACES = faceList(
  [
    block(CONTAINER_ID, KIND_BASIC, 'Container'),
    block('inline_block:text', KIND_BASIC, 'Text', ['marketing']),
    block('inline_block:signup', KIND_BASIC, 'Newsletter signup', ['newsletter']),
    block('field_block:node:page:title', 'Content fields', 'Title'),
    block('system_menu_block:main', 'Menus', 'Main navigation'),
  ],
  [
    record('signoff', 'Signoff', ['newsletter']),
    record('hero_band', 'Hero band', ['marketing']),
    record('design_notes', 'Design notes', ['undeclared']),
  ],
);

const ids = (hits) => hits.map((hit) => hit.face.id);

test('activeQuery: two characters, trimmed and lower-cased', () => {
  assert.equal(MIN_QUERY, 2);
  assert.equal(activeQuery(''), '');
  assert.equal(activeQuery('s'), '');
  assert.equal(activeQuery(' s '), '');
  assert.equal(activeQuery('Si'), 'si');
  assert.equal(activeQuery('  Sign  '), 'sign');
  assert.equal(activeQuery(null), '');
  assert.equal(activeQuery(undefined), '');
});

test('under two characters the panel is not searching: null, not a list', () => {
  assert.equal(search(FACES, GROUPS, ''), null);
  assert.equal(search(FACES, GROUPS, 's'), null);
  assert.equal(search(FACES, GROUPS, '   '), null);
});

test('a search answers one flat list in label order, each face once, naming its kind', () => {
  const hits = search(FACES, GROUPS, 'sign');
  assert.deepEqual(ids(hits), ['blueprint:design_notes', 'inline_block:signup', 'blueprint:signoff']);
  assert.deepEqual(
    hits.map((hit) => hit.kind),
    [
      { id: kindSetId('blueprint'), label: 'Blueprints' },
      { id: kindSetId(KIND_BASIC), label: 'Basics' },
      { id: kindSetId('blueprint'), label: 'Blueprints' },
    ],
  );
  assert.equal(new Set(ids(hits)).size, hits.length);
});

test('a search matches a group\'s label too, once per face', () => {
  // "market" is in no label; it finds the Marketing group's faces.
  const hits = search(FACES, GROUPS, 'market');
  assert.deepEqual(ids(hits), ['blueprint:hero_band', 'inline_block:text']);
  // A face whose label AND group both match is listed once.
  const newsletter = search(FACES, GROUPS, 'newsletter');
  assert.deepEqual(ids(newsletter), ['inline_block:signup', 'blueprint:signoff']);
  // An undeclared group id matches nothing by its id.
  assert.deepEqual(search(FACES, GROUPS, 'undeclared'), []);
});

test('a search is case-insensitive and reads any kind', () => {
  assert.deepEqual(ids(search(FACES, GROUPS, 'MAIN')), ['system_menu_block:main']);
  assert.deepEqual(search(FACES, GROUPS, 'TiTLe').map((hit) => hit.kind.label), ['Content fields']);
  assert.deepEqual(ids(search(FACES, GROUPS, 'cont')), [CONTAINER_ID]);
});

test('no hit is an empty list, told apart from not searching', () => {
  const hits = search(FACES, GROUPS, 'zzzz');
  assert.deepEqual(hits, []);
  assert.notEqual(hits, null);
});

test('the kind it lives under takes the component\'s words', () => {
  const words = { favorites: 'Favoris', all: 'Tout', basics: 'Bases', blueprints: 'Plans' };
  const hits = search(FACES, GROUPS, 'sign', words);
  assert.deepEqual(hits.map((hit) => hit.kind.label), ['Plans', 'Bases', 'Plans']);
});

test('the search set id keys its rows apart from every set', () => {
  assert.equal(SEARCH_ID, 'search');
});
