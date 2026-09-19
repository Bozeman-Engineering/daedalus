/**
 * Self-asserting tests for the code view's scope preference store
 * (code-scope.js): the vocabulary, the default, write-through and the
 * cross-window sync.
 *
 * Run directly (`node tests/js/code-scope.test.mjs`) or via the node
 * suite glob (`npm test`). Exits non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import {
  CODE_SCOPES,
  CODE_SCOPE_STORAGE_KEY,
  createCodeScopeStore,
  normalizeCodeScope,
} from '../../js/src/code-scope.js';

/**
 * A Storage stand-in recording writes.
 */
const fakeStorage = (initial = {}) => {
  const data = { ...initial };
  const writes = [];
  return {
    getItem: (key) => (key in data ? data[key] : null),
    setItem: (key, value) => {
      data[key] = String(value);
      writes.push([key, String(value)]);
    },
    writes,
    data,
  };
};

const tests = [];
const test = (name, run) => tests.push([name, run]);

test('the vocabulary is element | subtree | page in segment order', () => {
  assert.deepEqual(CODE_SCOPES, ['element', 'subtree', 'page']);
  assert.equal(CODE_SCOPE_STORAGE_KEY, 'daedalusStylishCodeScope');
});

test('normalize collapses everything but "subtree" and "page" onto element', () => {
  assert.equal(normalizeCodeScope('element'), 'element');
  assert.equal(normalizeCodeScope('subtree'), 'subtree');
  assert.equal(normalizeCodeScope('page'), 'page');
  assert.equal(normalizeCodeScope(null), 'element');
  assert.equal(normalizeCodeScope(undefined), 'element');
  assert.equal(normalizeCodeScope(''), 'element');
  // The retired surfaces' names never land here: they were places, not scopes.
  assert.equal(normalizeCodeScope('elements'), 'element');
  assert.equal(normalizeCodeScope('library'), 'element');
  assert.equal(normalizeCodeScope('Page'), 'element');
});

test('element is the default: empty storage, garbage, and no storage at all', () => {
  assert.equal(createCodeScopeStore(fakeStorage()).get(), 'element');
  assert.equal(createCodeScopeStore(fakeStorage({ [CODE_SCOPE_STORAGE_KEY]: 'bogus' })).get(), 'element');
  assert.equal(createCodeScopeStore(null).get(), 'element');
});

test('a persisted scope seeds the store', () => {
  assert.equal(createCodeScopeStore(fakeStorage({ [CODE_SCOPE_STORAGE_KEY]: 'page' })).get(), 'page');
  assert.equal(createCodeScopeStore(fakeStorage({ [CODE_SCOPE_STORAGE_KEY]: 'subtree' })).get(), 'subtree');
});

test('set moves through all three scopes with write-through and notifies', () => {
  const storage = fakeStorage();
  const store = createCodeScopeStore(storage);
  const heard = [];
  store.subscribe((scope) => heard.push(scope));
  store.set('page');
  store.set('subtree');
  store.set('element');
  assert.deepEqual(heard, ['page', 'subtree', 'element']);
  assert.deepEqual(storage.writes, [
    [CODE_SCOPE_STORAGE_KEY, 'page'],
    [CODE_SCOPE_STORAGE_KEY, 'subtree'],
    [CODE_SCOPE_STORAGE_KEY, 'element'],
  ]);
});

test('setting the current scope is a no-op: no write, no notification', () => {
  const storage = fakeStorage();
  const store = createCodeScopeStore(storage);
  const heard = [];
  store.subscribe((scope) => heard.push(scope));
  store.set('element');
  assert.deepEqual(heard, []);
  assert.deepEqual(storage.writes, []);
});

test('set normalizes garbage onto element (a real change still notifies)', () => {
  const store = createCodeScopeStore(fakeStorage({ [CODE_SCOPE_STORAGE_KEY]: 'page' }));
  const heard = [];
  store.subscribe((scope) => heard.push(scope));
  store.set('bogus');
  assert.equal(store.get(), 'element');
  assert.deepEqual(heard, ['element']);
});

test("receiveExternal applies the other window's scope but never writes back", () => {
  const storage = fakeStorage();
  const store = createCodeScopeStore(storage);
  const heard = [];
  store.subscribe((scope) => heard.push(scope));
  store.receiveExternal('subtree');
  assert.equal(store.get(), 'subtree');
  assert.deepEqual(heard, ['subtree']);
  assert.deepEqual(storage.writes, []);
  // A removed key (storage events deliver null) falls back to element.
  store.receiveExternal(null);
  assert.equal(store.get(), 'element');
  assert.deepEqual(heard, ['subtree', 'element']);
  // The current scope arriving again stays silent.
  store.receiveExternal('element');
  assert.deepEqual(heard, ['subtree', 'element']);
});

test('a throwing storage degrades to session state', () => {
  const throwing = {
    getItem: () => { throw new Error('denied'); },
    setItem: () => { throw new Error('denied'); },
  };
  const store = createCodeScopeStore(throwing);
  assert.equal(store.get(), 'element');
  store.set('page');
  assert.equal(store.get(), 'page');
});

let failures = 0;
for (const [name, run] of tests) {
  try {
    run();
    console.log(`ok - ${name}`);
  } catch (error) {
    failures += 1;
    console.error(`not ok - ${name}`);
    console.error(error.message);
  }
}
if (failures) {
  console.error(`${failures} of ${tests.length} code-scope tests failed.`);
  process.exit(1);
}
console.log(`${tests.length} code-scope tests passed.`);
