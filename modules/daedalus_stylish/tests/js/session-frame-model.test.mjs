// cspell:ignore Breite
/**
 * Self-asserting tests for the editing session frame's pure model
 * (session-frame-model.js): the frame posture, the park/resume
 * transitions, the header's document identity, the switcher roster, and
 * the resume chip.
 *
 * Run directly (`node tests/js/session-frame-model.test.mjs`) or via
 * PrinterEquivalenceTest, which executes this file inside the PHPUnit
 * suite. Exits non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import test from 'node:test';
import {
  framePosture,
  mintConsent,
  parkTransition,
  resumeChip,
  resumeTransition,
  sessionHeader,
  switcherRoster,
} from '../../js/src/session-frame-model.js';
import { saveSession, skinSession, stageLine, updateSession } from '../../js/src/pending.js';
import { EMPTY_MATERIALS, roster, blueprintRecord, variantRecord } from './materials-fixture.mjs';

// Drupal.t at call time (a skin session's scope words), placeholders
// substituted the way Drupal.formatString does.
globalThis.Drupal = {
  t: (text, args = {}) => Object.entries(args).reduce(
    (out, [key, value]) => out.split(key).join(String(value)),
    text,
  ),
};

const UUID_CTA = '00000000-0000-0000-0000-000000000001';
// The store's variant records; a reference resolves by id alone.
const LIBRARY = [
  variantRecord('cta', { label: 'CTA' }),
  variantRecord('wide', { label: 'Wide', group: 'width' }),
];

test('framePosture mounts the frame only over an anchored session', () => {
  assert.equal(framePosture(null, null), null);
  assert.equal(framePosture(null, 'cta'), null);
  const update = updateSession('cta', 'block:b1');
  assert.equal(framePosture(update, 'cta'), 'editing');
  // The editing echo is in flight (source round-trip): nothing mounts
  // rather than an anchorless frame.
  assert.equal(framePosture(update, null), null);
  // A draft mounts off the session itself — it has no document reference.
  assert.equal(framePosture(saveSession('block:b1'), null), 'creating');
  // Parked renders the chip instead of the frame, either kind.
  assert.equal(framePosture(update, null, true), 'parked');
  assert.equal(framePosture(saveSession('block:b1'), null, true), 'parked');
  // A skin session mounts the same editing frame over its document's
  // reference, and waits for the echo like an update session.
  const skin = skinSession('base:h2', 'block:b1');
  assert.equal(framePosture(skin, 'skin:base:h2'), 'editing');
  assert.equal(framePosture(skin, null), null);
  assert.equal(framePosture(skin, null, true), 'parked');
});

test('park clears the synced editing field; resume restores it from the session', () => {
  assert.deepEqual(parkTransition(), { editing: null, parked: true });
  const update = updateSession('cta', 'block:b1');
  assert.deepEqual(resumeTransition(update), { editing: 'cta', parked: false });
  // A draft resumes with editing still null: its frame mounts off the
  // session, and no phantom document reaches the other window.
  assert.deepEqual(resumeTransition(saveSession('block:b1')), { editing: null, parked: false });
  assert.deepEqual(resumeTransition(null), { editing: null, parked: false });
  // A skin session resumes on its skin document's reference.
  assert.deepEqual(resumeTransition(skinSession('base:h2', 'block:b1')), { editing: 'skin:base:h2', parked: false });
});

test('sessionHeader resolves an update session through the records', () => {
  const header = sessionHeader(updateSession('cta', 'block:b1'), LIBRARY);
  assert.deepEqual(header, { posture: 'editing', label: 'CTA', axis: 'style', axisLabel: 'Style', scope: null });
  // Declared axis labels win over the derived form.
  const declared = sessionHeader(
    updateSession('wide', 'block:b1'),
    LIBRARY,
    { axisLabels: { width: 'Breite' } },
  );
  assert.deepEqual(declared, { posture: 'editing', label: 'Wide', axis: 'width', axisLabel: 'Breite', scope: null });
  // An unresolvable reference (deleted, or the retired uuid spelling)
  // degrades honestly to the verbatim reference and the built-in axis:
  // the session must still render its exits.
  assert.deepEqual(
    sessionHeader(updateSession('gone', 'block:b1'), LIBRARY),
    { posture: 'editing', label: 'gone', axis: 'style', axisLabel: 'Style', scope: null },
  );
  assert.equal(sessionHeader(updateSession(UUID_CTA, 'block:b1'), LIBRARY).label, UUID_CTA);
  assert.equal(sessionHeader(null, LIBRARY), null);
});

test('sessionHeader speaks a draft\'s own name and axis pick', () => {
  let draft = saveSession('block:b1', 'container_layout');
  draft = { ...draft, label: '  Hero  ' };
  assert.deepEqual(
    sessionHeader(draft, LIBRARY),
    { posture: 'creating', label: 'Hero', axis: 'container_layout', axisLabel: 'Container layout', scope: null },
  );
  // An unnamed draft returns '': the view supplies its translated
  // fallback.
  assert.equal(sessionHeader(saveSession('block:b1'), LIBRARY).label, '');
});

test('sessionHeader speaks a skin session\'s scope in words, no axis (a default competes on none)', () => {
  assert.deepEqual(
    sessionHeader(skinSession('base:h2', 'block:b1'), LIBRARY, { tagLabels: { h2: 'Heading 2' } }),
    { posture: 'skin', label: 'Heading 2', axis: null, axisLabel: null, scope: 'base:h2' },
  );
  assert.deepEqual(
    sessionHeader(skinSession('block_type:text', 'block:b1'), LIBRARY),
    { posture: 'skin', label: 'text block type', axis: null, axisLabel: null, scope: 'block_type:text' },
  );
  // Only drafts mint: a skin session consents to nothing.
  assert.equal(mintConsent(skinSession('base:h2', 'block:b1'), 'call_to_action', EMPTY_MATERIALS), null);
});

test('mintConsent links a draft to its placement provenance until unlinked', () => {
  const draft = saveSession('block:b1');
  const materials = roster(blueprintRecord('call_to_action', { label: 'Call to action' }));
  // Born linked: the chip names the owner, label through the store's
  // blueprint record...
  assert.deepEqual(
    mintConsent(draft, 'call_to_action', materials),
    { blueprint: 'call_to_action', label: 'Call to action' },
  );
  // ...and the id when the roster holds no such blueprint (the linked fact
  // never depends on the name).
  assert.deepEqual(
    mintConsent(draft, 'call_to_action', EMPTY_MATERIALS),
    { blueprint: 'call_to_action', label: 'call_to_action' },
  );
  // Unlink is the opt-out: no chip, and by the one-read rule the push
  // bar sends no owner.
  assert.equal(mintConsent({ ...draft, linked: false }, 'call_to_action', materials), null);
  // No provenance = nothing to consent to: the null direction stays
  // chipless on purpose (adopt-later is the Library's Assign gesture).
  assert.equal(mintConsent(draft, null, materials), null);
  assert.equal(mintConsent(draft, '', materials), null);
  // Only drafts mint: update sessions and no session answer null.
  assert.equal(mintConsent(updateSession('cta', 'block:b1'), 'call_to_action', materials), null);
  assert.equal(mintConsent(null, 'call_to_action', materials), null);
});

test('switcherRoster lists the selection\'s resolved documents once each', () => {
  const entry = {
    variants: [
      // Delivery order under the vocabulary puts the style axis first.
      { variant: 'wide', fragment: '' },
      { variant: 'cta', fragment: '' },
      // A fragment wearing of the same variant is the SAME document.
      { variant: 'cta', fragment: 'block(text,1)' },
      // Unresolvable (deleted, or the retired uuid spelling): no document
      // to switch to.
      { variant: 'gone', fragment: '' },
      { variant: UUID_CTA, fragment: '' },
    ],
  };
  assert.deepEqual(switcherRoster(entry, LIBRARY, 'cta', ['style', 'width']), [
    { reference: 'cta', fragment: '', label: 'CTA', axis: 'style', current: true },
    { reference: 'wide', fragment: '', label: 'Wide', axis: 'width', current: false },
  ]);
  // The current mark is the record id, one identity: a uuid never marks.
  assert.equal(switcherRoster(entry, LIBRARY, UUID_CTA, ['style', 'width'])[0].current, false);
  // No editing session: a roster with nothing current (the creating
  // posture's dropdown).
  assert.deepEqual(
    switcherRoster(entry, LIBRARY, null, ['style', 'width']).map((row) => row.current),
    [false, false],
  );
  assert.deepEqual(switcherRoster(null, LIBRARY, null), []);
});

test('resumeChip carries the parked session\'s label and loud count', () => {
  let update = updateSession('cta', 'block:b1');
  update = stageLine(update, { relative: '', condition: 'default', property: 'gap', value: '2rem' });
  update = stageLine(update, { relative: '', condition: 'default', property: 'color', value: 'red' });
  assert.deepEqual(resumeChip(update, LIBRARY), { kind: 'update', label: 'CTA', count: 2 });
  // An unresolvable reference keeps verbatim identity.
  assert.equal(resumeChip(updateSession('gone', 'b'), LIBRARY).label, 'gone');
  // A save session counts its live capture, pruned of dismissals.
  let draft = saveSession('block:b1');
  draft = { ...draft, label: ' Hero ' };
  const capture = { '': { default: { padding: '1rem', gap: '2rem' } } };
  assert.deepEqual(resumeChip(draft, LIBRARY, capture), { kind: 'save', label: 'Hero', count: 2 });
  const dismissed = { ...draft, dismissed: ['line||default|gap'] };
  assert.equal(resumeChip(dismissed, LIBRARY, capture).count, 1);
  // An unnamed draft returns '': the view supplies its fallback.
  assert.equal(resumeChip(saveSession('block:b1'), LIBRARY).label, '');
  assert.equal(resumeChip(null, LIBRARY), null);
  // A parked skin session names its scope and counts its staged lines.
  let skin = skinSession('kind:container', 'container:c1');
  skin = stageLine(skin, { relative: '', condition: 'default', property: 'gap', value: '2rem' });
  assert.deepEqual(resumeChip(skin, LIBRARY), { kind: 'skin', label: 'container kind', count: 1 });
  assert.equal(resumeChip(skinSession('base:h2', 'block:b1'), LIBRARY, null, { tagLabels: { h2: 'Heading 2' } }).label, 'Heading 2');
});
