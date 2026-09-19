/**
 * Self-asserting tests for the media pick delivery (the inspector media
 * row's spine): a library selection must extend the live uuid -> URL table,
 * stage an instant token preview resolved through it, and commit the token
 * as one ordinary update_style op with the usual settle semantics. A
 * `companions` map in the pick payload (the basic Background widget's
 * seed; the advanced row passes none) must merge into that SAME op and
 * stage beside the token.
 *
 * Run directly (`node tests/js/media-picker.test.mjs`) or via
 * MediaClientMirrorTest, which executes this file inside the PHPUnit suite.
 * Exits non-zero on failure.
 *
 * The leader-source import is dynamic on purpose: media-library.js registers
 * its AJAX command handler at module evaluation, so the Drupal and
 * drupalSettings stubs must exist before the import graph loads.
 */
import { strict as assert } from 'node:assert';
import { EMPTY_MATERIALS } from './materials-fixture.mjs';

const KEY = 'block:aaaaaaaa-1111-2222-3333-444444444444';
const ROOT = 'bbbbbbbb-1111-2222-3333-444444444444';
const PICKED = '123e4567-e89b-12d3-a456-426614174000';
const TOKEN = `url('media:${PICKED}')`;

// The preview <style> tag's and the canvas scope root's stand-ins (see
// leader-source.test.mjs): the sheet prints each staged property as
// var(--daedalus-stylish-live-N), the literal (media token already resolved) lands
// inline on the root. The root is recreated per app.
let tag = null;
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
globalThis.document = {
  createElement: () => {
    tag = { isConnected: true, textContent: '', setAttribute: () => {} };
    return tag;
  },
  body: { appendChild: () => {} },
  querySelector: (selector) => (selector === `[data-selectable="${KEY}"]` ? root : null),
};
const previewCss = () => tag?.textContent ?? '';
const liveValues = () => ({ ...(root?.props ?? {}) });
const liveValueList = () => Object.values(liveValues());

// The modal helper's stand-in: openMediaLibrary reads the settings URL and
// hands it to daedalus_ui's Drupal.Daedalus.openMediaLibraryModal
// (the dialog shape is that helper's own contract, locked by its own suite);
// the selection command handler registers on Drupal.AjaxCommands.prototype
// at bundle evaluation, and the server's response invokes it exactly like
// deliverSelection() does below.
const ajaxCalls = [];
globalThis.Drupal = {
  AjaxCommands: function () {},
  Daedalus: {
    openMediaLibraryModal: (url) => ajaxCalls.push({ url }),
  },
  t: (text) => text,
};
globalThis.drupalSettings = { daedalusStylish: { mediaLibrary: { url: '/daedalus-stylish-media-library' } } };

const { createLeaderSource } = await import('../../js/src/sources/leader-source.js');

const deliverSelection = (response) =>
  new Drupal.AjaxCommands().daedalusStylishMediaLibrarySelection(null, response);

