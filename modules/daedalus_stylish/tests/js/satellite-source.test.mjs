/**
 * Self-asserting tests for the satellite source's channel frames: the op
 * intents' request/settled pairing in particular, and the rule that a
 * gesture outcome never crosses the wire (the satellite hosts its own
 * messenger; js/src/notify.js). The channel envelope (daedalus_ui
 * channel.js) reserves the `type` key for the frame name, so every
 * intent payload is asserted as it actually crosses the wire: these
 * tests ride real BroadcastChannel instances (a node global).
 *
 * Run directly (`node tests/js/satellite-source.test.mjs`) or via
 * PrinterEquivalenceTest, which executes this file inside the PHPUnit
 * suite. Exits non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import { createSatelliteSource } from '../../js/src/sources/satellite-source.js';
import { createPopoutLeader } from '../../js/src/broadcast/popout-leader.js';
import { failureText } from '../../js/src/gesture-transport.js';
import { MEDIA_PICK_PLACEHOLDER } from '../../js/src/media-library.js';

// The satellite touches window only for lifecycle wiring; a listener
// sink suffices.
globalThis.window = { addEventListener: () => {}, close: () => {} };

const SCOPE = `test-scope-${process.pid}`;

/**
 * Collects every frame the satellite posts on the scope's channel.
 * BroadcastChannel never self-delivers, so a second instance on the same
 * name hears exactly what a leader window would.
 */
const listen = () => {
  const channel = new BroadcastChannel(`daedalus_stylish:${SCOPE}`);
  const frames = [];
  channel.onmessage = (event) => frames.push(event.data);
  return { frames, close: () => channel.close() };
};

// Delivery is a queued task, never synchronous.
const settle = () => new Promise((resolve) => setTimeout(resolve, 20));

const tests = [];
const test = (name, run) => tests.push([name, run]);

test('notify rides this window\'s messenger, never the wire, and is never load-bearing', async () => {
  const wire = listen();
  const source = createSatelliteSource(SCOPE);
  // No Drupal at all (unit contexts, a page without the library): the
  // call silently no-ops.
  assert.equal(globalThis.Drupal, undefined);
  source.notify('status', 'Added the Rain gradient to the color palette.');
  // Drupal without the messenger attached: still a no-op.
  globalThis.Drupal = { Daedalus: {} };
  source.notify('status', 'Added the Rain gradient to the color palette.');
  // The messenger present in THIS window: text and type land in ITS
  // argument order, message(text, type), through Drupal.checkPlain (the
  // escape itself is pinned in notify.test.mjs; this text carries nothing
  // to escape).
  const heard = [];
  globalThis.Drupal.checkPlain = (text) => text.toString();
  globalThis.Drupal.Daedalus.messenger = { message: (text, type) => heard.push({ text, type }) };
  source.notify('error', 'That label is taken.');
  assert.deepEqual(heard, [{ text: 'That label is taken.', type: 'error' }]);
  await settle();
  // Only the boot hello crossed the wire, no message frame: the outcome
  // shows where the hand is, not on the leader page (which reports its
  // own fates beside its flash).
  assert.deepEqual(wire.frames.map((frame) => frame.type), ['hello']);
  delete globalThis.Drupal;
  // The satellite's own channel stays open (the source has no teardown
  // gesture); the trailing process.exit(0) is what releases it.
  wire.close();
});

