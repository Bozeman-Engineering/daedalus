/**
 * Self-asserting tests for the selection frame's pure model
 * (selection-frame-model.js): chip placement (a tab sitting flush on
 * the stroke's outer top-left corner, flip below when the top rail
 * leaves no room, pull back from the right rail only), the
 * editing dress (frameEditing: the dash follows the store's reactive
 * editingKey — the LIVE edit, never a prediction), and the chip face
 * (the set-order number, and the tab-flag partition of the primary's
 * verbs). The face never hides for an open edit: it renders whatever
 * verbs the registry's applies gate scoped to the live edit state
 * (selection verbs while no edit lands on the key, edit-scoped verbs
 * while editingKey IS it), so mid-edit the face IS the applicable
 * verbs and the dash is the frame's only editing dress.
 *
 * Run with `node --test tests/js/selection-frame.test.mjs` from the
 * module root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { chipPlacement, chipFace, frameEditing } from '../../js/edit_mode/components/selection-frame/selection-frame-model.js';

const viewport = { width: 1000, height: 800 };
const chip = { width: 120, height: 24 };

test('the chip sits flush on the stroke above the top-left corner by default', () => {
  // The 2px stroke paints outward from the rect: its outer left edge is
  // rect.left - 2 and its outer top is rect.top - 2. The chip's left
  // edge aligns with the stroke's left edge (298) and its bottom edge
  // touches the stroke's top edge, no gap (174 + 24 = 198 = 200 - 2).
  const placed = chipPlacement({ rect: { top: 200, left: 300, bottom: 260, right: 500 }, chip, viewport });
  assert.deepEqual(placed, { left: 298, top: 174, below: false });
});

test('the chip flips below the stroke bottom edge when the viewport top leaves no room', () => {
  // Below the stroke's outer bottom edge, still no gap: 70 + 2 = 72.
  const placed = chipPlacement({ rect: { top: 10, left: 300, bottom: 70, right: 500 }, chip, viewport });
  assert.deepEqual(placed, { left: 298, top: 72, below: true });
});

test('the top rail counts as no room above', () => {
  const rect = { top: 60, left: 300, bottom: 120, right: 500 };
  // 60 - 2 - 24 = 34: room without a rail, none under a 40px toolbar.
  assert.equal(chipPlacement({ rect, chip, viewport }).below, false);
  assert.equal(chipPlacement({ rect, chip, viewport, offsets: { top: 40 } }).below, true);
});

test('the chip never paints under a right rail', () => {
  // A full-bleed element under a 300px right sidebar: the chip hugs the
  // stroke's left edge and is unaffected; a narrow element at the far
  // right is pulled back into the canvas so its verbs stay reachable.
  const wide = chipPlacement({ rect: { top: 200, left: 0, bottom: 300, right: 1000 }, chip, viewport, offsets: { right: 300 } });
  assert.equal(wide.left, -2);
  const narrow = chipPlacement({ rect: { top: 200, left: 650, bottom: 300, right: 690 }, chip, viewport, offsets: { right: 300 } });
  assert.equal(narrow.left, 1000 - 300 - 120 - 4);
});

test('the left edge stays on the stroke even against a left rail', () => {
  // A full-bleed element starting flush at a 250px left sidebar's edge:
  // no left clamp — the chip aligns with the stroke (248) rather than
  // drifting into the canvas, because a chip that aligns on some
  // elements and not others reads as broken.
  const placed = chipPlacement({ rect: { top: 200, left: 250, bottom: 300, right: 400 }, chip, viewport, offsets: { left: 250 } });
  assert.equal(placed.left, 248);
});

const edit = { id: 'edit', tab: true };
const duplicate = { id: 'duplicate', tab: true };
const insert = { id: 'insert', tab: false };
const rename = { id: 'rename', tab: false };
const verbs = [edit, insert, duplicate, rename];

test('a single selection has no number; a set numbers members by selection order', () => {
  assert.equal(chipFace({ key: 'A', keys: ['A'], primary: 'A' }).number, null);
  const keys = ['B', 'A', 'C'];
  assert.equal(chipFace({ key: 'B', keys, primary: 'A' }).number, 1);
  assert.equal(chipFace({ key: 'A', keys, primary: 'A' }).number, 2);
  assert.equal(chipFace({ key: 'C', keys, primary: 'A' }).number, 3);
});

test('clean density partitions verbs by the tab flag, preserving menu order; nothing appears in both', () => {
  const face = chipFace({ key: 'A', keys: ['A'], primary: 'A', verbs, density: 'clean' });
  assert.deepEqual(face.faceVerbs, [edit, duplicate]);
  assert.deepEqual(face.overflow, [insert, rename]);
  assert.equal(face.labels, false);
  assert.equal(face.primary, true);
});

test('secondary members carry no verbs at all', () => {
  const face = chipFace({ key: 'B', keys: ['B', 'A'], primary: 'A', verbs, density: 'clean' });
  assert.deepEqual(face.faceVerbs, []);
  assert.deepEqual(face.overflow, []);
  assert.equal(face.primary, false);
});

test('full density (the default) puts every labeled verb on the face in menu order, no chevron', () => {
  const face = chipFace({ key: 'A', keys: ['A'], primary: 'A', verbs, density: 'full' });
  assert.deepEqual(face.faceVerbs, verbs);
  assert.deepEqual(face.overflow, []);
  assert.equal(face.labels, true);
  // Absent reads as full, like the html attribute.
  const absent = chipFace({ key: 'A', keys: ['A'], primary: 'A', verbs });
  assert.deepEqual(absent.faceVerbs, verbs);
  assert.equal(absent.labels, true);
});

test('minimal density is the full face with the labels dropped: every verb, icons only, no chevron', () => {
  const face = chipFace({ key: 'A', keys: ['A'], primary: 'A', verbs, density: 'minimal' });
  assert.deepEqual(face.faceVerbs, verbs);
  assert.deepEqual(face.overflow, []);
  assert.equal(face.labels, false);
});

test('the face while editing = the applicable verbs: chipFace has no editing gate', () => {
  // The registry's applies gate already scoped `verbs` to the live edit
  // state (edit-scoped verbs while editingKey IS the key); the face
  // renders them exactly as it renders selection verbs, density ladder
  // and all — the dash is the frame's only editing dress.
  const tag = { id: 'tag', tab: true };
  const link = { id: 'link', tab: false };
  const editVerbs = [tag, link];
  const full = chipFace({ key: 'A', keys: ['A'], primary: 'A', verbs: editVerbs, density: 'full' });
  assert.deepEqual(full.faceVerbs, editVerbs);
  assert.deepEqual(full.overflow, []);
  const clean = chipFace({ key: 'A', keys: ['A'], primary: 'A', verbs: editVerbs, density: 'clean' });
  assert.deepEqual(clean.faceVerbs, [tag]);
  assert.deepEqual(clean.overflow, [link]);
});

test('a primary with no live edit keeps the face: nothing about the tool or an edit hides it', () => {
  // A container selected under the armed session — or an editable leaf
  // whose edit ended — is a plain selection, verbs and all.
  const face = chipFace({ key: 'A', keys: ['A'], primary: 'A', verbs, density: 'clean' });
  assert.deepEqual(face.faceVerbs, [edit, duplicate]);
  assert.deepEqual(face.overflow, [insert, rename]);
});

test('the dash follows the live edit: editingKey IS the primary', () => {
  assert.equal(frameEditing({ editingKey: 'A', primary: 'A' }), true);
  // No open edit: solid, even if the session is armed on an editable
  // primary — the dash never predicts. This is the Escape path: the
  // engine's null report retires the dash.
  assert.equal(frameEditing({ editingKey: null, primary: 'A' }), false);
  // An edit living on a non-primary key never dresses the frame.
  assert.equal(frameEditing({ editingKey: 'B', primary: 'A' }), false);
  // No primary: nothing to dress, even mid-edit.
  assert.equal(frameEditing({ editingKey: null, primary: null }), false);
  assert.equal(frameEditing({}), false);
});

test('the dash and the face are independent readings: an open edit dresses the stroke, never empties the chip', () => {
  // Mid-edit the host still renders the face from the registry's
  // applicable verbs; frameEditing only decides the dash.
  assert.equal(frameEditing({ editingKey: 'A', primary: 'A' }), true);
  const face = chipFace({ key: 'A', keys: ['A'], primary: 'A', verbs });
  assert.deepEqual(face.faceVerbs, verbs);
});
