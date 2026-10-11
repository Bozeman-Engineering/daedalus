/**
 * Self-asserting tests for the Add panel's label order
 * (js/src/model/order.js): natural and case-insensitive, a named id
 * leading, stable, and never mutating.
 *
 * Run with `node --test "modules/daedalus_layout/tests/js/*.test.mjs"`
 * from the daedalus root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';

const { byLabel, compareLabels } = await import('../../js/src/model/order.js');

const face = (id, label) => ({ id, label });

test('compareLabels: case-insensitive', () => {
  assert.equal(compareLabels('image', 'Image'), 0);
  assert.ok(compareLabels('apple', 'Banana') < 0);
  assert.ok(compareLabels('Zebra', 'apple') > 0);
});

test('compareLabels: natural, so Band 2 precedes Band 10', () => {
  assert.ok(compareLabels('Band 2', 'Band 10') < 0);
  assert.ok(compareLabels('Band 10', 'Band 9') > 0);
  assert.ok(compareLabels('Issue 1', 'Issue 1b') < 0);
});

test('compareLabels: anything reads as its string', () => {
  assert.equal(compareLabels(null, ''), 0);
  assert.equal(compareLabels(undefined, ''), 0);
  assert.ok(compareLabels(2, 10) < 0);
});

test('byLabel: label order, stable, the list untouched', () => {
  const faces = [
    face('c', 'Logo wall'),
    face('b', 'band 2'),
    face('a', 'Band 10'),
    face('d', 'Hero band'),
    face('e', 'hero band'),
  ];
  const copy = [...faces];
  const sorted = byLabel(faces);
  assert.deepEqual(
    sorted.map((f) => f.id),
    // band 2, Band 10 (natural), then Hero band twice in the order given
    // (stable under a case-insensitive tie), then Logo wall.
    ['b', 'a', 'd', 'e', 'c'],
  );
  assert.deepEqual(faces, copy);
  assert.notEqual(sorted, faces);
});

test('byLabel: a named id leads whatever its label', () => {
  const faces = [face('text', 'Text'), face('container', 'Container'), face('a', 'Aside')];
  assert.deepEqual(
    byLabel(faces, 'text').map((f) => f.id),
    ['text', 'a', 'container'],
  );
  // An id that is not in the list leads nothing.
  assert.deepEqual(
    byLabel(faces, 'missing').map((f) => f.id),
    ['a', 'container', 'text'],
  );
  assert.deepEqual(byLabel([], 'text'), []);
});
