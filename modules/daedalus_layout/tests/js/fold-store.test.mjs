/**
 * Self-asserting tests for the Add panel's fold store
 * (js/src/model/fold-store.js): the key, explicit postures only, a
 * round trip, garbage degrading entry by entry, an empty map forgetting,
 * and every access guarded so a blocked or absent store degrades to the
 * defaults on read and to session state on write.
 *
 * Run with `node --test "modules/daedalus_layout/tests/js/*.test.mjs"`
 * from the daedalus root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';

const { STORAGE_KEY, normalizeFolds, readFolds, windowStorage, writeFolds } = await import(
  '../../js/src/model/fold-store.js'
);

/**
 * A Storage-shaped backend over a Map.
 */
const memoryStorage = () => {
  const map = new Map();
  return {
    map,
    getItem: (key) => (map.has(key) ? map.get(key) : null),
    setItem: (key, value) => map.set(key, String(value)),
    removeItem: (key) => map.delete(key),
  };
};

/**
 * A backend that throws on every access (a blocked store).
 */
const blockedStorage = () => ({
  getItem: () => {
    throw new Error('blocked');
  },
  setItem: () => {
    throw new Error('blocked');
  },
  removeItem: () => {
    throw new Error('blocked');
  },
});

test('the key', () => {
  assert.equal(STORAGE_KEY, 'daedalusLayoutAddPanel');
});

test('off-window there is no storage, and nothing throws', () => {
  assert.equal(typeof window, 'undefined');
  assert.equal(windowStorage(), null);
  assert.equal(readFolds(), null);
});

test('a round trip: explicit postures only', () => {
  const storage = memoryStorage();
  assert.equal(readFolds(storage), null);
  assert.equal(writeFolds(storage, { favorites: true, 'group:marketing': false }), true);
  assert.equal(storage.map.get(STORAGE_KEY), '{"favorites":true,"group:marketing":false}');
  assert.deepEqual(readFolds(storage), { favorites: true, 'group:marketing': false });
});

test('a write keeps booleans alone and an empty map forgets', () => {
  const storage = memoryStorage();
  writeFolds(storage, { favorites: true, all: 'yes', '': true, 'kind:basic': 0 });
  assert.deepEqual(readFolds(storage), { favorites: true });
  assert.equal(writeFolds(storage, {}), true);
  assert.equal(storage.map.has(STORAGE_KEY), false);
  assert.equal(readFolds(storage), null);
  writeFolds(storage, { favorites: true });
  writeFolds(storage, null);
  assert.equal(storage.map.has(STORAGE_KEY), false);
});

test('garbage under the key degrades to the defaults, entry by entry', () => {
  assert.equal(normalizeFolds(null), null);
  assert.equal(normalizeFolds(''), null);
  assert.equal(normalizeFolds('not json'), null);
  assert.equal(normalizeFolds('"a string"'), null);
  assert.equal(normalizeFolds('[true]'), null);
  assert.equal(normalizeFolds('42'), null);
  assert.equal(normalizeFolds('{}'), null);
  assert.equal(normalizeFolds('{"favorites":"true"}'), null);
  assert.deepEqual(normalizeFolds('{"favorites":true,"all":"no","":false,"kind:basic":false}'), { favorites: true, 'kind:basic': false });
  assert.equal(normalizeFolds(undefined), null);
  assert.equal(normalizeFolds({ favorites: true }), null);
  const storage = memoryStorage();
  storage.map.set(STORAGE_KEY, '{oops');
  assert.equal(readFolds(storage), null);
});

test('a blocked store degrades: the defaults on read, false on write, nothing thrown', () => {
  const storage = blockedStorage();
  assert.equal(readFolds(storage), null);
  assert.equal(writeFolds(storage, { favorites: true }), false);
  assert.equal(writeFolds(storage, {}), false);
});

test('no store at all is the same as a blocked one', () => {
  assert.equal(readFolds(null), null);
  assert.equal(readFolds(undefined), null);
  assert.equal(writeFolds(null, { favorites: true }), false);
  assert.equal(writeFolds(undefined, { favorites: true }), false);
});

test('a window whose localStorage throws on access reads as no storage', () => {
  globalThis.window = {
    get localStorage() {
      throw new Error('SecurityError');
    },
  };
  try {
    assert.equal(windowStorage(), null);
    assert.equal(readFolds(), null);
  } finally {
    delete globalThis.window;
  }
});

test('a window with a storage hands it back', () => {
  const storage = memoryStorage();
  globalThis.window = { localStorage: storage };
  try {
    assert.equal(windowStorage(), storage);
    writeFolds(windowStorage(), { all: false });
    assert.deepEqual(readFolds(), { all: false });
  } finally {
    delete globalThis.window;
  }
});
