/**
 * Self-asserting tests for the live preview's two-tier delivery
 * (preview.js): the shape (every staged triple as `var(--daedalus-stylish-live-N)`)
 * prints into the preview <style> tag on membership change only, and the
 * value rides inline as that custom property on the key's scope root per
 * tick. WebKit rebuilds its whole style resolver on any sheet mutation, so
 * the sheet text must hold still while a scrub delivers values.
 *
 * Run directly (`node tests/js/preview.test.mjs`) or via JsSuitesTest,
 * which executes this file inside the PHPUnit suite. Exits non-zero on
 * failure.
 *
 * The module touches the DOM through document.createElement and
 * body.appendChild for its <style> tag, and through the injected
 * resolveRoot for the inline write, so a fake tag and a fake root
 * (style.setProperty / removeProperty over a plain object) cover it.
 */
import { strict as assert } from 'node:assert';
import test from 'node:test';
import {
  TOKEN_SCOPE_CLASS,
  createMaterialDelivery,
  createPreview,
  scopedEntries,
} from '../../js/src/preview.js';
import { roster, swatchRecord, tokenRecord } from './materials-fixture.mjs';

const UUID = 'aaaaaaaa-1111-2222-3333-444444444444';
const KEY = `block:${UUID}`;
const MEDIA_UUID = '123e4567-e89b-12d3-a456-426614174000';

const CONDITIONS = {
  hover: { label: 'Hover', wrapper: ':hover', axis: 'state' },
};

// The preview <style> tag's stand-in; recreated per createElement call.
let tag = null;
globalThis.document = {
  createElement: () => {
    tag = { isConnected: true, textContent: '', setAttribute: () => {} };
    return tag;
  },
  body: { appendChild: () => {} },
};
const sheet = () => tag?.textContent ?? '';

/**
 * A fake scope root: its inline custom properties as a plain object.
 */
const createRoot = () => {
  const root = { props: {}, style: {} };
  root.style.setProperty = (name, value) => {
    root.props[name] = value;
  };
  root.style.removeProperty = (name) => {
    delete root.props[name];
  };
  return root;
};

/**
 * A preview over a mutable base map and a root table (root key -> fake
 * root), recording every resolveRoot call.
 */
const setup = ({ base = {}, roots, urls = {}, resolveRoot, conditions = CONDITIONS } = {}) => {
  const state = { base, urls, roots: roots ?? { [KEY]: createRoot() }, lookups: [], scope: createRoot() };
  const preview = createPreview({
    guide: { conditions },
    getBaseMap: () => state.base,
    getMediaUrls: () => state.urls,
    resolveRoot: resolveRoot === undefined
      ? (key) => {
        state.lookups.push(key);
        return state.roots[key] ?? null;
      }
      : resolveRoot,
    // The token scope element: one fake, the same for every read.
    resolveScope: () => state.scope,
  });
  return { preview, state, root: () => state.roots[KEY], scope: () => state.scope };
};

test('the first stage of a triple mints a slot: var() in the sheet, the literal inline on the root', () => {
  const { preview, root } = setup();
  preview.stage(KEY, 'default', 'font-size', '24px');
  assert.ok(sheet().includes(`@scope ([data-selectable="${KEY}"])`));
  assert.ok(sheet().includes('font-size: var(--daedalus-stylish-live-1);'));
  assert.ok(!sheet().includes('24px'), 'the literal never enters the sheet');
  assert.deepEqual(root().props, { '--daedalus-stylish-live-1': '24px' });
});

test('a second stage of the same triple is an inline write only: the sheet text holds still', () => {
  const { preview, root } = setup();
  preview.stage(KEY, 'default', 'font-size', '24px');
  const before = sheet();
  const tagBefore = tag;
  preview.stage(KEY, 'default', 'font-size', '32px');
  preview.stage(KEY, 'default', 'font-size', '40px');
  assert.equal(tag, tagBefore);
  assert.equal(sheet(), before);
  assert.deepEqual(root().props, { '--daedalus-stylish-live-1': '40px' });
});