test('a pick-media intent relays the routed changes verbatim', async () => {
  const wire = listen();
  const source = createSatelliteSource(SCOPE);
  // Before the leader's broadcast arms the gate, the intent is refused
  // (honest chrome: nothing would open over there).
  const pick = {
    condition: 'default',
    property: 'background-image',
    label: 'Styled Background',
    // The pipeline's routed list: the placeholder and the seed grouped on
    // the anchor, a replaced band's null on the root it lives on.
    changes: [
      {
        key: 'block:aaaaaaaa-1111-2222-3333-444444444444::a',
        properties: { 'background-image': MEDIA_PICK_PLACEHOLDER, 'background-size': 'cover' },
      },
      { key: 'block:aaaaaaaa-1111-2222-3333-444444444444', properties: { 'background-color': null } },
    ],
  };
  assert.equal(source.pickMedia(pick), false);
  // The leader says its library can open.
  const leader = new BroadcastChannel(`daedalus_stylish:${SCOPE}`);
  leader.postMessage({ type: 'state', state: { mediaLibraryAvailable: true } });
  await settle();
  assert.equal(source.pickMedia(pick), true);
  await settle();
  // The intent frame carries the routed changes verbatim (a plain
  // placeholder, no function): the popout leader hands the payload to
  // source.pickMedia unchanged, so what crosses the wire here is exactly
  // what the leader fills and commits.
  const intents = wire.frames.filter((frame) => frame.type === 'intent' && frame.intent === 'pick-media');
  assert.deepEqual(intents, [{
    type: 'intent',
    intent: 'pick-media',
    ...pick,
  }]);
  leader.close();
  wire.close();
});

test('materials: the satellite boots from its own envelope, follows the wire, relays its own gestures', async () => {
  // The popout page attaches the same MaterialsDelivery envelope the
  // editing page boots from; the satellite seats its own record set
  // from it and indexes it as a roster.
  const brand = { kind: 'swatch', id: 'brand_red', label: 'Brand red', description: '', value: '#b91c1c', group: 'color', spellings: { style: 'var(--swatch-brand_red)' }, scope: null, applies: {}, owned_by: null, published: true, weight: 0, icon: null, origin: 'user', updatable: true };
  const dawn = { ...brand, id: 'dawn', label: 'Dawn', value: '#ffd166', spellings: { style: 'var(--swatch-dawn)' } };
  globalThis.drupalSettings = { daedalusUi: { materials: JSON.stringify({ records: [brand] }) } };
  const source = createSatelliteSource(SCOPE);
  assert.deepEqual(source.getMaterials().ofKind('swatch').map((record) => record.id), ['brand_red']);
  assert.deepEqual(source.getMaterials().bySpelling('style', 'var(--swatch-brand_red)'), brand);
  const seen = [];
  source.onMaterialsChange((roster) => seen.push(roster.ofKind('swatch').map((record) => record.id)));
  // A delta the leader relays on the materials wire (materials-store.js's
  // protocol: channel materials:<scope>, frame delta) seats the record
  // by the shared rule and announces a fresh roster.
  const leaderWire = new BroadcastChannel(`materials:${SCOPE}`);
  const heard = [];
  leaderWire.onmessage = (event) => heard.push(event.data);
  leaderWire.postMessage({ type: 'delta', upsert: [dawn], remove: [] });
  await settle();
  assert.deepEqual(seen, [['brand_red', 'dawn']]);
  // An empty delta (every ordinary response's) touches nothing.
  leaderWire.postMessage({ type: 'delta', upsert: [], remove: [] });
  await settle();
  assert.equal(seen.length, 1);
  // A gesture run in THIS window applies its response's delta here and
  // posts it on the wire for the leader's store; an empty one posts
  // nothing, and a response without materials is ignored.
  source.applyResponse({ swatch: { machine_name: 'brand_red' }, materials: { upsert: [], remove: [{ kind: 'swatch', id: 'brand_red' }] } });
  source.applyResponse({ deleted: true, materials: { upsert: [], remove: [] } });
  source.applyResponse({ usages: 3 });
  await settle();
  assert.deepEqual(seen, [['brand_red', 'dawn'], ['dawn']]);
  assert.deepEqual(heard, [{ type: 'delta', upsert: [], remove: [{ kind: 'swatch', id: 'brand_red' }] }]);
  // A live material preview (a swatch's, a token's) travels to the
  // leader by kind and id; null releases; no record, no intent.
  const wire = listen();
  source.previewMaterial(dawn, '#000000');
  source.previewMaterial(dawn, null);
  source.previewMaterial(null, '#000000');
  await settle();
  assert.deepEqual(wire.frames.filter((frame) => frame.intent === 'material-preview'), [
    { type: 'intent', intent: 'material-preview', kind: 'swatch', id: 'dawn', value: '#000000' },
    { type: 'intent', intent: 'material-preview', kind: 'swatch', id: 'dawn', value: null },
  ]);
  // A keyframes session's draft travels the same way: by record id, the
  // steps map whole; null releases; no record, no intent.
  const fade = { ...brand, kind: 'animation', id: 'fade_in', label: 'Fade in', value: { '0%': { opacity: '0' }, '100%': { opacity: '1' } }, group: null, spellings: { animation: 'motion-fade_in' } };
  const draft = { '0%': { opacity: '0.2' }, '100%': { opacity: '1' } };
  source.previewAnimation(fade, draft);
  source.previewAnimation(fade, null);
  source.previewAnimation(null, draft);
  await settle();
  assert.deepEqual(wire.frames.filter((frame) => frame.intent === 'animation-preview'), [
    { type: 'intent', intent: 'animation-preview', id: 'fade_in', steps: draft },
    { type: 'intent', intent: 'animation-preview', id: 'fade_in', steps: null },
  ]);
  leaderWire.close();
  wire.close();
  delete globalThis.drupalSettings;
});

