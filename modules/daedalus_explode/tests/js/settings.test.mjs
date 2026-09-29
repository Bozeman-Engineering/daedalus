/**
 * Self-asserting tests for the settings store (settings.js): the defaults
 * seed an empty store, a stored object is normalized field by field
 * (numbers clamped to their ranges, toggles held to booleans, the depth
 * cap to an integer of at least one or All), garbage and unreadable
 * storage degrade to the defaults, set() merges, clamps, persists and
 * notifies real changes only, reset() returns to the defaults, and a
 * storage that throws on write degrades to session state.
 *
 * Run with `node --test tests/js/settings.test.mjs` from the module root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import {
  DEFAULTS,
  RANGES,
  STORAGE_KEY,
  createSettingsStore,
  normalize,
} from '../../js/src/settings.js';

/**
 * A Storage-shaped fake that records its writes.
 */
const fakeStorage = (stored = {}) => {
  const writes = [];
  return {
    writes,
    getItem: (key) => (key in stored ? stored[key] : null),
    setItem: (key, value) => {
      stored[key] = value;
      writes.push([key, value]);
    },
  };
};

test('an empty store carries the defaults', () => {
  const store = createSettingsStore(fakeStorage());
  assert.deepEqual(store.get(), DEFAULTS);
  assert.ok(Object.isFrozen(store.get()));
});

test('no storage at all still carries the defaults', () => {
  const store = createSettingsStore(null);
  assert.deepEqual(store.get(), DEFAULTS);
});

test('a stored object seeds the store, normalized field by field', () => {
  const stored = {
    [STORAGE_KEY]: JSON.stringify({
      tilt: 12,
      turn: -999,
      gap: 500,
      scale: 'big',
      depthCap: 2,
      labels: false,
      // The Parts toggle of an older stored shape: dropped like any stray
      // field (the part tier follows daedalus_ui's Markup view now).
      parts: true,
      isolate: true,
      stray: 1,
    }),
  };
  const store = createSettingsStore(fakeStorage(stored));
  assert.deepEqual(store.get(), {
    tilt: 12,
    turn: RANGES.turn[0],
    gap: RANGES.gap[1],
    scale: DEFAULTS.scale,
    depthCap: 2,
    labels: false,
    isolate: true,
  });
});

test('garbage in storage degrades to the defaults', () => {
  const notJson = createSettingsStore(fakeStorage({ [STORAGE_KEY]: '{oops' }));
  assert.deepEqual(notJson.get(), DEFAULTS);
  const notObject = createSettingsStore(fakeStorage({ [STORAGE_KEY]: '42' }));
  assert.deepEqual(notObject.get(), DEFAULTS);
  const unreadable = createSettingsStore({
    getItem: () => {
      throw new Error('storage unavailable');
    },
    setItem: () => {},
  });
  assert.deepEqual(unreadable.get(), DEFAULTS);
});

test('normalize holds the depth cap to an integer of at least one, else All', () => {
  assert.equal(normalize({ depthCap: 3 }).depthCap, 3);
  assert.equal(normalize({ depthCap: 1 }).depthCap, 1);
  assert.equal(normalize({ depthCap: 0 }).depthCap, null);
  assert.equal(normalize({ depthCap: 2.5 }).depthCap, null);
  assert.equal(normalize({ depthCap: '2' }).depthCap, null);
  assert.equal(normalize({ depthCap: null }).depthCap, null);
  assert.equal(normalize(undefined).depthCap, null);
});

test('normalize clamps every numeric control and rejects non-numbers', () => {
  for (const [name, [min, max]] of Object.entries(RANGES)) {
    assert.equal(normalize({ [name]: min - 1000 })[name], min, name);
    assert.equal(normalize({ [name]: max + 1000 })[name], max, name);
    assert.equal(normalize({ [name]: NaN })[name], DEFAULTS[name], name);
    assert.equal(normalize({ [name]: Infinity })[name], DEFAULTS[name], name);
    assert.equal(normalize({ [name]: '5' })[name], DEFAULTS[name], name);
  }
});

test('set merges a patch, clamps it, persists it and notifies once', () => {
  const storage = fakeStorage();
  const store = createSettingsStore(storage);
  const heard = [];
  store.subscribe((value) => heard.push(value));
  store.set({ tilt: 200, labels: false });
  assert.equal(store.get().tilt, RANGES.tilt[1]);
  assert.equal(store.get().labels, false);
  assert.equal(store.get().turn, DEFAULTS.turn, 'untouched fields keep their value');
  assert.equal(heard.length, 1);
  assert.equal(heard[0], store.get());
  assert.deepEqual(storage.writes, [[STORAGE_KEY, JSON.stringify(store.get())]]);
});

test('set with no real change neither persists nor notifies', () => {
  const storage = fakeStorage();
  const store = createSettingsStore(storage);
  let heard = 0;
  store.subscribe(() => {
    heard += 1;
  });
  store.set({ tilt: DEFAULTS.tilt });
  store.set({ tilt: 999 });
  store.set({ tilt: RANGES.tilt[1] });
  store.set({ isolate: 'yes' });
  assert.equal(heard, 1, 'only the clamp to the top of the range was a change');
  assert.equal(storage.writes.length, 1);
});

test('unsubscribe stops the notifications', () => {
  const store = createSettingsStore(fakeStorage());
  let heard = 0;
  const off = store.subscribe(() => {
    heard += 1;
  });
  store.set({ gap: 10 });
  off();
  store.set({ gap: 20 });
  assert.equal(heard, 1);
});

test('reset returns to the defaults and persists them', () => {
  const storage = fakeStorage();
  const store = createSettingsStore(storage);
  store.set({ tilt: 10, turn: 10, gap: 10, depthCap: 1, labels: false, isolate: true });
  store.reset();
  assert.deepEqual(store.get(), DEFAULTS);
  assert.deepEqual(storage.writes.at(-1), [STORAGE_KEY, JSON.stringify(DEFAULTS)]);
});

test('a storage that throws on write degrades to session state', () => {
  const store = createSettingsStore({
    getItem: () => null,
    setItem: () => {
      throw new Error('quota');
    },
  });
  store.set({ gap: 5 });
  assert.equal(store.get().gap, 5);
});

test('a later store reads what an earlier one persisted', () => {
  const stored = {};
  const first = createSettingsStore(fakeStorage(stored));
  first.set({ tilt: 55, depthCap: 2, isolate: true });
  const second = createSettingsStore(fakeStorage(stored));
  assert.deepEqual(second.get(), first.get());
});