test('each staged triple has its own slot, and a released triple re-staged mints a fresh one', () => {
  const { preview, root } = setup();
  preview.stage(KEY, 'default', 'font-size', '24px');
  preview.stage(KEY, 'hover', 'color', 'red');
  preview.stage(KEY, 'default', 'padding', '1rem');
  assert.ok(sheet().includes('font-size: var(--daedalus-stylish-live-1);'));
  assert.ok(sheet().includes('color: var(--daedalus-stylish-live-2);'));
  assert.ok(sheet().includes('padding: var(--daedalus-stylish-live-3);'));
  assert.ok(sheet().includes(':hover'), 'the hover wrapper compiles around the var() reference');
  assert.deepEqual(root().props, {
    '--daedalus-stylish-live-1': '24px',
    '--daedalus-stylish-live-2': 'red',
    '--daedalus-stylish-live-3': '1rem',
  });
  preview.release(KEY, 'hover', { color: 'red' });
  preview.stage(KEY, 'hover', 'color', 'blue');
  assert.ok(!sheet().includes('--daedalus-stylish-live-2'));
  assert.ok(sheet().includes('color: var(--daedalus-stylish-live-4);'));
  assert.equal(root().props['--daedalus-stylish-live-4'], 'blue');
  assert.equal('--daedalus-stylish-live-2' in root().props, false);
});

test('release drops the slot, removes the inline property, and re-renders', () => {
  const { preview, root } = setup();
  preview.stage(KEY, 'default', 'font-size', '24px');
  preview.stage(KEY, 'default', 'font-size', '32px');
  preview.release(KEY, 'default', { 'font-size': '32px' });
  assert.equal(sheet(), '', 'nothing staged: the preview empties');
  assert.deepEqual(root().props, {});
});

test('release keeps a re-staged newer value: slot, inline property and sheet all survive', () => {
  const { preview, root } = setup();
  preview.stage(KEY, 'default', 'font-size', '24px');
  const before = sheet();
  // The user kept scrubbing while the 24px op was in flight.
  preview.stage(KEY, 'default', 'font-size', '48px');
  preview.release(KEY, 'default', { 'font-size': '24px' });
  assert.equal(sheet(), before, 'no membership change, no re-render');
  assert.deepEqual(root().props, { '--daedalus-stylish-live-1': '48px' });
  // A release naming nothing staged is a no-op too.
  preview.release(KEY, 'hover', { color: 'red' });
  assert.equal(sheet(), before);
});

test('settling binds the release to what is staged at dispatch: a captured reference releases the literal it previewed', () => {
  const { preview, root } = setup();
  // Magnetic capture: the field previewed the stop's literal and the op
  // commits the token's reference.
  preview.stage(KEY, 'default', 'font-size', '0.8125rem');
  const settling = preview.settling(KEY, 'default', { 'font-size': 'var(--text-xs)' });
  assert.deepEqual(settling, { 'font-size': '0.8125rem' }, 'the staged literal, not the op spelling');
  // Releasing by the op's spelling would strand the literal.
  preview.release(KEY, 'default', { 'font-size': 'var(--text-xs)' });
  assert.notEqual(sheet(), '', 'the literal stays staged under the op spelling');
  preview.release(KEY, 'default', settling);
  assert.equal(sheet(), '', 'the bound release empties the preview');
  assert.deepEqual(root().props, {});
});

test('settling keeps a newer gesture staged during the flight, and passes unstaged properties through', () => {
  const { preview, root } = setup();
  preview.stage(KEY, 'default', 'font-size', '24px');
  const settling = preview.settling(KEY, 'default', { 'font-size': '24px', color: null });
  assert.deepEqual(settling, { 'font-size': '24px', color: null }, 'an unstaged property carries the op value');
  const before = sheet();
  preview.stage(KEY, 'default', 'font-size', '48px');
  preview.release(KEY, 'default', settling);
  assert.equal(sheet(), before, 'the newer gesture survives its predecessor');
  assert.deepEqual(root().props, { '--daedalus-stylish-live-1': '48px' });
});

test('a null stage is omission: the property drops from the print, no slot, no inline write', () => {
  const { preview, root, state } = setup({ base: { [KEY]: { default: { color: 'red', margin: '0' } } } });
  preview.stage(KEY, 'default', 'color', null);
  assert.ok(sheet().includes('margin: 0;'), 'the rest of the base entry still prints');
  assert.ok(!sheet().includes('color'));
  assert.ok(!sheet().includes('--daedalus-stylish-live'));
  assert.deepEqual(root().props, {});
  assert.deepEqual(state.lookups, [], 'no root lookup for an omission');
  // A null after a live value drops the slot and re-renders.
  preview.stage(KEY, 'default', 'margin', '2rem');
  assert.ok(sheet().includes('margin: var(--daedalus-stylish-live-1);'));
  preview.stage(KEY, 'default', 'margin', null);
  assert.equal(sheet(), '', 'both base properties omitted: the key prints nothing');
  assert.deepEqual(root().props, {});
});