test('the snapshot carries the selection, its target and the tree, and no canvas read of its own', async () => {
  // A leader source stub: a heading block selected with its eyebrow part
  // targeted. The satellite renders the same selection and target the
  // leader holds and reads every tag from the relayed tree (the read
  // subject's, style-stack.js subjectTag), never from a canvas.
  const wire = listen();
  const addressed = [];
  const selection = { key: 'block:aaaaaaaa-1111-2222-3333-444444444444', selector: '.eyebrow', address: { key: 'container:bbbbbbbb-1111-2222-3333-444444444444', selector: 'span' } };
  const tree = [{ kind: 'block', uuid: 'aaaaaaaa-1111-2222-3333-444444444444', block_type: 'heading', _element: { markup: { tag: 'h2', parts: { '.eyebrow': { tag: 'span' } } } } }];
  const targetListeners = new Set();
  const leaderSource = {
    getSelected: () => selection.key,
    getTarget: () => (selection.selector ? { key: selection.key, selector: selection.selector } : null),
    getAddress: () => selection.address,
    address: (key, selector) => addressed.push([key, selector]),
    getStyleMap: () => ({}),
    getRootUuid: () => 'root',
    getBlockTypes: () => ({}),
    getSectionLayouts: () => ({}),
    getTree: () => tree,
    getLayoutContext: () => null,
    getMediaUrls: () => ({}),
    canPickMedia: () => false,
    getCanvasVector: () => null,
    getForceState: () => null,
    getPlace: () => ({ place: 'styles', editing: null }),
    onTargetChange: (callback) => targetListeners.add(callback),
    onStyleMapChange: () => {},
    onForceStateChange: () => {},
    onPlaceChange: () => {},
    onCanvasVectorChange: () => () => {},
  };
  const satellite = createSatelliteSource(SCOPE);
  assert.equal(satellite.getTree(), null);
  createPopoutLeader({ scope: SCOPE, url: 'about:blank', source: leaderSource });
  await settle();
  assert.equal(satellite.getSelected(), selection.key);
  assert.deepEqual(satellite.getTarget(), { key: selection.key, selector: '.eyebrow' });
  // The write address rides the same snapshot beside the facet: spine
  // state on the leader, never a satellite-local pick.
  assert.deepEqual(satellite.getAddress(), { key: 'container:bbbbbbbb-1111-2222-3333-444444444444', selector: 'span' });
  assert.deepEqual(satellite.getTree(), tree);
  assert.equal('readCanvasTag' in satellite, false);
  // The satellite's target-aware subscription fires the facade's
  // three-fact shape, and an address move alone fires it.
  const heard = [];
  satellite.onTargetChange((current, previous) => heard.push([current, previous]));
  selection.address = null;
  targetListeners.forEach((callback) => callback({ selected: selection.key, target: { key: selection.key, selector: '.eyebrow' }, address: null }, { selected: selection.key, target: { key: selection.key, selector: '.eyebrow' }, address: { key: 'container:bbbbbbbb-1111-2222-3333-444444444444', selector: 'span' } }));
  await settle();
  assert.equal(satellite.getAddress(), null);
  assert.deepEqual(heard, [[
    { selected: selection.key, target: { key: selection.key, selector: '.eyebrow' }, address: null },
    { selected: selection.key, target: { key: selection.key, selector: '.eyebrow' }, address: { key: 'container:bbbbbbbb-1111-2222-3333-444444444444', selector: 'span' } },
  ]]);
  // The satellite's address pick crosses the wire as an intent of its
  // own, beside the facet's; a null key is the drop.
  satellite.address('container:bbbbbbbb-1111-2222-3333-444444444444', 'span');
  satellite.address(null);
  await settle();
  assert.deepEqual(wire.frames.filter((frame) => frame.intent === 'address'), [
    { type: 'intent', intent: 'address', key: 'container:bbbbbbbb-1111-2222-3333-444444444444', selector: 'span' },
    { type: 'intent', intent: 'address', key: null, selector: null },
  ]);
  // The popout leader lands each on the leader source's address(), the
  // drop as address(null, null).
  assert.deepEqual(addressed, [['container:bbbbbbbb-1111-2222-3333-444444444444', 'span'], [null, null]]);
  // Deselecting relays the empty selection.
  selection.key = null;
  selection.selector = null;
  targetListeners.forEach((callback) => callback({ selected: null, target: null, address: null }, { selected: 'x', target: { key: 'x', selector: '.eyebrow' }, address: null }));
  await settle();
  assert.equal(satellite.getSelected(), null);
  assert.equal(satellite.getTarget(), null);
  assert.equal(satellite.getAddress(), null);
  wire.close();
});

