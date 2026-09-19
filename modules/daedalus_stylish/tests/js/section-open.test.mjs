/**
 * Self-asserting tests for the property-group open-state model
 * (section-open.js): the arrival state from the three auto signals, the
 * user's per-section marks overriding it in both directions, marks under
 * a stale anchor reading as none, and a selection change yielding the
 * auto state again.
 *
 * Run directly (`node --test tests/js/section-open.test.mjs`) or via
 * JsSuitesTest, which executes this file inside the PHPUnit suite. Exits
 * non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import test from 'node:test';
import {
  autoOpen,
  markSection,
  marksFor,
  sectionOpen,
} from '../../js/src/section-open.js';

const row = (property) => ({ property });
const rows = [row('padding'), row('margin')];
const none = () => null;
const pendingOn = (property) => (candidate) => (candidate.property === property ? 'set' : null);
const defaultsOn = (property) => (candidate) => (candidate.property === property ? '4px' : null);

test('auto only: an empty section with no signals sits collapsed', () => {
  assert.equal(autoOpen({ hasValues: false }, rows, { pendingFor: none, defaultsValueFor: none }), false);
  assert.equal(autoOpen({ hasValues: false }, [], { pendingFor: none, defaultsValueFor: none }), false);
});

test('auto only: committed values open the section', () => {
  assert.equal(autoOpen({ hasValues: true }, rows, { pendingFor: none, defaultsValueFor: none }), true);
});

test('auto only: a pending line on a visible row opens the section, in or out of the session', () => {
  assert.equal(autoOpen({ hasValues: false }, rows, { pendingFor: pendingOn('margin'), defaultsValueFor: none }), true);
  assert.equal(autoOpen({ hasValues: false }, rows, { pendingFor: pendingOn('margin'), defaultsValueFor: none, sheet: {} }), true);
  // Only VISIBLE rows count: a pending line on a hidden row is not offered.
  assert.equal(autoOpen({ hasValues: false }, [row('padding')], { pendingFor: pendingOn('margin'), defaultsValueFor: none }), false);
});

test('auto only: a defaults-tier value (skin or declared) opens the section outside the editing session only', () => {
  assert.equal(autoOpen({ hasValues: false }, rows, { pendingFor: none, defaultsValueFor: defaultsOn('padding') }), true);
  assert.equal(autoOpen({ hasValues: false }, rows, { pendingFor: none, defaultsValueFor: defaultsOn('padding'), sheet: {} }), false);
});

test('auto only: no marks yields the auto state either way', () => {
  assert.equal(sectionOpen(true, {}, 'spacing'), true);
  assert.equal(sectionOpen(false, {}, 'spacing'), false);
  // A mark on another section is not this section's word.
  assert.equal(sectionOpen(false, { typography: true }, 'spacing'), false);
});

test('mark overrides auto: a hand-opened section stays open when auto says closed', () => {
  const state = markSection(null, 'node.1', 'spacing', true);
  assert.equal(sectionOpen(false, marksFor(state, 'node.1'), 'spacing'), true);
});

test('mark overrides auto: a hand-closed section stays closed when auto says open', () => {
  const state = markSection(null, 'node.1', 'spacing', false);
  assert.equal(sectionOpen(true, marksFor(state, 'node.1'), 'spacing'), false);
});

test('the open, add, delete sequence: the mark survives the auto flip', () => {
  // Empty group, user opens it by hand.
  const state = markSection(null, 'node.1', 'spacing', true);
  const marks = marksFor(state, 'node.1');
  // A value lands: auto true, still open.
  assert.equal(sectionOpen(true, marks, 'spacing'), true);
  // The value is deleted: auto false, the user's word still stands.
  assert.equal(sectionOpen(false, marks, 'spacing'), true);
});

test('marks accumulate per section under one anchor; the latest word wins', () => {
  let state = markSection(null, 'node.1', 'spacing', true);
  state = markSection(state, 'node.1', 'typography', false);
  state = markSection(state, 'node.1', 'spacing', false);
  assert.deepEqual(state, { anchor: 'node.1', marks: { spacing: false, typography: false } });
});

test('marks from a stale anchor are ignored', () => {
  const state = markSection(null, 'node.1', 'spacing', true);
  assert.deepEqual(marksFor(state, 'node.2'), {});
  assert.deepEqual(marksFor(state, 'node.1::part'), {});
  assert.deepEqual(marksFor(null, 'node.1'), {});
  assert.deepEqual(marksFor(undefined, 'node.1'), {});
  assert.equal(sectionOpen(false, marksFor(state, 'node.2'), 'spacing'), false);
});

test('reset: a selection change yields the auto state again', () => {
  const state = markSection(null, 'node.1', 'spacing', true);
  // Selection moves to node.2 with no marks: arrival state, both ways.
  assert.equal(sectionOpen(false, marksFor(state, 'node.2'), 'spacing'), false);
  assert.equal(sectionOpen(true, marksFor(state, 'node.2'), 'spacing'), true);
  // Selection comes back to node.1 through node.2 without new marks:
  // the state still carries node.1's word (marks reset on the first mark
  // under a new anchor, not on passing through).
  assert.equal(sectionOpen(false, marksFor(state, 'node.1'), 'spacing'), true);
});

test('a mark under a new anchor drops the stale marks', () => {
  let state = markSection(null, 'node.1', 'spacing', true);
  state = markSection(state, 'node.2', 'typography', false);
  assert.deepEqual(state, { anchor: 'node.2', marks: { typography: false } });
  assert.deepEqual(marksFor(state, 'node.1'), {});
});

test('the mark is coerced to a boolean', () => {
  const state = markSection(null, 'node.1', 'spacing', 1);
  assert.deepEqual(state.marks, { spacing: true });
});
