/**
 * Self-asserting tests for the leader source's settle semantics, the
 * compound-commit path in particular: a widget gesture that batches its own
 * update_style op with a parent-keyed companion (one mutate() batch, one
 * undo label) must have every key's staged preview released when the batch
 * settles, success or failure alike.
 *
 * Run directly (`node tests/js/leader-source.test.mjs`) or via
 * PrinterEquivalenceTest, which executes this file inside the PHPUnit suite.
 * Exits non-zero on failure.
 *
 * The preview machinery touches the DOM through document.createElement and
 * body.appendChild for its <style> tag and through document.querySelector
 * for the scope root its inline values land on, so a small stub suffices:
 * the assertions read the fake tag's textContent (empty = nothing staged;
 * a staged property prints as var(--daedalus-stylish-live-N)) and the fake root's
 * inline custom properties (the literal values).
 */
import { strict as assert } from 'node:assert';
import { createLeaderSource } from '../../js/src/sources/leader-source.js';
import { compoundOperations, createControlWidgetRegistry } from '../../js/src/controls.js';
import { EMPTY_MATERIALS, animationRecord, roster, swatchRecord } from './materials-fixture.mjs';
import { printKeyframes } from '../../js/src/printer.js';

const UUID_A = 'aaaaaaaa-1111-2222-3333-444444444444';
const UUID_B = 'bbbbbbbb-1111-2222-3333-444444444444';
const CHILD = `block:${UUID_A}`;
const PARENT = `container:${UUID_B}`;

// The preview <style> tag's stand-in; recreated per createElement call.
// The animation preview tag (the delivery extension's own <style>) is
// told apart by the attribute it stamps and kept aside.
let tag = null;
let animationTag = null;
// The canvas scope root's stand-in: every [data-selectable] lookup answers
// this one element (CHILD and PARENT alike; their slot names differ), its
// inline custom properties kept as a plain object. Recreated per app.
let root = null;
const createRoot = () => {
  const element = { props: {}, style: {} };
  element.style.setProperty = (name, value) => {
    element.props[name] = value;
  };
  element.style.removeProperty = (name) => {
    delete element.props[name];
  };
  return element;
};
// The token scope element's stand-in (DefaultsStylesheet::SCOPE_CLASS): the
// swatch delivery extension stamps custom properties inline on it.
let tokenScope = null;
globalThis.document = {
  createElement: () => {
    const created = { isConnected: true, textContent: '', writes: 0, setAttribute: () => {} };
    created.setAttribute = (name) => {
      if (name === 'data-daedalus-stylish-animation-preview') {
        animationTag = created;
      }
    };
    tag = created;
    return created;
  },
  body: { appendChild: () => {} },
  querySelector: (selector) => {
    if (selector === '.daedalus-stylish-token-scope') {
      return tokenScope;
    }
    return selector.startsWith('[data-selectable=') ? root : null;
  },
};
const previewCss = () => tag?.textContent ?? '';
const animationCss = () => animationTag?.textContent ?? '';
// The literal values currently inline on the root, by slot name.
const liveValues = () => ({ ...(root?.props ?? {}) });
const liveValueList = () => Object.values(liveValues());

/**
 * A fake app facade: mutate() parks its settlement in `pending` so a test
 * controls when (and how) each batch settles.
 */
const createApp = () => {
  root = createRoot();
  const blueprint = { fields: { daedalus_stylish_styles: {} } };
  const app = {
    mutations: [],
    failures: [],
    pending: [],
    Blueprint: {
      getBlueprint: () => blueprint,
      getRoot: () => ({ uuid: UUID_B }),
      rootContext: () => ({ entityType: 'node', entityId: '1' }),
      mutate: (payload) => {
        app.mutations.push(payload);
        return new Promise((resolve, reject) => app.pending.push({ resolve, reject }));
      },
      reportFailure: (error, key) => app.failures.push({ error, key }),
      applyResponse: (payload) => app.applied.push(payload),
    },
    applied: [],
    // The materials facade: a fixed roster and the change subscription the
    // source's swatch delivery extension binds to.
    materialsListeners: [],
    Materials: {
      getRoster: () => app.materials,
      get: (kind, id) => app.materials.get(kind, id),
      onChange: (callback) => {
        app.materialsListeners.push(callback);
        return () => {};
      },
    },
    materials: EMPTY_MATERIALS,
    fireMaterialsChange: (roster) => {
      app.materials = roster;
      app.materialsListeners.forEach((callback) => callback(roster));
    },
    Selection: {
      getSelected: () => CHILD,
      getSelectedPart: () => app.selectedPart,
      select: (key, part = null) => app.selections.push({ key, part }),
      getHoveredPart: () => app.hoveredPart,
      hoverPart: (key, part = null) => app.partHovers.push(key && part ? { key, part } : null),
    },
    selectedPart: null,
    selections: [],
    hoveredPart: null,
    partHovers: [],
    onHoverPartChange: (callback) => app.hoverPartListeners.push(callback),
    hoverPartListeners: [],
    onBlueprintChange: () => {},
    onSelectionChange: (callback) => app.selectionListeners.push(callback),
    selectionListeners: [],
    fireSelectionChange: (key) => app.selectionListeners.forEach((callback) => callback(key)),
    onPartChange: (callback) => app.partListeners.push(callback),
    partListeners: [],
  };
  return app;
};