test('the skin defaults delivered on the leader ride the snapshot, announced on content; a satellite refresh relays as an intent and settles with the leader\'s map', async () => {
  // A leader source stub holding the delivered skin defaults (null until
  // a push refreshed them) and answering a refresh with the map.
  let skinDefaults = null;
  const skinListeners = new Set();
  const targetListeners = new Set();
  let refreshes = 0;
  const leaderSource = {
    getSelected: () => null,
    getTarget: () => null,
    getAddress: () => null,
    getStyleMap: () => ({}),
    getRootUuid: () => 'root',
    getBlockTypes: () => ({}),
    getSectionLayouts: () => ({}),
    getLayoutContext: () => null,
    getMediaUrls: () => ({}),
    canPickMedia: () => false,
    getCanvasVector: () => null,
    getForceState: () => null,
    getPlace: () => ({ place: 'styles', editing: null }),
    getSkinDefaults: () => skinDefaults,
    onSkinDefaultsChange: (callback) => skinListeners.add(callback),
    refreshDefaults: () => {
      refreshes += 1;
      skinDefaults = { 'base:h2': { '': { default: { 'font-size': '3rem' } } } };
      skinListeners.forEach((callback) => callback(skinDefaults));
      return Promise.resolve(skinDefaults);
    },
    onTargetChange: (callback) => targetListeners.add(callback),
    onStyleMapChange: () => {},
    onForceStateChange: () => {},
    onPlaceChange: () => {},
    onCanvasVectorChange: () => () => {},
  };
  const scope = `${SCOPE}-skin`;
  const satellite = createSatelliteSource(scope);
  const announced = [];
  satellite.onSkinDefaultsChange((next) => announced.push(next));
  createPopoutLeader({ scope, url: 'about:blank', source: leaderSource });
  await settle();
  // Nothing delivered yet: the popout page's own guide payload stands.
  assert.equal(satellite.getSkinDefaults(), null);
  assert.deepEqual(announced, []);
  // The satellite's push lands through the leader (the canvas lives
  // there): the intent settles with the leader's map, and the map
  // broadcasts back on its change.
  const delivered = await satellite.refreshDefaults();
  assert.equal(refreshes, 1);
  assert.deepEqual(delivered, { 'base:h2': { '': { default: { 'font-size': '3rem' } } } });
  await settle();
  assert.deepEqual(satellite.getSkinDefaults(), { 'base:h2': { '': { default: { 'font-size': '3rem' } } } });
  assert.deepEqual(announced, [{ 'base:h2': { '': { default: { 'font-size': '3rem' } } } }]);
  // A later broadcast carrying the same map (a selection move) announces
  // nothing: the panel's guide is keyed on content, never on the clone.
  targetListeners.forEach((callback) => callback({ selected: null, target: null }, { selected: 'x', target: null }));
  await settle();
  assert.equal(announced.length, 1);
});

