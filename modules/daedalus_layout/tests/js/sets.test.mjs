/**
 * Self-asserting tests for the Add panel's sets (js/src/model/sets.js):
 * Favorites, each group that holds anything in declaration order, then
 * All nested as Basics, Blueprints and the categories; what each holds,
 * the counts, the order inside each, the Container leading Favorites,
 * the editor's marks over the defaults, undeclared group ids filing
 * nowhere, and the words of the fixed headings.
 *
 * Run with `node --test "modules/daedalus_layout/tests/js/*.test.mjs"`
 * from the daedalus root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';

const { CONTAINER_ID, KIND_BASIC, faceList } = await import('../../js/src/model/faces.js');
const {
  ALL_ID,
  DEFAULT_WORDS,
  FAVORITES_ID,
  buildSets,
  groupSetId,
  kindOf,
  kindSetId,
} = await import('../../js/src/model/sets.js');

const block = (id, kind, label, extra = {}) => ({
  id,
  kind,
  label,
  description: '',
  icon: null,
  groups: [],
  favorite: false,
  ...extra,
});
const record = (id, label, extra = {}) => ({
  kind: 'blueprint',
  id,
  label,
  description: '',
  icon: null,
  origin: 'user',
  updatable: true,
  groups: [],
  favorite: false,
  ...extra,
});

// The vocabulary in declaration order; "legal" holds nothing here.
const GROUPS = [
  { id: 'marketing', label: 'Marketing', description: 'Bands that sell.' },
  { id: 'newsletter', label: 'Newsletter', description: '' },
  { id: 'legal', label: 'Legal', description: '' },
];

const BLOCKS = [
  block(CONTAINER_ID, KIND_BASIC, 'Container', { favorite: true }),
  block('inline_block:text', KIND_BASIC, 'Text', { favorite: true, groups: ['marketing'] }),
  block('inline_block:image', KIND_BASIC, 'Image', { favorite: true }),
  block('inline_block:signup', KIND_BASIC, 'Newsletter signup', { groups: ['newsletter', 'undeclared'] }),
  block('field_block:node:page:title', 'Content fields', 'Title'),
  block('field_block:node:page:body', 'Content fields', 'Body'),
  block('system_menu_block:main', 'Menus', 'Main navigation'),
  block('views_block:recent-block_1', 'Lists', 'Recent content'),
];
const RECORDS = [
  record('hero_band', 'Hero band', { favorite: true, groups: ['marketing'] }),
  record('feature_trio', 'Feature trio', { groups: ['marketing'] }),
  record('band_10', 'Band 10', { groups: ['newsletter'] }),
  record('band_2', 'band 2', { groups: ['newsletter'] }),
  record('aside', 'Aside'),
];
const FACES = faceList(BLOCKS, RECORDS);

const ids = (set) => set.items.map((face) => face.id);

test('the sets, in order: Favorites, the groups that hold anything, All', () => {
  const sets = buildSets(FACES, GROUPS, null);
  assert.deepEqual(
    sets.map((set) => set.id),
    [FAVORITES_ID, groupSetId('marketing'), groupSetId('newsletter'), ALL_ID],
  );
  // The empty group is not drawn; All and Favorites always are.
  assert.ok(!sets.some((set) => set.id === groupSetId('legal')));
  assert.deepEqual(sets.map((set) => set.label), ['Favorites', 'Marketing', 'Newsletter', 'All']);
  // A group carries its description.
  assert.equal(sets[1].description, 'Bands that sell.');
});

test('Favorites: the defaults in label order, the Container leading', () => {
  const [favorites] = buildSets(FACES, GROUPS, null);
  assert.deepEqual(ids(favorites), [CONTAINER_ID, 'blueprint:hero_band', 'inline_block:image', 'inline_block:text']);
  assert.equal(favorites.count, 4);
});

test('Favorites: the editor\'s marks over the defaults', () => {
  const marks = {
    'inline_block:image': false,
    'field_block:node:page:title': true,
    'blueprint:aside': true,
  };
  const [favorites] = buildSets(FACES, GROUPS, marks);
  assert.deepEqual(ids(favorites), [CONTAINER_ID, 'blueprint:aside', 'blueprint:hero_band', 'inline_block:text', 'field_block:node:page:title']);
  // A FALSE mark removes the Container too, like any default.
  const [none] = buildSets(FACES, GROUPS, { ...marks, [CONTAINER_ID]: false });
  assert.ok(!ids(none).includes(CONTAINER_ID));
});

test('Favorites is drawn when empty, with a count of 0', () => {
  const plain = FACES.map((face) => ({ ...face, favorite: false }));
  const [favorites] = buildSets(plain, GROUPS, null);
  assert.equal(favorites.id, FAVORITES_ID);
  assert.deepEqual(favorites.items, []);
  assert.equal(favorites.count, 0);
});

test('a group holds blocks and blueprints alike, in label order, with its count', () => {
  const sets = buildSets(FACES, GROUPS, null);
  const marketing = sets.find((set) => set.id === groupSetId('marketing'));
  assert.deepEqual(ids(marketing), ['blueprint:feature_trio', 'blueprint:hero_band', 'inline_block:text']);
  assert.equal(marketing.count, 3);
  // Natural order inside a group: band 2 before Band 10.
  const newsletter = sets.find((set) => set.id === groupSetId('newsletter'));
  assert.deepEqual(ids(newsletter), ['blueprint:band_2', 'blueprint:band_10', 'inline_block:signup']);
});

test('an undeclared group id files a face nowhere', () => {
  const sets = buildSets(FACES, GROUPS, null);
  assert.ok(!sets.some((set) => set.id === groupSetId('undeclared')));
  // The face keeps its id and is still under All.
  const all = sets.find((set) => set.id === ALL_ID);
  assert.ok(ids(all).includes('inline_block:signup'));
});

test('All holds everything once, nested as Basics, Blueprints, then the categories by label', () => {
  const sets = buildSets(FACES, GROUPS, null);
  const all = sets[sets.length - 1];
  assert.equal(all.id, ALL_ID);
  assert.equal(all.count, FACES.length);
  assert.equal(new Set(ids(all)).size, FACES.length);
  assert.deepEqual(
    all.children.map((kind) => [kind.id, kind.label, kind.count]),
    [
      [kindSetId(KIND_BASIC), 'Basics', 4],
      [kindSetId('blueprint'), 'Blueprints', 5],
      [kindSetId('Content fields'), 'Content fields', 2],
      [kindSetId('Lists'), 'Lists', 1],
      [kindSetId('Menus'), 'Menus', 1],
    ],
  );
  // Each kind in label order; the Container leads nothing but Favorites.
  assert.deepEqual(ids(all.children[0]), [CONTAINER_ID, 'inline_block:image', 'inline_block:signup', 'inline_block:text']);
  assert.deepEqual(ids(all.children[1]), ['blueprint:aside', 'blueprint:band_2', 'blueprint:band_10', 'blueprint:feature_trio', 'blueprint:hero_band']);
  assert.deepEqual(ids(all.children[2]), ['field_block:node:page:body', 'field_block:node:page:title']);
  // Every face is in exactly one kind.
  const inKinds = all.children.flatMap(ids);
  assert.equal(inKinds.length, FACES.length);
  assert.deepEqual(new Set(inKinds), new Set(ids(all)));
});

test('a kind with nothing in it is not drawn', () => {
  const sets = buildSets(faceList(BLOCKS, []), GROUPS, null);
  const all = sets[sets.length - 1];
  assert.ok(!all.children.some((kind) => kind.id === kindSetId('blueprint')));
  assert.equal(all.children[0].id, kindSetId(KIND_BASIC));
});

test('the fixed headings take the component\'s words', () => {
  const words = { favorites: 'Favoris', all: 'Tout', basics: 'Bases', blueprints: 'Plans' };
  const sets = buildSets(FACES, GROUPS, null, words);
  assert.equal(sets[0].label, 'Favoris');
  const all = sets[sets.length - 1];
  assert.equal(all.label, 'Tout');
  assert.deepEqual(all.children.slice(0, 2).map((kind) => kind.label), ['Bases', 'Plans']);
  // The defaults are English.
  assert.deepEqual(DEFAULT_WORDS, { favorites: 'Favorites', all: 'All', basics: 'Basics', blueprints: 'Blueprints' });
});

test('kindOf: where a face lives under All', () => {
  assert.deepEqual(kindOf(FACES[0]), { id: kindSetId(KIND_BASIC), label: 'Basics' });
  assert.deepEqual(kindOf(FACES.find((face) => face.id === 'blueprint:aside')), { id: kindSetId('blueprint'), label: 'Blueprints' });
  assert.deepEqual(kindOf(FACES.find((face) => face.id === 'system_menu_block:main')), { id: kindSetId('Menus'), label: 'Menus' });
});

test('buildSets never mutates its inputs', () => {
  const faces = JSON.parse(JSON.stringify(FACES));
  const groups = JSON.parse(JSON.stringify(GROUPS));
  const marks = { 'inline_block:image': false };
  buildSets(faces, groups, marks);
  assert.deepEqual(faces, JSON.parse(JSON.stringify(FACES)));
  assert.deepEqual(groups, GROUPS);
  assert.deepEqual(marks, { 'inline_block:image': false });
});