const tests = [];
const test = (name, run) => tests.push([name, run]);

test('the swatch delivery extension follows the materials store and previews drafts', () => {
  const app = createApp();
  tokenScope = createRoot();
  const source = createLeaderSource(app);
  // The roster reads and the change subscription ride the app's facade.
  assert.equal(source.getMaterials(), EMPTY_MATERIALS);
  const seen = [];
  source.onMaterialsChange((next) => seen.push(next));
  // A store change (a mint, an edit, a relayed delta) re-stamps every
  // swatch's custom property inline on the token scope element, the
  // compiled file's element; condition-keyed values stamp their default.
  const brand = swatchRecord('brand_red', 'color', '#b91c1c', 'Brand red');
  const paper = swatchRecord('paper', 'color', { default: '#fdfdf6', dark: '#15151a' }, 'Paper');
  const minted = roster(brand, paper);
  app.fireMaterialsChange(minted);
  assert.deepEqual(tokenScope.props, { '--swatch-brand_red': '#b91c1c', '--swatch-paper': '#fdfdf6' });
  assert.deepEqual(seen, [minted]);
  // A removed swatch keeps its stamped property (the delete rewrote every
  // reference to the literal value; the look must not change).
  app.fireMaterialsChange(roster(brand));
  assert.deepEqual(tokenScope.props, { '--swatch-brand_red': '#b91c1c', '--swatch-paper': '#fdfdf6' });
  // A preview lands a draft on the same property; null releases it back
  // to the record's own value; a record the store lost previews nothing.
  source.previewSwatch(brand, '#ff0000');
  assert.equal(tokenScope.props['--swatch-brand_red'], '#ff0000');
  source.previewSwatch(brand, null);
  assert.equal(tokenScope.props['--swatch-brand_red'], '#b91c1c');
  source.previewSwatch(null, '#00ff00');
  assert.deepEqual(tokenScope.props, { '--swatch-brand_red': '#b91c1c', '--swatch-paper': '#fdfdf6' });
  // A gesture route's response lands through the op queue's response path.
  const response = { swatch: { machine_name: 'brand_red' }, materials: { upsert: [brand], remove: [] } };
  source.applyResponse(response);
  assert.deepEqual(app.applied, [response]);
  tokenScope = null;
});

