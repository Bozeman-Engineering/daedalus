/**
 * Self-asserting tests for the selection write door
 * (js/edit_mode/selection/select-door.js): whether a selection write
 * leaves the open edit, and the five writes that ask before they land.
 *
 * Editing is a fact of the one selection, so while an edit is open the
 * selection is the editing key and any write that lands elsewhere is
 * leaving the edit. The spec pins the decision's five shapes (no edit,
 * the same single key, a different primary, a set that merely contains
 * the editing key, an empty set), the two next-set helpers that mirror
 * the slice's toggle and remove reducers, and the imperative half over
 * the real store and edit session: with no engine or no open edit every
 * write lands; with an edit open the engine's canEndEdit decides, a
 * refusal writes nothing and returns false, a yes commits the edit
 * through endEdit and the write lands, and a write that stays on the
 * editing key never asks.
 *
 * Run with `node --test tests/js/select-door.test.mjs` from the module
 * root. The resolve hook lets the door's extensionless source imports
 * load under Node; the globals are what the store and the edit session
 * touch at import and boot (the window bridge, the click listener, the
 * hotkey labels), the same stubs picker.test.mjs carries.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { registerHooks } from 'node:module';
import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

registerHooks({
  resolve(specifier, context, nextResolve) {
    try {
      return nextResolve(specifier, context);
    } catch (error) {
      if (
        (specifier.startsWith('.') || specifier.startsWith('/')) &&
        existsSync(fileURLToPath(new URL(`${specifier}.js`, context.parentURL)))
      ) {
        return nextResolve(`${specifier}.js`, context);
      }
      throw error;
    }
  },
});

globalThis.window = globalThis;
globalThis.addEventListener = () => {};
globalThis.document = {
  addEventListener: () => {},
  querySelector: () => null,
  activeElement: null,
  documentElement: { addEventListener: () => {} },
};
globalThis.Element = class Element {};
globalThis.drupalSettings = {};
globalThis.Drupal = { t: (string) => string };

const { default: store } = await import('../../js/edit_mode/store/store.js');
const { initEditSession } = await import(
  '../../js/edit_mode/edit_session/edit-session.js'
);
const {
  leaveDecision,
  toggledSet,
  removedSet,
  selectKey,
  selectKeys,
  toggleKey,
  removeKey,
  clearKeys,
  STAY,
  LEAVE,
} = await import('../../js/edit_mode/selection/select-door.js');

const EDITED = 'block:aaaaaaaa-1111-2222-3333-444444444444';
const OTHER = 'block:bbbbbbbb-1111-2222-3333-444444444444';
const THIRD = 'container:cccccccc-1111-2222-3333-444444444444';

const selection = () => store.getState().selection;

// ---------------------------------------------------------------------------
// The pure decision
// ---------------------------------------------------------------------------

test('the two decisions are the strings the door compares against', () => {
  assert.equal(STAY, 'stay');
  assert.equal(LEAVE, 'leave');
});

test('no open edit: every write stays, whatever it lands on', () => {
  assert.equal(leaveDecision({ editingKey: null, nextKeys: [OTHER] }), STAY);
  assert.equal(leaveDecision({ editingKey: null, nextKeys: [OTHER, THIRD] }), STAY);
  assert.equal(leaveDecision({ editingKey: null, nextKeys: [] }), STAY);
  assert.equal(leaveDecision({ nextKeys: [OTHER] }), STAY);
  assert.equal(leaveDecision(), STAY);
});

test('the same single key: re-selecting the edited element stays', () => {
  assert.equal(leaveDecision({ editingKey: EDITED, nextKeys: [EDITED] }), STAY);
  // Duplicates collapse the way setSelectedSet collapses them.
  assert.equal(leaveDecision({ editingKey: EDITED, nextKeys: [EDITED, EDITED] }), STAY);
  // A null member is no member (setSelected(null) is the empty set, not a key).
  assert.equal(leaveDecision({ editingKey: EDITED, nextKeys: [EDITED, null] }), STAY);
});

test('a different primary: selecting another element leaves', () => {
  assert.equal(leaveDecision({ editingKey: EDITED, nextKeys: [OTHER] }), LEAVE);
  assert.equal(leaveDecision({ editingKey: EDITED, nextKeys: [THIRD] }), LEAVE);
});

test('a set containing the editing key leaves: no edit rides a multi-select', () => {
  assert.equal(leaveDecision({ editingKey: EDITED, nextKeys: [EDITED, OTHER] }), LEAVE);
  assert.equal(leaveDecision({ editingKey: EDITED, nextKeys: [OTHER, EDITED] }), LEAVE);
  assert.equal(leaveDecision({ editingKey: EDITED, nextKeys: [OTHER, THIRD] }), LEAVE);
});

test('an empty set leaves: a clear ends the edit the same as a move', () => {
  assert.equal(leaveDecision({ editingKey: EDITED, nextKeys: [] }), LEAVE);
  assert.equal(leaveDecision({ editingKey: EDITED, nextKeys: [null] }), LEAVE);
  assert.equal(leaveDecision({ editingKey: EDITED }), LEAVE);
});

test('toggledSet mirrors toggleSelected: a non-member joins, a member leaves, a falsy key is a no-op', () => {
  assert.deepEqual(toggledSet([EDITED], OTHER), [EDITED, OTHER]);
  assert.deepEqual(toggledSet([EDITED, OTHER], OTHER), [EDITED]);
  assert.deepEqual(toggledSet([EDITED], EDITED), []);
  assert.deepEqual(toggledSet([EDITED], null), [EDITED]);
  assert.deepEqual(toggledSet([], OTHER), [OTHER]);
  // The input is never mutated: the reducer stays the one writer.
  const keys = [EDITED];
  toggledSet(keys, OTHER);
  assert.deepEqual(keys, [EDITED]);
});

test('removedSet mirrors removeSelected: a member leaves, a non-member is a no-op', () => {
  assert.deepEqual(removedSet([EDITED, OTHER], OTHER), [EDITED]);
  assert.deepEqual(removedSet([EDITED], EDITED), []);
  assert.deepEqual(removedSet([EDITED], OTHER), [EDITED]);
  assert.deepEqual(removedSet([], OTHER), []);
  const keys = [EDITED, OTHER];
  removedSet(keys, OTHER);
  assert.deepEqual(keys, [EDITED, OTHER]);
});

test('the helpers feed the decision: toggling in a second key or the edited key off leaves, a no-op toggle or remove stays', () => {
  const current = [EDITED];
  assert.equal(leaveDecision({ editingKey: EDITED, nextKeys: toggledSet(current, OTHER) }), LEAVE);
  assert.equal(leaveDecision({ editingKey: EDITED, nextKeys: toggledSet(current, EDITED) }), LEAVE);
  assert.equal(leaveDecision({ editingKey: EDITED, nextKeys: toggledSet(current, null) }), STAY);
  assert.equal(leaveDecision({ editingKey: EDITED, nextKeys: removedSet(current, EDITED) }), LEAVE);
  assert.equal(leaveDecision({ editingKey: EDITED, nextKeys: removedSet(current, OTHER) }), STAY);
});

// ---------------------------------------------------------------------------
// The writes, with no engine registered
// ---------------------------------------------------------------------------

test('with no engine every write lands and returns true', () => {
  assert.equal(selectKey(OTHER), true);
  assert.equal(selection().selectedKey, OTHER);
  assert.equal(selectKeys([OTHER, THIRD], OTHER), true);
  assert.deepEqual(selection().selectedKeys, [OTHER, THIRD]);
  assert.equal(selection().selectedKey, OTHER);
  assert.equal(toggleKey(EDITED), true);
  assert.deepEqual(selection().selectedKeys, [OTHER, THIRD, EDITED]);
  assert.equal(removeKey(THIRD), true);
  assert.deepEqual(selection().selectedKeys, [OTHER, EDITED]);
  assert.equal(clearKeys(), true);
  assert.deepEqual(selection().selectedKeys, []);
  assert.equal(selection().selectedKey, null);
  assert.equal(selectKey(null), true);
  assert.equal(selection().selectedKey, null);
});

// ---------------------------------------------------------------------------
// The writes against a registered engine
// ---------------------------------------------------------------------------

/**
 * A stub engine: the contract's members over three facts the cases set
 * (editing, the canEndEdit answer, whether endEdit rejects). endEdit
 * reports through the real editChanged seam the way an engine's setter
 * does, so the store's editingKey follows the stub's edit as it follows a
 * real one.
 */