test('a blank value mints no slot (it would not print either)', () => {
  const { preview, root } = setup({ base: { [KEY]: { default: { color: 'red' } } } });
  preview.stage(KEY, 'default', 'font-size', '  ');
  assert.ok(!sheet().includes('--daedalus-stylish-live'));
  assert.ok(sheet().includes('color: red;'));
  assert.deepEqual(root().props, {});
});

test('stageEntries prints the whole entry literal in the sheet, the property overlay on top', () => {
  const { preview, root } = setup({ base: { [KEY]: { default: { color: 'red' } } } });
  preview.stageEntries({ [KEY]: { default: { color: 'blue', 'font-size': '20px' } } });
  assert.ok(sheet().includes('color: blue;'));
  assert.ok(sheet().includes('font-size: 20px;'));
  assert.ok(!sheet().includes('--daedalus-stylish-live'));
  assert.deepEqual(root().props, {});
  // A widget's stage on the same key rides a slot over the literal entry.
  preview.stage(KEY, 'default', 'font-size', '24px');
  assert.ok(sheet().includes('color: blue;'));
  assert.ok(sheet().includes('font-size: var(--daedalus-stylish-live-1);'));
  assert.ok(!sheet().includes('20px'));
  // Removing the key previews the removal, and releasing the entry
  // returns to the base with the slot still live.
  preview.stageEntries({ [KEY]: null });
  assert.ok(!sheet().includes('color'));
  assert.ok(sheet().includes('font-size: var(--daedalus-stylish-live-1);'));
  preview.releaseEntries([KEY]);
  assert.ok(sheet().includes('color: red;'));
  assert.ok(sheet().includes('font-size: var(--daedalus-stylish-live-1);'));
  assert.deepEqual(root().props, { '--daedalus-stylish-live-1': '24px' });
});

test('baseChanged re-applies every slot on the freshly resolved roots and re-renders over the new base', () => {
  const { preview, state } = setup();
  preview.stage(KEY, 'default', 'font-size', '24px');
  preview.stage(KEY, 'hover', 'color', 'red');
  // The mirror swap: new markup (fresh root without inline state) and a
  // new base carrying a foreign change.
  state.roots[KEY] = createRoot();
  state.base = { [KEY]: { default: { margin: '0' } } };
  preview.baseChanged();
  assert.deepEqual(state.roots[KEY].props, {
    '--daedalus-stylish-live-1': '24px',
    '--daedalus-stylish-live-2': 'red',
  });
  assert.ok(sheet().includes('margin: 0;'));
  assert.ok(sheet().includes('font-size: var(--daedalus-stylish-live-1);'));
  assert.ok(sheet().includes('color: var(--daedalus-stylish-live-2);'));
});

test('baseChanged with nothing staged touches neither roots nor sheet', () => {
  const { preview, state } = setup();
  tag = null;
  preview.baseChanged();
  assert.equal(tag, null);
  assert.deepEqual(state.lookups, []);
});

test('a missing root still prints the shape, skips the inline write, and never throws', () => {
  const { preview, state } = setup({ roots: {} });
  preview.stage(KEY, 'default', 'font-size', '24px');
  assert.ok(sheet().includes('font-size: var(--daedalus-stylish-live-1);'));
  assert.deepEqual(state.lookups, [KEY]);
  preview.stage(KEY, 'default', 'font-size', '32px');
  preview.baseChanged();
  preview.release(KEY, 'default', { 'font-size': '32px' });
  assert.equal(sheet(), '');
  // No resolveRoot injected at all reads as root missing.
  const bare = createPreview({ guide: { conditions: CONDITIONS }, getBaseMap: () => ({}) });
  bare.stage(KEY, 'default', 'font-size', '24px');
  assert.ok(sheet().includes('font-size: var(--daedalus-stylish-live-1);'));
  bare.release(KEY, 'default', { 'font-size': '24px' });
});

