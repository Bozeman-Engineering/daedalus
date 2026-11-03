/**
 * Self-asserting tests for the pending/push engine's model.
 *
 * Run directly (`node tests/js/pending.test.mjs`) or via
 * PrinterEquivalenceTest, which executes this file inside the PHPUnit
 * suite. Locks the payload row/pruning/summary machinery (inherited from
 * the retired staging surface) and the session contracts: save-as-variant
 * re-expressed as pending lines, and the per-document update session whose
 * staged lines Push merges into a master-push payload. Exits non-zero on
 * the first failure with a readable diff.
 */
import { strict as assert } from 'node:assert';
import {
  dismissLine,
  movedStyles,
  payloadRows,
  payloadSummary,
  pendingLineId,
  pendingMoveId,
  pendingRefId,
  prunedPayload,
  restoreLine,
  saveSession,
  sessionCount,
  sessionStyles,
  stageIslandLines,
  stageLine,
  stageMove,
  stageValue,
  skinPushOperations,
  skinSession,
  stagesLines,
  updateSession,
} from '../../js/src/pending.js';

let current = null;
const test = (name, run) => {
  current = name;
  run();
  process.stdout.write(`ok - ${name}\n`);
};

process.on('uncaughtException', (error) => {
  process.stderr.write(`FAIL - ${current}\n${error.stack ?? error.message}\n`);
  process.exit(1);
});

// A saveStyles()-shaped payload: root values + a part, children with
// values and a worn reference, deliberately listed children-first (the
// capture walk's direct-before-own key order).
const payload = {
  'container(1)/block(text,2)': {
    variants: [{ variant: 'card_left', fragment: '' }],
    default: { padding: '0px', 'text-align': 'center' },
    narrow: { 'font-size': '0.875rem' },
  },
  'container(1)/block(text,1)': {
    default: { margin: '0px' },
  },
  'container(1)': {
    default: { gap: '1rem' },
  },
  '::.quote': {
    default: { color: '#1a1a2e' },
  },
  '': {
    default: { display: 'grid', 'justify-items': 'center' },
  },
};

// --- payloadRows: render order, ids, reference rows -------------------------

test('rows order root first, then parts, then children shallow-first in slot order', () => {
  const rows = payloadRows(payload, 'container');
  assert.deepEqual(rows.map((group) => group.relative), [
    '',
    '::.quote',
    'container(1)',
    'container(1)/block(text,1)',
    'container(1)/block(text,2)',
  ]);
});

test('a group carries its reference rows and its value lines with stable ids', () => {
  const rows = payloadRows(payload, 'container');
  const child = rows.find((group) => group.relative === 'container(1)/block(text,2)');
  assert.deepEqual(child.refs, [
    { variant: 'card_left', id: pendingRefId('container(1)/block(text,2)', 'card_left') },
  ]);
  assert.deepEqual(child.lines, [
    { condition: 'default', property: 'padding', value: '0px', id: pendingLineId('container(1)/block(text,2)', 'default', 'padding') },
    { condition: 'default', property: 'text-align', value: 'center', id: pendingLineId('container(1)/block(text,2)', 'default', 'text-align') },
    { condition: 'narrow', property: 'font-size', value: '0.875rem', id: pendingLineId('container(1)/block(text,2)', 'narrow', 'font-size') },
  ]);
  assert.equal(child.kind, 'block');
  assert.equal(child.detail, 'text');
});

test('the default condition renders before named conditions regardless of stored order', () => {
  const rows = payloadRows({ '': { narrow: { gap: '0' }, default: { display: 'grid' } } }, 'container');
  assert.deepEqual(rows[0].lines.map((line) => line.condition), ['default', 'narrow']);
});

test('empty entries drop and an unknown root kind degrades to payload order for path keys', () => {
  const rows = payloadRows({ '': {}, 'container(1)': { default: { gap: '0' } }, 'container(2)': { default: { gap: '1px' } } }, null);
  assert.deepEqual(rows.map((group) => group.relative), ['container(1)', 'container(2)']);
});

// --- prunedPayload: what a save session commits -----------------------------

test('an empty dismissal set commits the payload verbatim', () => {
  assert.deepEqual(prunedPayload(payload, new Set()), payload);
});