test('the animation delivery extension prints the roster and substitutes session drafts', () => {
  const app = createApp();
  tokenScope = createRoot();
  animationTag = null;
  const source = createLeaderSource(app);
  // No animations, no tag: an empty roster mints no <style>.
  app.fireMaterialsChange(EMPTY_MATERIALS);
  assert.equal(animationTag, null);
  // A store change (a mint, an edit, a relayed delta) prints every
  // rostered animation's @keyframes into the preview tag, later in the
  // document than the compiled file, so a minted animation plays before
  // any reload.
  const fade = animationRecord('fade_in', 'Fade in', { '0%': { opacity: '0' }, '100%': { opacity: '1' } });
  const spin = animationRecord('spin', 'Spin', { '0%': { transform: 'rotate(0deg)' }, '100%': { transform: 'rotate(360deg)' } }, { origin: 'shipped' });
  app.fireMaterialsChange(roster(fade, spin));
  assert.equal(animationCss(), `${printKeyframes('motion-fade_in', fade.value)}\n${printKeyframes('motion-spin', spin.value)}`);
  // The swatch extension and the animation extension share the change
  // subscription; a swatch-only change leaves the sheet's text alone.
  const brand = swatchRecord('brand_red', 'color', '#b91c1c', 'Brand red');
  app.fireMaterialsChange(roster(fade, spin, brand));
  assert.equal(tokenScope.props['--swatch-brand_red'], '#b91c1c');
  assert.ok(animationCss().includes('@keyframes motion-spin'));
  // A session's draft substitutes for the animation it edits; the other
  // animations keep their stored steps.
  const draft = { '0%': { opacity: '0.2' }, '100%': { opacity: '1' } };
  source.previewAnimation(fade, draft);
  assert.equal(animationCss(), `${printKeyframes('motion-fade_in', draft)}\n${printKeyframes('motion-spin', spin.value)}`);
  // A store change mid-session keeps the draft in front of the record.
  app.fireMaterialsChange(roster(fade, spin));
  assert.ok(animationCss().includes('opacity: 0.2'));
  // Null releases: the sheet returns to the record's own steps; a record
  // the store lost previews nothing.
  source.previewAnimation(fade, null);
  assert.equal(animationCss(), `${printKeyframes('motion-fade_in', fade.value)}\n${printKeyframes('motion-spin', spin.value)}`);
  source.previewAnimation(null, draft);
  assert.ok(!animationCss().includes('opacity: 0.2'));
  // A deleted animation leaves the sheet (its wearers were rewritten to
  // none server-side); the tag stays, empty, so nothing else needs a
  // second tag.
  app.fireMaterialsChange(EMPTY_MATERIALS);
  assert.equal(animationCss(), '');
  tokenScope = null;
  animationTag = null;
});

test('a settled compound batch releases every key\'s staged preview', async () => {
  const app = createApp();
  const source = createLeaderSource(app);
  // The gesture's optimistic paint: the child's alignment AND the parent's
  // mid-gesture display: grid stage under their own keys.
  source.preview(CHILD, 'default', 'align-self', 'center');
  source.preview(PARENT, 'default', 'display', 'grid');
  // Shape in the sheet, values inline: each staged property prints as its
  // live slot's var() and the literal rides the scope root.
  assert.ok(previewCss().includes('align-self: var(--daedalus-stylish-live-1)'));
  assert.ok(previewCss().includes('display: var(--daedalus-stylish-live-2)'));
  assert.deepEqual(liveValues(), { '--daedalus-stylish-live-1': 'center', '--daedalus-stylish-live-2': 'grid' });

  const request = source.commitBatch({
    operations: compoundOperations([
      { key: CHILD, properties: { 'align-self': 'center' } },
      { key: PARENT, properties: { display: 'grid' } },
    ]),
    label: 'Styled Position',
  });
  // One mutate() call carries the whole gesture: both ops, one undo label.
  assert.equal(app.mutations.length, 1);
  assert.equal(app.mutations[0].label, 'Styled Position');
  assert.deepEqual(app.mutations[0].operations.map((op) => op.key), [CHILD, PARENT]);
  // In flight, the previews keep painting.
  assert.ok(previewCss().includes('display: var(--daedalus-stylish-live-2)'));
  assert.equal(liveValues()['--daedalus-stylish-live-2'], 'grid');

  app.pending[0].resolve({});
  await request;
  // Settled: the mirror owns the values now; the whole overlay is gone,
  // sheet and inline alike.
  assert.equal(previewCss(), '');
  assert.deepEqual(liveValues(), {});
  assert.deepEqual(app.failures, []);
});

test('a failed compound batch honestly reverts both keys and reports once', async () => {
  const app = createApp();
  const source = createLeaderSource(app);
  source.preview(CHILD, 'default', 'align-self', 'center');
  source.preview(PARENT, 'default', 'display', 'grid');

  const request = source.commitBatch({
    operations: compoundOperations([
      { key: CHILD, properties: { 'align-self': 'center' } },
      { key: PARENT, properties: { display: 'grid' } },
    ]),
    label: 'Styled Position',
  });
  const failure = new Error('validator said no');
  app.pending[0].reject(failure);
  await assert.rejects(request, failure);
  // All or nothing: the batch failed, so NEITHER preview may linger — the
  // parent grid reverts with the child alignment, and the op failure
  // report lands once, flashed at the first op's scope root.
  assert.equal(previewCss(), '');
  assert.deepEqual(liveValues(), {});
  assert.equal(app.failures.length, 1);
  assert.equal(app.failures[0].key, CHILD);
});

