/**
 * Self-asserting tests for the property row's provenance model
 * (js/src/provenance-model.js): one origin per row read at the
 * DELIVERING condition (the four postures, the cross-condition variant
 * case), the "Also set at" roster, the token half over closed and open
 * spaces, and the row menu's assembly.
 *
 * Run directly (`node tests/js/provenance-model.test.mjs`) or via the
 * suite glob. Exits non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import { conditionText, declarationLabel, defaultsAtPose, provenanceFor, rowMenu, rowOrigin, setsAt, tokenItems } from '../../js/src/provenance-model.js';
import { ELEMENT_LAYER, SKIN_COLOR, layerColor, variantLayerId } from '../../js/src/code/layers.js';
import { propertyValues, valueAtPose } from '../../js/src/condition-cascade.js';
import { valueSpaceFor } from '../../js/src/value-space.js';
import { materialsFrom } from './materials-fixture.mjs';

// Drupal.t at call time, placeholders substituted, so the sentences
// under test read as they render.
globalThis.Drupal = {
  t: (text, args = {}) => Object.entries(args).reduce((out, [key, value]) => out.split(key).join(String(value)), text),
};

const tests = [];
const test = (name, run) => tests.push([name, run]);

// --- fixtures -------------------------------------------------------------

const CONDITIONS = {
  medium: { label: 'Medium', axis: 'width' },
  narrow: { label: 'Narrow', axis: 'width' },
  dark: { label: 'Dark', axis: 'scheme' },
};

const LABELS = { stuff: 'Stuff', calm: 'Calm' };
const labelOf = (reference) => LABELS[reference] ?? reference;
const VARIANT_VALUES = { stuff: { default: { 'padding-top': '22px' }, narrow: { 'padding-top': '12px' } } };
const valueOf = (reference, condition, property) => VARIANT_VALUES[reference]?.[condition]?.[property];

// A row's origin from the shapes the Inspector hands the rows: the
// resolved values (buildSections' row.values), the own entry, the raw
// supplier/shadow tables, the defaults tier's delivering block at the
// pose (defaultsAtPose's shape; `skin`/`declared` are shorthands for
// one delivered at the pose itself).
const originFor = ({ values, condition, own = null, suppliers = null, shadowed = null, skin = null, declared = null, defaults = null, skinEditUrl = null, declaredScope = null, winner = null, ownLayer = ELEMENT_LAYER }) => rowOrigin({
  property: 'padding-top',
  values,
  atPose: valueAtPose(values, condition, CONDITIONS),
  condition,
  conditions: CONDITIONS,
  own,
  ownLayer,
  suppliers,
  shadowed,
  defaults: defaults
    ?? (skin != null ? { layer: 'skin', condition, value: skin } : null)
    ?? (declared != null ? { layer: 'declared', condition, value: declared } : null),
  skinEditUrl,
  declaredScope,
  winner,
  labelOf,
  valueOf,
});

const GUIDE = {
  enforcement: 'guarded',
  conditions: { dark: { label: 'Dark', axis: 'scheme' } },
  tokenScales: { spacing: { label: 'Spacing', prefix: 'space', shape: 'dimension' } },
  tokens: { spacing: { 1: '0.25rem', 5: '1.5rem', gutter: { default: '1.5rem', dark: '1.25rem' } } },
  swatches: [],
};
const MATERIALS = materialsFrom(GUIDE);
const spaceFor = (enforcement) => valueSpaceFor({ ...GUIDE, enforcement }, MATERIALS, 'margin-top', { type: 'dimension' }, { scale: 'spacing' }, 'default');

// --- conditionText ----------------------------------------------------------

test('conditionText: Base for default, site labels joined for compounds', () => {
  assert.equal(conditionText('default', CONDITIONS), 'Base');
  assert.equal(conditionText('medium', CONDITIONS), 'Medium');
  assert.equal(conditionText('narrow+dark', CONDITIONS), 'Narrow + Dark');
});

test('setsAt: a null value is an unset, not a value', () => {
  assert.equal(setsAt({ default: { color: 'red' } }, 'default', 'color'), true);
  assert.equal(setsAt({ default: { color: null } }, 'default', 'color'), false);
  assert.equal(setsAt({ default: [] }, 'default', 'color'), false);
  assert.equal(setsAt(null, 'default', 'color'), false);
});

// --- rowOrigin: the four postures -------------------------------------------

test('own: set here at this pose with nothing beneath earns no chrome', () => {
  const origin = originFor({
    values: [{ condition: 'default', value: '19px' }],
    condition: 'default',
    own: { default: { 'padding-top': '19px' } },
  });
  assert.equal(origin.posture, 'own');
  assert.equal(origin.color, null);
  assert.equal(origin.title, undefined);
  assert.equal(origin.header, null);
  assert.deepEqual(origin.doorways, []);
  assert.deepEqual(origin.alsoAt, []);
});

test('own: no value anywhere is quiet with no layer', () => {
  const origin = originFor({ values: [], condition: 'medium' });
  assert.equal(origin.posture, 'own');
  assert.equal(origin.layer, null);
  assert.equal(origin.color, null);
});

test('inherited from another condition: the own value at Base shows at Medium in the element blue', () => {
  const origin = originFor({
    values: [{ condition: 'default', value: '2rem' }],
    condition: 'medium',
    own: { default: { 'padding-top': '2rem' } },
  });
  assert.equal(origin.posture, 'inherited');
  assert.equal(origin.layer, ELEMENT_LAYER);
  assert.equal(origin.color, layerColor(ELEMENT_LAYER));
  assert.equal(origin.title, '2rem from Base');
  assert.equal(origin.header, origin.title);
  assert.deepEqual(origin.doorways.map((doorway) => [doorway.id, doorway.label, doorway.condition]), [['drive', 'Go to Base', 'default']]);
});

test('inherited across conditions from a variant: supplier at Base, posed at Medium, reads the variant at the DELIVERING condition', () => {
  const origin = originFor({
    values: [{ condition: 'default', value: '22px' }],
    condition: 'medium',
    own: { variants: [{ variant: 'stuff', fragment: '' }] },
    suppliers: { default: { 'padding-top': 'stuff' } },
    shadowed: {},
  });
  assert.equal(origin.posture, 'inherited');
  assert.equal(origin.layer, variantLayerId('stuff'));
  assert.equal(origin.reference, 'stuff');
  assert.equal(origin.color, layerColor(variantLayerId('stuff')));
  assert.equal(origin.title, '22px from Stuff at Base');
  assert.deepEqual(origin.doorways.map((doorway) => [doorway.id, doorway.label]), [
    ['document', 'Edit Stuff at Base…'],
    ['drive', 'Go to Base'],
  ]);
  assert.equal(origin.doorways[0].reference, 'stuff');
  assert.equal(origin.doorways[0].condition, 'default');
});

test('inherited from a variant at the posed condition itself: no drive doorway, the sentence drops the condition', () => {
  const origin = originFor({
    values: [{ condition: 'default', value: '22px' }],
    condition: 'default',
    own: { variants: [{ variant: 'stuff', fragment: '' }] },
    suppliers: { default: { 'padding-top': 'stuff' } },
  });
  assert.equal(origin.posture, 'inherited');
  assert.equal(origin.title, '22px from Stuff');
  assert.deepEqual(origin.doorways.map((doorway) => doorway.id), ['document']);
});

test('inherited from the skin: nothing in the map, the skin value shows in the skin teal with the skin doorway iff a URL exists', () => {
  const withUrl = originFor({ values: [], condition: 'default', skin: '1.125rem', skinEditUrl: '/admin/appearance/skins/1' });
  assert.equal(withUrl.posture, 'inherited');
  assert.equal(withUrl.layer, 'skin');
  assert.equal(withUrl.color, SKIN_COLOR);
  assert.equal(withUrl.title, '1.125rem from the active skin');
  assert.deepEqual(withUrl.doorways.map((doorway) => [doorway.id, doorway.url]), [['skin', '/admin/appearance/skins/1']]);
  const noUrl = originFor({ values: [], condition: 'default', skin: '1.125rem' });
  assert.deepEqual(noUrl.doorways, []);
});

test('inherited from the skin at another condition: posed at Medium, the skin\'s Base block delivers and the sentence says where; the doorway is still the skin form, never a drive', () => {
  const origin = originFor({
    values: [],
    condition: 'medium',
    defaults: { layer: 'skin', condition: 'default', value: '#1475ad' },
    skinEditUrl: '/admin/appearance/skins/1',
  });
  assert.equal(origin.posture, 'inherited');
  assert.equal(origin.layer, 'skin');
  assert.equal(origin.color, SKIN_COLOR);
  assert.equal(origin.title, '#1475ad from the active skin at Base');
  assert.equal(origin.header, origin.title);
  assert.deepEqual(origin.doorways.map((doorway) => doorway.id), ['skin']);
  // A subset compound delivering at a posed compound names itself.
  const narrow = originFor({ values: [], condition: 'narrow+dark', defaults: { layer: 'skin', condition: 'narrow', value: '100%' } });
  assert.equal(narrow.title, '100% from the active skin at Narrow');
});

test('inherited from the declaration: nothing in the map and no skin value, the declared default shows in the defaults posture, the declaring scope named, one inert doorway', () => {
  const origin = originFor({ values: [], condition: 'default', declared: 'block', declaredScope: 'block_type:image' });
  assert.equal(origin.posture, 'inherited');
  assert.equal(origin.layer, 'declared');
  assert.equal(origin.color, SKIN_COLOR, 'the defaults tier\'s one hue');
  assert.equal(origin.title, 'block from the image block type declaration');
  assert.equal(origin.header, origin.title);
  // The one doorway is inert, even with a skin edit URL on the page: a
  // declaration is code, and the skin form does not edit it. It exists
  // so the row menu opens on the sentence (the primitive refuses an
  // empty list) and says why nothing is offered.
  const inert = (doorways) => doorways.map((d) => [d.id, d.label, d.disabled === true, typeof d.reason]);
  assert.deepEqual(inert(origin.doorways), [['declared', 'Declared in code', true, 'string']]);
  assert.deepEqual(inert(originFor({ values: [], condition: 'default', declared: 'block', declaredScope: 'block_type:image', skinEditUrl: '/admin/appearance/skins/1' }).doorways), [['declared', 'Declared in code', true, 'string']]);
});

test('inherited from the declaration at another condition: posed at Medium, the declaration\'s Base block delivers, "at Base" appended, the same inert doorway', () => {
  const origin = originFor({
    values: [],
    condition: 'medium',
    defaults: { layer: 'declared', condition: 'default', value: 'block' },
    declaredScope: 'block_type:image',
    skinEditUrl: '/admin/appearance/skins/1',
  });
  assert.equal(origin.posture, 'inherited');
  assert.equal(origin.layer, 'declared');
  assert.equal(origin.color, SKIN_COLOR);
  assert.equal(origin.title, 'block from the image block type declaration at Base');
  assert.equal(origin.header, origin.title);
  assert.deepEqual(origin.doorways.map((doorway) => [doorway.id, doorway.disabled]), [['declared', true]]);
});

test('the skin\'s value sits over the declaration\'s: both present, the origin is the skin\'s', () => {
  const origin = originFor({ values: [], condition: 'default', skin: 'inline-block', skinEditUrl: '/admin/appearance/skins/1', declared: 'block', declaredScope: 'block_type:image' });
  assert.equal(origin.layer, 'skin');
  assert.equal(origin.title, 'inline-block from the active skin');
  assert.deepEqual(origin.doorways.map((doorway) => doorway.id), ['skin']);
});

test('overriding the declaration: own at pose over a declared default wears the defaults hue, unset returns it, no other doorway', () => {
  const origin = originFor({
    values: [{ condition: 'default', value: 'inline' }],
    condition: 'default',
    own: { default: { 'padding-top': 'inline' } },
    declared: 'block',
    declaredScope: 'block_type:image',
    skinEditUrl: '/admin/appearance/skins/1',
  });
  assert.equal(origin.posture, 'overriding');
  assert.equal(origin.layer, 'declared');
  assert.equal(origin.color, SKIN_COLOR);
  assert.equal(origin.title, 'Your inline overrides the image block type declaration\'s block');
  assert.deepEqual(origin.doorways.map((doorway) => [doorway.id, doorway.hint]), [
    ['unset', 'the image block type declaration\'s block returns'],
  ]);
  // A worn variant beneath the own value still names the variant, never
  // the declaration: the declared tier is the floor, not a layer.
  const overVariant = originFor({
    values: [{ condition: 'default', value: '24px' }],
    condition: 'default',
    own: { variants: [{ variant: 'stuff', fragment: '' }], default: { 'padding-top': '24px' } },
    suppliers: { default: { 'padding-top': 'stuff' } },
    shadowed: { default: { 'padding-top': 'stuff' } },
    declared: 'block',
    declaredScope: 'block_type:image',
  });
  assert.equal(overVariant.layer, variantLayerId('stuff'));
});

test('overriding the defaults tier at another condition: own at Medium over a Base default says where the beaten value delivers from', () => {
  const overSkin = originFor({
    values: [{ condition: 'medium', value: '1.25rem' }],
    condition: 'medium',
    own: { medium: { 'padding-top': '1.25rem' } },
    defaults: { layer: 'skin', condition: 'default', value: '1.125rem' },
    skinEditUrl: '/admin/appearance/skins/1',
  });
  assert.equal(overSkin.posture, 'overriding');
  assert.equal(overSkin.layer, 'skin');
  assert.equal(overSkin.title, 'Your 1.25rem overrides the active skin\'s 1.125rem at Base');
  assert.deepEqual(overSkin.doorways.map((doorway) => [doorway.id, doorway.hint]), [
    ['unset', 'the skin\'s 1.125rem returns'],
    ['skin', 'opens the skin form'],
  ]);
  const overDeclared = originFor({
    values: [{ condition: 'medium', value: 'inline' }],
    condition: 'medium',
    own: { medium: { 'padding-top': 'inline' } },
    defaults: { layer: 'declared', condition: 'default', value: 'block' },
    declaredScope: 'block_type:image',
  });
  assert.equal(overDeclared.layer, 'declared');
  assert.equal(overDeclared.title, 'Your inline overrides the image block type declaration\'s block at Base');
  assert.deepEqual(overDeclared.doorways.map((doorway) => doorway.id), ['unset']);
});

test('declarationLabel speaks a captured scope in words, a kind included, and nothing for a base tag', () => {
  assert.equal(declarationLabel('block_type:image'), 'image block type');
  assert.equal(declarationLabel('block_plugin:system_menu_block'), 'system_menu_block block plugin');
  assert.equal(declarationLabel('layout:layout_onecol'), 'layout_onecol layout');
  // A kind declares defaults for the markup it renders (the ownership
  // law), so an element whose scope is the kind names it.
  assert.equal(declarationLabel('kind:container'), 'container kind');
  assert.equal(declarationLabel('kind:entity'), 'entity kind');
  assert.equal(declarationLabel('base:h2'), null);
  assert.equal(declarationLabel(null), null);
});

test('defaultsAtPose: the tier reads through the cascade, the skin\'s delivering block over the declaration\'s', () => {
  const skin = { default: { display: 'inline-block' }, narrow: { width: '100%' }, medium: { display: 'flex' } };
  const declared = { default: { display: 'block', width: 'fit-content' } };
  // At Base: the skin's Base block over the declaration's for display,
  // the declaration alone for width.
  assert.deepEqual(defaultsAtPose(skin, declared, 'display', 'default', CONDITIONS), { layer: 'skin', condition: 'default', value: 'inline-block' });
  assert.deepEqual(defaultsAtPose(skin, declared, 'width', 'default', CONDITIONS), { layer: 'declared', condition: 'default', value: 'fit-content' });
  assert.deepEqual(defaultsAtPose(null, declared, 'display', 'default', CONDITIONS), { layer: 'declared', condition: 'default', value: 'block' });
  // A `default` block delivers at every pose: the declaration's Base
  // width answers at Medium (the canvas paints it there), from Base.
  assert.deepEqual(defaultsAtPose(null, declared, 'width', 'medium', CONDITIONS), { layer: 'declared', condition: 'default', value: 'fit-content' });
  // A skin block at the pose overrides the skin's Base block.
  assert.deepEqual(defaultsAtPose(skin, declared, 'display', 'medium', CONDITIONS), { layer: 'skin', condition: 'medium', value: 'flex' });
  // Skin beats declared at the same pose, and across conditions: the
  // skin's Base display still wins at Narrow over a declared Narrow
  // block, because the declared tier prints entirely before the skin's.
  assert.deepEqual(defaultsAtPose(skin, { narrow: { display: 'grid' } }, 'display', 'narrow', CONDITIONS), { layer: 'skin', condition: 'default', value: 'inline-block' });
  // A subset compound delivers at a posed compound.
  assert.deepEqual(defaultsAtPose(skin, declared, 'width', 'narrow+dark', CONDITIONS), { layer: 'skin', condition: 'narrow', value: '100%' });
  // A block at an unrelated condition reaches nothing.
  assert.equal(defaultsAtPose({ medium: { gap: '1rem' } }, null, 'gap', 'narrow', CONDITIONS), null);
  assert.equal(defaultsAtPose(null, null, 'display', 'default', CONDITIONS), null);
});

test('overriding a variant: own at pose beats the supplier, hued in the BEATEN layer, with unset and document doorways', () => {
  const origin = originFor({
    values: [{ condition: 'default', value: '24px' }],
    condition: 'default',
    own: { variants: [{ variant: 'stuff', fragment: '' }], default: { 'padding-top': '24px' } },
    suppliers: { default: { 'padding-top': 'stuff' } },
    shadowed: { default: { 'padding-top': 'stuff' } },
  });
  assert.equal(origin.posture, 'overriding');
  assert.equal(origin.layer, variantLayerId('stuff'));
  assert.equal(origin.color, layerColor(variantLayerId('stuff')));
  assert.equal(origin.title, 'Your 24px overrides Stuff\'s 22px');
  assert.deepEqual(origin.doorways.map((doorway) => [doorway.id, doorway.label, doorway.hint]), [
    ['unset', 'Unset', 'Stuff\'s 22px returns'],
    ['document', 'Edit Stuff at Base…', 'opens the variant'],
  ]);
});

test('overriding the skin: own at pose over a skin value wears the skin teal', () => {
  const origin = originFor({
    values: [{ condition: 'default', value: '1.25rem' }],
    condition: 'default',
    own: { default: { 'padding-top': '1.25rem' } },
    skin: '1.125rem',
    skinEditUrl: '/admin/appearance/skins/1',
  });
  assert.equal(origin.posture, 'overriding');
  assert.equal(origin.layer, 'skin');
  assert.equal(origin.color, SKIN_COLOR);
  assert.equal(origin.title, 'Your 1.25rem overrides the active skin\'s 1.125rem');
  assert.deepEqual(origin.doorways.map((doorway) => [doorway.id, doorway.hint]), [
    ['unset', 'the skin\'s 1.125rem returns'],
    ['skin', 'opens the skin form'],
  ]);
});

test('overridden in a session: a winner wears its own hue and offers the winner doorway', () => {
  const byElement = originFor({
    values: [{ condition: 'default', value: '22px' }],
    condition: 'default',
    own: { default: { 'padding-top': '22px' } },
    ownLayer: variantLayerId('stuff'),
    winner: { variant: null },
  });
  assert.equal(byElement.posture, 'overridden');
  assert.equal(byElement.color, layerColor(ELEMENT_LAYER));
  assert.equal(byElement.title, 'Overridden by this element on this page');
  assert.deepEqual(byElement.doorways.map((doorway) => [doorway.id, doorway.label]), [['winner', 'Go to element']]);
  assert.deepEqual(byElement.doorways[0].winner, { variant: null });
  const byVariant = originFor({
    values: [{ condition: 'default', value: '22px' }],
    condition: 'default',
    own: { default: { 'padding-top': '22px' } },
    ownLayer: variantLayerId('stuff'),
    winner: { variant: 'calm' },
  });
  assert.equal(byVariant.color, layerColor(variantLayerId('calm')));
  assert.equal(byVariant.title, 'Overridden by Calm on this page');
  assert.deepEqual(byVariant.doorways.map((doorway) => doorway.label), ['Edit Calm…']);
});

test('in a session the own layer is the document: its value from another condition wears the variant hue', () => {
  const origin = originFor({
    values: [{ condition: 'default', value: '22px' }],
    condition: 'medium',
    own: { default: { 'padding-top': '22px' } },
    ownLayer: variantLayerId('stuff'),
  });
  assert.equal(origin.posture, 'inherited');
  assert.equal(origin.color, layerColor(variantLayerId('stuff')));
  assert.equal(origin.title, '22px from Base');
});

test('alsoAt: every other condition carrying a value, the delivering one excluded, prelabeled', () => {
  const origin = originFor({
    values: [
      { condition: 'default', value: '22px' },
      { condition: 'narrow', value: '12px' },
      { condition: 'narrow+dark', value: '8px' },
    ],
    condition: 'medium',
    own: { variants: [{ variant: 'stuff', fragment: '' }] },
    suppliers: { default: { 'padding-top': 'stuff' }, narrow: { 'padding-top': 'stuff' } },
  });
  assert.deepEqual(origin.alsoAt, [
    { condition: 'narrow', label: 'Narrow', value: '12px' },
    { condition: 'narrow+dark', label: 'Narrow + Dark', value: '8px' },
  ]);
  // Nothing delivers at Base: every set condition is an "also".
  const none = originFor({ values: [{ condition: 'narrow', value: '12px' }], condition: 'default' });
  assert.deepEqual(none.alsoAt.map((entry) => entry.condition), ['narrow']);
});

// --- tokenItems -------------------------------------------------------------

test('tokenItems on a closed space: the worn stop\'s twins, checked on the worn token, no escape', () => {
  const calls = [];
  const items = tokenItems(GUIDE, MATERIALS, 'default', spaceFor('guarded'), 'var(--space-gutter)', {
    onPreview: (value) => calls.push(['preview', value]),
    onSettle: (value) => calls.push(['settle', value]),
  });
  assert.deepEqual(items.map((item) => [item.label, item.hint, item.checked]), [
    ['Spacing · 5', 'follows the design system', false],
    ['Spacing · gutter', 'follows the design system', true],
  ]);
  assert.equal(items[1].title, 'Dark: 1.25rem', 'the twin that separates under dark says where');
  assert.equal(items[0].title, undefined);
  items[0].run();
  assert.deepEqual(calls, [['preview', '1.5rem'], ['settle', 'var(--space-5)']]);
  // A value-matching literal (a scrub preview echoing back) still finds
  // the stop; off every stop there is nothing to steer.
  assert.equal(tokenItems(GUIDE, MATERIALS, 'default', spaceFor('guarded'), '1.5rem', {}).length, 2);
  assert.deepEqual(tokenItems(GUIDE, MATERIALS, 'default', spaceFor('guarded'), '3rem', {}), []);
});

test('tokenItems on a detented space: the twins plus the Custom value escape, checked while the literal is worn', () => {
  const calls = [];
  const items = tokenItems(GUIDE, MATERIALS, 'default', spaceFor('open'), '1.5rem', {
    onPreview: (value) => calls.push(['preview', value]),
    onSettle: (value) => calls.push(['settle', value]),
  });
  assert.deepEqual(items.map((item) => item.separator ? 'sep' : [item.label, item.checked]), [
    ['Spacing · 5', false],
    ['Spacing · gutter', false],
    'sep',
    ['Custom value', true],
  ]);
  assert.equal(items[3].hint, 'frozen, ignores skin changes');
  items[3].run();
  assert.deepEqual(calls, [['preview', '1.5rem'], ['settle', '1.5rem']], 'the escape commits the face as itself');
  // Worn reference: its token checked, the escape unchecked.
  const worn = tokenItems(GUIDE, MATERIALS, 'default', spaceFor('open'), 'var(--space-5)', {});
  assert.deepEqual(worn.filter((item) => !item.separator).map((item) => item.checked), [true, false, false]);
  // Free spaces and off-stop values offer nothing.
  assert.deepEqual(tokenItems(GUIDE, MATERIALS, 'default', { kind: 'free', stops: [] }, '1.5rem', {}), []);
  assert.deepEqual(tokenItems(GUIDE, MATERIALS, 'default', spaceFor('open'), '3rem', {}), []);
  assert.deepEqual(tokenItems(GUIDE, MATERIALS, 'default', null, '3rem', {}), []);
});

// --- rowMenu ----------------------------------------------------------------

test('rowMenu: doorways, the Also set at group, then the tokens, separated; the origin header leads', () => {
  const origin = originFor({
    values: [{ condition: 'default', value: '22px' }, { condition: 'narrow', value: '12px' }],
    condition: 'medium',
    own: { variants: [{ variant: 'stuff', fragment: '' }] },
    suppliers: { default: { 'padding-top': 'stuff' }, narrow: { 'padding-top': 'stuff' } },
  });
  const opened = [];
  const tokens = [{ label: 'Spacing · 5', checked: true, run: () => {} }];
  const menu = rowMenu({
    origin,
    tokens,
    face: '22px',
    onDoorway: (doorway) => opened.push(['doorway', doorway.id]),
    onCondition: (condition) => opened.push(['condition', condition]),
  });
  assert.equal(menu.header, '22px from Stuff at Base');
  assert.deepEqual(menu.items.map((item) => (item.separator ? 'sep' : [item.label, item.hint ?? null, item.checked, Boolean(item.disabled)])), [
    ['Edit Stuff at Base…', 'opens the variant', null, false],
    ['Go to Base', 'drives the canvas', null, false],
    'sep',
    ['Also set at', null, null, true],
    ['Narrow', '12px', null, false],
    'sep',
    ['Spacing · 5', null, true, false],
  ]);
  menu.items[0].run();
  menu.items[4].run();
  assert.deepEqual(opened, [['doorway', 'document'], ['condition', 'narrow']]);
});

test('rowMenu: a declared value opens on its sentence with the inert doorway, no run, so a choice row still answers the right-click', () => {
  const origin = originFor({ values: [], condition: 'default', declared: 'grid', declaredScope: 'layout:container' });
  const menu = rowMenu({ origin, tokens: [], face: 'grid', onDoorway: () => assert.fail('an inert doorway never dispatches') });
  assert.equal(menu.header, origin.header);
  assert.deepEqual(menu.items.map((item) => [item.label, item.disabled, typeof item.reason, 'run' in item]), [['Declared in code', true, 'string', false]]);
});

test('rowMenu: tokens alone wear the "Where should" header; nothing at all is null', () => {
  const own = originFor({ values: [{ condition: 'default', value: '19px' }], condition: 'default', own: { default: { 'padding-top': '19px' } } });
  const tokensOnly = rowMenu({ origin: own, tokens: [{ label: 'Spacing · 5', checked: false, run: () => {} }], face: '1.5rem' });
  assert.equal(tokensOnly.header, 'Where should 1.5rem come from?');
  assert.equal(tokensOnly.items.length, 1);
  assert.equal(rowMenu({ origin: own, tokens: [] }), null);
  assert.equal(rowMenu({ origin: null, tokens: [] }), null);
  const noOrigin = rowMenu({ origin: null, tokens: [{ label: 'x', checked: null, run: () => {} }], face: '' });
  assert.equal(noOrigin.header, null);
});

// --- the basic rows' input assembly ----------------------------------------

test('provenanceFor: the tables for a key, root and part, with the resolvers bound', () => {
  const UUID = 'aaaaaaaa-1111-2222-3333-444444444444';
  const root = `block:${UUID}`;
  const part = `${root}::.title`;
  const variants = [{
    id: 'stuff',
    label: 'Stuff',
    scope: 'block_type:text',
    value: {
      '': { default: { 'padding-top': '22px' } },
      '::.title': { default: { 'font-size': '2rem' } },
    },
  }];
  const styleMap = {
    [root]: { variants: [{ variant: 'stuff', fragment: '' }], default: { 'padding-top': '30px' } },
    [part]: { medium: { 'font-size': '3rem' } },
  };
  const guide = {
    skinDefaults: { 'block_type:text': { '::.title': { default: { 'font-size': '1.5rem' } } } },
    declaredDefaults: { 'block_type:text': { '': { default: { display: 'block' } }, '::.title': { default: { display: 'inline' } } } },
  };
  const blockTypes = { [UUID]: 'text' };
  const forRoot = provenanceFor(guide, styleMap, root, variants, null, blockTypes);
  assert.equal(forRoot.own, styleMap[root]);
  assert.deepEqual(forRoot.suppliers, { default: { 'padding-top': 'stuff' } });
  assert.deepEqual(forRoot.shadowed, { default: { 'padding-top': 'stuff' } });
  assert.equal(forRoot.skinEntry, null, 'the skin defaults nothing for the root');
  assert.deepEqual(forRoot.declaredEntry, { default: { display: 'block' } }, 'the declaration\'s own root defaults');
  assert.equal(forRoot.declaredScope, 'block_type:text');
  assert.equal(forRoot.labelOf('stuff'), 'Stuff');
  assert.equal(forRoot.labelOf('gone'), 'gone');
  assert.equal(forRoot.valueOf('stuff', 'default', 'padding-top'), '22px');
  const forPart = provenanceFor(guide, styleMap, part, variants, null, blockTypes);
  assert.equal(forPart.own, styleMap[part]);
  assert.deepEqual(forPart.suppliers, { default: { 'font-size': 'stuff' } }, 'the variant\'s part layer through the root\'s reference');
  assert.deepEqual(forPart.shadowed, {}, 'own at Medium shadows nothing the variant sets at Base');
  assert.deepEqual(forPart.skinEntry, { default: { 'font-size': '1.5rem' } }, 'a part key resolves its relative in the skin defaults');
  assert.deepEqual(forPart.declaredEntry, { default: { display: 'inline' } }, 'and in the declared defaults');
  assert.equal(forPart.declaredScope, 'block_type:text', 'a part names its owner\'s declaration');
  assert.equal(forPart.valueOf('stuff', 'default', 'font-size'), '2rem');
  // An unresolved bundle degrades to the kind: the skin's kind layer
  // may answer, and the declared tier reads the kind's own defaults
  // (none in this payload); the captured scope rides as data, and
  // declarationLabel speaks the kind for it.
  const degraded = provenanceFor(guide, styleMap, root, variants, null, {});
  assert.equal(degraded.declaredEntry, null);
  assert.equal(degraded.declaredScope, 'kind:block');
  assert.equal(declarationLabel(degraded.declaredScope), 'block kind');
  // A kind that declares defaults (a composed root, a declared part)
  // reaches the read beneath the refinement's: the bundle's own display
  // over the kind's, the kind's width surviving, and the degraded
  // element reading the kind's alone.
  const kindGuide = {
    declaredDefaults: {
      'kind:block': { '': { default: { display: 'flex', width: 'fit-content' } } },
      ...guide.declaredDefaults,
    },
  };
  assert.deepEqual(
    provenanceFor(kindGuide, styleMap, root, variants, null, blockTypes).declaredEntry,
    { default: { display: 'block', width: 'fit-content' } },
  );
  assert.deepEqual(
    provenanceFor(kindGuide, styleMap, root, variants, null, {}).declaredEntry,
    { default: { display: 'flex', width: 'fit-content' } },
  );
  // The full basic-row path: a part row posed at Medium, its value the
  // variant's at Base — inherited, the variant's hue, opened at Base.
  const values = propertyValues({ default: { 'font-size': '2rem' } }, 'font-size', CONDITIONS);
  const origin = rowOrigin({
    property: 'font-size',
    values,
    atPose: valueAtPose(values, 'medium', CONDITIONS),
    condition: 'medium',
    conditions: CONDITIONS,
    own: forPart.own,
    suppliers: forPart.suppliers,
    shadowed: forPart.shadowed,
    labelOf: forPart.labelOf,
    valueOf: forPart.valueOf,
  });
  assert.equal(origin.posture, 'inherited');
  assert.equal(origin.color, layerColor(variantLayerId('stuff')));
  assert.equal(origin.title, '2rem from Stuff at Base');
  assert.deepEqual(origin.doorways.map((doorway) => doorway.label), ['Edit Stuff at Base…', 'Go to Base']);
});

test('provenanceFor: the shadow read is grain-aware, so a band\'s own padding is seen masking its worn width inset', () => {
  const UUID = 'bbbbbbbb-1111-2222-3333-444444444444';
  const root = `container:${UUID}`;
  // The shipped width posture spells the inset on the logical inline
  // pair; the padding row writes the shorthand.
  const variants = [{
    id: 'site',
    label: 'Site width',
    scope: 'kind:container',
    value: { '': { default: { 'padding-inline': 'max(var(--space-gutter), calc((100% - var(--layout-max-width)) / 2))' } } },
  }];
  const worn = (own) => ({ [root]: { variants: [{ variant: 'site', fragment: '' }], default: own } });
  const masked = provenanceFor({}, worn({ padding: '3rem' }), root, variants, null, {}, { [UUID]: 'daedalus_stylish_container' });
  assert.deepEqual(masked.shadowed, { default: { padding: 'site' } }, 'own padding shadows the worn padding-inline: their grains meet');
  assert.deepEqual(masked.suppliers, { default: { 'padding-inline': 'site' } });
  const beside = provenanceFor({}, worn({ 'padding-top': '3rem' }), root, variants, null, {}, { [UUID]: 'daedalus_stylish_container' });
  assert.deepEqual(beside.shadowed, {}, 'own padding-top leaves the inset showing: no shadow');
  // The reset op restores: with the own padding gone, the same read
  // shadows nothing.
  const restored = provenanceFor({}, worn({}), root, variants, null, {}, { [UUID]: 'daedalus_stylish_container' });
  assert.deepEqual(restored.shadowed, {});
  // The widget badge inherits the read: the padding row's origin is
  // overriding in Site width's hue. The variant spells the beaten sides
  // on another grain, so no same-named value is quoted beneath.
  const values = propertyValues({ default: { padding: '3rem' } }, 'padding', CONDITIONS);
  const origin = rowOrigin({
    property: 'padding',
    values,
    atPose: valueAtPose(values, 'default', CONDITIONS),
    condition: 'default',
    conditions: CONDITIONS,
    own: masked.own,
    suppliers: masked.suppliers,
    shadowed: masked.shadowed,
    labelOf: masked.labelOf,
    valueOf: masked.valueOf,
  });
  assert.equal(origin.posture, 'overriding');
  assert.equal(origin.reference, 'site');
  assert.equal(origin.color, layerColor(variantLayerId('site')));
  assert.equal(origin.title, 'Your 3rem overrides Site width');
  assert.deepEqual(origin.doorways.map((doorway) => doorway.id), ['unset', 'document']);
  assert.equal(origin.doorways[0].hint, 'Site width\'s value returns');
});

// --- runner -----------------------------------------------------------------

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
  console.error(`${failures} provenance-model test(s) failed.`);
  process.exit(1);
}
console.log(`${tests.length} provenance-model tests passed.`);