test('dismissed value rows drop their property; emptied conditions and entries drop whole', () => {
  const pruned = prunedPayload(payload, new Set([
    pendingLineId('container(1)/block(text,2)', 'narrow', 'font-size'),
    pendingLineId('container(1)/block(text,1)', 'default', 'margin'),
  ]));
  assert.equal(pruned['container(1)/block(text,2)'].narrow, undefined);
  assert.deepEqual(pruned['container(1)/block(text,2)'].default, { padding: '0px', 'text-align': 'center' });
  assert.equal(pruned['container(1)/block(text,1)'], undefined);
});

test('a dismissed reference row drops the ref and an emptied variants list drops', () => {
  const pruned = prunedPayload(payload, new Set([
    pendingRefId('container(1)/block(text,2)', 'card_left'),
  ]));
  assert.equal(pruned['container(1)/block(text,2)'].variants, undefined);
  assert.deepEqual(pruned['container(1)/block(text,2)'].default, { padding: '0px', 'text-align': 'center' });
});

test('a ref-only entry dismissed whole drops entirely', () => {
  const refOnly = { 'container(1)': { variants: [{ variant: 'split_50_50', fragment: '' }] } };
  assert.deepEqual(prunedPayload(refOnly, new Set([pendingRefId('container(1)', 'split_50_50')])), {});
});

test('pruning never mutates the input payload', () => {
  const before = JSON.stringify(payload);
  prunedPayload(payload, new Set([pendingRefId('container(1)/block(text,2)', 'card_left')]));
  assert.equal(JSON.stringify(payload), before);
});

// --- payloadSummary: the bar's numbers --------------------------------------

test('the summary counts value and reference rows, pending over total', () => {
  assert.deepEqual(payloadSummary(payload, new Set()), { total: 9, included: 9 });
  assert.deepEqual(payloadSummary(payload, new Set([
    pendingRefId('container(1)/block(text,2)', 'card_left'),
    pendingLineId('', 'default', 'display'),
  ])), { total: 9, included: 7 });
});

// --- save sessions: the capture as pending lines ----------------------------

test('a fresh save session is anchored, unnamed, and dismisses nothing', () => {
  const session = saveSession('block:abc', 'style');
  // Born linked: mint-time ownership consent holds until the header
  // chip's Unlink flips it (session-frame-model.js mintConsent).
  // Born offered to the AI: the default every material has; the push bar
  // sends the key only when the draft is off (ai-offer.js).
  assert.deepEqual(session, { kind: 'save', anchor: 'block:abc', label: '', description: '', offered: true, axis: 'style', dismissed: [], linked: true });
  assert.equal(sessionCount(session, payload), 9);
  assert.deepEqual(sessionStyles(session, { capture: payload }), payload);
});

test('a save session carries the draft\'s description beside its name (the roster face the push bar mints)', () => {
  const session = saveSession('block:abc');
  assert.equal(session.description, '');
  // The frame's inputs write the identity by spread, like the name; the
  // pending set (the capture) and the dismissals are untouched by it.
  const named = { ...session, label: 'Hero', description: 'The landing hero, one per page.' };
  assert.equal(named.description, 'The landing hero, one per page.');
  assert.equal(sessionCount(named, payload), 9);
  assert.deepEqual(sessionStyles(named, { capture: payload }), payload);
});

test('dismissing capture rows prunes exactly like the retired checkbox surface', () => {
  let session = saveSession('block:abc');
  session = dismissLine(session, pendingLineId('container(1)/block(text,1)', 'default', 'margin'));
  session = dismissLine(session, pendingRefId('container(1)/block(text,2)', 'card_left'));
  // Dismissal is idempotent.
  session = dismissLine(session, pendingRefId('container(1)/block(text,2)', 'card_left'));
  assert.equal(sessionCount(session, payload), 7);
  assert.deepEqual(
    sessionStyles(session, { capture: payload }),
    prunedPayload(payload, session.dismissed),
  );
});

test('staged lines pass through a save session untouched (the capture IS its pending set)', () => {
  const session = saveSession('block:abc');
  assert.equal(stageLine(session, { relative: '', condition: 'default', property: 'gap', value: '1rem' }), session);
});

// --- update sessions: the per-document pending edit store -------------------

const variantStyles = {
  '': {
    default: { display: 'grid', gap: '16px' },
    dark: { background: 'black' },
  },
  '::.inner': {
    default: { padding: '1rem' },
  },
  'container(1)': {
    variants: [{ variant: 'nested', fragment: '' }],
    default: { margin: '0' },
  },
};