test('a satellite commitBatch settles with the leader\'s op-queue result, success and failure alike', async () => {
  // The leader source stub: its commitBatch is the op queue's promise, a
  // payload on success (the materials delta a material op's caller reads
  // its record from) or an Error carrying .payload on failure.
  const batches = [];
  const minted = { kind: 'swatch', id: 'ember', label: 'Ember', group: 'color', value: '#ff5a1f' };
  const leaderSource = {
    getSelected: () => null,
    getTarget: () => null,
    getAddress: () => null,
    getStyleMap: () => ({}),
    getRootUuid: () => 'root',
    getBlockTypes: () => ({}),
    getSectionLayouts: () => ({}),
    getLayoutContext: () => null,
    getMediaUrls: () => ({}),
    canPickMedia: () => false,
    getCanvasVector: () => null,
    getForceState: () => null,
    getPlace: () => ({ place: 'styles', editing: null }),
    onTargetChange: () => {},
    onStyleMapChange: () => {},
    onForceStateChange: () => {},
    onPlaceChange: () => {},
    onCanvasVectorChange: () => () => {},
    commitBatch: (batch) => {
      batches.push(batch);
      if (batch.operations[0].label === 'Taken') {
        const error = new Error('That label is taken.');
        error.payload = { success: false, errors: [{ path: 'label', code: 'STYLE_SWATCH_LABEL_TAKEN', message: 'That label is taken.', hint: 'Pick another name.' }] };
        return Promise.reject(error);
      }
      return Promise.resolve({ success: true, materials: { upsert: [minted], remove: [] } });
    },
  };
  // Its own scope: the earlier tests' leaders still listen on SCOPE and
  // would answer the intent too.
  const scope = `${SCOPE}-batch`;
  const satellite = createSatelliteSource(scope);
  createPopoutLeader({ scope, url: 'about:blank', source: leaderSource });
  await settle();
  const ok = await satellite.commitBatch({ operations: [{ op: 'save_material', kind: 'swatch', label: 'Ember', value: '#ff5a1f' }], label: 'Added the swatch Ember' });
  // The leader ran the batch verbatim and the satellite read the leader's
  // own payload: the minted record is where a material op's caller looks.
  assert.deepEqual(batches[0], { operations: [{ op: 'save_material', kind: 'swatch', label: 'Ember', value: '#ff5a1f' }], label: 'Added the swatch Ember' });
  assert.deepEqual(ok.materials.upsert, [minted]);
  // A failure rejects in the op queue's shape (message + payload.errors),
  // the one shape failureText reads on either lane.
  await assert.rejects(
    satellite.commitBatch({ operations: [{ op: 'save_material', kind: 'swatch', label: 'Taken', value: '#000' }], label: 'x' }),
    (error) => error.message === 'That label is taken.' && error.payload.errors[0].hint === 'Pick another name.',
  );
  assert.equal(batches.length, 2);
});

