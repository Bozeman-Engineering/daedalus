/**
 * Self-asserting tests for the client's material gesture family
 * (js/src/material-gestures.js): save_material / update_material over the
 * source's batch commit, the record read from the response's materials
 * delta, the failure text both lanes share and the route lane's one
 * reporter (gesture-transport.js).
 *
 * Run directly: `node tests/js/material-gestures.test.mjs`. Exits
 * non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import { mintedMaterial, respondedMaterial, saveMaterial, updateMaterial } from '../../js/src/material-gestures.js';
import { failureText, gesture } from '../../js/src/gesture-transport.js';

const ember = { kind: 'token', id: 'color/ember', label: 'ember', group: 'color', value: '#ff5a1f' };
const section = { kind: 'variant', id: 'section', label: 'Section', group: 'style', value: {} };
const hero = { kind: 'token', id: 'space/hero', label: 'hero', group: 'space', value: '3rem', description: '', offered: true };

/**
 * A source whose commitBatch records the batch and answers a scripted
 * payload (or rejects with a scripted Error), over a roster holding the
 * given records before the commit (getMaterials, the mint's novelty read).
 */
const createSource = (answer, standing = []) => {
  const source = { batches: [] };
  source.commitBatch = (batch) => {
    source.batches.push(batch);
    return answer instanceof Error ? Promise.reject(answer) : Promise.resolve(answer);
  };
  source.getMaterials = () => ({
    has: (kind, id) => standing.some((record) => record.kind === kind && record.id === id),
  });
  return source;
};

const tests = [];
const test = (name, run) => tests.push([name, run]);

test('respondedMaterial reads the delta by kind, and by id when named', () => {
  const payload = { materials: { upsert: [section, ember], remove: [] } };
  assert.equal(respondedMaterial(payload, 'token'), ember);
  assert.equal(respondedMaterial(payload, 'variant', 'section'), section);
  assert.equal(respondedMaterial(payload, 'variant', 'other'), null);
  assert.equal(respondedMaterial(payload, 'blueprint'), null);
  assert.equal(respondedMaterial({ deleted: true }, 'token'), null);
  assert.equal(respondedMaterial(null, 'token'), null);
});

test('mintedMaterial reads the mint as the record of its kind the roster lacked', () => {
  const standing = { has: (kind, id) => kind === 'token' && id === 'space/hero' };
  const payload = { materials: { upsert: [hero, section, ember], remove: [] } };
  assert.equal(mintedMaterial(payload, 'token', standing), ember);
  assert.equal(mintedMaterial(payload, 'variant', standing), section);
  // Every token of the kind already on the roster: no mint in the delta.
  assert.equal(mintedMaterial(payload, 'token', { has: () => true }), null);
  assert.equal(mintedMaterial(null, 'token', standing), null);
});

test('a skin mint resolves beside the skin\'s standing records in the delta', async () => {
  // A skin write's delta carries every material the active skin holds (the
  // skin hook touches each id it carried before and carries now), so the
  // minted gradient arrives AFTER the package's own gradient token and the
  // color scale; the record is the one the roster did not hold.
  const heroFade = { kind: 'token', id: 'gradient/hero-fade', label: 'hero-fade', group: 'gradient', value: 'linear-gradient(135deg, #e94560, #16213e)' };
  const molten = { kind: 'token', id: 'gradient/molten-brass', label: 'molten-brass', group: 'gradient', value: 'linear-gradient(180deg, #800080 50%, #ff0000 99.5%, #0000ff)' };
  const source = createSource(
    { success: true, materials: { upsert: [ember, heroFade, molten], remove: [] } },
    [ember, heroFade],
  );
  const record = await saveMaterial(source, { kind: 'token', label: 'molten-brass', value: molten.value }, 'Saved the gradient as the token molten-brass');
  assert.equal(record, molten);
  // No group on the wire: the scale is the server's inference from the value.
  assert.deepEqual(source.batches[0].operations, [
    { op: 'save_material', kind: 'token', label: 'molten-brass', value: molten.value },
  ]);
});

test('saveMaterial submits one save_material op and resolves with the minted record', async () => {
  const source = createSource({ success: true, materials: { upsert: [ember], remove: [] } });
  const record = await saveMaterial(source, { kind: 'token', label: 'ember', group: 'color', value: '#ff5a1f', owned_by: undefined }, 'Saved the color as the token ember');
  assert.equal(record, ember);
  // One batch, one op, undefined keys dropped (the wire carries no
  // `owned_by: undefined`), the history label as given.
  assert.deepEqual(source.batches, [{
    operations: [{ op: 'save_material', kind: 'token', label: 'ember', group: 'color', value: '#ff5a1f' }],
    label: 'Saved the color as the token ember',
  }]);
});

