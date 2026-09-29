/**
 * Self-asserting tests for the wearing rows' pure model (the styling
 * place; four controls, one loud mode).
 *
 * Run directly (`node tests/js/wear-bar.test.mjs`) or via
 * PrinterEquivalenceTest inside the PHPUnit suite. Locks the row roster
 * (a distributed wearing's origin riding into its row included), the
 * variant-reach read behind Re-apply, the reset-overrides op builder,
 * and the per-row menu roster (a variant is edited in the editing
 * session, and the menu's edit entry is a doorway into it); the row
 * label, the origin's name and the menu header live in
 * wear-bar-model.test.mjs. Exits non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import {
  wornRows,
  wornProperties,
  outlineGesture,
  overriddenWearing,
  reachesBeyond,
  refusalText,
  resetOverrideOps,
  staleReason,
  wearRowMenu,
} from '../../js/src/wear-bar-model.js';
import { variantRecord } from './materials-fixture.mjs';

const tests = [];
const test = (name, run) => tests.push([name, run]);

// The store's variant records (materials-fixture.mjs), the shape every
// `variants` argument here reads.
const LIBRARY = [
  variantRecord('cta', { label: 'CTA', value: { '': { default: { color: 'red' } } } }),
  variantRecord('row', { label: 'Columns', group: 'container_layout', value: { '': { default: { display: 'flex' } } }, weight: 10, description: 'Children side by side.' }),
  variantRecord('hero', {
    label: 'Hero',
    value: {
      '': { default: { padding: '2rem' }, dark: { background: 'black' } },
      '::.inner': { default: { gap: '1rem' } },
      'block(text,1)': { default: { margin: '0' } },
    },
  }),
  variantRecord('retired_axis', { group: 'width', value: { '': {} } }),
  // The shipped width posture: the band's inset spelled on the logical
  // inline pair (daedalus_stylish_layouts container_site).
  variantRecord('site', { label: 'Site width', group: 'width', value: { '': { default: { 'padding-inline': 'max(var(--space-gutter), calc((100% - var(--layout-max-width)) / 2))' } } } }),
];

test('wornRows: worn references only, in vocabulary order; empty axes have no row', () => {
  // Worn out of vocabulary order: rows follow the axes, not the list.
  const entry = { variants: [{ variant: 'row', fragment: '' }, { variant: 'cta', fragment: '' }] };
  const { rows, stale } = wornRows(entry, LIBRARY, ['style', 'container_layout', 'emphasis']);
  assert.deepEqual(rows, [
    { axis: 'style', ref: { variant: 'cta', fragment: '', via: null } },
    { axis: 'container_layout', ref: { variant: 'row', fragment: '', via: null } },
  ]);
  assert.deepEqual(stale, []);
  // Nothing worn: no rows at all (offering is the picker's gesture).
  assert.deepEqual(wornRows({ default: { color: 'red' } }, LIBRARY, ['style', 'emphasis']), { rows: [], stale: [] });
  assert.deepEqual(wornRows(null, LIBRARY, ['style']), { rows: [], stale: [] });
});

test('wornRows: a worn axis outside the vocabulary appends (axis-gone)', () => {
  const entry = { variants: [{ variant: 'retired_axis', fragment: '' }, { variant: 'cta', fragment: '' }] };
  const { rows } = wornRows(entry, LIBRARY, ['style']);
  assert.deepEqual(rows, [
    { axis: 'style', ref: { variant: 'cta', fragment: '', via: null } },
    { axis: 'width', ref: { variant: 'retired_axis', fragment: '', via: null } },
  ]);
});

test('wornRows: unresolvable refs are stale (deleted, unpublished, a uuid spelling)', () => {
  const entry = { variants: [{ variant: 'gone', fragment: '' }, { variant: 'cta', fragment: '' }] };
  const { rows, stale } = wornRows(entry, LIBRARY, ['style']);
  assert.deepEqual(stale, [{ variant: 'gone', fragment: '', via: null }]);
  assert.deepEqual(rows, [{ axis: 'style', ref: { variant: 'cta', fragment: '', via: null } }]);
  // A reference is the record id: the retired uuid form is unresolved.
  const uuidEntry = { variants: [{ variant: '11111111-1111-2222-3333-444444444444', fragment: '' }] };
  assert.deepEqual(wornRows(uuidEntry, LIBRARY, ['style']).stale, [{ variant: '11111111-1111-2222-3333-444444444444', fragment: '', via: null }]);
});

test('staleReason: a withheld name carries the guide\'s refusals; any other stale reference is gone', () => {
  // The guide's verdict: the same {path, code, message} triples the
  // STYLE_VARIANT_NONCONFORMING refusal carries, paths relative to the
  // variant's styles. The withheld variant is off the records (stale),
  // and the reason says why.
  const refusals = [
    { path: 'default.border-radius', code: 'STYLE_CONSTRAINT_SCALE', message: '"0.5em" is not a "radius" scale token reference for border-radius.' },
    { path: 'default.font-weight', code: 'STYLE_CONSTRAINT_SCALE', message: '"600" is not a "weight" scale token reference for font-weight.' },
  ];
  const guide = { withheld: { button: refusals } };
  const entry = { variants: [{ variant: 'button', fragment: '' }, { variant: 'gone', fragment: '' }] };
  const { stale } = wornRows(entry, LIBRARY, ['style']);
  assert.deepEqual(stale.map((ref) => ref.variant), ['button', 'gone']);
  assert.deepEqual(staleReason(guide, 'button'), { withheld: true, refusals });
  // Gone: deleted, unpublished, a retired uuid spelling. No guide, no
  // verdict, or an empty verdict all say the same.
  assert.deepEqual(staleReason(guide, 'gone'), { withheld: false, refusals: [] });
  assert.deepEqual(staleReason({ withheld: { button: [] } }, 'button'), { withheld: false, refusals: [] });
  assert.deepEqual(staleReason({}, 'button'), { withheld: false, refusals: [] });
  assert.deepEqual(staleReason(null, 'button'), { withheld: false, refusals: [] });
  // The row's text: the server refusal's own spelling, one line, each
  // message's trailing period trimmed.
  assert.equal(
    refusalText(refusals),
    'default.border-radius: "0.5em" is not a "radius" scale token reference for border-radius; default.font-weight: "600" is not a "weight" scale token reference for font-weight',
  );
  assert.equal(refusalText([]), '');
});

test('wornRows: a distributed fragment wearing occupies its variant axis', () => {
  const entry = { variants: [{ variant: 'hero', fragment: 'block(text,1)' }] };
  const { rows } = wornRows(entry, LIBRARY, ['style']);
  assert.deepEqual(rows, [{ axis: 'style', ref: { variant: 'hero', fragment: 'block(text,1)', via: null } }]);
});

test('wornRows: a distributed nested wearing rides its origin into the row', () => {
  // The card scenario: the composition's apply nested `row` onto the
  // wrapper and stamped `via`; the row's ref is the entryRefs() item
  // verbatim, so the view can name the origin. The editor's own wearing
  // beside it carries none.
  const entry = { variants: [{ variant: 'cta', fragment: '' }, { variant: 'row', fragment: '', via: 'card_left' }] };
  const { rows, stale } = wornRows(entry, LIBRARY, ['style', 'container_layout']);
  assert.deepEqual(rows, [
    { axis: 'style', ref: { variant: 'cta', fragment: '', via: null } },
    { axis: 'container_layout', ref: { variant: 'row', fragment: '', via: 'card_left' } },
  ]);
  assert.deepEqual(stale, []);
  // A stale wearing keeps its origin fact too (the composition still
  // reaps it on a switch), and an empty origin reads as none, the
  // server's gate.
  const gone = { variants: [{ variant: 'gone', fragment: '', via: 'card_left' }, { variant: 'row', fragment: '', via: '' }] };
  const later = wornRows(gone, LIBRARY, ['style', 'container_layout']);
  assert.deepEqual(later.stale, [{ variant: 'gone', fragment: '', via: 'card_left' }]);
  assert.deepEqual(later.rows, [{ axis: 'container_layout', ref: { variant: 'row', fragment: '', via: null } }]);
});

test('wornRows: a claimed axis yields its row; unclaimed and stale rows are untouched', () => {
  // A mounted section owns the claimed wearing's surface (basic mode's
  // Layout segment for container_layout): one reference, one surface.
  const entry = { variants: [{ variant: 'row', fragment: '' }, { variant: 'cta', fragment: '' }, { variant: 'gone', fragment: '' }] };
  const { rows, stale } = wornRows(entry, LIBRARY, ['style', 'container_layout'], ['container_layout']);
  assert.deepEqual(rows, [{ axis: 'style', ref: { variant: 'cta', fragment: '', via: null } }]);
  assert.deepEqual(stale, [{ variant: 'gone', fragment: '', via: null }]);
  // A claim on an axis nothing wears changes nothing.
  assert.deepEqual(wornRows(entry, LIBRARY, ['style', 'container_layout'], ['emphasis']).rows.map((row) => row.axis), ['style', 'container_layout']);
});

test('wornProperties: the root entry across conditions, deduped, in order', () => {
  assert.deepEqual(wornProperties(LIBRARY, { variant: 'hero', fragment: '' }), ['padding', 'background']);
  assert.deepEqual(wornProperties(LIBRARY, { variant: 'row', fragment: '' }), ['display']);
  assert.deepEqual(wornProperties(LIBRARY, { variant: 'retired_axis', fragment: '' }), []);
  assert.deepEqual(wornProperties(LIBRARY, { variant: 'nope', fragment: '' }), []);
  assert.deepEqual(wornProperties(null, { variant: 'hero', fragment: '' }), []);
  assert.deepEqual(wornProperties(LIBRARY, null), []);
});

test('wornProperties: a fragment wearing reads its own slice root', () => {
  assert.deepEqual(wornProperties(LIBRARY, { variant: 'hero', fragment: 'block(text,1)' }), ['margin']);
});

test('reachesBeyond: the slice is the fragment suffix space', () => {
  const hero = LIBRARY[2].value;
  // A root wearing of a composition reaches its children.
  assert.equal(reachesBeyond(hero, ''), true);
  // A fragment wearing always reaches back to the root at least.
  assert.equal(reachesBeyond(hero, 'block(text,1)'), true);
  // A single-element variant (root + parts only) reaches nothing beyond.
  assert.equal(reachesBeyond({ '': {}, '::.inner': {} }, ''), false);
  assert.equal(reachesBeyond({}, ''), false);
  assert.equal(reachesBeyond(null, ''), false);
});

test('resetOverrideOps unsets exactly the tuples the wearing covers', () => {
  const map = {
    'block:b1': {
      variants: [{ variant: 'hero', fragment: '' }],
      // padding shadows hero's default; margin is hero-free and stays.
      default: { padding: '0', margin: '1rem' },
      // background shadows hero's dark block, under its condition.
      dark: { background: 'white' },
    },
    // The part entry shadows hero's part styles.
    'block:b1::.inner': { default: { gap: '2rem', color: 'red' } },
    // Another element's entry never joins the walk.
    'block:b2': { default: { padding: '9px' } },
  };
  assert.deepEqual(resetOverrideOps(map, 'block:b1', 'hero', LIBRARY), [
    { op: 'update_style', key: 'block:b1', properties: { padding: null } },
    { op: 'update_style', key: 'block:b1', condition: 'dark', properties: { background: null } },
    { op: 'update_style', key: 'block:b1::.inner', properties: { gap: null } },
  ]);
});

test('resetOverrideOps: a fragment wearing resets against its own slice', () => {
  const map = {
    'block:b1': {
      variants: [{ variant: 'hero', fragment: 'block(text,1)' }],
      // margin shadows the fragment's delivered entry; padding (the
      // variant ROOT's property) is not in this wearing's slice.
      default: { margin: '4px', padding: '0' },
    },
  };
  assert.deepEqual(resetOverrideOps(map, 'block:b1', 'hero', LIBRARY), [
    { op: 'update_style', key: 'block:b1', properties: { margin: null } },
  ]);
});

test('resetOverrideOps: nothing shadowing, unworn, or unresolvable is empty', () => {
  assert.deepEqual(resetOverrideOps({ 'block:b1': { variants: [{ variant: 'cta', fragment: '' }], default: { margin: '0' } } }, 'block:b1', 'cta', LIBRARY), []);
  assert.deepEqual(resetOverrideOps({ 'block:b1': { default: { color: 'blue' } } }, 'block:b1', 'cta', LIBRARY), []);
  assert.deepEqual(resetOverrideOps({ 'block:b1': { variants: [{ variant: 'gone', fragment: '' }], default: { color: 'blue' } } }, 'block:b1', 'gone', LIBRARY), []);
});

// A band wearing Site width with its own padding: the map before and
// after the reset patch lands (the mirror applies update_style unsets by
// deleting the properties).
const wornSite = (own) => ({ 'container:c1': { variants: [{ variant: 'site', fragment: '' }], default: own } });
const applyUnsets = (map, ops) => {
  const next = structuredClone(map);
  ops.forEach(({ key, condition = 'default', properties }) => {
    Object.entries(properties).forEach(([property, value]) => {
      if (value === null) {
        delete next[key][condition][property];
      }
    });
  });
  return next;
};

test('resetOverrideOps matches on the longhand grain: an own padding masks a worn padding-inline', () => {
  // The shorthand addresses all four sides, the inset the inline pair:
  // they meet, so the own padding is the shadow to unset.
  assert.deepEqual(resetOverrideOps(wornSite({ padding: '3rem' }), 'container:c1', 'site', LIBRARY), [
    { op: 'update_style', key: 'container:c1', properties: { padding: null } },
  ]);
  // A side outside the inset's grain leaves the wearing showing: nothing
  // to reset.
  assert.deepEqual(resetOverrideOps(wornSite({ 'padding-top': '3rem', 'padding-bottom': '3rem' }), 'container:c1', 'site', LIBRARY), []);
  // A side inside it shadows, exactly as CSS delivers.
  assert.deepEqual(resetOverrideOps(wornSite({ 'padding-left': '0' }), 'container:c1', 'site', LIBRARY), [
    { op: 'update_style', key: 'container:c1', properties: { 'padding-left': null } },
  ]);
  // The other direction too: an own inline pair over a worn shorthand
  // (hero's padding: 2rem).
  assert.deepEqual(resetOverrideOps({ 'block:b1': { variants: [{ variant: 'hero', fragment: '' }], default: { 'padding-inline': '0' } } }, 'block:b1', 'hero', LIBRARY), [
    { op: 'update_style', key: 'block:b1', properties: { 'padding-inline': null } },
  ]);
});

test('overriddenWearing: the lost declarations, the own properties hiding them, and the reset patch that restores them', () => {
  const map = wornSite({ padding: '3rem', margin: '0' });
  const state = overriddenWearing(map, 'container:c1', 'site', LIBRARY);
  assert.deepEqual(state, {
    lost: ['padding-inline'],
    by: ['padding'],
    ops: [{ op: 'update_style', key: 'container:c1', properties: { padding: null } }],
  });
  // The reset op restores: once the patch lands nothing shadows, the
  // row is plainly worn, and the unrelated margin survived.
  const after = applyUnsets(map, state.ops);
  assert.equal(overriddenWearing(after, 'container:c1', 'site', LIBRARY), null);
  assert.deepEqual(after['container:c1'].default, { margin: '0' });
  // An own top/bottom padding beside the inset is no override at all.
  assert.equal(overriddenWearing(wornSite({ 'padding-top': '3rem' }), 'container:c1', 'site', LIBRARY), null);
  // Across the wearing's whole slice: every masked declaration is named
  // once, in delivery order, and the own properties likewise.
  const hero = {
    'block:b1': { variants: [{ variant: 'hero', fragment: '' }], default: { padding: '0' }, dark: { background: 'white' } },
    'block:b1::.inner': { default: { gap: '2rem' } },
  };
  assert.deepEqual(overriddenWearing(hero, 'block:b1', 'hero', LIBRARY), {
    lost: ['padding', 'background', 'gap'],
    by: ['padding', 'background', 'gap'],
    ops: resetOverrideOps(hero, 'block:b1', 'hero', LIBRARY),
  });
  // Unworn or unresolvable: no state.
  assert.equal(overriddenWearing({ 'block:b1': { default: { color: 'blue' } } }, 'block:b1', 'cta', LIBRARY), null);
  assert.equal(overriddenWearing({ 'block:b1': { variants: [{ variant: 'gone', fragment: '' }], default: { color: 'blue' } } }, 'block:b1', 'gone', LIBRARY), null);
});

const menuTypes = (items) => items.map((item) => (item.separator ? '|' : item.type));

test('overriddenWearing at a rule: the rule\'s own values and its continuations by descent shadow the wearing', () => {
  const RULE = 'container:root::> pb-text';
  const map = {
    [RULE]: { variants: [{ variant: 'hero', fragment: '' }], default: { padding: '1rem' } },
    [`${RULE} .inner`]: { default: { gap: '2rem' } },
    // A sibling rule and an unrelated part: not the wearing's slice.
    'container:root::> pb-image': { default: { padding: '3rem' } },
    'container:root::.inner': { default: { gap: '3rem' } },
  };
  const overridden = overriddenWearing(map, RULE, 'hero', LIBRARY);
  assert.deepEqual(overridden.lost, ['padding', 'gap']);
  assert.deepEqual(overridden.by, ['padding', 'gap']);
  assert.deepEqual(resetOverrideOps(map, RULE, 'hero', LIBRARY), [
    { op: 'update_style', key: RULE, properties: { padding: null } },
    { op: 'update_style', key: `${RULE} .inner`, properties: { gap: null } },
  ]);
});

test('wearRowMenu: the full worn roster, grouped', () => {
  const items = wearRowMenu({
    stale: false,
    worn: LIBRARY[2],
    ref: { variant: 'hero', fragment: '' },
    outlined: false,
    canEditFull: true,
    hasSettings: true,
  });
  // The fork ("Copy as my variant") is no row gesture anymore: it lives
  // on the library card behind the Variant settings… item. Reset
  // overrides is no menu item either: it is the overridden row's own
  // action (overriddenWearing), announced on the row.
  assert.deepEqual(menuTypes(items), ['outline', 'reapply', '|', 'detach', 'remove', '|', 'edit-full', 'settings']);
  assert.equal(items.some((item) => item.type === 'reset'), false);
});

test('wearRowMenu: the jump offers for every resolved wearing', () => {
  const items = wearRowMenu({
    stale: false,
    worn: LIBRARY[0],
    ref: { variant: 'cta', fragment: '' },
    outlined: true,
    canEditFull: true,
    hasSettings: false,
  });
  // cta is single-element: no Re-apply; nothing shadows: no Reset
  // overrides; no settings doorway wired. The edit doorway still offers —
  // the editing session is the one place ANY resolved variant is
  // edited, single-slot variants included (reach no longer gates it).
  assert.deepEqual(menuTypes(items), ['outline', '|', 'detach', 'remove', '|', 'edit-full']);
  assert.equal(items[0].checked, true);
});

test('wearRowMenu: a fragment wearing offers the edit jump, not Re-apply', () => {
  const items = wearRowMenu({
    stale: false,
    worn: LIBRARY[2],
    ref: { variant: 'hero', fragment: 'block(text,1)' },
    outlined: false,
    canEditFull: true,
    hasSettings: true,
  });
  assert.deepEqual(menuTypes(items), ['outline', '|', 'detach', 'remove', '|', 'edit-full', 'settings']);
});

test('wearRowMenu: Update from this element leads the lifecycle group when granted and staged', () => {
  const items = wearRowMenu({
    stale: false,
    worn: LIBRARY[2],
    ref: { variant: 'hero', fragment: '' },
    outlined: false,
    canUpdate: true,
    updatable: true,
    canEditFull: true,
    hasSettings: false,
  });
  assert.deepEqual(menuTypes(items), ['outline', 'reapply', '|', 'detach', 'remove', '|', 'update', 'edit-full']);
  // Without the grant, or with nothing differing, the doorway stays away.
  const ungranted = wearRowMenu({
    stale: false,
    worn: LIBRARY[2],
    ref: { variant: 'hero', fragment: '' },
    outlined: false,
    canUpdate: false,
    updatable: true,
    canEditFull: false,
    hasSettings: false,
  });
  assert.deepEqual(menuTypes(ungranted), ['outline', 'reapply', '|', 'detach', 'remove']);
  const identical = wearRowMenu({
    stale: false,
    worn: LIBRARY[2],
    ref: { variant: 'hero', fragment: '' },
    outlined: false,
    canUpdate: true,
    updatable: false,
    canEditFull: false,
    hasSettings: false,
  });
  assert.deepEqual(menuTypes(identical), ['outline', 'reapply', '|', 'detach', 'remove']);
});

test('wearRowMenu: an origin-bearing wearing keeps the plain worn roster', () => {
  // The origin is said on the row (wear-bar.js's sub-line), never
  // gestured: no new menu item, and Detach, Remove and the edit doorway
  // apply to a distributed wearing exactly as to a chosen one.
  const plain = wearRowMenu({
    stale: false,
    worn: LIBRARY[1],
    ref: { variant: 'row', fragment: '', via: null },
    outlined: false,
    canEditFull: true,
    hasSettings: true,
  });
  const distributed = wearRowMenu({
    stale: false,
    worn: LIBRARY[1],
    ref: { variant: 'row', fragment: '', via: 'card_left' },
    outlined: false,
    canEditFull: true,
    hasSettings: true,
  });
  assert.deepEqual(menuTypes(distributed), menuTypes(plain));
  assert.deepEqual(menuTypes(distributed), ['outline', '|', 'detach', 'remove', '|', 'edit-full', 'settings']);
});

test('wearRowMenu: a stale row keeps only resolution-free gestures', () => {
  const items = wearRowMenu({
    stale: true,
    worn: null,
    ref: { variant: 'gone', fragment: '' },
    outlined: false,
    canEditFull: true,
    hasSettings: true,
  });
  // No document to jump to: Outline, Detach, Remove.
  assert.deepEqual(menuTypes(items), ['outline', '|', 'detach', 'remove']);
});

test('wearRowMenu: read-only (a drilled part) keeps Outline and offers Select wearer alone', () => {
  // The wearing belongs to the whole element: under a drilled part every
  // wear gesture (detach, remove, re-apply, update, edit, settings)
  // waits behind selecting the wearer, whatever the host
  // wires and whether the row is worn or stale.
  const worn = wearRowMenu({
    stale: false,
    worn: LIBRARY[2],
    ref: { variant: 'hero', fragment: '' },
    readOnly: true,
    outlined: true,
    canUpdate: true,
    updatable: true,
    canEditFull: true,
    hasSettings: true,
  });
  assert.deepEqual(menuTypes(worn), ['outline', '|', 'select-wearer']);
  assert.equal(worn[0].checked, true);
  const stale = wearRowMenu({
    stale: true,
    worn: null,
    ref: { variant: 'gone', fragment: '' },
    readOnly: true,
    outlined: false,
    canEditFull: true,
    hasSettings: true,
  });
  assert.deepEqual(menuTypes(stale), ['outline', '|', 'select-wearer']);
});

test('outlineGesture: one toggle over one identity across entry points', () => {
  // The wear bar toggles the worn reference, the library card the record
  // id; both are the same string, so each recognizes the other's toggle.
  let held = null;
  const set = (value) => {
    held = value;
  };
  const off = outlineGesture(null, set);
  assert.equal(off.reference, null);
  assert.ok(!off.isOn('cta'));
  off.toggle('cta');
  assert.equal(held, 'cta', 'toggle on holds the reference');

  const on = outlineGesture(held, set);
  assert.ok(on.isOn('cta'));
  assert.ok(!on.isOn('hero'));
  assert.ok(!on.isOn(null));
  on.toggle('cta');
  assert.equal(held, null, 'the other entry point toggles the same outline OFF');

  const other = outlineGesture('cta', set);
  other.toggle('hero');
  assert.equal(held, 'hero', 'a different variant replaces the outline');
});

test('outlineGesture: stale references keep verbatim identity', () => {
  let held = 'gone';
  const gesture = outlineGesture(held, (value) => {
    held = value;
  });
  assert.ok(gesture.isOn('gone'));
  assert.ok(!gesture.isOn('also-gone'));
  gesture.toggle('gone');
  assert.equal(held, null);
});

let failures = 0;
for (const [name, run] of tests) {
  try {
    run();
    console.log(`ok - ${name}`);
  }
  catch (error) {
    failures += 1;
    console.error(`not ok - ${name}`);
    console.error(error?.stack ?? error);
  }
}
if (failures) {
  console.error(`${failures} wear-bar test(s) failed.`);
  process.exit(1);
}
console.log(`${tests.length} wear-bar tests passed.`);