test('a satellite parseCss relays the fragment to the leader\'s door and settles with its answer', async () => {
  // The leader source stub: parseCss is the route fetch, the door's
  // answer on 200 (entries, wear, errors) or an Error carrying .payload
  // when the transport refuses (a stranger key).
  const reads = [];
  const answer = { key: 'container:x', entries: [{ key: 'container:x', condition: 'hover', properties: { color: 'red' } }], wear: [], errors: [] };
  const leaderSource = {
    getSelected: () => null,
    getTarget: () => null,
    getAddress: () => null,
    getStyleMap: () => ({}),
    getRootUuid: () => 'root',
    getBlockTypes: () => ({}),
    getSectionLayouts: () => ({}),
    getLayoutContext: () => null,
    getMediaUrls: () => ({}),
    canPickMedia: () => false,
    getCanvasVector: () => null,
    getForceState: () => null,
    getPlace: () => ({ place: 'styles', editing: null }),
    onTargetChange: () => {},
    onStyleMapChange: () => {},
    onForceStateChange: () => {},
    onPlaceChange: () => {},
    onCanvasVectorChange: () => () => {},
    parseCss: (key, css) => {
      reads.push([key, css]);
      if (key === 'container:stranger') {
        const error = new Error('The key names no element on this page.');
        error.payload = { errors: [{ code: 'INVALID_REQUEST', message: 'The key names no element on this page.' }] };
        return Promise.reject(error);
      }
      return Promise.resolve(answer);
    },
  };
  const scope = `${SCOPE}-door`;
  const satellite = createSatelliteSource(scope);
  createPopoutLeader({ scope, url: 'about:blank', source: leaderSource });
  await settle();
  const ok = await satellite.parseCss('container:x', '&:hover { color: red; }');
  // The leader read the fragment under the key verbatim and the
  // satellite got the door's own answer: the island lands it itself.
  assert.deepEqual(reads, [['container:x', '&:hover { color: red; }']]);
  assert.deepEqual(ok, answer);
  await assert.rejects(
    satellite.parseCss('container:stranger', 'color: red;'),
    (error) => error.message === 'The key names no element on this page.',
  );
});

