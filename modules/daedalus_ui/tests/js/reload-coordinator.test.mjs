/**
 * Self-asserting tests for the responsive preview's reload coordinator.
 *
 * Run with `node --test tests/js/*.test.mjs` from the module root (the bare
 * directory form would try to execute non-test helpers as tests).
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { createReloadCoordinator } from '../../js/responsive_preview/reload-coordinator.js';

const createHarness = (scroll = { x: 0, y: 480 }) => {
  const log = [];
  const coordinator = createReloadCoordinator({
    capture: () => {
      log.push('capture');
      return scroll;
    },
    reload: () => log.push('reload'),
    restore: (position) => log.push(`restore:${position.x},${position.y}`),
  });
  return { coordinator, log };
};

test('a ping while idle captures scroll and reloads', () => {
  const { coordinator, log } = createHarness();
  coordinator.mutated();
  assert.deepEqual(log, ['capture', 'reload']);
});

test('the load completing a reload restores the captured scroll', () => {
  const { coordinator, log } = createHarness({ x: 12, y: 340 });
  coordinator.mutated();
  coordinator.loaded();
  assert.deepEqual(log, ['capture', 'reload', 'restore:12,340']);
});

test('loads the coordinator did not trigger restore nothing', () => {
  const { coordinator, log } = createHarness();
  // The iframe's very first load.
  coordinator.loaded();
  assert.deepEqual(log, []);
  // A settled reload cycle, then the reader navigates inside the frame.
  coordinator.mutated();
  coordinator.loaded();
  coordinator.loaded();
  assert.deepEqual(log, ['capture', 'reload', 'restore:0,480']);
});

test('pings during a reload coalesce into one follow-up reload', () => {
  const { coordinator, log } = createHarness();
  coordinator.mutated();
  coordinator.mutated();
  coordinator.mutated();
  assert.deepEqual(log, ['capture', 'reload']);
  // The in-flight reload lands: restore, then the one queued follow-up.
  coordinator.loaded();
  assert.deepEqual(log, ['capture', 'reload', 'restore:0,480', 'capture', 'reload']);
  // The follow-up lands: restore again, and the machine is idle.
  coordinator.loaded();
  coordinator.loaded();
  assert.deepEqual(log, [
    'capture', 'reload', 'restore:0,480',
    'capture', 'reload', 'restore:0,480',
  ]);
});

test('a null capture restores nothing', () => {
  const { coordinator, log } = createHarness(null);
  coordinator.mutated();
  coordinator.loaded();
  assert.deepEqual(log, ['capture', 'reload']);
});
