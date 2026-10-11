/**
 * Self-asserting tests for the inspector's place-state model
 * (surface-state.js): the two-place vocabulary (styles, materials), the
 * mode-keyed selection-move rule, and the session-anchoring reads.
 *
 * Run directly (`node tests/js/surface-state.test.mjs`) or via
 * PrinterEquivalenceTest, which executes this file inside the PHPUnit
 * suite. Exits non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import test from 'node:test';
import {
  PLACES,
  normalizePlace,
  reanchorSession,
  selectionMove,
  sessionFocuses,
  wearsDocument,
} from '../../js/src/surface-state.js';
import { skinSession, stageLine, updateSession, saveSession } from '../../js/src/pending.js';

test('the vocabulary is the styling place and the materials catalog', () => {
  assert.deepEqual(PLACES, ['styles', 'materials']);
});

test('normalizePlace lands junk and retired values on styles', () => {
  assert.equal(normalizePlace('styles'), 'styles');
  assert.equal(normalizePlace('materials'), 'materials');
  // The retired values are out of the vocabulary: the deleted pill row's
  // 'element' / 'library', the 'variants' document surface (now the
  // editing session, not a place) and the code-only surfaces normalize
  // home like any unknown, silently retiring stale stored/broadcast
  // values.
  assert.equal(normalizePlace('element'), 'styles');
  assert.equal(normalizePlace('library'), 'styles');
  assert.equal(normalizePlace('variants'), 'styles');
  assert.equal(normalizePlace('children'), 'styles');
  assert.equal(normalizePlace('page'), 'styles');
  assert.equal(normalizePlace('bogus'), 'styles');
  assert.equal(normalizePlace(null), 'styles');
});

test('wearsDocument matches the document reference verbatim on the base entry', () => {
  const entry = { variants: [{ variant: 'cta', fragment: '' }] };
  const fragmentEntry = { variants: [{ variant: 'cta', fragment: 'block(text,1)' }] };
  assert.equal(wearsDocument(entry, 'cta'), true);
  // A distributed fragment wearing wears the document too.
  assert.equal(wearsDocument(fragmentEntry, 'cta'), true);
  // A reference is the record id: a retired uuid spelling never matches.
  assert.equal(wearsDocument({ variants: [{ variant: '00000000-0000-0000-0000-000000000001', fragment: '' }] }, 'cta'), false);
  assert.equal(wearsDocument(entry, 'other'), false);
  assert.equal(wearsDocument(null, 'cta'), false);
  assert.equal(wearsDocument(entry, null), false);
});

test('selection move: with no editing session the move passes through whole', () => {
  for (const place of ['styles', 'materials']) {
    assert.deepEqual(
      selectionMove({ place, editing: null }, { entry: null, pending: 9 }),
      { place, editing: null, prompt: false },
    );
  }
  // A draft (creating posture) keeps editing null while its session
  // holds the panel: moves pass through here — the draft's own subtree
  // rule (its holder's business) decides when it leaves.
  assert.deepEqual(
    selectionMove({ place: 'styles' }, { entry: null, pending: 4 }),
    { place: 'styles', editing: null, prompt: false },
  );
});

test('selection move: the session survives when the new selection wears the document', () => {
  const entry = { variants: [{ variant: 'cta', fragment: '' }] };
  assert.deepEqual(
    selectionMove({ place: 'styles', editing: 'cta' }, { entry, pending: 3 }),
    { place: 'styles', editing: 'cta', prompt: false },
  );
  // The place beneath the mode is untouched wherever it was.
  assert.deepEqual(
    selectionMove({ place: 'materials', editing: 'cta' }, { entry, pending: 3 }),
    { place: 'materials', editing: 'cta', prompt: false },
  );
});

test('selection move: a non-wearer exits the session, prompting when pending', () => {
  const bare = { default: { padding: '1rem' } };
  assert.deepEqual(
    selectionMove({ place: 'styles', editing: 'cta' }, { entry: bare, pending: 0 }),
    { place: 'styles', editing: null, prompt: false },
  );
  // Pending work would be left behind: the existing leave-prompt fires.
  assert.deepEqual(
    selectionMove({ place: 'styles', editing: 'cta' }, { entry: bare, pending: 2 }),
    { place: 'styles', editing: null, prompt: true },
  );
  // Exiting the mode never moves the place beneath it.
  assert.deepEqual(
    selectionMove({ place: 'materials', editing: 'cta' }, { entry: bare, pending: 2 }),
    { place: 'materials', editing: null, prompt: true },
  );
});

test('reanchorSession moves only an update session\'s preview anchor', () => {
  let session = updateSession('cta', 'block:b1');
  session = stageLine(session, { relative: '', condition: 'default', property: 'gap', value: '2rem' });
  const moved = reanchorSession(session, 'block:b2');
  assert.equal(moved.anchor, 'block:b2');
  // The staged lines are variant-relative and survive verbatim.
  assert.deepEqual(moved.lines, session.lines);
  // A rule wearing the document anchors the same way (a target move).
  assert.equal(reanchorSession(session, 'container:c1::a').anchor, 'container:c1::a');
  // A no-op move returns the same object so subscribers can skip.
  assert.equal(reanchorSession(session, 'block:b1'), session);
  assert.equal(reanchorSession(session, ''), session);
  assert.equal(reanchorSession(session, null), session);
  // A save session's anchor is its capture root: never re-anchors.
  const save = saveSession('block:b1');
  assert.equal(reanchorSession(save, 'block:b2'), save);
  assert.equal(reanchorSession(null, 'block:b2'), null);
  // A skin session re-anchors like an update session (its lines are
  // scope-relative; only the previewing element travels).
  const skin = skinSession('base:h2', 'block:b1');
  assert.equal(reanchorSession(skin, 'block:b2').anchor, 'block:b2');
  assert.equal(reanchorSession(skin, 'block:b1'), skin);
});

test('a skin document is worn by every subject whose stack reads its scope: the move rule and the wearing read key on the skin scopes', () => {
  const bare = { default: { padding: '1rem' } };
  assert.equal(wearsDocument(bare, 'skin:base:h2', ['base:h2', 'kind:block', 'block_type:text']), true);
  assert.equal(wearsDocument(bare, 'skin:base:h2', ['base:p', 'kind:block']), false);
  assert.equal(wearsDocument(bare, 'skin:base:h2'), false);
  // A variant reference never reads the scopes.
  assert.equal(wearsDocument(bare, 'cta', ['base:h2']), false);
  assert.deepEqual(
    selectionMove({ place: 'styles', editing: 'skin:base:h2' }, { entry: bare, pending: 2, skinScopes: ['base:h2', 'kind:block'] }),
    { place: 'styles', editing: 'skin:base:h2', prompt: false },
  );
  assert.deepEqual(
    selectionMove({ place: 'styles', editing: 'skin:base:h2' }, { entry: bare, pending: 2, skinScopes: ['base:p', 'kind:block'] }),
    { place: 'styles', editing: null, prompt: true },
  );
  // One session per document: a skin session focuses its skin document.
  assert.equal(sessionFocuses(skinSession('base:h2', 'block:b1'), 'skin:base:h2'), true);
  assert.equal(sessionFocuses(skinSession('base:h2', 'block:b1'), 'skin:base:p'), false);
  assert.equal(sessionFocuses(skinSession('base:h2', 'block:b1'), 'cta'), false);
});

test('sessionFocuses ties the open update session to the edited document', () => {
  const session = updateSession('cta', 'block:b1');
  assert.equal(sessionFocuses(session, 'cta'), true);
  // One identity: the session holds the record id every doorway speaks.
  assert.equal(sessionFocuses(session, '00000000-0000-0000-0000-000000000001'), false);
  assert.equal(sessionFocuses(session, 'other'), false);
  assert.equal(sessionFocuses(session, null), false);
  // Save sessions never match: a draft document is panel state with no
  // edited document behind it.
  assert.equal(sessionFocuses(saveSession('block:b1'), 'cta'), false);
  assert.equal(sessionFocuses(null, 'cta'), false);
});