test('every op intent carries a request id and settles from the leader\'s answer; a rejection toasts here', async () => {
  // The leader source stub: each op is the op queue's promise, a payload
  // on success or an Error carrying .payload on failure (the shape
  // failureText reads). 'Taken' fails, everything else lands.
  const calls = [];
  const outcome = (name, args, label) => {
    calls.push([name, ...args]);
    if (label === 'Taken') {
      const error = new Error('That label is taken.');
      error.payload = { success: false, errors: [{ path: 'label', code: 'STYLE_SWATCH_LABEL_TAKEN', message: 'That label is taken.', hint: 'Pick another name.' }] };
      return Promise.reject(error);
    }
    return Promise.resolve({ success: true, op: name });
  };
  const leaderSource = {
    getSelected: () => null,
    getTarget: () => null,
    getAddress: () => null,
    getStyleMap: () => ({}),
    getRootUuid: () => 'root',
    getBlockTypes: () => ({}),
    getSectionLayouts: () => ({}),
    getLayoutContext: () => null,
    getMediaUrls: () => ({}),
    canPickMedia: () => false,
    getCanvasVector: () => null,
    getForceState: () => null,
    getPlace: () => ({ place: 'styles', editing: null }),
    onTargetChange: () => {},
    onStyleMapChange: () => {},
    onForceStateChange: () => {},
    onPlaceChange: () => {},
    onCanvasVectorChange: () => () => {},
    commit: (change) => outcome('commit', [change], change.label),
    commitBatch: (batch) => outcome('commitBatch', [batch], batch.label),
    applyVariant: (key, variant, label) => outcome('applyVariant', [key, variant, label], label),
    detachVariant: (key, variant, label) => outcome('detachVariant', [key, variant, label], label),
  };
  // Its own scope: the earlier tests' leaders still listen on SCOPE and
  // would answer the intents too.
  const scope = `${SCOPE}-ops`;
  const wire = new BroadcastChannel(`daedalus_stylish:${scope}`);
  const intents = [];
  wire.onmessage = (event) => {
    if (event.data.type === 'intent') {
      intents.push(event.data);
    }
  };
  const heard = [];
  globalThis.Drupal = {
    checkPlain: (text) => text.toString(),
    Daedalus: { messenger: { message: (text, type) => heard.push({ text, type }) } },
  };
  const satellite = createSatelliteSource(scope);
  createPopoutLeader({ scope, url: 'about:blank', source: leaderSource });
  await settle();
  const key = 'block:aaaaaaaa-1111-2222-3333-444444444444';
  const change = { key, condition: 'default', properties: { color: 'red' }, label: 'Color' };
  // Success: every op resolves with the leader's own payload, the leader
  // ran it verbatim, and nothing toasts.
  assert.deepEqual(await satellite.commit(change), { success: true, op: 'commit' });
  assert.deepEqual(await satellite.applyVariant(key, 'hero', 'Apply Hero'), { success: true, op: 'applyVariant' });
  assert.deepEqual(await satellite.detachVariant(key, 'hero', 'Detach Hero'), { success: true, op: 'detachVariant' });
  assert.deepEqual(await satellite.commitBatch({ operations: [{ op: 'save_material', kind: 'swatch', label: 'Ember', value: '#ff5a1f' }], label: 'Added the swatch Ember' }), { success: true, op: 'commitBatch' });
  assert.deepEqual(calls, [
    ['commit', change],
    ['applyVariant', key, 'hero', 'Apply Hero'],
    ['detachVariant', key, 'hero', 'Detach Hero'],
    ['commitBatch', { operations: [{ op: 'save_material', kind: 'swatch', label: 'Ember', value: '#ff5a1f' }], label: 'Added the swatch Ember' }],
  ]);
  assert.deepEqual(heard, []);
  // Each intent crossed the wire with its own uuid request id beside its
  // fields (never under the reserved `type` key), and no two share one.
  const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
  assert.deepEqual(intents.map((frame) => frame.intent), ['commit', 'apply-variant', 'detach-variant', 'commit-batch']);
  intents.forEach((frame) => assert.match(frame.request, uuid));
  assert.equal(new Set(intents.map((frame) => frame.request)).size, 4);
  assert.deepEqual(intents[0], { type: 'intent', intent: 'commit', change, request: intents[0].request });
  assert.deepEqual(intents[1], { type: 'intent', intent: 'apply-variant', key, variant: 'hero', label: 'Apply Hero', request: intents[1].request });
  // Failure: every op rejects in the op queue's shape (message +
  // payload.errors) so the caller can mark its input, AND this window
  // toasts failureText once per rejection: the satellite reports its own
  // fates where the hand is.
  const taken = (error) => error.message === 'That label is taken.' && error.payload.errors[0].hint === 'Pick another name.';
  await assert.rejects(satellite.commit({ ...change, label: 'Taken' }), taken);
  await assert.rejects(satellite.applyVariant(key, 'hero', 'Taken'), taken);
  await assert.rejects(satellite.detachVariant(key, 'hero', 'Taken'), taken);
  await assert.rejects(satellite.commitBatch({ operations: [], label: 'Taken' }), taken);
  await settle();
  assert.equal(failureText({ payload: { errors: [{ message: 'That label is taken.', hint: 'Pick another name.' }] } }), 'That label is taken. Pick another name.');
  assert.deepEqual(heard, Array(4).fill({ text: 'That label is taken. Pick another name.', type: 'error' }));
  delete globalThis.Drupal;
  wire.close();
});