test('staging upserts by row id: a re-staged property replaces its pending value', () => {
  let session = updateSession('cta', 'block:abc');
  session = stageLine(session, { relative: '', condition: 'default', property: 'gap', value: '24px' });
  session = stageLine(session, { relative: '', condition: 'default', property: 'gap', value: '32px' });
  assert.equal(sessionCount(session), 1);
  assert.deepEqual(session.lines, [{
    id: pendingLineId('', 'default', 'gap'),
    relative: '',
    condition: 'default',
    property: 'gap',
    value: '32px',
  }]);
});

test('dismissing an update line drops the staged edit', () => {
  let session = updateSession('cta', 'block:abc');
  session = stageLine(session, { relative: '', condition: 'default', property: 'gap', value: '24px' });
  session = stageLine(session, { relative: '', condition: 'dark', property: 'background', value: null });
  session = dismissLine(session, pendingLineId('', 'default', 'gap'));
  assert.equal(sessionCount(session), 1);
  assert.deepEqual(session.lines.map((line) => line.property), ['background']);
});

test('stageIslandLines replaces one island\'s lines and spares the rest', () => {
  let session = updateSession('cta', 'block:abc');
  session = stageLine(session, { relative: '', condition: 'default', property: 'gap', value: '24px' });
  session = stageLine(session, { relative: '::.inner', condition: 'default', property: 'color', value: 'red' });
  session = stageIslandLines(session, '', 'default', [
    { property: 'padding', value: '2rem' },
    { property: 'margin', value: null },
  ]);
  // The island's old gap line dropped (re-typed back to baseline); the
  // other island's staged line survives untouched.
  assert.deepEqual(session.lines.map((line) => [line.relative, line.property, line.value]), [
    ['::.inner', 'color', 'red'],
    ['', 'padding', '2rem'],
    ['', 'margin', null],
  ]);
  assert.equal(session.lines[1].id, pendingLineId('', 'default', 'padding'));
  // An empty replacement clears the island.
  const cleared = stageIslandLines(session, '', 'default', []);
  assert.deepEqual(cleared.lines.map((line) => line.property), ['color']);
  // A save session passes through untouched.
  const save = saveSession('block:abc');
  assert.equal(stageIslandLines(save, '', 'default', [{ property: 'gap', value: '0' }]), save);
});

test('stageValue: a set differing from the stored baseline stages', () => {
  const styles = { '::.inner': { default: { gap: '16px' } } };
  let session = updateSession('cta', 'block:abc');
  session = stageValue(session, styles, '::.inner', 'default', 'gap', '24px');
  assert.deepEqual(session.lines.map((line) => [line.relative, line.property, line.value]), [
    ['::.inner', 'gap', '24px'],
  ]);
  assert.equal(session.lines[0].id, pendingLineId('::.inner', 'default', 'gap'));
  // A property the variant does not store stages as a plain add.
  session = stageValue(session, styles, '::.inner', 'default', 'color', ' teal ');
  assert.deepEqual(session.lines[1], {
    id: pendingLineId('::.inner', 'default', 'color'),
    relative: '::.inner',
    condition: 'default',
    property: 'color',
    value: 'teal',
  });
});

test('stageValue: empty/null where the variant stores a value stages an unset', () => {
  const styles = { '': { default: { gap: '16px' } } };
  const byNull = stageValue(updateSession('cta', 'block:abc'), styles, '', 'default', 'gap', null);
  assert.deepEqual(byNull.lines.map((line) => line.value), [null]);
  const byEmpty = stageValue(updateSession('cta', 'block:abc'), styles, '', 'default', 'gap', '  ');
  assert.deepEqual(byEmpty.lines.map((line) => line.value), [null]);
});

test('stageValue: settling back on the baseline DISMISSES the staged line', () => {
  const styles = { '': { default: { gap: '16px' } } };
  let session = updateSession('cta', 'block:abc');
  session = stageValue(session, styles, '', 'default', 'gap', '24px');
  assert.equal(session.lines.length, 1);
  // The revert unstages (re-typing a code line back to baseline's twin).
  session = stageValue(session, styles, '', 'default', 'gap', '16px');
  assert.equal(session.lines.length, 0);
  // Clearing a property the variant never stored stages nothing either.
  session = stageValue(session, styles, '', 'default', 'color', null);
  assert.equal(session.lines.length, 0);
  // A save session passes through untouched.
  const save = saveSession('block:abc');
  assert.equal(stageValue(save, styles, '', 'default', 'gap', '24px'), save);
});

