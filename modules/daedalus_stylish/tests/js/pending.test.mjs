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
  payloadRows,
  payloadSummary,
  pendingLineId,
  pendingRefId,
  prunedPayload,
  restoreLine,
  saveSession,
  sessionCount,
  sessionStyles,
  stageIslandLines,
  stageLine,
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
  assert.deepEqual(session, { kind: 'skin', reference: 'skin:base:h2', scope: 'base:h2', anchor: 'block:abc', lines: [] });
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
