/**
 * Self-asserting tests for the registry's verb-group fold
 * (js/edit_mode/verbs/verb-groups.js groupVerbs): a menu-ordered verb
 * list becomes face entries, a plain `{verb}` or a
 * `{group, primary, rest}`; a group sits where its lightest member sits,
 * its members keep weight order with the primary first, a group of one
 * is the plain entry it would be alone, and the input is never mutated.
 *
 * Run with `node --test tests/js/verb-groups.test.mjs` from the module
 * root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { groupVerbs } from '../../js/edit_mode/verbs/verb-groups.js';

// The registry hands faces a list sorted by weight then label (verbs.js
// verbsFor); the fixtures below are written in that order and the group
// key is read exactly as register() stores it (a string or null).
const edit = { id: 'edit', weight: 10, group: null };
const open = { id: 'open', weight: 26, group: 'blueprint' };
const update = { id: 'update', weight: 25, group: 'blueprint' };
const saveAs = { id: 'save_as', weight: 27, group: 'blueprint' };
const trash = { id: 'trash', weight: 40, group: null };
const copy = { id: 'copy', weight: 30, group: 'clipboard' };
const paste = { id: 'paste', weight: 31, group: 'clipboard' };

test('verbs with no group are plain entries in the order given', () => {
  assert.deepEqual(groupVerbs([edit, trash]), [{ verb: edit }, { verb: trash }]);
});

test('the empty list folds to no entries', () => {
  assert.deepEqual(groupVerbs([]), []);
});

test('a group with one applicable member is a plain entry, not a one-member control', () => {
  // An unsaved container: only Save as applies of the blueprint group, so
  // its chip shows the lone button a verb without a group would show.
  assert.deepEqual(groupVerbs([edit, saveAs, trash]), [
    { verb: edit },
    { verb: saveAs },
    { verb: trash },
  ]);
});

test('a group of three sits where its lightest member sits, members in weight order, primary first', () => {
  const entries = groupVerbs([edit, update, open, saveAs, trash]);
  assert.deepEqual(entries, [
    { verb: edit },
    { group: 'blueprint', primary: update, rest: [open, saveAs] },
    { verb: trash },
  ]);
});

test('two groups interleaved with plain verbs each fold at their own lightest member', () => {
  // Menu order: edit(10), update(25), open(26), saveAs(27), copy(30),
  // paste(31), trash(40). Each group collapses onto its first member's
  // slot; the plain verbs keep their places around them.
  const entries = groupVerbs([edit, update, open, saveAs, copy, paste, trash]);
  assert.deepEqual(entries, [
    { verb: edit },
    { group: 'blueprint', primary: update, rest: [open, saveAs] },
    { group: 'clipboard', primary: copy, rest: [paste] },
    { verb: trash },
  ]);
});

test('a group whose members are separated by plain verbs still folds onto the lightest member', () => {
  // A heavier member registered far down the menu joins the control at
  // the lightest member's position; nothing of the group remains at its
  // own slot.
  const late = { id: 'late', weight: 50, group: 'blueprint' };
  const entries = groupVerbs([update, trash, late]);
  assert.deepEqual(entries, [
    { group: 'blueprint', primary: update, rest: [late] },
    { verb: trash },
  ]);
});

test('a verb registered before the group key existed (group undefined) is a plain entry', () => {
  // register() defaults the key to null; a spec object from elsewhere
  // (a test fixture, an older face) reads the same.
  const bare = { id: 'bare', weight: 5 };
  assert.deepEqual(groupVerbs([bare, update, open]), [
    { verb: bare },
    { group: 'blueprint', primary: update, rest: [open] },
  ]);
});

test('the fold never mutates the verbs or the list it is given', () => {
  const verbs = [edit, update, open, saveAs];
  const before = JSON.stringify(verbs);
  groupVerbs(verbs);
  assert.equal(JSON.stringify(verbs), before);
  assert.equal(verbs.length, 4);
});
