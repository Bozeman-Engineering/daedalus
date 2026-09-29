/**
 * Self-asserting tests for the op queue's response application and the
 * materials delivery seams around it.
 *
 * Locks the client half of the delivery contract: the store boots from the
 * drupalSettings envelope BlueprintMirror attaches (daedalusUi
 * .materials, `{records: [...]}`), applyResponse applies `materials`
 * {upsert, remove} and `history` on EVERY payload, a materials-only
 * payload (no blueprint, entity_uuid or markup: an AI turn that minted
 * and mutated no page) leaves the mirror alone, an applied delta is
 * relayed over the `materials:<scope>` BroadcastChannel and a relayed
 * delta received from another window is applied without ringing back, the
 * armed edit session hears a response (payload in hand) only after its
 * piggybacked commands, a job (a write answering the contract by its own
 * transport, daedalus_edit's form commit) sequences FIFO with the ops
 * around it and fails like one, the contract's Drupal AJAX command applies
 * a carried payload through the same pipeline, and the facade's reads and
 * bound grammar answer from the store.
 *
 * The queue imports the app store (webpack-style extensionless imports;
 * window, document, Drupal and drupalSettings globals; BroadcastChannel),
 * so the suite registers an extension-adding resolve hook and stubs the
 * globals before dynamically importing it.
 *
 * Run with `npm test` (node --test tests/js/*.test.mjs) from the module root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { registerHooks } from 'node:module';

registerHooks({
  resolve(specifier, context, nextResolve) {
    try {
      return nextResolve(specifier, context);
    } catch (error) {
      if (specifier.startsWith('.') || specifier.startsWith('/')) {
        return nextResolve(`${specifier}.js`, context);
      }
      throw error;
    }
  },
});

/**
 * A BroadcastChannel stub: every channel of a name shares one bus, posts
 * are recorded per name, and a post is delivered to the OTHER channels of
 * that name (never echoed to the poster, as the real wire behaves).
 */
const buses = new Map();
const posted = [];
class FakeBroadcastChannel {
  constructor(name) {
    this.name = name;
    this.onmessage = null;
    if (!buses.has(name)) {
      buses.set(name, new Set());
    }
    buses.get(name).add(this);
  }

  postMessage(data) {
    posted.push({ name: this.name, data });
    buses.get(this.name).forEach((other) => {
      if (other !== this) {
        other.onmessage?.({ data });
      }
    });
  }

  close() {
    buses.get(this.name).delete(this);
  }
}
globalThis.BroadcastChannel = FakeBroadcastChannel;
globalThis.window = globalThis;
// The markup swap holds the window's scroll offsets across the swap
// (preserve-scroll.js); a window stub without them would fail every
// markup-applying case.
globalThis.scrollX = 0;
globalThis.scrollY = 0;
globalThis.scrollTo = () => {};
globalThis.document = {
  addEventListener: () => {},
  querySelector: () => null,
  querySelectorAll: () => [],
  documentElement: { addEventListener: () => {} },
};
globalThis.Drupal = { t: (string) => string, behaviors: {} };

const record = (fields) => ({
  kind: 'token', id: '', label: '', description: '', value: null, group: null, spellings: {}, scope: null, applies: [],
  owned_by: null, published: true, weight: 0, icon: null, root_kind: null, origin: 'user', updatable: true, ...fields,
});
const RECORDS = [
  record({ kind: 'token', id: 'color/accent', label: 'accent', value: { default: '#1475ad' }, group: 'color', spellings: { style: 'var(--color-accent)' }, origin: 'shipped' }),
  record({ kind: 'swatch', id: 'lime_green', label: 'Lime green', value: '#4dff8c', group: 'color', spellings: { style: 'var(--swatch-lime_green)' } }),
  record({ kind: 'variant', id: 'button', label: 'Button', group: 'style', spellings: { class: 'button' }, scope: 'block_type:text', origin: 'shipped' }),
];
const CORAL = record({ kind: 'swatch', id: 'coral', label: 'Coral', value: '#ff7f50', group: 'color', spellings: { style: 'var(--swatch-coral)' } });
const SCOPE = 'node-uuid:en:live';

globalThis.drupalSettings = {
  daedalusUi: {
    blueprint: JSON.stringify({ entityType: 'node', entityId: '1', uuid: 'root-uuid', blueprint: { _tree: { kind: 'root' } } }),
    materials: JSON.stringify({ records: RECORDS }),
    editSession: { scope: SCOPE },
    responsivePreview: { url: '/preview' },
  },
};

const { default: store } = await import('../../js/edit_mode/store/store.js');
const { applyResponse } = await import('../../js/edit_mode/ops/op-queue.js');
const { initMaterialsStore } = await import('../../js/edit_mode/materials/materials-store.js');
const { initBlueprintMirror } = await import('../../js/edit_mode/blueprint/blueprint-mirror.js');
const { initSelectionFacade } = await import('../../js/edit_mode/selection/selection-facade.js');
const history = await import('../../js/edit_mode/history/history.js');