test('a no-change replacement returns the SAME session object', () => {
  let session = updateSession('cta', 'block:abc');
  session = stageIslandLines(session, '', 'default', [{ property: 'gap', value: '24px' }]);
  const same = stageIslandLines(session, '', 'default', [{ property: 'gap', value: '24px' }]);
  assert.equal(same, session);
  const other = stageIslandLines(session, '', 'default', [{ property: 'gap', value: '32px' }]);
  assert.notEqual(other, session);
});

test('restoreLine un-dismisses a save row; update sessions pass through', () => {
  let save = saveSession('block:abc');
  save = dismissLine(save, pendingLineId('', 'default', 'gap'));
  save = dismissLine(save, pendingRefId('', 'cta'));
  const restored = restoreLine(save, pendingLineId('', 'default', 'gap'));
  assert.deepEqual(restored.dismissed, [pendingRefId('', 'cta')]);
  // An id not dismissed returns the same object.
  assert.equal(restoreLine(restored, pendingLineId('', 'default', 'gap')), restored);
  let update = updateSession('cta', 'block:abc');
  update = stageLine(update, { relative: '', condition: 'default', property: 'gap', value: '1rem' });
  assert.equal(restoreLine(update, pendingLineId('', 'default', 'gap')), update);
});

test('the push payload merges sets over the variant styles; untouched facts ride verbatim', () => {
  let session = updateSession('cta', 'block:abc');
  session = stageLine(session, { relative: '', condition: 'default', property: 'gap', value: '24px' });
  session = stageLine(session, { relative: '::.inner', condition: 'narrow', property: 'padding', value: '0.5rem' });
  const merged = sessionStyles(session, { documentStyles: variantStyles });
  assert.deepEqual(merged[''], {
    default: { display: 'grid', gap: '24px' },
    dark: { background: 'black' },
  });
  assert.deepEqual(merged['::.inner'], {
    default: { padding: '1rem' },
    narrow: { padding: '0.5rem' },
  });
  // The nested reference list and its entry survive untouched.
  assert.deepEqual(merged['container(1)'], variantStyles['container(1)']);
});

test('a null value is a pending unset: the property leaves and emptied shells drop', () => {
  let session = updateSession('cta', 'block:abc');
  session = stageLine(session, { relative: '', condition: 'dark', property: 'background', value: null });
  session = stageLine(session, { relative: '::.inner', condition: 'default', property: 'padding', value: null });
  const merged = sessionStyles(session, { documentStyles: variantStyles });
  // The emptied dark block drops; the default block survives.
  assert.deepEqual(merged[''], { default: { display: 'grid', gap: '16px' } });
  // The part entry emptied entirely drops.
  assert.equal(merged['::.inner'], undefined);
});

test('an unset that empties conditions never drops an entry still carrying references', () => {
  let session = updateSession('cta', 'block:abc');
  session = stageLine(session, { relative: 'container(1)', condition: 'default', property: 'margin', value: null });
  const merged = sessionStyles(session, { documentStyles: variantStyles });
  assert.deepEqual(merged['container(1)'], { variants: [{ variant: 'nested', fragment: '' }] });
});

test('a set landing where the variant stores nothing mints the entry and condition', () => {
  let session = updateSession('cta', 'block:abc');
  session = stageLine(session, { relative: 'container(1)/block(text,1)', condition: 'default', property: 'color', value: 'red' });
  const merged = sessionStyles(session, { documentStyles: variantStyles });
  assert.deepEqual(merged['container(1)/block(text,1)'], { default: { color: 'red' } });
});

test('merging never mutates the variant styles or the session', () => {
  const before = JSON.stringify(variantStyles);
  let session = updateSession('cta', 'block:abc');
  session = stageLine(session, { relative: '', condition: 'default', property: 'gap', value: null });
  const snapshot = JSON.stringify(session);
  sessionStyles(session, { documentStyles: variantStyles });
  assert.equal(JSON.stringify(variantStyles), before);
  assert.equal(JSON.stringify(session), snapshot);
});

// --- the skin session: the update session's site-scope twin -----------------

