/**
 * Self-asserting tests for the notification boundary (js/src/notify.js).
 *
 * Run directly (`node --test tests/js/notify.test.mjs`) or via the node
 * suite (`npm test` from the daedalus root). Pins the one escape: the
 * messenger renders HTML by contract and every stylish string is text, so
 * notify() hands the messenger Drupal.checkPlain(text) and nothing else
 * escapes. Without the messenger the call drops silently. Exits non-zero
 * on failure.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { notify } from '../../js/src/notify.js';

// Core's Drupal.checkPlain (web/core/misc/drupal.js), its five
// replacements verbatim, so the expected text below is what a browser
// hands the messenger.
const checkPlain = (text) =>
  text
    .toString()
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');

test('notify escapes once at the messenger boundary', () => {
  const heard = [];
  globalThis.Drupal = {
    checkPlain,
    Daedalus: { messenger: { message: (text, type) => heard.push({ text, type }) } },
  };
  try {
    notify('status', "a & b's <c>");
    assert.deepEqual(heard, [{ text: 'a &amp; b&#39;s &lt;c&gt;', type: 'status' }]);
  } finally {
    delete globalThis.Drupal;
  }
});

test('a document without the messenger drops the call silently', () => {
  // No Drupal at all (a unit context): nothing to call, nothing thrown.
  assert.equal(globalThis.Drupal, undefined);
  notify('status', "a & b's <c>");
  // Drupal without the messenger attached (daedalus_ui/messenger never
  // loaded): still a no-op, and checkPlain is never reached.
  globalThis.Drupal = {
    checkPlain: () => assert.fail('checkPlain ran with no messenger to hand the text to'),
    Daedalus: {},
  };
  try {
    notify('error', "a & b's <c>");
  } finally {
    delete globalThis.Drupal;
  }
});