const stub = {
  editing: false,
  answer: true,
  rejects: false,
  asked: 0,
  ended: [],
  items: () => ({ canvas: [], panel: [] }),
  beginEdit: () => Promise.resolve(),
  endEdit: (options) => {
    stub.ended.push(options);
    if (stub.rejects) {
      return Promise.reject(new Error('HTML validation failed'));
    }
    stub.editing = false;
    Drupal.Daedalus.EditSession.editChanged(null);
    return Promise.resolve();
  },
  canEndEdit: () => {
    stub.asked += 1;
    return stub.answer;
  },
  isEditing: () => stub.editing,
  currentItem: () => null,
  inputs: () => [],
  keepsEdit: () => false,
  mutationApplied: () => undefined,
  presentSubject: () => Promise.resolve(false),
};

/** Opens the stub's edit on the EDITED key, selected first as every door does. */
const openEdit = ({ answer = true, rejects = false } = {}) => {
  stub.answer = answer;
  stub.rejects = rejects;
  stub.asked = 0;
  stub.ended = [];
  assert.equal(selectKey(EDITED), true);
  stub.editing = true;
  Drupal.Daedalus.EditSession.editChanged({}, EDITED);
  assert.equal(selection().editingKey, EDITED);
};

initEditSession();

test('registerEngine requires canEndEdit beside the other members', () => {
  const { canEndEdit, ...without } = stub;
  assert.throws(
    () => Drupal.Daedalus.EditSession.registerEngine(without),
    /Invalid edit session engine/,
  );
  Drupal.Daedalus.EditSession.registerEngine(stub);
});