initBlueprintMirror();
initMaterialsStore();
initSelectionFacade();

const materialsPosts = () => posted.filter((entry) => entry.name === `materials:${SCOPE}`);
const swatchIds = () => store.getState().materials.records.swatch.map((entry) => entry.id);

test('the store boots from the drupalSettings envelope beside the blueprint mirror', () => {
  assert.equal(store.getState().materials.booted, true);
  assert.deepEqual(swatchIds(), ['lime_green']);
  assert.equal(store.getState().blueprint.root.uuid, 'root-uuid');
  // Re-attaching with the same envelope boots nothing (identity kept).
  const before = store.getState().materials.records;
  Drupal.behaviors.NpMaterialsStore.attach();
  assert.equal(store.getState().materials.records, before);
});

test('applyResponse applies materials and history on a page payload', async () => {
  const mirror = store.getState().blueprint.blueprint;
  await applyResponse({
    success: true,
    entity_uuid: 'root-uuid',
    blueprint: { _tree: { kind: 'root', mutated: true } },
    markup: {},
    attachments: [],
    drupal_commands: [],
    history: { pointer: 3, can_undo: true, can_redo: false, entries: [{ seq: 3, label: 'Minted Coral' }] },
    materials: { upsert: [CORAL], remove: [] },
  });
  assert.deepEqual(swatchIds(), ['coral', 'lime_green']);
  assert.notEqual(store.getState().blueprint.blueprint, mirror);
  assert.equal(store.getState().blueprint.blueprint._tree.mutated, true);
  assert.equal(history.getState().pointer, 3);
  assert.equal(history.getState().canUndo, true);
  assert.deepEqual(history.getState().entries, [{ seq: 3, label: 'Minted Coral' }]);
  // The applied delta is relayed verbatim on the materials wire.
  assert.deepEqual(materialsPosts().map((entry) => entry.data), [{ upsert: [CORAL], remove: [], type: 'delta' }]);
});

test('an empty delta touches nothing and relays nothing', async () => {
  const records = store.getState().materials.records;
  const relayed = materialsPosts().length;
  await applyResponse({ success: true, materials: { upsert: [], remove: [] } });
  assert.equal(store.getState().materials.records, records);
  assert.equal(materialsPosts().length, relayed);
});

test('a materials-only payload applies its delta and leaves the mirror alone', async () => {
  const mirror = store.getState().blueprint.blueprint;
  const root = store.getState().blueprint.root;
  await applyResponse({
    success: true,
    targets: [],
    errors: [],
    warnings: [],
    markup: [],
    attachments: [],
    drupal_commands: [],
    materials: { upsert: [], remove: [{ kind: 'swatch', id: 'lime_green' }] },
  });
  assert.deepEqual(swatchIds(), ['coral']);
  assert.equal(store.getState().blueprint.blueprint, mirror);
  assert.equal(store.getState().blueprint.root, root);
});

test('a payload for another entity applies its delta without replacing the mirror', async () => {
  const mirror = store.getState().blueprint.blueprint;
  await applyResponse({
    success: true,
    entity_uuid: 'other-uuid',
    blueprint: { _tree: { kind: 'root', foreign: true } },
    materials: { upsert: [{ ...CORAL, value: '#ff6f40' }], remove: [] },
  });
  assert.equal(store.getState().blueprint.blueprint, mirror);
  assert.equal(store.getState().materials.records.swatch[0].value, '#ff6f40');
});

test('a malformed delta fails loudly instead of being absorbed', async () => {
  await assert.rejects(
    () => applyResponse({ success: true, materials: { upsert: [{ kind: 'texture', id: 'x' }], remove: [] } }),
    /names a material kind and id/,
  );
});

test('a delta relayed from another window is applied and not rung back', () => {
  const relayed = materialsPosts().length;
  const other = new FakeBroadcastChannel(`materials:${SCOPE}`);
  other.postMessage({ upsert: [], remove: [{ kind: 'swatch', id: 'coral' }], type: 'delta' });
  assert.deepEqual(swatchIds(), []);
  // The other window's own post is the only new frame: this window did not relay it.
  assert.equal(materialsPosts().length, relayed + 1);
  other.close();
});