test('a fresh skin session names its scope and its skin document reference, and stages lines exactly as an update session does', () => {
  const session = skinSession('base:h2', 'block:abc');
  assert.deepEqual(session, { kind: 'skin', reference: 'skin:base:h2', scope: 'base:h2', anchor: 'block:abc', lines: [], moves: [] });
  assert.equal(stagesLines(session), true);
  assert.equal(stagesLines(updateSession('cta', 'block:abc')), true);
  assert.equal(stagesLines(saveSession('block:abc')), false);
  assert.equal(stagesLines(null), false);
  const defaults = { '': { default: { 'font-size': '2rem', 'font-weight': '700' } } };
  let staged = stageValue(session, defaults, '', 'default', 'font-size', '3rem');
  staged = stageValue(staged, defaults, '', 'default', 'font-weight', null);
  staged = stageValue(staged, defaults, '::strong', 'dark', 'color', 'white');
  assert.equal(sessionCount(staged), 3);
  // Settling back on the stored default dismisses, as on a variant.
  assert.equal(sessionCount(stageValue(staged, defaults, '', 'default', 'font-size', '2rem')), 2);
  assert.deepEqual(sessionStyles(staged, { documentStyles: defaults }), {
    '': { default: { 'font-size': '3rem' } },
    '::strong': { dark: { color: 'white' } },
  });
  assert.deepEqual(defaults, { '': { default: { 'font-size': '2rem', 'font-weight': '700' } } }, 'the stored defaults are never mutated');
  assert.equal(stageIslandLines(staged, '', 'default', [{ property: 'font-size', value: '4rem' }]).lines.find((line) => line.property === 'font-size').value, '4rem');
});

test('skinPushOperations spells the staged lines as one update_default per (key, condition), null unsetting', () => {
  let session = skinSession('base:h2', 'block:abc');
  session = stageLine(session, { relative: '', condition: 'default', property: 'font-size', value: '3rem' });
  session = stageLine(session, { relative: '::strong', condition: 'dark', property: 'color', value: 'white' });
  session = stageLine(session, { relative: '', condition: 'default', property: 'font-weight', value: null });
  assert.deepEqual(skinPushOperations(session), [
    { op: 'update_default', scope: 'base:h2', key: '', condition: 'default', properties: { 'font-size': '3rem', 'font-weight': null } },
    { op: 'update_default', scope: 'base:h2', key: '::strong', condition: 'dark', properties: { color: 'white' } },
  ]);
  assert.deepEqual(skinPushOperations(skinSession('kind:container', 'container:c')), []);
  assert.deepEqual(skinPushOperations(updateSession('cta', 'block:abc')), []);
});

// --- staged moves: a rule's selector edit as its own pending fact ------------

// A variant document with a rule, its family (a continuation by descent
// and the rule's own box), a nested reference on the rule, and bystanders.
const ruledStyles = {
  '': { default: { display: 'grid' } },
  '::> d-text': {
    variants: [{ variant: 'nested', fragment: '' }],
    default: { color: 'teal', 'font-weight': '700' },
    dark: { color: 'white' },
  },
  '::> d-text .link': { default: { 'text-decoration': 'underline' } },
  '::> d-text::before': { default: { content: '"*"' } },
  '::> d-text:not(:has(a))': { default: { opacity: '0.5' } },
  'block(text,1)': { default: { margin: '0' } },
  'block(text,1)::p': { default: { 'line-height': '1.4' } },
};

test('a fresh line-staging session holds no moves; stageMove stages one row with a canonical landing', () => {
  assert.deepEqual(updateSession('cta', 'block:abc').moves, []);
  assert.deepEqual(skinSession('base:h2', 'block:abc').moves, []);
  let session = updateSession('cta', 'block:abc');
  // A lenient spelling of `to` lands canonical, as the op's does.
  session = stageMove(session, '::> d-text', '>d-text:has( a )');
  assert.deepEqual(session.moves, [
    { id: pendingMoveId('::> d-text', '> d-text:has(a)'), from: '::> d-text', to: '> d-text:has(a)' },
  ]);
  assert.equal(session.moves[0].id, 'move|::> d-text|> d-text:has(a)');
  assert.equal(sessionCount(session), 1, 'a move is one pending row');
  // Re-staging the same rule replaces its row (one rule, one row).
  session = stageMove(session, '::> d-text', '> d-text:has(img)');
  assert.deepEqual(session.moves.map((move) => [move.from, move.to]), [['::> d-text', '> d-text:has(img)']]);
  // A save session passes through untouched.
  const save = saveSession('block:abc');
  assert.equal(stageMove(save, '::> d-text', '> d-text:has(a)'), save);
  // Caller bugs throw: a slot's own entry is no rule, and `to` must parse.
  assert.throws(() => stageMove(updateSession('cta', 'block:abc'), '', '> d-text'), TypeError);
  assert.throws(() => stageMove(updateSession('cta', 'block:abc'), 'block(text,1)', 'p'), TypeError);
  assert.throws(() => stageMove(updateSession('cta', 'block:abc'), '::> d-text', 'd-text >'), TypeError);
});

