/**
 * Locks the pure halves of the drag layer's edge auto-scroll
 * (auto-scroll.js): the edge-band velocity ramp and the native-scroll
 * top-up that keeps our scrolling from compounding with the browser's.
 *
 * Run with `node --test tests/js/*.test.mjs` from the module root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import {
  EDGE_BAND,
  MAX_VELOCITY,
  desiredVelocity,
  topUp,
} from '../../js/edit_mode/drag/auto-scroll.js';

const VIEWPORT = 800;

test('the viewport interior asks for no scrolling', () => {
  assert.equal(desiredVelocity(VIEWPORT / 2, VIEWPORT), 0);
  assert.equal(desiredVelocity(EDGE_BAND, VIEWPORT), 0);
  assert.equal(desiredVelocity(VIEWPORT - EDGE_BAND, VIEWPORT), 0);
});

test('the top band scrolls up, ramping to full speed at the edge', () => {
  const midBand = desiredVelocity(EDGE_BAND / 2, VIEWPORT);
  assert.equal(midBand, -MAX_VELOCITY / 2);
  assert.equal(desiredVelocity(0, VIEWPORT), -MAX_VELOCITY);
});

test('the bottom band scrolls down, ramping to full speed at the edge', () => {
  const midBand = desiredVelocity(VIEWPORT - EDGE_BAND / 2, VIEWPORT);
  assert.equal(midBand, MAX_VELOCITY / 2);
  assert.equal(desiredVelocity(VIEWPORT, VIEWPORT), MAX_VELOCITY);
});

test('the ramp clamps at the viewport edge rather than extrapolating', () => {
  // dragover cannot report coordinates outside the viewport, but a resize
  // mid-drag can leave the last cursor beyond the new bounds.
  assert.equal(desiredVelocity(-30, VIEWPORT), -MAX_VELOCITY);
  assert.equal(desiredVelocity(VIEWPORT + 30, VIEWPORT), MAX_VELOCITY);
});

test('native scrolling in the same direction is subtracted, not stacked', () => {
  // Chrome scrolls down natively at the bottom edge; we only top up.
  assert.equal(topUp(20, 12), 8);
  assert.equal(topUp(-20, -12), -8);
});

test('native scrolling at or beyond the target velocity means we add nothing', () => {
  assert.equal(topUp(20, 20), 0);
  assert.equal(topUp(20, 35), 0);
  assert.equal(topUp(-20, -35), -0);
});

test('movement opposite our direction is ignored rather than fought', () => {
  assert.equal(topUp(20, -15), 20);
  assert.equal(topUp(-20, 15), -20);
});

test('a zero desired step stays zero regardless of native movement', () => {
  assert.equal(topUp(0, 25), 0);
});
