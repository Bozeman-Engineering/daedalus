/**
 * Self-asserting tests for the cue a restore gives when it moved another
 * editor's change (js/edit_mode/history/history-cue.js): another author's
 * change is reported once, naming the author and the label; one's own is
 * silent; the sentence reaches the messenger as plain text.
 *
 * Drupal.t is stubbed with core's placeholder rule (Drupal.formatString):
 * an `@name` value is escaped, any other sigil passes through. The
 * messenger is a recorder.
 *
 * Run with `node --test modules/daedalus_ui/tests/js/history-cue.test.mjs`
 * from the daedalus root.
 */
import { strict as assert } from 'node:assert';
import { beforeEach, test } from 'node:test';

const checkPlain = (text) =>
  String(text)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');

const reported = [];

globalThis.drupalSettings = { user: { uid: '2' } };
globalThis.Drupal = {
  t: (text, args = {}) =>
    Object.entries(args).reduce(
      (out, [key, value]) =>
        out.split(key).join(key.startsWith('@') ? checkPlain(value) : value),
      text,
    ),
  Daedalus: {
    messenger: {
      message: (text, type) => reported.push({ text, type }),
    },
  },
};

const { announceRestore, restoreCue } =
  await import('../../js/edit_mode/history/history-cue.js');

const MINE = { seq: 3, label: 'Moved a section', uid: 2, author: 'Tim' };
const LILYS = { seq: 2, label: 'Edited heading', uid: 5, author: 'Lily' };

const answer = (direction, ...changes) => ({
  success: true,
  restored_direction: direction,
  restored_changes: changes,
});

beforeEach(() => {
  reported.length = 0;
});

test("an undo of another author's change is reported with the name and the label", () => {
  announceRestore(answer('undo', LILYS));
  assert.deepEqual(reported, [
    { text: "Undid Lily's change: Edited heading", type: 'status' },
  ]);
});

test("an undo or a redo of one's own change is silent", () => {
  announceRestore(answer('undo', MINE));
  announceRestore(answer('redo', MINE));
  assert.deepEqual(reported, []);
  // The settings carry the id as a string, the answer as a number.
  assert.equal(restoreCue(answer('undo', MINE), '2'), null);
  assert.equal(restoreCue(answer('undo', MINE), 2), null);
});

test('a redo is worded as a redo', () => {
  announceRestore(answer('redo', LILYS));
  assert.deepEqual(reported, [
    { text: "Redid Lily's change: Edited heading", type: 'status' },
  ]);
});

test('the label and the name reach the messenger character for character', () => {
  announceRestore(
    answer('undo', { seq: 2, label: 'Q&A', uid: 5, author: 'A <b> & Co' }),
  );
  assert.deepEqual(reported, [
    { text: "Undid A <b> & Co's change: Q&A", type: 'status' },
  ]);
});

test('an author who no longer exists reads as an unnamed other editor', () => {
  const gone = { seq: 2, label: 'Edited heading', uid: 9, author: null };
  assert.equal(
    restoreCue(answer('undo', gone), 2),
    "Undid another editor's change: Edited heading",
  );
  assert.equal(
    restoreCue(answer('redo', gone), 2),
    "Redid another editor's change: Edited heading",
  );
});

test('a jump over several changes is reported once', () => {
  const second = { seq: 1, label: 'Added a button', uid: 5, author: 'Lily' };
  // One's own changes in the span are not counted.
  announceRestore(answer('undo', MINE, LILYS));
  assert.deepEqual(reported, [
    { text: "Undid Lily's change: Edited heading", type: 'status' },
  ]);
  assert.equal(
    restoreCue(answer('undo', MINE, LILYS, second), 2),
    "Undid 2 of Lily's changes.",
  );
  assert.equal(
    restoreCue(answer('redo', second, LILYS), 2),
    "Redid 2 of Lily's changes.",
  );
  const sams = { seq: 4, label: 'Restyled', uid: 7, author: 'Sam' };
  assert.equal(
    restoreCue(answer('undo', sams, LILYS), 2),
    'Undid 2 changes by other editors.',
  );
  assert.equal(
    restoreCue(answer('redo', LILYS, sams), 2),
    'Redid 2 changes by other editors.',
  );
});

test('an answer that names no change is silent', () => {
  announceRestore(answer('undo'));
  announceRestore(null);
  announceRestore({ success: true });
  // The baseline is nobody's.
  announceRestore(
    answer('undo', { seq: 0, label: 'Initial state', uid: null, author: null }),
  );
  assert.deepEqual(reported, []);
});