test('staging a move renames the staged lines it carries at once (the rule and its staged continuations), other slots untouched', () => {
  let session = updateSession('cta', 'block:abc');
  session = stageLine(session, { relative: '::> d-text', condition: 'default', property: 'color', value: 'red' });
  session = stageLine(session, { relative: '::> d-text .link', condition: 'default', property: 'color', value: 'blue' });
  session = stageLine(session, { relative: '::> d-text:not(:has(a))', condition: 'default', property: 'opacity', value: '1' });
  session = stageLine(session, { relative: 'block(text,1)::p', condition: 'default', property: 'margin', value: '0' });
  session = stageMove(session, '::> d-text', '> d-text:has(a)');
  assert.deepEqual(session.lines.map((line) => [line.relative, line.id]), [
    ['::> d-text:has(a)', pendingLineId('::> d-text:has(a)', 'default', 'color')],
    ['::> d-text:has(a) .link', pendingLineId('::> d-text:has(a) .link', 'default', 'color')],
    ['::> d-text:not(:has(a))', pendingLineId('::> d-text:not(:has(a))', 'default', 'opacity')],
    ['block(text,1)::p', pendingLineId('block(text,1)::p', 'default', 'margin')],
  ]);
  assert.equal(sessionCount(session), 5);
  // A line staged after the move lands beside them, at the new key.
  session = stageLine(session, { relative: '::> d-text:has(a)', condition: 'dark', property: 'color', value: 'pink' });
  assert.equal(session.lines.filter((line) => line.relative === '::> d-text:has(a)').length, 2);
});

test('a chain of moves on one rule collapses to one row from the original key; a move back cancels it', () => {
  let session = updateSession('cta', 'block:abc');
  session = stageLine(session, { relative: '::> d-text', condition: 'default', property: 'color', value: 'red' });
  session = stageMove(session, '::> d-text', '> d-text:has(a)');
  session = stageMove(session, '::> d-text:has(a)', '> d-text:not(:has(img))');
  assert.deepEqual(session.moves.map((move) => [move.from, move.to]), [['::> d-text', '> d-text:not(:has(img))']]);
  assert.deepEqual(session.lines.map((line) => line.relative), ['::> d-text:not(:has(img))']);
  assert.equal(sessionCount(session), 2);
  // Back onto the original selector: no move remains, the line returns.
  session = stageMove(session, '::> d-text:not(:has(img))', '> d-text');
  assert.deepEqual(session.moves, []);
  assert.deepEqual(session.lines.map((line) => [line.relative, line.id]), [
    ['::> d-text', pendingLineId('::> d-text', 'default', 'color')],
  ]);
});

test('dismissing a move drops its row and returns the lines it carried to the keys they left', () => {
  let session = updateSession('cta', 'block:abc');
  session = stageLine(session, { relative: '::> d-text', condition: 'default', property: 'color', value: 'red' });
  session = stageMove(session, '::> d-text', '> d-text:has(a)');
  // A line staged under the moved rule after the move rides back too.
  session = stageLine(session, { relative: '::> d-text:has(a) .link', condition: 'default', property: 'color', value: 'blue' });
  const [move] = session.moves;
  session = dismissLine(session, move.id);
  assert.deepEqual(session.moves, []);
  assert.deepEqual(session.lines.map((line) => line.relative), ['::> d-text', '::> d-text .link']);
  assert.equal(sessionCount(session), 2);
  // An unknown id dismisses nothing.
  assert.deepEqual(dismissLine(session, pendingMoveId('::p', 'strong')), session);
});

test('movedStyles reads the stored document through the moves: the family moves whole, keeps its position, and the read is idempotent', () => {
  let session = updateSession('cta', 'block:abc');
  session = stageMove(session, '::> d-text', '> d-text:has(a)');
  const moved = movedStyles(session, ruledStyles);
  assert.deepEqual(Object.keys(moved), [
    '',
    '::> d-text:has(a)',
    '::> d-text:has(a) .link',
    '::> d-text:has(a)::before',
    '::> d-text:not(:has(a))',
    'block(text,1)',
    'block(text,1)::p',
  ], 'the moved keys sit where the old ones did; the sibling rule and the other slot stay');
  // The rule's entry moves WHOLE: every condition and the nested reference.
  assert.deepEqual(moved['::> d-text:has(a)'], ruledStyles['::> d-text']);
  assert.deepEqual(moved['::> d-text:has(a) .link'], ruledStyles['::> d-text .link']);
  assert.deepEqual(moved['::> d-text:has(a)::before'], ruledStyles['::> d-text::before']);
  assert.deepEqual(movedStyles(session, moved), moved, 'a document the move already left reads the same');
  assert.deepEqual(movedStyles(null, ruledStyles), ruledStyles);
  assert.deepEqual(movedStyles(saveSession('block:abc'), ruledStyles), ruledStyles);
  assert.deepEqual(ruledStyles['::> d-text'].default, { color: 'teal', 'font-weight': '700' }, 'the stored document is never mutated');
  // A move in a composition child's slot moves that slot's rule alone.
  const child = stageMove(updateSession('cta', 'block:abc'), 'block(text,1)::p', 'strong');
  assert.deepEqual(Object.keys(movedStyles(child, ruledStyles)).filter((key) => key.startsWith('block')), ['block(text,1)', 'block(text,1)::strong']);
});

