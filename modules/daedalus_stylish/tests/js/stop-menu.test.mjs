/**
 * Self-asserting tests for the stop picker's pure half (js/src/stop-menu.js):
 * the menu items a detented space's stops become (labels via stopLabel,
 * the magnetized stop checked, none off-stop or empty, a pick handing the
 * stop back) and the keyboard opener's key test.
 *
 * Run directly: `node tests/js/stop-menu.test.mjs`. Exits non-zero on
 * failure.
 */
import { strict as assert } from 'node:assert';
import test from 'node:test';
import { isOpenKey, stopMenuItems } from '../../js/src/stop-menu.js';

const STOPS = [
  { value: '0.5rem', ref: '0.5rem', tokens: [] },
  { value: '1rem', ref: 'var(--space-md)', tokens: [{ ref: 'var(--space-md)', name: 'md' }] },
  { value: '2rem', ref: '2rem', tokens: [] },
];

test('every stop lists by its stopLabel, in the space\'s order', () => {
  const items = stopMenuItems(STOPS, '', () => {});
  assert.deepEqual(items.map((item) => item.label), ['0.5rem', 'md · 1rem', '2rem']);
});

test('the stop the shown value magnetizes to is checked, spelled either way', () => {
  const onValue = stopMenuItems(STOPS, '1rem', () => {});
  assert.deepEqual(onValue.map((item) => item.checked), [false, true, false]);
  const respelled = stopMenuItems(STOPS, '.5rem', () => {});
  assert.deepEqual(respelled.map((item) => item.checked), [true, false, false]);
});

test('off-stop and empty faces check nothing, but every item stays checkable', () => {
  for (const face of ['12px', '', '  ', undefined]) {
    const items = stopMenuItems(STOPS, face, () => {});
    assert.equal(items.length, STOPS.length, `face ${JSON.stringify(face)} lists every stop`);
    assert.ok(items.every((item) => item.checked === false), `face ${JSON.stringify(face)} checks nothing`);
  }
});

test('no header, no separators: the list is the stops alone', () => {
  const items = stopMenuItems(STOPS, '1rem', () => {});
  assert.ok(items.every((item) => !item.separator && typeof item.run === 'function'));
  assert.ok(items.every((item) => !('header' in item)));
});

test('a pick hands the stop itself to onPick', () => {
  const picked = [];
  const items = stopMenuItems(STOPS, '', (stop) => picked.push(stop));
  items[1].run();
  assert.equal(picked.length, 1);
  assert.equal(picked[0], STOPS[1]);
});

test('no stops, no items', () => {
  assert.deepEqual(stopMenuItems([], '1rem', () => {}), []);
});

test('Alt+ArrowDown is the open key; ArrowDown alone and Alt+ArrowUp are not', () => {
  assert.equal(isOpenKey({ key: 'ArrowDown', altKey: true }), true);
  assert.equal(isOpenKey({ key: 'ArrowDown', altKey: false }), false);
  assert.equal(isOpenKey({ key: 'ArrowUp', altKey: true }), false);
});