test('a newer staged value survives its predecessor batch\'s settlement', async () => {
  const app = createApp();
  const source = createLeaderSource(app);
  source.preview(PARENT, 'default', 'display', 'grid');
  const request = source.commitBatch({
    operations: compoundOperations([{ key: PARENT, properties: { display: 'grid' } }]),
    label: 'Styled layout',
  });
  // The user keeps going while the batch is in flight: the re-staged value
  // must outlive the older batch's release (value-matched, not blanket).
  source.preview(PARENT, 'default', 'display', 'flex');
  app.pending[0].resolve({});
  await request;
  // The triple's slot survives (same membership, newer literal inline).
  assert.ok(previewCss().includes('display: var(--daedalus-stylish-live-1)'));
  assert.deepEqual(liveValueList(), ['flex']);
});

test('a key-less material op batch releases nothing and reports without an element', async () => {
  const app = createApp();
  const source = createLeaderSource(app);
  // A staged preview on another key must survive a material op's batch:
  // save_material addresses no style key, so the release set is empty.
  source.preview(CHILD, 'default', 'text-align', 'center');
  const operation = { op: 'save_material', kind: 'swatch', label: 'Ember', group: 'color', value: '#ff5a1f' };
  const request = source.commitBatch({ operations: [operation], label: 'Added the swatch Ember' });
  assert.deepEqual(app.mutations[0].operations, [operation]);
  const payload = { success: true, materials: { upsert: [{ kind: 'swatch', id: 'ember' }], remove: [] } };
  app.pending[0].resolve(payload);
  // The batch resolves with the applied payload (the caller reads its
  // record from the delta) and the unrelated preview still paints.
  assert.deepEqual(await request, payload);
  assert.ok(previewCss().includes('text-align: var(--daedalus-stylish-live-1)'));
  // A failure reports once, flashing no element (there is none).
  const failing = source.commitBatch({ operations: [operation], label: 'Added the swatch Ember' });
  const failure = new Error('That label is taken.');
  app.pending[1].reject(failure);
  await assert.rejects(failing, failure);
  assert.deepEqual(app.failures.map((entry) => entry.key), [null]);
});

test('single-op commit() settle semantics still hold beside the batch path', async () => {
  const app = createApp();
  const source = createLeaderSource(app);
  source.preview(CHILD, 'default', 'text-align', 'center');
  const request = source.commit({
    key: CHILD,
    condition: 'default',
    properties: { 'text-align': 'center' },
    label: 'Styled Text alignment',
  });
  assert.equal(app.mutations.length, 1);
  assert.deepEqual(app.mutations[0].operations, [
    { op: 'update_style', key: CHILD, properties: { 'text-align': 'center' } },
  ]);
  app.pending[0].resolve({});
  await request;
  assert.equal(previewCss(), '');
  assert.deepEqual(liveValues(), {});
});

test('force state is selection-scoped and the vector degrades without its facade', () => {
  const app = createApp();
  const source = createLeaderSource(app);
  // No Drupal global in node: the vector honestly reads null and posing
  // it is a no-op, never a throw.
  assert.equal(source.getCanvasVector(), null);
  source.setCanvasVector({ width: 375 });
  assert.equal(typeof source.onCanvasVectorChange(() => {}), 'function');

  const heard = [];
  source.onForceStateChange((state) => heard.push(state));
  assert.equal(source.getForceState(), null);
  source.setForceState('hover');
  assert.equal(source.getForceState(), 'hover');
  // Same value: no announcement; '' normalizes to null.
  source.setForceState('hover');
  source.setForceState('');
  source.setForceState('focus');
  // A selection move clears the forced state — an editing pose for ONE
  // element, never a sticky mode.
  app.fireSelectionChange(PARENT);
  assert.equal(source.getForceState(), null);
  assert.deepEqual(heard, ['hover', null, 'focus', null]);
});