test('updateMaterial submits one update_material op and resolves with the record by id', async () => {
  const source = createSource({ success: true, materials: { upsert: [ember, section], remove: [] } });
  const record = await updateMaterial(source, { kind: 'variant', id: 'section', styles: { '': { default: { padding: '1rem' } } } }, 'Pushed changes into Section');
  assert.equal(record, section);
  assert.deepEqual(source.batches[0].operations, [
    { op: 'update_material', kind: 'variant', id: 'section', styles: { '': { default: { padding: '1rem' } } } },
  ]);
});

test('a token mints and updates through the same two ops: the label IS the name, the group the scale', async () => {
  const source = createSource({ success: true, materials: { upsert: [hero], remove: [] } });
  const minted = await saveMaterial(source, { kind: 'token', group: 'space', label: 'hero', value: '3rem', description: undefined, offered: undefined }, 'Minted the token space/hero');
  assert.equal(minted, hero);
  assert.deepEqual(source.batches[0].operations, [
    { op: 'save_material', kind: 'token', group: 'space', label: 'hero', value: '3rem' },
  ]);
  // The site's override of a package token, a description, the offered
  // flag: one op each, by the roster id.
  const paired = { ...hero, id: 'color/bg', label: 'bg', group: 'color', value: { default: '#fdfdf6', dark: '#0b0d0f' } };
  const edits = createSource({ success: true, materials: { upsert: [paired], remove: [] } });
  const record = await updateMaterial(edits, { kind: 'token', id: 'color/bg', value: paired.value }, 'Updated the token color/bg');
  assert.equal(record, paired);
  await updateMaterial(edits, { kind: 'token', id: 'color/bg', offered: false }, 'Withheld the token color/bg');
  await updateMaterial(edits, { kind: 'token', id: 'color/bg', description: 'The page.' }, 'Described the token color/bg');
  assert.deepEqual(edits.batches.map((batch) => batch.operations[0]), [
    { op: 'update_material', kind: 'token', id: 'color/bg', value: { default: '#fdfdf6', dark: '#0b0d0f' } },
    { op: 'update_material', kind: 'token', id: 'color/bg', offered: false },
    { op: 'update_material', kind: 'token', id: 'color/bg', description: 'The page.' },
  ]);
  assert.equal(respondedMaterial({ materials: { upsert: [hero, paired] } }, 'token', 'color/bg'), paired);
});

test('a settled write without its record is a contract break, loud', async () => {
  const source = createSource({ success: true, materials: { upsert: [], remove: [] } });
  await assert.rejects(
    saveMaterial(source, { kind: 'token', label: 'ember', value: '#ff5a1f' }, 'x'),
    /save_material \(token\) settled without its record/,
  );
});

test('a rejected batch rejects verbatim, and failureText phrases either lane\'s Error', async () => {
  const failure = new Error('That label is taken.');
  failure.payload = { success: false, errors: [{ path: 'label', code: 'X', message: 'That label is taken.', hint: 'Pick another name.' }] };
  const source = createSource(failure);
  await assert.rejects(saveMaterial(source, { kind: 'token', label: 'taken', value: '#000' }, 'x'), failure);
  // The op queue's shape and the route transport's alike: message + hint
  // from payload.errors[0].
  assert.equal(failureText(failure), 'That label is taken. Pick another name.');
  // A bare Error (no page context, a network failure) phrases its message.
  assert.equal(failureText(new Error('No root entity mutation context on this page.')), 'No root entity mutation context on this page.');
  assert.equal(failureText(null), '');
});

test('gesture() reports a route failure once through the source and rethrows it', async () => {
  const notified = [];
  const source = { notify: (type, text) => notified.push([type, text]) };
  const failure = new Error('The request failed (403).');
  failure.payload = { errors: [{ path: '', code: 'X', message: 'Not yours to rename.', hint: 'Copy it first.' }] };
  await assert.rejects(gesture(source, () => Promise.reject(failure)), (error) => error === failure);
  assert.deepEqual(notified, [['error', 'Not yours to rename. Copy it first.']]);
  // Success passes through untouched and says nothing.
  assert.equal(await gesture(source, () => Promise.resolve('ok')), 'ok');
  assert.equal(notified.length, 1);
  // A source without notify (a host with no messenger) still settles.
  await assert.rejects(gesture({}, () => Promise.reject(failure)), (error) => error === failure);
});

let failures = 0;
for (const [name, run] of tests) {
  try {
    await run();
    console.log(`ok - ${name}`);
  } catch (error) {
    failures += 1;
    console.error(`not ok - ${name}`);
    console.error(error.message);
  }
}
if (failures) {
  console.error(`${failures} of ${tests.length} material-gestures tests failed.`);
  process.exit(1);
}
console.log(`${tests.length} material-gestures tests passed.`);
process.exit(0);