test('the facade reads from the store and binds the grammar to its roster', async () => {
  const { Materials } = Drupal.Daedalus;
  assert.deepEqual(Materials.KINDS, ['token', 'swatch', 'variant', 'stencil', 'animation']);
  assert.equal(Materials.isBooted(), true);
  assert.equal(Materials.get('variant', 'button').label, 'Button');
  assert.equal(Materials.has('swatch', 'coral'), false);
  assert.equal(Materials.bySpelling('class', 'button').id, 'button');
  assert.deepEqual(Object.keys(Materials.spellings('style')), ['var(--color-accent)']);
  assert.equal(Materials.resolveValue('var(--color-accent)', Materials.SLOT.STYLE).kind, Materials.KIND.TOKEN);
  assert.equal(Materials.resolveValue('var(--swatch-coral)', 'style').kind, Materials.KIND.UNKNOWN);
  assert.equal(Materials.resolveValue('#fff', 'style').kind, Materials.KIND.LITERAL);
  assert.equal(Materials.canonical('var( --color-accent )', 'style'), 'var(--color-accent)');
  assert.equal(Materials.spell(Materials.get('variant', 'button'), 'class'), 'button');
  assert.equal(Materials.applicable(Materials.get('variant', 'button'), 'block_type:text'), true);
  assert.equal(Materials.applicable(Materials.get('variant', 'button'), 'kind:container'), false);

  const changes = [];
  const unsubscribe = Materials.onChange((roster) => changes.push(roster.ofKind('swatch').map((entry) => entry.id)));
  await applyResponse({ success: true, materials: { upsert: [CORAL], remove: [] } });
  assert.deepEqual(changes, [['coral']]);
  assert.equal(Materials.resolveValue('var(--swatch-coral)', 'style').record, Materials.get('swatch', 'coral'));
  assert.equal(Materials.getRoster(), Materials.getRoster());
  // A selection change is not a materials change.
  Drupal.Daedalus.Selection.select('block:u1');
  assert.equal(changes.length, 1);
  unsubscribe();
});

test('the armed edit session hears a response, with its payload, only once it is FULLY applied', async () => {
  const order = [];
  const ajax = Drupal.ajax;
  Drupal.ajax = () => ({ success: (commands) => { order.push(`commands:${commands.length}`); } });
  Drupal.Daedalus.EditSession = {
    mutationApplied: (payload) => {
      order.push(`applied:${store.getState().blueprint.blueprint._tree.stamp}:${payload.targets.length}`);
    },
  };
  try {
    await applyResponse({
      success: true,
      entity_uuid: 'root-uuid',
      blueprint: { _tree: { kind: 'root', stamp: 'fresh' } },
      markup: {},
      targets: [{ uuid: 'root-uuid', field_name: 'title' }],
      attachments: [{ command: 'add_css' }],
      drupal_commands: [{ command: 'editModeMessage' }, { command: 'insert' }],
      materials: { upsert: [], remove: [] },
    });
  } finally {
    Drupal.ajax = ajax;
    delete Drupal.Daedalus.EditSession;
  }
  // Assets, then the piggybacked commands, and only then the session with
  // the payload in hand: the mirror is already fresh.
  assert.deepEqual(order, ['commands:1', 'commands:2', 'applied:fresh:1']);
});

test('a job sequences FIFO with ops, and a failed job rolls back what is queued behind it', async () => {
  const order = [];
  const applied = [];
  const rolledBack = [];
  const ajax = Drupal.ajax;
  Drupal.ajax = () => ({ success: () => {} });
  Drupal.url = (path) => `/${path}`;
  Drupal.Daedalus.getCookieValue = () => 'edit';
  drupalSettings.ajaxPageState = {};
  const payloadFor = (stamp) => ({
    success: true,
    entity_uuid: 'root-uuid',
    blueprint: { _tree: { kind: 'root', stamp } },
    markup: {},
    targets: [],
    attachments: [],
    drupal_commands: [],
    materials: { upsert: [], remove: [] },
  });
  let sent = 0;
  globalThis.fetch = async (url) => {
    if (String(url).includes('session/token')) {
      return { text: async () => 'token' };
    }
    sent += 1;
    const stamp = `op-${sent}`;
    order.push(`send:${stamp}`);
    return { json: async () => payloadFor(stamp) };
  };
  Drupal.Daedalus.EditSession = {
    mutationApplied: (payload) => { applied.push(payload.blueprint._tree.stamp); },
  };
  const { enqueueOperations, enqueueJob, isDraining, drained } = await import('../../js/edit_mode/ops/op-queue.js');
  const context = { entityType: 'node', entityId: '1', operations: [], viewMode: 'full' };
  try {
    const first = enqueueOperations(context);
    // The job's transport applies its own payload during the round (the
    // command handler's job in the browser) and resolves with it.
    const job = enqueueJob({
      run: async () => {
        order.push('job:run');
        const payload = payloadFor('job');
        await applyResponse(payload);
        return payload;
      },
    });
    const last = enqueueOperations(context);
    assert.equal(isDraining(), true);
    const results = await Promise.all([first, job, last]);
    await drained();
    assert.equal(isDraining(), false);
    assert.deepEqual(order, ['send:op-1', 'job:run', 'send:op-2']);
    assert.deepEqual(applied, ['op-1', 'job', 'op-2']);
    assert.deepEqual(results.map((payload) => payload.blueprint._tree.stamp), ['op-1', 'job', 'op-2']);

    // A job whose transport refuses (a form's validation errors) rejects
    // with that refusal and rolls back the op queued behind it, newest
    // first, exactly as a failed op does; the queue then drains.
    const failing = enqueueJob({
      run: async () => { throw new Error('invalid widget'); },
    });
    const behind = enqueueOperations({
      ...context,
      optimistic: { apply: () => {}, rollback: () => rolledBack.push('behind') },
    });
    await assert.rejects(failing, /invalid widget/);
    await assert.rejects(behind, /preceding mutation failed/);
    assert.deepEqual(rolledBack, ['behind']);
    await drained();
    assert.equal(isDraining(), false);
  } finally {
    Drupal.ajax = ajax;
    delete Drupal.Daedalus.EditSession;
  }
});

