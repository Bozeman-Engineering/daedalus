/**
 * Self-asserting tests for the sentence that reports a face's new site
 * default (js/src/site-favorite.js favoriteNotice): the message display
 * takes plain text and escapes once, so the label reaches it character
 * for character.
 *
 * Drupal.t is stubbed with core's placeholder rule (Drupal.formatString):
 * an `@name` value is escaped, any other sigil passes through.
 *
 * Run with `node --test "modules/daedalus_layout/tests/js/*.test.mjs"`
 * from the daedalus root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';

const checkPlain = (text) =>
  String(text)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');

globalThis.Drupal = {
  t: (text, args = {}) =>
    Object.entries(args).reduce(
      (out, [key, value]) =>
        out.split(key).join(key.startsWith('@') ? checkPlain(value) : value),
      text,
    ),
};

const { favoriteNotice } = await import('../../js/src/site-favorite.js');

test('the favorite notice holds the label as plain text', () => {
  assert.equal(
    favoriteNotice({ label: 'Q&A' }, true),
    '"Q&A" is now a favorite for everyone.',
  );
  assert.equal(
    favoriteNotice({ label: 'Q&A' }, false),
    '"Q&A" is no longer a favorite for everyone.',
  );
  assert.equal(
    favoriteNotice({ label: 'Tim\'s "<b>"' }, true),
    '"Tim\'s "<b>"" is now a favorite for everyone.',
  );
});