test('the variant push payload of one move and one line at the moved key carries the new keys only', () => {
  let session = updateSession('cta', 'block:abc');
  session = stageLine(session, { relative: '::> d-text', condition: 'default', property: 'color', value: 'red' });
  session = stageMove(session, '::> d-text', '> d-text:has(a)');
  const payload = sessionStyles(session, { documentStyles: ruledStyles });
  const oldKeys = ['::> d-text', '::> d-text .link', '::> d-text::before'];
  assert.deepEqual(Object.keys(payload).filter((key) => oldKeys.includes(key)), [], 'no old key survives');
  assert.deepEqual(payload['::> d-text:has(a)'], {
    variants: [{ variant: 'nested', fragment: '' }],
    default: { color: 'red', 'font-weight': '700' },
    dark: { color: 'white' },
  }, 'the line merged over the moved entry, the reference rode along');
  assert.deepEqual(payload['::> d-text:has(a) .link'], { default: { 'text-decoration': 'underline' } });
  assert.deepEqual(payload['::> d-text:has(a)::before'], { default: { content: '"*"' } });
  assert.deepEqual(payload['::> d-text:not(:has(a))'], { default: { opacity: '0.5' } });
  assert.deepEqual(payload['block(text,1)::p'], { default: { 'line-height': '1.4' } });
  // A line a stale caller stages at the OLD key after the move follows too.
  const stale = stageLine(session, { relative: '::> d-text', condition: 'dark', property: 'color', value: 'pink' });
  assert.deepEqual(sessionStyles(stale, { documentStyles: ruledStyles })['::> d-text:has(a)'].dark, { color: 'pink' });
  assert.equal(sessionStyles(stale, { documentStyles: ruledStyles })['::> d-text'], undefined);
});

test('a move of a lines-only draft row carries just its lines; a move onto a stored key is a collision and fails loudly', () => {
  let session = updateSession('cta', 'block:abc');
  session = stageLine(session, { relative: '::> d-image', condition: 'default', property: 'width', value: '100%' });
  session = stageMove(session, '::> d-image', '> d-image:has(img)');
  const payload = sessionStyles(session, { documentStyles: ruledStyles });
  assert.deepEqual(payload['::> d-image:has(img)'], { default: { width: '100%' } });
  assert.equal(payload['::> d-image'], undefined);
  assert.deepEqual(payload['::> d-text'], ruledStyles['::> d-text'], 'nothing else moved');
  // The sibling rule already holds the landing: the collision throws
  // instead of picking a winner (the chrome's gate refuses it first).
  const colliding = stageMove(updateSession('cta', 'block:abc'), '::> d-text', '> d-text:not(:has(a))');
  assert.throws(() => sessionStyles(colliding, { documentStyles: ruledStyles }), /target_exists at "> d-text:not\(:has\(a\)\)"/);
  assert.throws(() => movedStyles(colliding, ruledStyles), /refused/);
});

test('stageValue diffs against the moved baseline: settling the moved entry\'s own value at the new key stages nothing', () => {
  let session = updateSession('cta', 'block:abc');
  session = stageMove(session, '::> d-text', '> d-text:has(a)');
  const same = stageValue(session, ruledStyles, '::> d-text:has(a)', 'default', 'color', 'teal');
  assert.equal(same.lines.length, 0);
  const changed = stageValue(session, ruledStyles, '::> d-text:has(a)', 'default', 'color', 'navy');
  assert.deepEqual(changed.lines.map((line) => [line.relative, line.value]), [['::> d-text:has(a)', 'navy']]);
  // Clearing a moved value stages an unset at the new key.
  const cleared = stageValue(session, ruledStyles, '::> d-text:has(a)', 'dark', 'color', '');
  assert.deepEqual(cleared.lines.map((line) => [line.relative, line.condition, line.value]), [['::> d-text:has(a)', 'dark', null]]);
});