test('the daedalusBlueprintApplyResponse command applies a success and hands every payload to its carrier', async () => {
  const applied = [];
  Drupal.AjaxCommands = function AjaxCommands() {};
  const { initApplyResponseCommand } = await import('../../js/edit_mode/ops/op-queue.js');
  initApplyResponseCommand();
  Drupal.Daedalus.EditSession = {
    mutationApplied: (payload) => { applied.push(payload.blueprint._tree.stamp); },
  };
  try {
    const handler = Drupal.AjaxCommands.prototype.daedalusBlueprintApplyResponse;
    const carrier = {};
    const success = {
      success: true,
      entity_uuid: 'root-uuid',
      blueprint: { _tree: { kind: 'root', stamp: 'carried' } },
      markup: {},
      targets: [],
      attachments: [],
      drupal_commands: [],
      materials: { upsert: [], remove: [] },
    };
    await handler(carrier, { command: 'daedalusBlueprintApplyResponse', payload: success });
    assert.equal(carrier.daedalusBlueprintPayload, success);
    assert.deepEqual(applied, ['carried']);
    assert.equal(store.getState().blueprint.blueprint._tree.stamp, 'carried');

    // A failed payload is handed over untouched and applies nothing.
    const failure = { success: false, errors: [{ message: 'nope' }] };
    const other = {};
    await handler(other, { command: 'daedalusBlueprintApplyResponse', payload: failure });
    assert.equal(other.daedalusBlueprintPayload, failure);
    assert.deepEqual(applied, ['carried']);
    assert.equal(store.getState().blueprint.blueprint._tree.stamp, 'carried');
  } finally {
    delete Drupal.Daedalus.EditSession;
  }
});

test('a markup swap revalidates the styling target: a target whose key has no element clears, the selection stays', async () => {
  const { setTarget } = await import('../../js/edit_mode/store/slices/selection-slice.js');
  const Selection = Drupal.Daedalus.Selection;
  // Only the selected element exists on the (stub) page: the target's
  // ancestor rule names a container the swap has removed.
  const elements = { 'block:kept': { tagName: 'DIV' } };
  const querySelector = document.querySelector;
  document.querySelector = (selector) => {
    const match = /^\[data-selectable="(.+)"\]$/.exec(selector);
    return match ? (elements[match[1]] ?? null) : null;
  };
  try {
    Selection.clear();
    Selection.select('block:kept');
    // Seated through the slice: the facade's ancestry check has no
    // projection index to consult in this suite.
    store.dispatch(setTarget({ key: 'container:gone', selector: '> pb-text' }));
    assert.deepEqual(Selection.getTarget(), { key: 'container:gone', selector: '> pb-text' });
    await applyResponse({
      success: true,
      entity_uuid: 'root-uuid',
      blueprint: { _tree: { kind: 'root' } },
      markup: {},
      targets: [],
      attachments: [],
      drupal_commands: [],
      materials: { upsert: [], remove: [] },
    });
    assert.equal(Selection.getSelected(), 'block:kept');
    assert.equal(Selection.getTarget(), null);
    // A target on an element that survives the swap survives with it.
    store.dispatch(setTarget({ key: 'block:kept', selector: '.icon' }));
    await applyResponse({
      success: true,
      entity_uuid: 'root-uuid',
      blueprint: { _tree: { kind: 'root' } },
      markup: {},
      targets: [],
      attachments: [],
      drupal_commands: [],
      materials: { upsert: [], remove: [] },
    });
    assert.equal(Selection.getSelected(), 'block:kept');
    assert.deepEqual(Selection.getTarget(), { key: 'block:kept', selector: '.icon' });
  } finally {
    document.querySelector = querySelector;
    Selection.clear();
  }
});
