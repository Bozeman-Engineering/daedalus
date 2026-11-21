/**
 * The emit gate's client twin (emit-gate.js) over the shared battery
 * (daedalus_ui/tests/js/emit-gate-cases.mjs, the record the PHP side
 * reads too). PHP is authoritative (EmitGateTest).
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { admits, admitsSelector } from '../../js/edit_mode/materials/emit-gate.js';
import { CASES } from './emit-gate-cases.mjs';

test('the client twin answers every battery case as recorded', () => {
  assert.ok(CASES.length > 0, 'the battery carries cases');
  CASES.forEach(({ name, text, selector, answer }) => {
    assert.equal((selector ? admitsSelector : admits)(text), answer, name);
  });
});

test('a selector differs from a value only by the child combinator', () => {
  CASES.filter(({ text }) => !text.includes('>')).forEach(({ name, text }) => {
    assert.equal(admitsSelector(text), admits(text), name);
  });
});
