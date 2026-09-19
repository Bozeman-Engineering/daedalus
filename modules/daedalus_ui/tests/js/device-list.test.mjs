/**
 * Self-asserting tests for the responsive preview's device list state.
 *
 * Run with `node --test tests/js/*.test.mjs` from the module root (the bare
 * directory form would try to execute non-test helpers as tests).
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { createDeviceList, validateDevice } from '../../js/responsive_preview/device-list.js';

const defaults = [
  { label: 'iPhone SE', width: 375, height: 667 },
  { label: 'Laptop', width: 1440, height: 900 },
];

const createHarness = (custom = []) => {
  const persisted = [];
  const list = createDeviceList({
    defaults,
    custom,
    persist: (all) => {
      persisted.push(all);
      return Promise.resolve();
    },
  });
  return { list, persisted };
};

test('entries compose defaults first, then user devices', () => {
  const { list } = createHarness([{ label: 'Kiosk', width: 800, height: 1200 }]);
  assert.deepEqual(list.entries().map((entry) => entry.key), ['default:0', 'default:1', 'custom:0']);
  assert.equal(list.entries()[2].custom, true);
  assert.equal(list.get('custom:0').label, 'Kiosk');
});

test('add validates, appends, and persists the whole user list', () => {
  const { list, persisted } = createHarness();
  const result = list.add({ label: '  Kiosk ', width: '800', height: '1200' });
  assert.equal(result.key, 'custom:0');
  // Label trimmed, dimensions coerced to numbers.
  assert.deepEqual(persisted, [[{ label: 'Kiosk', width: 800, height: 1200 }]]);
});

test('invalid devices are rejected without persisting', () => {
  const { list, persisted } = createHarness();
  assert.ok(list.add({ label: '  ', width: 800, height: 1200 }).error);
  assert.ok(list.add({ label: 'Kiosk', width: '80.5', height: 1200 }).error);
  assert.ok(list.add({ label: 'Kiosk', width: 800, height: 10001 }).error);
  assert.ok(list.add({ label: 'Kiosk', width: 49, height: 1200 }).error);
  assert.equal(persisted.length, 0);
});

test('remove drops a user device and suggests the entry that slid in', () => {
  const { list, persisted } = createHarness([
    { label: 'A', width: 800, height: 800 },
    { label: 'B', width: 900, height: 900 },
  ]);
  const result = list.remove('custom:0');
  // B slid into slot 0.
  assert.equal(result.key, 'custom:0');
  assert.equal(list.get('custom:0').label, 'B');
  assert.deepEqual(persisted, [[{ label: 'B', width: 900, height: 900 }]]);
});

test('removing the last user device falls back to the last default', () => {
  const { list } = createHarness([{ label: 'A', width: 800, height: 800 }]);
  const result = list.remove('custom:0');
  assert.equal(result.key, 'default:1');
});

test('defaults cannot be removed', () => {
  const { list, persisted } = createHarness();
  assert.ok(list.remove('default:0').error);
  assert.equal(persisted.length, 0);
});

test('validateDevice accepts a sane device', () => {
  assert.equal(validateDevice({ label: 'Kiosk', width: '800', height: '1200' }), null);
});
