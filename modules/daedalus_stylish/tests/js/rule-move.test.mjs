/**
 * Self-asserting tests for the rule move (js/src/rule-move.js): the
 * shared battery (tests/js/rule-move-cases.mjs, the record the PHP side
 * answers too through RuleMoveTest), the mapping's order, and the
 * exported refusal reasons.
 *
 * Run directly (`node --test tests/js/rule-move.test.mjs`) or via the
 * suite glob. Exits non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import {
  REFUSED_CONTINUATION,
  REFUSED_GRAMMAR,
  REFUSED_TARGET_EXISTS,
  planRuleMove,
} from '../../js/src/rule-move.js';
import { CASES } from './rule-move-cases.mjs';

/**
 * The twin's answer in the battery's shape: the plan, or {error: true}
 * where `from` is outside the grammar.
 */
const answer = ({ keys, from, to }) => {
  try {
    return planRuleMove(keys, from, to);
  } catch (error) {
    if (!(error instanceof TypeError)) {
      throw error;
    }
    return { error: true };
  }
};

test('the client twin answers every battery case as recorded', () => {
  assert.ok(CASES.length > 0, 'the battery carries cases');
  for (const item of CASES) {
    assert.deepEqual(answer(item), item.answer, item.name);
  }
});

test('the mapping lists the rule first, then the continuations in list order', () => {
  const { moves } = planRuleMove(['> d-text > *', '> d-text::before', '> d-text', '> d-text .link'], '> d-text', '> d-image');
  assert.deepEqual(Object.keys(moves), ['> d-text', '> d-text > *', '> d-text::before', '> d-text .link']);
  assert.deepEqual(Object.values(moves), ['> d-image', '> d-image > *', '> d-image::before', '> d-image .link']);
});

test('the refusal reasons are the server\'s three', () => {
  assert.deepEqual([REFUSED_GRAMMAR, REFUSED_CONTINUATION, REFUSED_TARGET_EXISTS], ['grammar', 'continuation', 'target_exists']);
  assert.equal(planRuleMove([], '> d-text', 'p:has(:has(a))').refusal.reason, REFUSED_GRAMMAR);
  assert.equal(planRuleMove(['> d-text .link'], '> d-text', 'p::before').refusal.reason, REFUSED_CONTINUATION);
  assert.equal(planRuleMove(['> d-image'], '> d-text', '> d-image').refusal.reason, REFUSED_TARGET_EXISTS);
});

test('`from` outside the grammar throws', () => {
  assert.throws(() => planRuleMove([], 'p:has(:has(a))', 'p'), TypeError);
  assert.throws(() => planRuleMove([], '', 'p'), TypeError);
});