test('no edit open: the engine is never asked and the write lands', () => {
  assert.equal(selectKey(OTHER), true);
  assert.equal(selection().selectedKey, OTHER);
  assert.equal(stub.asked, 0);
  assert.deepEqual(stub.ended, []);
});

test('a write staying on the editing key never asks: styling the edited element moves nothing', () => {
  openEdit();
  assert.equal(selectKey(EDITED), true);
  assert.equal(selectKeys([EDITED], EDITED), true);
  assert.equal(toggleKey(null), true);
  assert.equal(removeKey(OTHER), true);
  assert.equal(stub.asked, 0);
  assert.deepEqual(stub.ended, []);
  assert.equal(selection().selectedKey, EDITED);
  assert.equal(selection().editingKey, EDITED);
});

test('the edit refuses: every leaving write returns false and writes nothing', () => {
  openEdit({ answer: false });
  assert.equal(selectKey(OTHER), false);
  assert.equal(selectKey(null), false);
  assert.equal(selectKeys([EDITED, OTHER], OTHER), false);
  assert.equal(selectKeys([OTHER, THIRD]), false);
  assert.equal(toggleKey(OTHER), false);
  assert.equal(toggleKey(EDITED), false);
  assert.equal(removeKey(EDITED), false);
  assert.equal(clearKeys(), false);
  assert.equal(stub.asked, 8);
  assert.deepEqual(stub.ended, []);
  assert.equal(selection().selectedKey, EDITED);
  assert.deepEqual(selection().selectedKeys, [EDITED]);
  assert.equal(selection().editingKey, EDITED);
});

test('the edit may end: selecting another key commits the edit, then the selection moves', () => {
  openEdit();
  assert.equal(selectKey(OTHER), true);
  assert.equal(stub.asked, 1);
  assert.deepEqual(stub.ended, [{ cancel: false }]);
  assert.equal(selection().selectedKey, OTHER);
  assert.equal(selection().editingKey, null);
  // The edit is over: the next write asks nothing.
  assert.equal(selectKey(THIRD), true);
  assert.equal(stub.asked, 1);
});

test('the edit may end: a set, a toggle, a remove and a clear each commit it the same way', () => {
  openEdit();
  assert.equal(selectKeys([EDITED, OTHER], EDITED), true);
  assert.deepEqual(stub.ended, [{ cancel: false }]);
  assert.deepEqual(selection().selectedKeys, [EDITED, OTHER]);
  assert.equal(selection().selectedKey, EDITED);
  assert.equal(selection().editingKey, null);

  openEdit();
  assert.equal(toggleKey(OTHER), true);
  assert.deepEqual(stub.ended, [{ cancel: false }]);
  assert.deepEqual(selection().selectedKeys, [EDITED, OTHER]);
  assert.equal(selection().editingKey, null);

  openEdit();
  assert.equal(removeKey(EDITED), true);
  assert.deepEqual(stub.ended, [{ cancel: false }]);
  assert.deepEqual(selection().selectedKeys, []);
  assert.equal(selection().editingKey, null);

  openEdit();
  assert.equal(clearKeys(), true);
  assert.deepEqual(stub.ended, [{ cancel: false }]);
  assert.equal(selection().selectedKey, null);
  assert.equal(selection().editingKey, null);
});

test('endEdit rejecting after canEndEdit said yes is logged, and the write has already landed', async () => {
  openEdit({ rejects: true });
  const logged = [];
  const { error } = console;
  console.error = (...args) => logged.push(args);
  try {
    assert.equal(selectKey(OTHER), true);
    assert.equal(selection().selectedKey, OTHER);
    await Promise.resolve();
    await Promise.resolve();
  } finally {
    console.error = error;
  }
  assert.equal(logged.length, 1);
  assert.match(logged[0][0], /Cannot end the edit/);
  assert.equal(logged[0][1].message, 'HTML validation failed');
  stub.editing = false;
  Drupal.Daedalus.EditSession.editChanged(null);
});