test('a part key writes on the scope root, never on the part', () => {
  const { preview, state, root } = setup();
  preview.stage(`${KEY}::.quote`, 'default', 'color', 'red');
  assert.deepEqual(state.lookups, [KEY]);
  assert.deepEqual(root().props, { '--daedalus-stylish-live-1': 'red' });
  assert.ok(sheet().includes('.quote'));
  assert.ok(sheet().includes('color: var(--daedalus-stylish-live-1);'));
});

test('media tokens substitute inline per value from the live table', () => {
  const { preview, root, state } = setup({ urls: { [MEDIA_UUID]: '/files/hero.png' } });
  preview.stage(KEY, 'default', 'background-image', `linear-gradient(red, blue), url('media:${MEDIA_UUID}')`);
  assert.equal(root().props['--daedalus-stylish-live-1'], 'linear-gradient(red, blue), url("/files/hero.png")');
  assert.ok(sheet().includes('background-image: var(--daedalus-stylish-live-1);'));
  assert.ok(!sheet().includes('hero.png'));
  // An unknown uuid degrades to none, and a table extended before the
  // next stage answers on that write (read per write, not captured).
  preview.stage(KEY, 'default', 'background-image', `url(media:${MEDIA_UUID.replace('1', '9')})`);
  assert.equal(root().props['--daedalus-stylish-live-1'], 'none');
  state.urls[MEDIA_UUID.replace('1', '9')] = '/files/other.png';
  preview.stage(KEY, 'default', 'background-image', `url(media:${MEDIA_UUID.replace('1', '9')})`);
  assert.equal(root().props['--daedalus-stylish-live-1'], 'url("/files/other.png")');
});

// ----- The token scope lane. -----

// The scheme axis the compiled skin file wraps a dark entry under.
const SCHEME_CONDITIONS = {
  ...CONDITIONS,
  narrow: { label: 'Narrow', wrapper: '@container page (max-width: 500px)', axis: 'width' },
  dark: { label: 'Dark', wrapper: '@media (prefers-color-scheme: dark)', axis: 'scheme' },
};

test('scopedEntries: a string is its default entry, a map its live entries', () => {
  assert.deepEqual(scopedEntries('#fff'), { default: '#fff' });
  assert.deepEqual(scopedEntries(' 1rem '), { default: '1rem' });
  assert.deepEqual(scopedEntries({ default: '#fff', dark: '#111' }), { default: '#fff', dark: '#111' });
  // Blank and null entries print nothing, so they stage nothing.
  assert.deepEqual(scopedEntries({ default: '#fff', dark: '' }), { default: '#fff' });
  assert.deepEqual(scopedEntries(null), {});
  assert.deepEqual(scopedEntries(''), {});
});

test('stageScoped: the shape prints on the token scope element, the literal rides inline there', () => {
  const { preview, scope, root } = setup();
  preview.stageScoped('--color-accent', '#1475ad');
  assert.ok(sheet().includes(`@scope (.${TOKEN_SCOPE_CLASS})`), 'the block opens at the token scope element, as the compiled file prints it');
  assert.ok(sheet().includes('--color-accent: var(--daedalus-stylish-live-1);'));
  assert.ok(!sheet().includes('#1475ad'), 'the literal never enters the sheet');
  assert.deepEqual(scope().props, { '--daedalus-stylish-live-1': '#1475ad' });
  assert.deepEqual(root().props, {}, 'nothing lands on a style-key root');
  // A second value is one inline write: the sheet holds still.
  const before = sheet();
  preview.stageScoped('--color-accent', '#116392');
  assert.equal(sheet(), before);
  assert.deepEqual(scope().props, { '--daedalus-stylish-live-1': '#116392' });
});