test('payloadRows renders a move as one pending row: the selector it leaves, the one it lands at, its id', () => {
  let session = updateSession('cta', 'block:abc');
  session = stageMove(session, '::> d-text', '> d-text:has(a)');
  session = stageMove(session, 'block(text,1)::p', 'strong');
  const rows = payloadRows({}, 'container', session.moves);
  assert.deepEqual(rows, [
    { relative: '::> d-text', kind: 'root', detail: null, part: '> d-text', refs: [], lines: [], move: { id: pendingMoveId('::> d-text', '> d-text:has(a)'), to: '> d-text:has(a)' } },
    { relative: 'block(text,1)::p', kind: 'block', detail: 'text', part: 'p', refs: [], lines: [], move: { id: pendingMoveId('block(text,1)::p', 'strong'), to: 'strong' } },
  ]);
  // Entry groups carry no move and render before the move rows.
  const mixed = payloadRows({ '': { default: { gap: '0' } } }, 'container', session.moves);
  assert.equal(mixed[0].move, null);
  assert.equal(mixed.length, 3);
  assert.equal(payloadRows(payload, 'container').every((group) => group.move === null), true);
});

test('a skin push spells a move as update_default nulls at the old keys and sets at the new, the lines grouped after', () => {
  const defaults = {
    '': { default: { 'font-size': '2rem' } },
    '::> d-text': { default: { color: 'teal', 'font-weight': '700' }, dark: { color: 'white' } },
    '::> d-text .link': { default: { 'text-decoration': 'underline' } },
    '::strong': { default: { color: 'red' } },
  };
  let session = skinSession('kind:container', 'container:c');
  // A line staged before the move follows it; one staged after sits at
  // the landing already; an unset at the landing beats the moved value.
  session = stageLine(session, { relative: '::> d-text', condition: 'default', property: 'color', value: 'navy' });
  session = stageMove(session, '::> d-text', '> d-text:has(a)');
  session = stageLine(session, { relative: '::> d-text:has(a)', condition: 'dark', property: 'color', value: null });
  session = stageLine(session, { relative: '::strong', condition: 'default', property: 'color', value: 'blue' });
  assert.deepEqual(skinPushOperations(session, defaults), [
    { op: 'update_default', scope: 'kind:container', key: '::> d-text', condition: 'default', properties: { color: null, 'font-weight': null } },
    { op: 'update_default', scope: 'kind:container', key: '::> d-text', condition: 'dark', properties: { color: null } },
    { op: 'update_default', scope: 'kind:container', key: '::> d-text:has(a)', condition: 'default', properties: { color: 'navy', 'font-weight': '700' } },
    { op: 'update_default', scope: 'kind:container', key: '::> d-text:has(a)', condition: 'dark', properties: { color: null } },
    { op: 'update_default', scope: 'kind:container', key: '::> d-text .link', condition: 'default', properties: { 'text-decoration': null } },
    { op: 'update_default', scope: 'kind:container', key: '::> d-text:has(a) .link', condition: 'default', properties: { 'text-decoration': 'underline' } },
    { op: 'update_default', scope: 'kind:container', key: '::strong', condition: 'default', properties: { color: 'blue' } },
  ]);
  // The ops and the merge tell one story.
  assert.deepEqual(sessionStyles(session, { documentStyles: defaults }), {
    '': { default: { 'font-size': '2rem' } },
    '::> d-text:has(a)': { default: { color: 'navy', 'font-weight': '700' } },
    '::> d-text:has(a) .link': { default: { 'text-decoration': 'underline' } },
    '::strong': { default: { color: 'blue' } },
  });
  // A move with nothing stored at the rule spells no nulls: the lines alone.
  const draft = stageMove(
    stageLine(skinSession('kind:container', 'container:c'), { relative: '::> d-image', condition: 'default', property: 'width', value: '100%' }),
    '::> d-image',
    '> d-image:has(img)',
  );
  assert.deepEqual(skinPushOperations(draft, defaults), [
    { op: 'update_default', scope: 'kind:container', key: '::> d-image:has(img)', condition: 'default', properties: { width: '100%' } },
  ]);
  assert.deepEqual(skinPushOperations(stageMove(updateSession('cta', 'block:abc'), '::> d-text', '> d-text:has(a)'), defaults), []);
});

process.stdout.write('pending suite passed\n');
