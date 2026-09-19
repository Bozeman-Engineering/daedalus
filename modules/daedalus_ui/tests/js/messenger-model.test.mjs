/**
 * Self-asserting tests for the messenger's pure model
 * (js/messenger-model.js): a message's identity, the per-type durations,
 * the persisted history's shape and cap, and the replace decision that
 * replaced the old dedupe (an identical showing message is restarted and
 * pulsed, never dropped). The DOM host (js/messenger.js) renders these
 * decisions and is covered by the FJS tier.
 *
 * Run with `node --test tests/js/*.test.mjs` from the module root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';

const {
  HISTORY_CAP,
  hash,
  messageId,
  durationFor,
  planMessage,
  historyEntry,
  pushHistory,
  parseHistory,
} = await import('../../js/messenger-model.js');

test('the hash is stable and matches the String.hashCode recipe', () => {
  assert.equal(hash(''), 0);
  assert.equal(hash('a'), 97);
  assert.equal(hash('hello'), 99162322);
  assert.equal(hash('error:Saving failed.'), hash('error:Saving failed.'));
  assert.notEqual(hash('error:Saving failed.'), hash('error:Saving failed'));
});

test('the hash stays a signed 32-bit integer on long input', () => {
  const value = hash('x'.repeat(500));
  assert.equal(value, value | 0);
});

test('the same type and text is the same message; the type is part of the identity', () => {
  assert.equal(messageId('error', 'Saving failed.'), messageId('error', 'Saving failed.'));
  assert.notEqual(messageId('error', 'Saving failed.'), messageId('warning', 'Saving failed.'));
  assert.equal(typeof messageId('error', 'Saving failed.'), 'string');
});

test('each type has its showing time and an unknown type gets the default', () => {
  assert.equal(durationFor('status'), 6000);
  assert.equal(durationFor('warning'), 8000);
  assert.equal(durationFor('error'), 10000);
  assert.equal(durationFor('info'), 8000);
});

test('a plan resolves the id, the type default and the duration for the type', () => {
  const plan = planMessage({ text: 'Saving failed.' });
  assert.deepEqual(plan, {
    id: messageId('error', 'Saving failed.'),
    text: 'Saving failed.',
    type: 'error',
    duration: 10000,
    scroll: true,
    target: null,
    replace: false,
  });
});

test('an explicit duration wins, sticky included', () => {
  assert.equal(planMessage({ text: 'Saved.', type: 'status', duration: 3000 }).duration, 3000);
  assert.equal(planMessage({ text: 'Saved.', type: 'status', duration: -1 }).duration, -1);
});

test('scroll and target ride through the plan', () => {
  const plan = planMessage({ text: 'Saved.', type: 'status', scroll: false, target: 'block-7' });
  assert.equal(plan.scroll, false);
  assert.equal(plan.target, 'block-7');
});

test('a message whose id is already showing is a replace, not an add', () => {
  const showing = [messageId('error', 'Saving failed.')];
  assert.equal(planMessage({ text: 'Saving failed.' }, showing).replace, true);
  assert.equal(planMessage({ text: 'Saving failed.', type: 'warning' }, showing).replace, false);
  assert.equal(planMessage({ text: 'Something else failed.' }, showing).replace, false);
  assert.equal(planMessage({ text: 'Saving failed.' }).replace, false);
});

test('a history entry carries the id, text, type and time, and a target only when given', () => {
  const plan = planMessage({ text: 'Saved.', type: 'status' });
  assert.deepEqual(historyEntry(plan, 1000), {
    id: plan.id,
    message: 'Saved.',
    type: 'status',
    timestamp: 1000,
  });
  const targeted = planMessage({ text: 'Saving failed.', target: 'block-7' });
  assert.deepEqual(historyEntry(targeted, 2000), {
    id: targeted.id,
    message: 'Saving failed.',
    type: 'error',
    timestamp: 2000,
    target: 'block-7',
  });
});

test('the history keeps the newest entries up to the cap without mutating its input', () => {
  const history = [];
  let next = history;
  for (let i = 0; i < HISTORY_CAP + 5; i++) {
    next = pushHistory(next, { id: String(i) });
  }
  assert.equal(HISTORY_CAP, 100);
  assert.equal(next.length, HISTORY_CAP);
  assert.equal(next[0].id, '5');
  assert.equal(next[next.length - 1].id, String(HISTORY_CAP + 4));
  assert.deepEqual(history, []);
});

test('pushing below the cap appends in order', () => {
  const next = pushHistory([{ id: 'a' }], { id: 'b' });
  assert.deepEqual(next, [{ id: 'a' }, { id: 'b' }]);
});

test('the stored history reads back; nothing, junk or a non-array starts fresh', () => {
  assert.deepEqual(parseHistory(null), []);
  assert.deepEqual(parseHistory(undefined), []);
  assert.deepEqual(parseHistory('not json'), []);
  assert.deepEqual(parseHistory('{"id":"1"}'), []);
  assert.deepEqual(parseHistory('[{"id":"1","message":"Saved.","type":"status","timestamp":1}]'), [
    { id: '1', message: 'Saved.', type: 'status', timestamp: 1 },
  ]);
});