test('stageScoped: every condition of a paired value rides its own slot under its wrapper', () => {
  const { preview, scope } = setup({ conditions: SCHEME_CONDITIONS });
  preview.stageScoped('--color-bg', { default: '#ffffff', dark: '#0b0d0f' });
  assert.ok(sheet().includes('--color-bg: var(--daedalus-stylish-live-1);'));
  assert.ok(sheet().includes('@media (prefers-color-scheme: dark)'), 'the dark entry keeps its wrapper: the other scheme never wears the wrong value');
  assert.ok(sheet().includes('--color-bg: var(--daedalus-stylish-live-2);'));
  assert.deepEqual(scope().props, {
    '--daedalus-stylish-live-1': '#ffffff',
    '--daedalus-stylish-live-2': '#0b0d0f',
  });
  // Dragging one entry is an inline write on its slot alone.
  const before = sheet();
  preview.stageScoped('--color-bg', { default: '#fdfdf6', dark: '#0b0d0f' });
  assert.equal(sheet(), before);
  assert.equal(scope().props['--daedalus-stylish-live-1'], '#fdfdf6');
  // Dropping the dark entry is a membership change: its slot goes, the
  // default keeps its slot.
  preview.stageScoped('--color-bg', '#fdfdf6');
  assert.ok(!sheet().includes('prefers-color-scheme'));
  assert.ok(sheet().includes('--color-bg: var(--daedalus-stylish-live-1);'));
  assert.deepEqual(scope().props, { '--daedalus-stylish-live-1': '#fdfdf6' });
});

test('stageScoped: a container condition brings the page container root along', () => {
  const { preview } = setup({ conditions: SCHEME_CONDITIONS });
  // The client's containerRootsCss reads guide.containerRoots; the
  // setup's guide lacks it, so the rule is withheld here...
  preview.stageScoped('--space-gutter', { default: '2rem', narrow: '1rem' });
  assert.ok(sheet().includes('@container page (max-width: 500px)'));
  assert.ok(!sheet().includes('container-type'));
  // ...and printed with it (the compiled file's rule, replicated).
  const withRoots = createPreview({
    guide: { conditions: SCHEME_CONDITIONS, containerRoots: true },
    getBaseMap: () => ({}),
    resolveScope: () => null,
  });
  withRoots.stageScoped('--space-gutter', { default: '2rem', narrow: '1rem' });
  assert.ok(sheet().includes('.dialog-off-canvas-main-canvas {\n  container-type: inline-size;\n}'));
});

test('releaseScoped drops the slots, the inline properties and the block; a blank value releases too', () => {
  const { preview, scope } = setup({ conditions: SCHEME_CONDITIONS });
  preview.stageScoped('--color-bg', { default: '#ffffff', dark: '#0b0d0f' });
  preview.stageScoped('--color-accent', '#1475ad');
  preview.releaseScoped('--color-bg');
  assert.ok(!sheet().includes('--color-bg'));
  assert.ok(sheet().includes('--color-accent: var(--daedalus-stylish-live-3);'));
  assert.deepEqual(scope().props, { '--daedalus-stylish-live-3': '#1475ad' });
  preview.stageScoped('--color-accent', '');
  assert.equal(sheet(), '', 'nothing staged: the preview empties');
  assert.deepEqual(scope().props, {});
  // A release naming nothing staged is a no-op.
  preview.releaseScoped('--color-accent');
  assert.equal(sheet(), '');
});

test('the scope lane and the style-key overlay share one sheet and one slot sequence', () => {
  const { preview, scope, root } = setup();
  preview.stage(KEY, 'default', 'font-size', '24px');
  preview.stageScoped('--swatch-brand_red', '#b91c1c');
  assert.ok(sheet().includes(`@scope ([data-selectable="${KEY}"])`));
  assert.ok(sheet().includes(`@scope (.${TOKEN_SCOPE_CLASS})`));
  assert.deepEqual(root().props, { '--daedalus-stylish-live-1': '24px' });
  assert.deepEqual(scope().props, { '--daedalus-stylish-live-2': '#b91c1c' });
  // Releasing the style key keeps the scope block, and vice versa.
  preview.release(KEY, 'default', { 'font-size': '24px' });
  assert.ok(!sheet().includes('font-size'));
  assert.ok(sheet().includes('--swatch-brand_red: var(--daedalus-stylish-live-2);'));
  preview.releaseScoped('--swatch-brand_red');
  assert.equal(sheet(), '');
});

test('baseChanged rewrites the scope slots too', () => {
  const { preview, scope, state } = setup();
  preview.stageScoped('--color-accent', '#1475ad');
  // The token scope element outlives a markup swap; a fresh element
  // (a full re-render) still gets every slot back.
  state.scope = createRoot();
  preview.baseChanged();
  assert.deepEqual(scope().props, { '--daedalus-stylish-live-1': '#1475ad' });
});

