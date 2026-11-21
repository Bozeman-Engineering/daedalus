/**
 * Self-asserting tests for the notification boundary (js/src/notify.js).
 *
 * Run directly (`node --test tests/js/notify.test.mjs`) or via the node
 * suite (`npm test` from the daedalus root). Pins the hand-over: the
 * messenger's contract is text and every stylish string is text, so
 * notify() hands the messenger the text as it is and escapes nothing (the
 * messenger escapes once, where it writes the toast). Without the
 * messenger the call drops silently. Exits non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { notify } from '../../js/src/notify.js';

test('notify hands the messenger the text unescaped', () => {
  const heard = [];
  globalThis.Drupal = {
    checkPlain: () => assert.fail('notify escaped; the messenger is the one place that does'),
    Daedalus: { messenger: { message: (text, type) => heard.push({ text, type }) } },
  };
  try {
    notify('status', "a & b's <c>");
    assert.deepEqual(heard, [{ text: "a & b's <c>", type: 'status' }]);
  } finally {
    delete globalThis.Drupal;
  }
});

test('a document without the messenger drops the call silently', () => {
  // No Drupal at all (a unit context): nothing to call, nothing thrown.
  assert.equal(globalThis.Drupal, undefined);
  notify('status', "a & b's <c>");
  // Drupal without the messenger attached (daedalus_ui/messenger never
  // loaded): still a no-op.
  globalThis.Drupal = { Daedalus: {} };
  try {
    notify('error', "a & b's <c>");
  } finally {
    delete globalThis.Drupal;
  }
});