test('the selection channel carries the part facet through to the spine', () => {
  const app = createApp();
  const source = createLeaderSource(app);
  // select() passes the facet (or its absence) verbatim to the spine
  // facade: a bare key is the facet-clearing gesture, never rewritten.
  source.select(CHILD, '.quote');
  source.select(CHILD);
  assert.deepEqual(app.selections, [
    { key: CHILD, part: '.quote' },
    { key: CHILD, part: null },
  ]);
  // Reads and the part-aware subscription delegate to the spine: no
  // source-local part state exists anymore.
  app.selectedPart = '.quote';
  assert.equal(source.getSelectedPart(), '.quote');
  const heard = [];
  source.onPartChange((current, previous) => heard.push([current, previous]));
  app.partListeners.forEach((callback) => callback({ key: CHILD, part: '.quote' }, { key: CHILD, part: null }));
  assert.deepEqual(heard, [[{ key: CHILD, part: '.quote' }, { key: CHILD, part: null }]]);
});

test('the hover-part channel delegates to the spine facade', () => {
  const app = createApp();
  const source = createLeaderSource(app);
  // Writes pass through verbatim: a (key, part) pair sets the facet, a
  // bare/null call clears it — the facade owns the shape.
  source.hoverPart(CHILD, '.quote');
  source.hoverPart(null);
  assert.deepEqual(app.partHovers, [{ key: CHILD, part: '.quote' }, null]);
  // Reads and the subscription delegate too: no source-local hover state.
  app.hoveredPart = { key: CHILD, part: '.quote' };
  assert.deepEqual(source.getHoveredPart(), { key: CHILD, part: '.quote' });
  const heard = [];
  source.onHoverPartChange((pair) => heard.push(pair));
  app.hoverPartListeners.forEach((callback) => callback({ key: CHILD, part: '.quote' }));
  assert.deepEqual(heard, [{ key: CHILD, part: '.quote' }]);
});

test('the place state defaults to styles, normalizes, and holds across selection moves', () => {
  const app = createApp();
  const source = createLeaderSource(app);
  const heard = [];
  source.onPlaceChange((state) => heard.push(state));
  // The home place, no editing session mounted.
  assert.deepEqual(source.getPlace(), { place: 'styles', editing: null });
  // An editing reference passes verbatim (machine name or uuid alike),
  // and the session overlays any place.
  source.setPlace({ place: 'materials', editing: 'cta' });
  assert.deepEqual(source.getPlace(), { place: 'materials', editing: 'cta' });
  // Same value: no announcement; '' editing normalizes to null and an
  // off-vocabulary place (the deleted pill row's 'library' included)
  // lands on the home place.
  source.setPlace({ place: 'materials', editing: 'cta' });
  source.setPlace({ place: 'library', editing: '' });
  source.setPlace({ place: 'bogus', editing: UUID_A });
  assert.deepEqual(source.getPlace(), { place: 'styles', editing: UUID_A });
  // NOT selection-scoped at the source: the selection-move rule
  // (surface-state.js selectionMove) needs the library and the open
  // session, which are panel knowledge; the panels apply it and write
  // the result back.
  app.fireSelectionChange(PARENT);
  assert.deepEqual(source.getPlace(), { place: 'styles', editing: UUID_A });
  // Reads hand out copies: mutating one never corrupts the state.
  const copy = source.getPlace();
  copy.place = 'materials';
  assert.deepEqual(source.getPlace(), { place: 'styles', editing: UUID_A });
  assert.deepEqual(heard, [
    { place: 'materials', editing: 'cta' },
    { place: 'styles', editing: null },
    { place: 'styles', editing: UUID_A },
  ]);
});

test('notify rides this window\'s messenger and is never load-bearing', () => {
  const app = createApp();
  const source = createLeaderSource(app);
  // No Drupal at all (unit contexts, a page without the app chrome):
  // guarded like announce, the call silently no-ops.
  assert.equal(globalThis.Drupal, undefined);
  source.notify('status', 'Added the Rain gradient to the color palette.');
  // Drupal without the messenger attached (daedalus_ui/messenger
  // never loaded): still a no-op.
  globalThis.Drupal = { Daedalus: {} };
  source.notify('status', 'Added the Rain gradient to the color palette.');
  // The messenger present: text and type land in ITS argument order,
  // message(text, type).
  const heard = [];
  globalThis.Drupal.Daedalus.messenger = { message: (text, type) => heard.push({ text, type }) };
  source.notify('error', 'That label is taken.');
  assert.deepEqual(heard, [{ text: 'That label is taken.', type: 'error' }]);
  delete globalThis.Drupal;
});