test('without a token scope element the shape still prints and nothing throws', () => {
  const bare = createPreview({ guide: { conditions: CONDITIONS }, getBaseMap: () => ({}) });
  bare.stageScoped('--color-accent', '#1475ad');
  assert.ok(sheet().includes('--color-accent: var(--daedalus-stylish-live-1);'));
  bare.releaseScoped('--color-accent');
  assert.equal(sheet(), '');
});

// ----- The committed materials' delivery. -----

const BOOT = roster(
  tokenRecord('color', 'accent', '#1475ad'),
  tokenRecord('color', 'bg', { default: '#ffffff', dark: '#0b0d0f' }),
  swatchRecord('brand_red', 'color', '#b91c1c'),
);

test('delivery stages what differs from boot, releases what returns to it, never touches the unchanged', () => {
  const { preview, scope } = setup({ conditions: SCHEME_CONDITIONS });
  const delivery = createMaterialDelivery(preview, BOOT);
  // The boot roster itself delivers nothing: the compiled files print it.
  delivery.apply(BOOT);
  assert.equal(sheet(), '');
  assert.deepEqual(scope().props, {});
  // An edit: the changed token alone, condition-honest.
  delivery.apply(roster(
    tokenRecord('color', 'accent', '#1475ad'),
    tokenRecord('color', 'bg', { default: '#fdfdf6', dark: '#0b0d0f' }),
    swatchRecord('brand_red', 'color', '#b91c1c'),
  ));
  assert.ok(sheet().includes('--color-bg: var(--daedalus-stylish-live-1);'));
  assert.ok(sheet().includes('@media (prefers-color-scheme: dark)'));
  assert.ok(!sheet().includes('--color-accent'));
  assert.ok(!sheet().includes('--swatch-brand_red'));
  assert.deepEqual(scope().props, {
    '--daedalus-stylish-live-1': '#fdfdf6',
    '--daedalus-stylish-live-2': '#0b0d0f',
  });
  // A revert: back to the boot value, the property releases.
  delivery.apply(BOOT);
  assert.equal(sheet(), '');
  assert.deepEqual(scope().props, {});
});

test('delivery stages a minted material and releases a deleted one', () => {
  const { preview, scope } = setup({ conditions: SCHEME_CONDITIONS });
  const delivery = createMaterialDelivery(preview, BOOT);
  delivery.apply(roster(
    tokenRecord('color', 'accent', '#1475ad'),
    tokenRecord('color', 'bg', { default: '#ffffff', dark: '#0b0d0f' }),
    tokenRecord('space', 'hero', '3rem'),
    swatchRecord('brand_red', 'color', '#b91c1c'),
    swatchRecord('dusk', 'color', '#334455'),
  ));
  assert.ok(sheet().includes('--space-hero: var(--daedalus-stylish-live-1);'));
  assert.ok(sheet().includes('--swatch-dusk: var(--daedalus-stylish-live-2);'));
  assert.deepEqual(scope().props, {
    '--daedalus-stylish-live-1': '3rem',
    '--daedalus-stylish-live-2': '#334455',
  });
  // The delete: the rewrite gave every use the literal, the file's stale
  // property changes nothing, and the lane forgets it.
  delivery.apply(BOOT);
  assert.equal(sheet(), '');
  assert.deepEqual(scope().props, {});
});

test('delivery.restore re-delivers one record after a draft: the store value, or the file', () => {
  const { preview, scope } = setup({ conditions: SCHEME_CONDITIONS });
  const delivery = createMaterialDelivery(preview, BOOT);
  const accent = tokenRecord('color', 'accent', '#1475ad');
  // A draft on an unchanged record, then its release: nothing stays.
  preview.stageScoped('--color-accent', '#ff0000');
  assert.equal(scope().props['--daedalus-stylish-live-1'], '#ff0000');
  delivery.restore(accent);
  assert.equal(sheet(), '');
  assert.deepEqual(scope().props, {});
  // A draft over a delivered (edited) record restores the committed value.
  const edited = tokenRecord('color', 'accent', '#116392');
  delivery.apply(roster(edited, tokenRecord('color', 'bg', { default: '#ffffff', dark: '#0b0d0f' }), swatchRecord('brand_red', 'color', '#b91c1c')));
  preview.stageScoped('--color-accent', '#ff0000');
  delivery.restore(edited);
  assert.ok(sheet().includes('--color-accent: var(--daedalus-stylish-live-'));
  assert.deepEqual(Object.values(scope().props), ['#116392']);
});