test('an address pick crosses the wire as a target intent and echoes back in the snapshot\'s target', async () => {
  // The leader source stub seats the target the way the leader source
  // does (the spine holds it, the target-aware subscription announces
  // it); the popout leader relays the satellite's intent into it and
  // broadcasts the change back. The switcher's pick and "Edit at" ride
  // this one road: no write-address state, intent or snapshot field.
  const selected = 'block:aaaaaaaa-1111-2222-3333-444444444444';
  let held = { key: selected, selector: '.text-block-link' };
  const targetListeners = new Set();
  const leaderSource = {
    getSelected: () => selected,
    getTarget: () => held,
    getAddress: () => null,
    target: (key, selector) => {
      const previous = held;
      held = { key, selector };
      targetListeners.forEach((callback) =>
        callback({ selected, target: held }, { selected, target: previous }),
      );
    },
    select: (key) => {
      const previous = held;
      held = null;
      targetListeners.forEach((callback) =>
        callback({ selected: key, target: null }, { selected, target: previous }),
      );
    },
    getStyleMap: () => ({}),
    getRootUuid: () => 'root',
    getBlockTypes: () => ({}),
    getSectionLayouts: () => ({}),
    getTree: () => [],
    getLayoutContext: () => null,
    getMediaUrls: () => ({}),
    canPickMedia: () => false,
    getCanvasVector: () => null,
    getForceState: () => null,
    getPlace: () => ({ place: 'styles', editing: null }),
    onTargetChange: (callback) => targetListeners.add(callback),
    onStyleMapChange: () => {},
    onForceStateChange: () => {},
    onPlaceChange: () => {},
    onCanvasVectorChange: () => () => {},
  };
  const scope = `${SCOPE}-address`;
  const wire = new BroadcastChannel(`daedalus_stylish:${scope}`);
  const frames = [];
  wire.onmessage = (event) => frames.push(event.data);
  const satellite = createSatelliteSource(scope);
  const heard = [];
  satellite.onTargetChange(({ target }) => heard.push(target));
  createPopoutLeader({ scope, url: 'about:blank', source: leaderSource });
  await settle();
  // The snapshot carries the facet below the selection as the target.
  assert.deepEqual(satellite.getTarget(), { key: selected, selector: '.text-block-link' });
  assert.equal(satellite.getWriteAddress, undefined);
  assert.equal(satellite.setWriteAddress, undefined);
  assert.deepEqual(heard, [{ key: selected, selector: '.text-block-link' }]);
  const address = { key: 'container:bbbbbbbb-1111-2222-3333-444444444444', selector: 'a' };
  satellite.target(address.key, address.selector);
  await settle();
  // The intent carries the address alone, the leader's spine seats it,
  // and the echo lands here once as the snapshot's target.
  assert.deepEqual(frames.filter((frame) => frame.intent === 'target'), [{ type: 'intent', intent: 'target', ...address }]);
  assert.deepEqual(held, address);
  assert.deepEqual(satellite.getTarget(), address);
  assert.deepEqual(heard, [{ key: selected, selector: '.text-block-link' }, address]);
  assert.equal(frames.some((frame) => frame.intent === 'write-address'), false);
  // Dropping to the bare element is a select intent; the target echoes
  // back null.
  satellite.select(selected);
  await settle();
  assert.equal(held, null);
  assert.equal(satellite.getTarget(), null);
  assert.deepEqual(heard, [{ key: selected, selector: '.text-block-link' }, address, null]);
  wire.close();
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
  console.error(`${failures} of ${tests.length} satellite-source tests failed.`);
  process.exit(1);
}
console.log(`${tests.length} satellite-source tests passed.`);
process.exit(0);