test('getLayoutContext serves per-declared-part presence: the guide + annotation join', () => {
  const app = createApp();
  // Declared at the kind for the test's simplicity; block-type elements
  // resolve through the same declarationForKey path (parts.test.mjs).
  const source = createLeaderSource(app, {
    kinds: { block: { elements: {
      '.text-block-link': { label: 'Link' },
      // The image part's declared shape: part-level label as the
      // fallback, ordered forms carrying the per-form names (the guide
      // ships forms as authored; partsForKey reads only the part label).
      '.image-block-image': { label: 'Image', forms: [
        { tag: 'img', label: 'Raster' },
        { tag: 'svg', label: 'Vector' },
      ] },
      '.card__ornament': { label: 'Ornament' },
    } } },
  });
  // The model's answers (app.Blueprint.partPresence over the markup
  // annotation): anchor present, image positively absent (the EMPTY
  // image block: no media, so neither form matches), ornament
  // unspoken-for (null), omitted from the facts so gates degrade to
  // offering.
  app.Blueprint.partPresence = (key, selector) => {
    assert.equal(key, CHILD);
    return { '.text-block-link': true, '.image-block-image': false, '.card__ornament': null }[selector];
  };
  // A minimal canvas: the selected block (computed facts still read the
  // DOM; presence does not).
  const block = {
    nodeType: 1,
    childNodes: [],
    children: [],
    parentElement: null,
    matches: () => false,
    querySelector: () => null,
  };
  const baseQuerySelector = document.querySelector;
  document.querySelector = (selector) => (selector === `[data-selectable="${CHILD}"]` ? block : null);
  globalThis.window = { getComputedStyle: () => ({ display: 'block', flexDirection: 'row', alignItems: 'normal' }) };
  try {
    assert.deepEqual(source.getLayoutContext().self.parts, {
      '.text-block-link': true,
      '.image-block-image': false,
    });
  } finally {
    document.querySelector = baseQuerySelector;
    delete globalThis.window;
  }
});

test('a vector-present part serves presence TRUE and keeps its controls offered', () => {
  const app = createApp();
  // The image part under the svg-root model: one part, ordered forms
  // (img/Raster on raster, svg/Vector on vector). On vector media the
  // part matches its svg form, so presence HOLDS — the old "svg media
  // has no picture" absence is gone.
  const source = createLeaderSource(app, {
    kinds: { block: { elements: {
      '.image-block-image': { label: 'Image', forms: [
        { tag: 'img', label: 'Raster' },
        { tag: 'svg', label: 'Vector' },
      ] },
    } } },
  });
  app.Blueprint.partPresence = (key, selector) => {
    assert.equal(key, CHILD);
    return { '.image-block-image': true }[selector];
  };
  const block = {
    nodeType: 1,
    childNodes: [],
    children: [],
    parentElement: null,
    matches: () => false,
    querySelector: () => null,
  };
  const baseQuerySelector = document.querySelector;
  document.querySelector = (selector) => (selector === `[data-selectable="${CHILD}"]` ? block : null);
  globalThis.window = { getComputedStyle: () => ({ display: 'block', flexDirection: 'row', alignItems: 'normal' }) };
  try {
    const layout = source.getLayoutContext();
    assert.deepEqual(layout.self.parts, { '.image-block-image': true });
    // The join's consequence: an element-bound control (Image width) is
    // NOT hidden by the presence gate on vector media — only a positive
    // absence (false) hides, and the vector-present part answers true.
    const registry = createControlWidgetRegistry();
    registry.register('width', {});
    const bound = { element: '.image-block-image', values: {}, hasMore: false };
    assert.equal(registry.offered('width', bound, layout), true);
    assert.equal(
      registry.offered('width', bound, { self: { parts: { '.image-block-image': false } } }),
      false,
    );
  } finally {
    document.querySelector = baseQuerySelector;
    delete globalThis.window;
  }
});

test('getMarkup serves the mirror annotation through the app facade', () => {
  const app = createApp();
  const markup = { tag: 'h2', instances: [{ class: 's-a3', tag: 'p', label: 'Paragraph', children: [] }] };
  app.Blueprint.markupForKey = (key) => (key === CHILD ? markup : null);
  const source = createLeaderSource(app, {});
  assert.equal(source.getMarkup(CHILD), markup);
  assert.equal(source.getMarkup('block:other'), null);
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
  console.error(`${failures} of ${tests.length} leader-source tests failed.`);
  process.exit(1);
}
console.log(`${tests.length} leader-source tests passed.`);