/**
 * The fake app facade (leader-source.test.mjs shape): mutate() parks its
 * settlement in `pending` so a test controls when each op settles.
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
      getRoot: () => ({ uuid: ROOT }),
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
    Selection: { getSelected: () => KEY },
    onBlueprintChange: () => {},
    onSelectionChange: () => {},
  };
  return app;
};

const settleMicrotasks = () => new Promise((resolve) => setImmediate(resolve));

const tests = [];
const test = (name, run) => tests.push([name, run]);

test('a selection extends the table, previews instantly, and commits the token', async () => {
  const app = createApp();
  const mediaUrls = {};
  const source = createLeaderSource(app, {}, mediaUrls);
  assert.equal(source.canPickMedia(), true);
  assert.equal(source.getMediaUrls(), mediaUrls);

  ajaxCalls.length = 0;
  const opened = source.pickMedia({
    key: KEY,
    condition: 'default',
    property: 'background-image',
    label: 'Styled background-image',
  });
  assert.equal(opened, true);
  assert.equal(ajaxCalls.length, 1);
  assert.equal(ajaxCalls[0].url, '/daedalus-stylish-media-library');
  // Nothing staged, nothing committed until the user actually selects.
  assert.equal(previewCss(), '');
  assert.deepEqual(liveValues(), {});
  assert.equal(app.mutations.length, 0);

  deliverSelection({ uuid: PICKED, token: TOKEN, url: '/files/hero.png' });
  // The live table gained the server-resolved URL (the preview transform
  // and the row thumbnail both read this object)...
  assert.equal(mediaUrls[PICKED], '/files/hero.png');
  // ...the preview staged instantly: the sheet carries the slot, the
  // inline literal is the token already resolved through the table...
  assert.ok(previewCss().includes('background-image: var(--daedalus-stylish-live-1)'));
  assert.deepEqual(liveValues(), { '--daedalus-stylish-live-1': 'url("/files/hero.png")' });
  assert.ok(!previewCss().includes('media:'));
  // ...and the semantic token went out as one ordinary update_style op.
  assert.equal(app.mutations.length, 1);
  assert.equal(app.mutations[0].label, 'Styled background-image');
  assert.deepEqual(app.mutations[0].operations, [
    { op: 'update_style', key: KEY, properties: { 'background-image': TOKEN } },
  ]);

  // Settled: the mirror owns the token now; the staged preview releases.
  app.pending[0].resolve({});
  await settleMicrotasks();
  assert.equal(previewCss(), '');
  assert.deepEqual(liveValues(), {});
  assert.deepEqual(app.failures, []);
});

test('a basic pick merges its companions into the one commit and stages them', async () => {
  const app = createApp();
  const mediaUrls = {};
  const source = createLeaderSource(app, {}, mediaUrls);
  const companions = {
    'background-size': 'cover',
    'background-repeat': 'no-repeat',
    'background-position': 'center',
  };
  source.pickMedia({
    key: KEY,
    condition: 'default',
    property: 'background-image',
    label: 'Styled Background',
    companions,
  });
  deliverSelection({ uuid: PICKED, token: TOKEN, url: '/files/hero.png' });
  // ONE update_style op carries the token AND the seed (one batch, one
  // undo; the seed-at-pick decision)...
  assert.equal(app.mutations.length, 1);
  assert.deepEqual(app.mutations[0].operations, [
    {
      op: 'update_style',
      key: KEY,
      properties: { 'background-image': TOKEN, ...companions },
    },
  ]);
  // ...and the instant preview already paints the seeded posture instead
  // of flashing the tiled browser defaults: every companion has its own
  // slot in the sheet and its literal inline.
  ['background-size', 'background-repeat', 'background-position'].forEach((property) => {
    assert.ok(previewCss().includes(`${property}: var(--daedalus-stylish-live-`));
  });
  assert.deepEqual(liveValueList().sort(), ['center', 'cover', 'no-repeat', 'url("/files/hero.png")']);
  // Settled: the one properties map releases every staged property.
  app.pending[0].resolve({});
  await settleMicrotasks();
  assert.equal(previewCss(), '');
  assert.deepEqual(liveValues(), {});
  assert.deepEqual(app.failures, []);
});

test('malformed companions degrade to the bare token commit', async () => {
  const app = createApp();
  const source = createLeaderSource(app, {}, {});
  source.pickMedia({ key: KEY, property: 'background-image', companions: ['cover'] });
  deliverSelection({ uuid: PICKED, token: TOKEN, url: '/files/hero.png' });
  assert.equal(app.mutations.length, 1);
  assert.deepEqual(app.mutations[0].operations, [
    { op: 'update_style', key: KEY, properties: { 'background-image': TOKEN } },
  ]);
  app.pending[0].resolve({});
  await settleMicrotasks();
});

test('a selection without a URL still commits; the preview degrades to none', async () => {
  const app = createApp();
  const mediaUrls = {};
  const source = createLeaderSource(app, {}, mediaUrls);
  source.pickMedia({ key: KEY, property: 'background-image' });

  deliverSelection({ uuid: PICKED, token: TOKEN, url: null });
  // No table entry, so the staged preview resolves to the emit-gate "none"
  // (inline, where the value lives); the token still commits (the server
  // renders the same degradation).
  assert.deepEqual(mediaUrls, {});
  assert.ok(previewCss().includes('background-image: var(--daedalus-stylish-live-1)'));
  assert.deepEqual(liveValues(), { '--daedalus-stylish-live-1': 'none' });
  assert.equal(app.mutations.length, 1);
  app.pending[0].resolve({});
  await settleMicrotasks();
});

test('a failed commit reverts the preview and reports on this page', async () => {
  const app = createApp();
  const source = createLeaderSource(app, {}, {});
  source.pickMedia({ key: KEY, property: 'background-image' });
  deliverSelection({ uuid: PICKED, token: TOKEN, url: '/files/hero.png' });
  assert.equal(app.mutations.length, 1);

  app.pending[0].reject(new Error('validator said no'));
  await settleMicrotasks();
  assert.equal(previewCss(), '');
  assert.deepEqual(liveValues(), {});
  assert.equal(app.failures.length, 1);
  assert.equal(app.failures[0].key, KEY);
});

test('without a library URL the picker cannot open (free-text degradation)', () => {
  const app = createApp();
  const source = createLeaderSource(app, {}, {});
  const settings = drupalSettings.daedalusStylish.mediaLibrary;
  delete drupalSettings.daedalusStylish.mediaLibrary;
  try {
    ajaxCalls.length = 0;
    assert.equal(source.canPickMedia(), false);
    assert.equal(source.pickMedia({ key: KEY, property: 'background-image' }), false);
    assert.equal(ajaxCalls.length, 0);
    assert.equal(app.mutations.length, 0);
  }
  finally {
    drupalSettings.daedalusStylish.mediaLibrary = settings;
  }
});

let failures = 0;
for (const [name, run] of tests) {
  try {
    await run();
    console.log(`ok - ${name}`);
  }
  catch (error) {
    failures += 1;
    console.error(`not ok - ${name}`);
    console.error(error?.message ?? error);
  }
}
if (failures > 0) {
  console.error(`${failures} of ${tests.length} media-picker tests failed.`);
  process.exit(1);
}
console.log(`${tests.length} media-picker tests passed.`);
