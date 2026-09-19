/**
 * Self-asserting tests for the basic/advanced/code UI-mode store
 * and its preference-store siblings (the variant line tints toggle).
 *
 * Run directly (`node tests/js/ui-mode.test.mjs`) or via
 * PrinterEquivalenceTest, which executes this file inside the PHPUnit suite
 * so the mode contract has backpressure. Exits non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import {
  createUiModeStore,
  normalizeUiMode,
  UI_MODE_STORAGE_KEY,
} from '../../js/src/ui-mode.js';
import {
  createLineTintsStore,
  normalizeLineTints,
  LINE_TINTS_STORAGE_KEY,
} from '../../js/src/line-tints.js';

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

test('normalize collapses everything but "advanced" and "code" onto basic', () => {
  assert.equal(normalizeUiMode('advanced'), 'advanced');
  assert.equal(normalizeUiMode('code'), 'code');
  assert.equal(normalizeUiMode('basic'), 'basic');
  assert.equal(normalizeUiMode(null), 'basic');
  assert.equal(normalizeUiMode(undefined), 'basic');
  assert.equal(normalizeUiMode('expert'), 'basic');
  assert.equal(normalizeUiMode(''), 'basic');
});

test('basic is the default: empty storage, garbage, and no storage at all', () => {
  assert.equal(createUiModeStore(fakeStorage()).get(), 'basic');
  assert.equal(createUiModeStore(fakeStorage({ [UI_MODE_STORAGE_KEY]: 'bogus' })).get(), 'basic');
  assert.equal(createUiModeStore(null).get(), 'basic');
});

test('a persisted "advanced" seeds the store', () => {
  const store = createUiModeStore(fakeStorage({ [UI_MODE_STORAGE_KEY]: 'advanced' }));
  assert.equal(store.get(), 'advanced');
});

test('a persisted "code" seeds the store: code is a peer mode', () => {
  const store = createUiModeStore(fakeStorage({ [UI_MODE_STORAGE_KEY]: 'code' }));
  assert.equal(store.get(), 'code');
});

test('set moves through all three modes with write-through', () => {
  const storage = fakeStorage();
  const store = createUiModeStore(storage);
  const heard = [];
  store.subscribe((mode) => heard.push(mode));
  store.set('code');
  store.set('advanced');
  store.set('basic');
  assert.deepEqual(heard, ['code', 'advanced', 'basic']);
  assert.deepEqual(storage.writes, [
    [UI_MODE_STORAGE_KEY, 'code'],
    [UI_MODE_STORAGE_KEY, 'advanced'],
    [UI_MODE_STORAGE_KEY, 'basic'],
  ]);
});

test('set persists write-through and notifies with the new mode', () => {
  const storage = fakeStorage();
  const store = createUiModeStore(storage);
  const heard = [];
  store.subscribe((mode) => heard.push(mode));
  store.set('advanced');
  assert.equal(store.get(), 'advanced');
  assert.deepEqual(heard, ['advanced']);
  assert.deepEqual(storage.writes, [[UI_MODE_STORAGE_KEY, 'advanced']]);
});

test('setting the current mode is a no-op: no write, no notification', () => {
  const storage = fakeStorage();
  const store = createUiModeStore(storage);
  const heard = [];
  store.subscribe((mode) => heard.push(mode));
  store.set('basic');
  assert.deepEqual(heard, []);
  assert.deepEqual(storage.writes, []);
});

test('set normalizes garbage onto basic (a real change still notifies)', () => {
  const store = createUiModeStore(fakeStorage({ [UI_MODE_STORAGE_KEY]: 'advanced' }));
  const heard = [];
  store.subscribe((mode) => heard.push(mode));
  store.set('bogus');
  assert.equal(store.get(), 'basic');
  assert.deepEqual(heard, ['basic']);
});

test('receiveExternal notifies but never writes back', () => {
  const storage = fakeStorage();
  const store = createUiModeStore(storage);
  const heard = [];
  store.subscribe((mode) => heard.push(mode));
  store.receiveExternal('advanced');
  assert.equal(store.get(), 'advanced');
  assert.deepEqual(heard, ['advanced']);
  assert.deepEqual(storage.writes, []);
  // A removed key (storage events deliver null) falls back to basic.
  store.receiveExternal(null);
  assert.equal(store.get(), 'basic');
  assert.deepEqual(heard, ['advanced', 'basic']);
});

test('receiveExternal of the current mode stays silent', () => {
  const store = createUiModeStore(fakeStorage());
  const heard = [];
  store.subscribe((mode) => heard.push(mode));
  store.receiveExternal('basic');
  assert.deepEqual(heard, []);
});

test('unsubscribe stops notifications', () => {
  const store = createUiModeStore(fakeStorage());
  const heard = [];
  const unsubscribe = store.subscribe((mode) => heard.push(mode));
  unsubscribe();
  store.set('advanced');
  assert.deepEqual(heard, []);
});

test('line tints: on is the default, off is the one other value', () => {
  assert.equal(normalizeLineTints('off'), 'off');
  assert.equal(normalizeLineTints('on'), 'on');
  assert.equal(normalizeLineTints(null), 'on');
  assert.equal(normalizeLineTints('bogus'), 'on');
  assert.equal(createLineTintsStore(null).get(), 'on');
  assert.equal(createLineTintsStore(fakeStorage({ [LINE_TINTS_STORAGE_KEY]: 'off' })).get(), 'off');
});

test('line tints: the toggle round-trips with write-through and external sync', () => {
  const storage = fakeStorage();
  const store = createLineTintsStore(storage);
  const heard = [];
  store.subscribe((state) => heard.push(state));
  store.set('off');
  assert.equal(store.get(), 'off');
  assert.deepEqual(storage.writes, [[LINE_TINTS_STORAGE_KEY, 'off']]);
  // The other window's storage event: applied, never written back.
  store.receiveExternal(null);
  assert.equal(store.get(), 'on');
  assert.deepEqual(heard, ['off', 'on']);
  assert.deepEqual(storage.writes, [[LINE_TINTS_STORAGE_KEY, 'off']]);
});

test('a throwing storage degrades to session state', () => {
  const throwing = {
    getItem: () => { throw new Error('denied'); },
    setItem: () => { throw new Error('denied'); },
  };
  const store = createUiModeStore(throwing);
  assert.equal(store.get(), 'basic');
  const heard = [];
  store.subscribe((mode) => heard.push(mode));
  store.set('advanced');
  assert.equal(store.get(), 'advanced');
  assert.deepEqual(heard, ['advanced']);
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
  console.error(`${failures} of ${tests.length} ui-mode tests failed.`);
  process.exit(1);
}
console.log(`${tests.length} ui-mode tests passed.`);
