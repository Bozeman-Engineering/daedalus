/**
 * Self-asserting tests for the property row's provenance model
 * (js/src/provenance-model.js): originOf over stack reads (the two
 * axes, who supplies and whether at the pose, off the winner against
 * the editable layer, one case per state in the mark's legend; the
 * cross-condition variant case; the session's layers above the
 * document), the "Also set at" roster, the token half over closed and
 * open spaces, and the row menu's assembly.
 *
 * Run directly (`node tests/js/provenance-model.test.mjs`) or via the
 * suite glob. Exits non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import { conditionText, declarationLabel, originOf, rowMenu, sidesSentence, supplierName, tokenItems } from '../../js/src/provenance-model.js';
import { ELEMENT_LAYER, SKIN_COLOR, ancestorLayerId, ancestorWearLayerId, declaredLayerId, layerColor, skinLayerId, variantLayerId } from '../../js/src/code/layers.js';
import { buildStack, stackAt } from '../../js/src/style-stack.js';
import { valueSpaceFor } from '../../js/src/value-space.js';
import { materialsFrom, variantRecord } from './materials-fixture.mjs';

// Drupal.t at call time, placeholders substituted the way
// Drupal.formatString does: `@` escapes its value (a `>` renders as its
// entity, so a selector must ride `!`), `!` passes it through.
const checkPlain = (value) => String(value)
  .replaceAll('&', '&amp;')
  .replaceAll('<', '&lt;')
  .replaceAll('>', '&gt;')
  .replaceAll('"', '&quot;')
  .replaceAll("'", '&#039;');
globalThis.Drupal = {
  t: (text, args = {}) => Object.entries(args).reduce(
    (out, [key, value]) => out.split(key).join(key.startsWith('@') ? checkPlain(value) : String(value)),
    text,
  ),
};

const tests = [];
const test = (name, run) => tests.push([name, run]);

// --- fixtures -------------------------------------------------------------

const CONDITIONS = {
  medium: { label: 'Medium', axis: 'width' },
  narrow: { label: 'Narrow', axis: 'width' },
  dark: { label: 'Dark', axis: 'scheme' },
};

const UUID = 'aaaaaaaa-1111-2222-3333-444444444444';
const ROOT = `block:${UUID}`;
const BLOCK_TYPES = { [UUID]: 'text' };
const AXES = ['style', 'emphasis'];
const SKIN_URL = '/admin/appearance/skins/1';

// Two worn looks on two axes: Stuff (style, first in delivery) beneath
// Calm (emphasis, above it).
const VARIANTS = [
  variantRecord('stuff', {
    label: 'Stuff',
    scope: 'block_type:text',
    value: { '': { default: { 'padding-top': '22px' }, narrow: { 'padding-top': '12px' } } },
  }),
  variantRecord('calm', {
    label: 'Calm',
    group: 'emphasis',
    scope: 'block_type:text',
    value: { '': { default: { 'padding-top': '20px' } } },
  }),
  // A look styling padding at Narrow only: worn, it authors the property
  // without delivering at Base.
  variantRecord('shy', {
    label: 'Shy',
    scope: 'block_type:text',
    value: { '': { narrow: { 'padding-top': '9px' } } },
  }),
  // A container look whose `::> pb-text` block reaches the block from
  // the section's ROOT (the root-carrier shape of a worn ancestor layer).
  variantRecord('band', {
    label: 'Band',
    scope: 'kind:container',
    value: { '': { default: { gap: '3rem' } }, '::> pb-text': { default: { 'padding-top': '18px' } } },
  }),
];

// Guides: bare (no floor), a skin supplying padding-top (Base and Narrow
// blocks), a declaration supplying display, both.
const BARE = { conditions: CONDITIONS };
const SKIN = {
  conditions: CONDITIONS,
  skinDefaults: { 'block_type:text': { '': { default: { 'padding-top': '1.125rem' }, narrow: { 'padding-top': '100%' } } } },
};
const DECLARED = {
  conditions: CONDITIONS,
  declaredDefaults: { 'block_type:text': { '': { default: { display: 'block' } } } },
};
const BOTH = {
  conditions: CONDITIONS,
  skinDefaults: { 'block_type:text': { '': { default: { display: 'inline-block' } } } },
  declaredDefaults: DECLARED.declaredDefaults,
};

// The worn root entry: the given own blocks over the given references.
const worn = (refs, own = {}) => ({ [ROOT]: { variants: refs.map((variant) => ({ variant, fragment: '' })), ...own } });
const STUFF = [{ variant: 'stuff', fragment: '' }];

const stackFor = ({ map = {}, guide = BARE, session = null, tree = null, editable = null } = {}) => buildStack({
  guide,
  map,
  key: ROOT,
  variants: VARIANTS,
  axes: AXES,
  blockTypes: BLOCK_TYPES,
  session,
  tree,
  editable,
});

// The page around the block: a section holding it, labelled or not, so
// an ancestor rule (`> *`) reaches the block's root.
const SECTION = 'container:cccccccc-1111-2222-3333-444444444444';
// The section's minted words ride its node: its custom label as the name
// when renamed, else its type word (the annotator's rule).
const treeFor = (label = null) => [{
  kind: 'container',
  uuid: SECTION.slice('container:'.length),
  layout_id: 'daedalus_stylish_container',
  ...(label ? { label } : {}),
  _element: { name: label ?? 'Section', type: 'Section' },
  children: [{ kind: 'block', uuid: UUID, block_type: 'text', _element: { name: 'Text', type: 'Text' } }],
}];

// A row's origin the way the advanced row derives it: the stack read at
// the pose, projected.
const originAt = (stack, property, pose, options = {}) => originOf(
  stackAt(stack, property, pose, CONDITIONS),
  { stack, property, pose, conditions: CONDITIONS, ...options },
);
const doorways = (origin) => origin.doorways.map((doorway) => doorway.id);

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

// --- originOf: the two axes, one case per legend state ----------------------
// The origin's shape is {layer, tier, reference, color, atPose, delivers,
// title, header, doorways, alsoAt, alsoOn}: the identity axis is the
// supplier's (the winner's, the topmost author's with no winner), the
// pose axis is whether the winner's block is the posed compound, every
// origin speaks (the header is the sentence, the title appends the
// roster), and alsoOn is the address rule's second box when the read
// carries one (controls.js resolveCandidates; null off a bare read).

test('set here at Base: the editable layer delivers at the pose with nothing beneath, solid in the element hue, "!value set here"', () => {
  const origin = originAt(stackFor({ map: { [ROOT]: { default: { 'padding-top': '19px' } } } }), 'padding-top', 'default');
  assert.deepEqual(Object.keys(origin), ['layer', 'tier', 'reference', 'color', 'atPose', 'delivers', 'title', 'header', 'doorways', 'alsoAt', 'alsoOn']);
  assert.equal(origin.alsoOn, null);
  assert.equal(origin.delivers, true);
  assert.equal(origin.atPose, true);
  assert.equal(origin.layer, ELEMENT_LAYER);
  assert.equal(origin.tier, 'element');
  assert.equal(origin.reference, null);
  assert.equal(origin.color, layerColor(ELEMENT_LAYER));
  assert.equal(origin.title, '19px set here');
  assert.equal(origin.header, '19px set here');
  assert.deepEqual(origin.doorways, []);
  assert.deepEqual(origin.alsoAt, []);
});

test('set here at a posed compound: the sentence names the pose, the title appends the roster, the header stays the sentence', () => {
  const map = { [ROOT]: { default: { 'padding-top': '2rem' }, narrow: { 'padding-top': '1rem' }, 'narrow+dark': { 'padding-top': '8px' } } };
  const origin = originAt(stackFor({ map }), 'padding-top', 'narrow+dark');
  assert.equal(origin.atPose, true);
  assert.equal(origin.delivers, true);
  assert.equal(origin.color, layerColor(ELEMENT_LAYER));
  assert.equal(origin.header, '8px set here at Narrow + Dark');
  assert.equal(origin.title, '8px set here at Narrow + Dark. Also set at Base, Narrow');
  assert.deepEqual(origin.alsoAt.map((entry) => [entry.label, entry.value]), [['Base', '2rem'], ['Narrow', '1rem']]);
  assert.deepEqual(origin.doorways, []);
});

test('not set: no layer authors the property anywhere: no hue, "Not set", the editable layer\'s identity', () => {
  const origin = originAt(stackFor(), 'padding-top', 'medium');
  assert.equal(origin.delivers, false);
  assert.equal(origin.atPose, false);
  assert.equal(origin.layer, ELEMENT_LAYER);
  assert.equal(origin.tier, 'element');
  assert.equal(origin.reference, null);
  assert.equal(origin.color, null);
  assert.equal(origin.title, 'Not set');
  assert.equal(origin.header, 'Not set');
  assert.deepEqual(origin.doorways, []);
  assert.deepEqual(origin.alsoAt, []);
});

test('not here: nothing delivers at the pose but the element authors the property elsewhere: hollow in the element hue, "Set at Narrow, not here", the roster names only what the sentence did not', () => {
  const origin = originAt(stackFor({ map: { [ROOT]: { narrow: { 'padding-top': '12px' } } } }), 'padding-top', 'default');
  assert.equal(origin.delivers, false);
  assert.equal(origin.atPose, false);
  assert.equal(origin.layer, ELEMENT_LAYER);
  assert.equal(origin.color, layerColor(ELEMENT_LAYER));
  assert.equal(origin.title, 'Set at Narrow, not here');
  assert.equal(origin.header, 'Set at Narrow, not here');
  assert.deepEqual(origin.doorways, []);
  assert.deepEqual(origin.alsoAt.map((entry) => entry.condition), ['narrow']);
  // Two authored conditions on the element: both named, no roster tail.
  const two = originAt(stackFor({ map: { [ROOT]: { narrow: { 'padding-top': '12px' }, dark: { 'padding-top': '10px' } } } }), 'padding-top', 'medium');
  assert.equal(two.title, 'Set at Narrow, Dark, not here');
  assert.deepEqual(two.alsoAt.map((entry) => entry.condition), ['narrow', 'dark']);
});

test('not here by another layer: a worn variant authoring only at Narrow, posed at Base: the variant\'s identity and hue, "Set by Shy at Narrow, not here"; the element\'s own elsewhere-block outranks it and the roster carries the rest', () => {
  const origin = originAt(stackFor({ map: worn(['shy']) }), 'padding-top', 'default');
  assert.equal(origin.delivers, false);
  assert.equal(origin.atPose, false);
  assert.equal(origin.layer, variantLayerId('shy'));
  assert.equal(origin.tier, 'variant');
  assert.equal(origin.reference, 'shy');
  assert.equal(origin.color, layerColor(variantLayerId('shy')));
  assert.equal(origin.title, 'Set by Shy at Narrow, not here');
  assert.equal(origin.header, origin.title);
  assert.deepEqual(origin.doorways, []);
  const above = originAt(stackFor({ map: worn(['shy'], { dark: { 'padding-top': '10px' } }) }), 'padding-top', 'default');
  assert.equal(above.layer, ELEMENT_LAYER, 'the topmost authoring layer lends the identity');
  assert.equal(above.header, 'Set at Dark, not here');
  assert.equal(above.title, 'Set at Dark, not here. Also set at Narrow');
  // A skin authoring only at Narrow names itself the way its delivering
  // sentence does.
  const skinOnly = { conditions: CONDITIONS, skinDefaults: { 'block_type:text': { '': { narrow: { 'padding-top': '4px' } } } } };
  const skin = originAt(stackFor({ guide: skinOnly }), 'padding-top', 'default');
  assert.equal(skin.title, 'Set by the active skin at Narrow, not here');
  assert.equal(skin.color, SKIN_COLOR);
});

test('inherited from another condition: the own value at Base shows at Medium in the element blue, hollow (not at the pose), with the drive doorway', () => {
  const origin = originAt(stackFor({ map: { [ROOT]: { default: { 'padding-top': '2rem' } } } }), 'padding-top', 'medium');
  assert.equal(origin.delivers, true);
  assert.equal(origin.atPose, false);
  assert.equal(origin.layer, ELEMENT_LAYER);
  assert.equal(origin.reference, null);
  assert.equal(origin.color, layerColor(ELEMENT_LAYER));
  assert.equal(origin.header, '2rem from Base');
  assert.ok(origin.title.startsWith(origin.header), "the title opens with the sentence");
  assert.deepEqual(origin.doorways.map((doorway) => [doorway.id, doorway.label, doorway.condition]), [['drive', 'Go to Base', 'default']]);
});

test('inherited across conditions from a variant: supplied at Base, posed at Medium, the origin names the variant at the DELIVERING condition', () => {
  const origin = originAt(stackFor({ map: worn(['stuff']) }), 'padding-top', 'medium');
  assert.equal(origin.delivers, true);
  assert.equal(origin.layer, variantLayerId('stuff'));
  assert.equal(origin.reference, 'stuff');
  assert.equal(origin.color, layerColor(variantLayerId('stuff')));
  assert.equal(origin.header, '22px from Stuff at Base');
  assert.deepEqual(origin.doorways.map((doorway) => [doorway.id, doorway.label]), [
    ['document', 'Edit Stuff at Base…'],
    ['drive', 'Go to Base'],
  ]);
  assert.equal(origin.doorways[0].reference, 'stuff');
  assert.equal(origin.doorways[0].condition, 'default');
  // Posed at Narrow the variant's own Narrow block delivers: no drive.
  const narrow = originAt(stackFor({ map: worn(['stuff']) }), 'padding-top', 'narrow');
  assert.equal(narrow.header, '12px from Stuff');
  assert.deepEqual(doorways(narrow), ['document']);
});

test('inherited from a variant at the posed condition itself: no drive doorway, the sentence drops the condition; the topmost reference wins', () => {
  const origin = originAt(stackFor({ map: worn(['stuff']) }), 'padding-top', 'default');
  assert.equal(origin.delivers, true);
  assert.equal(origin.header, '22px from Stuff');
  assert.deepEqual(doorways(origin), ['document']);
  // Calm sits above Stuff in delivery: its value shows, Stuff is beneath.
  const both = originAt(stackFor({ map: worn(['stuff', 'calm']) }), 'padding-top', 'default');
  assert.equal(both.header, '20px from Calm');
  assert.equal(both.layer, variantLayerId('calm'));
});

test('inherited from the skin: nothing in the map, the skin value shows lit in the skin teal with the skin doorway iff a URL exists', () => {
  const withUrl = originAt(stackFor({ guide: SKIN }), 'padding-top', 'default', { skinEditUrl: SKIN_URL });
  assert.equal(withUrl.delivers, true);
  assert.equal(withUrl.layer, skinLayerId('block_type:text'), 'the delivering scope\'s layer');
  assert.equal(withUrl.reference, null);
  assert.equal(withUrl.color, SKIN_COLOR);
  assert.equal(withUrl.header, '1.125rem from the active skin');
  assert.deepEqual(withUrl.doorways.map((doorway) => [doorway.id, doorway.url]), [['skin', SKIN_URL]]);
  const noUrl = originAt(stackFor({ guide: SKIN }), 'padding-top', 'default');
  assert.deepEqual(noUrl.doorways, []);
});

test('inherited from the skin at another condition: posed at Medium the skin\'s Base block delivers and the sentence says where; a subset compound names itself', () => {
  const origin = originAt(stackFor({ guide: SKIN }), 'padding-top', 'medium', { skinEditUrl: SKIN_URL });
  assert.equal(origin.delivers, true);
  assert.equal(origin.color, SKIN_COLOR);
  assert.equal(origin.header, '1.125rem from the active skin at Base');
  assert.ok(origin.title.startsWith(origin.header), "the title opens with the sentence");
  assert.deepEqual(doorways(origin), ['skin'], 'the doorway is still the skin form, never a drive');
  const narrow = originAt(stackFor({ guide: SKIN }), 'padding-top', 'narrow+dark');
  assert.equal(narrow.header, '100% from the active skin at Narrow');
});

test('inherited from the declaration: the declared default shows in the defaults hue, the declaring scope named, one inert doorway', () => {
  const origin = originAt(stackFor({ guide: DECLARED }), 'display', 'default', { skinEditUrl: SKIN_URL });
  assert.equal(origin.delivers, true);
  assert.equal(origin.layer, declaredLayerId('block_type:text'));
  assert.equal(origin.color, SKIN_COLOR, 'the defaults tier\'s one hue');
  assert.equal(origin.header, 'block from the text block type declaration');
  assert.ok(origin.title.startsWith(origin.header), "the title opens with the sentence");
  // The one doorway is inert, even with a skin edit URL on the page: a
  // declaration is code, and the skin form does not edit it.
  assert.deepEqual(origin.doorways.map((d) => [d.id, d.label, d.disabled === true, typeof d.reason]), [['declared', 'Declared in code', true, 'string']]);
  const medium = originAt(stackFor({ guide: DECLARED }), 'display', 'medium');
  assert.equal(medium.header, 'block from the text block type declaration at Base');
  assert.deepEqual(medium.doorways.map((doorway) => [doorway.id, doorway.disabled]), [['declared', true]]);
});

test('inherited from an ancestor rule: lit in the floor hue, the sentence names the section and its rule, one doorway edits at the rule', () => {
  const map = { [`${SECTION}::> *`]: { default: { 'padding-top': '8px' }, narrow: { 'padding-top': '4px' } } };
  const origin = originAt(stackFor({ map, tree: treeFor() }), 'padding-top', 'default');
  assert.equal(origin.delivers, true);
  assert.equal(origin.layer, `ancestor:${SECTION}::> *`);
  assert.equal(origin.color, SKIN_COLOR);
  assert.equal(origin.header, '8px inherited from Section (> *)');
  assert.deepEqual(doorways(origin), ['ancestor']);
  // The doorway carries the rule as a write address (the inspector sets
  // it on the source; the selection stays); the selector rides unescaped
  // since the menu renders labels as text.
  assert.deepEqual([origin.doorways[0].key, origin.doorways[0].selector, origin.doorways[0].label], [SECTION, '> *', 'Edit at Section (> *)']);
  // A labelled section names itself; the ancestor rule beats the skin beneath it.
  const labelled = originAt(stackFor({ map, guide: SKIN, tree: treeFor('Hero') }), 'padding-top', 'default');
  assert.equal(labelled.header, '8px inherited from Hero (> *)');
  // Posed at Medium the ancestor's Base block delivers, and the sentence
  // says where, with the drive doorway beside the rule's.
  const medium = originAt(stackFor({ map, tree: treeFor() }), 'padding-top', 'medium');
  assert.equal(medium.header, '8px inherited from Section (> *) at Base');
  assert.deepEqual(doorways(medium), ['ancestor', 'drive']);
  assert.deepEqual(medium.alsoAt.map((entry) => entry.label), ['Narrow']);
  // A child-combinated type rule: the `>` rides literally in the title
  // (the mark is text, an escaped `>` would show as `&gt;`).
  const typed = { [`${SECTION}::> pb-text`]: { default: { 'padding-top': '6px' }, narrow: { 'padding-top': '3px' } } };
  const chained = originAt(stackFor({ map: typed, tree: treeFor() }), 'padding-top', 'default');
  assert.equal(chained.layer, `ancestor:${SECTION}::> pb-text`);
  assert.equal(chained.header, '6px inherited from Section (> pb-text)');
  assert.equal(chained.doorways[0].label, 'Edit at Section (> pb-text)');
  assert.equal(originAt(stackFor({ map: typed, tree: treeFor() }), 'padding-top', 'medium').header, '6px inherited from Section (> pb-text) at Base');
});

// --- originOf: a variant an ancestor's rule wears ------------------------------
// The section's `> pb-text` rule wears Stuff (T10's worn ancestor layer,
// `ancestor:{sec}::> pb-text|stuff`, carrier the rule): the member reads
// the variant's identity (hue, reference, name) and the rule in the
// switcher's words, with the doorways to the variant and to the carrier.

// The guide names the text type so the rule reads "Text blocks directly
// in the section" (write-address.js addressLabel).
const WORDED = { ...BARE, blockTypes: { text: { label: 'Text' } } };
const WORN_RULE = `${SECTION}::> pb-text`;
const wornRule = (own = {}) => ({ [WORN_RULE]: { variants: [{ variant: 'stuff', fragment: '' }], ...own } });
const wornAt = (map, pose, options = {}) => originAt(stackFor({ map, guide: WORDED, tree: treeFor(options.label ?? null) }), 'padding-top', pose, { guide: WORDED, ...options });

test('a variant an ancestor\'s rule wears: lit in the VARIANT\'s hue with the variant as the reference, the sentence naming the variant at the rule in the switcher\'s words, doorways to the variant (anchored at the carrier) and to the carrier rule', () => {
  const origin = wornAt(wornRule(), 'default');
  assert.equal(origin.delivers, true);
  assert.equal(origin.layer, ancestorWearLayerId(SECTION, '> pb-text', 'stuff'));
  assert.equal(origin.reference, 'stuff', 'the mark reads as a variant');
  assert.equal(origin.color, layerColor(variantLayerId('stuff')), 'the same hue an own wearing of Stuff gets');
  assert.notEqual(origin.color, SKIN_COLOR);
  assert.equal(origin.header, '22px from Stuff at Text blocks directly in Section');
  assert.ok(origin.title.startsWith(origin.header), "the title opens with the sentence");
  assert.deepEqual(doorways(origin), ['document', 'ancestor']);
  // Open the variant: the session anchors at the CARRIER (the rule that
  // wears Stuff), never at this element, which wears nothing.
  const [document, ancestor] = origin.doorways;
  assert.deepEqual([document.label, document.hint, document.reference, document.condition, document.anchor], ['Edit Stuff at Base…', 'opens the variant', 'stuff', 'default', WORN_RULE]);
  // Edit at the carrier: the rule becomes the styling target, worded as
  // the switcher words it.
  assert.deepEqual([ancestor.label, ancestor.hint, ancestor.key, ancestor.selector], ['Edit at Text blocks directly in Section', 'writes land on that rule; the selection stays', SECTION, '> pb-text']);
  // A labelled section names itself in the rule's words (the owner's
  // label is read from the group's OWN layer: the worn layer's is the
  // variant's).
  const labelled = wornAt(wornRule(), 'default', { label: 'Hero' });
  assert.equal(labelled.header, '22px from Stuff at Text blocks directly in Hero');
  assert.equal(labelled.doorways[1].label, 'Edit at Text blocks directly in Hero');
  // Posed at Narrow the variant's own Narrow block delivers; at Medium
  // its Base block reaches, the sentence says where, and the drive
  // doorway follows the two.
  assert.equal(wornAt(wornRule(), 'narrow').header, '12px from Stuff at Text blocks directly in Section');
  const medium = wornAt(wornRule(), 'medium');
  assert.equal(medium.header, '22px from Stuff at Text blocks directly in Section, set at Base');
  assert.deepEqual(doorways(medium), ['document', 'ancestor', 'drive']);
  assert.deepEqual(medium.alsoAt.map((entry) => entry.label), ['Narrow']);
  // Without a guide the rule reads as its selector in the owner.
  const bare = originAt(stackFor({ map: wornRule(), tree: treeFor() }), 'padding-top', 'default');
  assert.equal(bare.header, '22px from Stuff at pb-text directly in Section');
});

test('the rule\'s own value beats its worn variant inside the group: the own ancestor sentence and doorway, unchanged, with the worn layer beneath', () => {
  const origin = wornAt(wornRule({ default: { 'padding-top': '30px' } }), 'default');
  assert.equal(origin.layer, ancestorLayerId(SECTION, '> pb-text'));
  assert.equal(origin.reference, null);
  assert.equal(origin.color, SKIN_COLOR);
  assert.equal(origin.header, '30px inherited from Section (> pb-text)');
  assert.deepEqual(origin.doorways.map((doorway) => [doorway.id, doorway.label, doorway.key, doorway.selector]), [['ancestor', 'Edit at Section (> pb-text)', SECTION, '> pb-text']]);
  const read = stackAt(stackFor({ map: wornRule({ default: { 'padding-top': '30px' } }), guide: WORDED, tree: treeFor() }), 'padding-top', 'default', CONDITIONS);
  assert.deepEqual(read.beneath.map((entry) => entry.id), [ancestorWearLayerId(SECTION, '> pb-text', 'stuff')]);
});

test('overriding a variant an ancestor\'s rule wears: the element\'s own identity (the beaten variant lives in the sentence), unset returns the variant\'s value, the variant and carrier doorways beside', () => {
  const map = { ...wornRule(), [ROOT]: { default: { 'padding-top': '24px' } } };
  const origin = wornAt(map, 'default');
  assert.equal(origin.atPose, true);
  assert.equal(origin.layer, ELEMENT_LAYER);
  assert.equal(origin.reference, null);
  assert.equal(origin.color, layerColor(ELEMENT_LAYER));
  assert.equal(origin.header, "Your 24px overrides Stuff's 22px at Text blocks directly in Section");
  assert.deepEqual(origin.doorways.map((doorway) => [doorway.id, doorway.label, doorway.hint]), [
    ['unset', 'Unset', "Stuff's 22px returns"],
    ['document', 'Edit Stuff at Base…', 'opens the variant'],
    ['ancestor', 'Edit at Text blocks directly in Section', 'writes land on that rule; the selection stays'],
  ]);
  assert.equal(origin.doorways[1].anchor, WORN_RULE);
  assert.deepEqual([origin.doorways[2].key, origin.doorways[2].selector], [SECTION, '> pb-text']);
  const posed = { ...wornRule(), [ROOT]: { medium: { 'padding-top': '24px' } } };
  assert.equal(wornAt(posed, 'medium').header, "Your 24px overrides Stuff's 22px at Text blocks directly in Section, set at Base");
});

test('a variant the section wears at its ROOT reaching the block through its `::> pb-text` block: named as worn by the owner, no rule to seat, the variant doorway anchored at the owner', () => {
  const map = { [SECTION]: { variants: [{ variant: 'band', fragment: '' }] } };
  const origin = wornAt(map, 'default');
  assert.equal(origin.delivers, true);
  assert.equal(origin.layer, ancestorWearLayerId(SECTION, '> pb-text', 'band'));
  assert.equal(origin.reference, 'band');
  assert.equal(origin.color, layerColor(variantLayerId('band')));
  assert.equal(origin.header, '18px from Band worn by Section');
  assert.deepEqual(origin.doorways.map((doorway) => [doorway.id, doorway.label, doorway.anchor]), [['document', 'Edit Band at Base…', SECTION]]);
  assert.equal(wornAt(map, 'medium').header, '18px from Band worn by Section, set at Base');
  assert.deepEqual(doorways(wornAt(map, 'medium')), ['document', 'drive']);
  const over = wornAt({ ...map, [ROOT]: { default: { 'padding-top': '24px' } } }, 'default');
  assert.equal(over.header, "Your 24px overrides Band's 18px worn by Section");
  assert.deepEqual(doorways(over), ['unset', 'document']);
});

test('overriding an ancestor rule: own at pose beats it, in the element hue, unset returns it, the rule\'s doorway beside', () => {
  const map = {
    [`${SECTION}::> *`]: { default: { 'padding-top': '8px' } },
    [ROOT]: { default: { 'padding-top': '10px' } },
  };
  const origin = originAt(stackFor({ map, tree: treeFor() }), 'padding-top', 'default');
  assert.equal(origin.atPose, true);
  assert.equal(origin.layer, ELEMENT_LAYER);
  assert.equal(origin.color, layerColor(ELEMENT_LAYER));
  assert.equal(origin.header, "Your 10px overrides Section's 8px (> *)");
  assert.deepEqual(doorways(origin), ['unset', 'ancestor']);
  assert.equal(origin.doorways[0].hint, "Section's 8px returns");
  // The overriding sentence carries the `>` literally too, at Base and
  // posed elsewhere.
  const typed = {
    [`${SECTION}::> pb-text`]: { default: { 'padding-top': '8px' } },
    [ROOT]: { default: { 'padding-top': '10px' } },
  };
  assert.equal(originAt(stackFor({ map: typed, tree: treeFor() }), 'padding-top', 'default').header, "Your 10px overrides Section's 8px (> pb-text)");
  const posed = {
    [`${SECTION}::> pb-text`]: { default: { 'padding-top': '8px' } },
    [ROOT]: { medium: { 'padding-top': '10px' } },
  };
  assert.equal(originAt(stackFor({ map: posed, tree: treeFor() }), 'padding-top', 'medium').header, "Your 10px overrides Section's 8px (> pb-text) at Base");
});

test('the skin\'s value sits over the declaration\'s: both present, the origin is the skin\'s and the declaration is beneath', () => {
  const stack = stackFor({ guide: BOTH });
  const origin = originAt(stack, 'display', 'default', { skinEditUrl: SKIN_URL });
  assert.equal(origin.layer, skinLayerId('block_type:text'));
  assert.equal(origin.header, 'inline-block from the active skin');
  assert.deepEqual(doorways(origin), ['skin']);
  assert.deepEqual(stackAt(stack, 'display', 'default', CONDITIONS).beneath.map((entry) => entry.id), [declaredLayerId('block_type:text')]);
});

test('overriding a variant: own at pose beats the reference, the WINNER\'s identity (the beaten layer is the sentence\'s), with unset and document doorways', () => {
  const origin = originAt(stackFor({ map: worn(['stuff'], { default: { 'padding-top': '24px' } }) }), 'padding-top', 'default');
  assert.equal(origin.atPose, true);
  assert.equal(origin.layer, ELEMENT_LAYER);
  assert.equal(origin.reference, null);
  assert.equal(origin.color, layerColor(ELEMENT_LAYER));
  assert.equal(origin.header, 'Your 24px overrides Stuff\'s 22px');
  assert.deepEqual(origin.doorways.map((doorway) => [doorway.id, doorway.label, doorway.hint]), [
    ['unset', 'Unset', 'Stuff\'s 22px returns'],
    ['document', 'Edit Stuff at Base…', 'opens the variant'],
  ]);
  // The nearest layer beneath is the one named: Calm over Stuff over
  // the skin, the own value names Calm.
  const nearest = originAt(stackFor({ map: worn(['stuff', 'calm'], { default: { 'padding-top': '24px' } }), guide: SKIN }), 'padding-top', 'default');
  assert.equal(nearest.header, 'Your 24px overrides Calm\'s 20px');
  assert.equal(nearest.layer, ELEMENT_LAYER);
});

test('overriding the skin: own at pose over a skin value wears the element hue; at another condition it says where the beaten value delivers from', () => {
  const origin = originAt(stackFor({ map: { [ROOT]: { default: { 'padding-top': '1.25rem' } } }, guide: SKIN }), 'padding-top', 'default', { skinEditUrl: SKIN_URL });
  assert.equal(origin.atPose, true);
  assert.equal(origin.layer, ELEMENT_LAYER);
  assert.equal(origin.color, layerColor(ELEMENT_LAYER));
  assert.equal(origin.header, 'Your 1.25rem overrides the active skin\'s 1.125rem');
  assert.deepEqual(origin.doorways.map((doorway) => [doorway.id, doorway.hint]), [
    ['unset', 'the skin\'s 1.125rem returns'],
    ['skin', 'opens the skin form'],
  ]);
  const medium = originAt(stackFor({ map: { [ROOT]: { medium: { 'padding-top': '1.25rem' } } }, guide: SKIN }), 'padding-top', 'medium', { skinEditUrl: SKIN_URL });
  assert.equal(medium.atPose, true);
  assert.equal(medium.header, 'Your 1.25rem overrides the active skin\'s 1.125rem at Base');
});

test('overriding the declaration: own at pose over a declared default wears the element hue, unset returns it, no other doorway', () => {
  const origin = originAt(stackFor({ map: { [ROOT]: { default: { display: 'inline' } } }, guide: DECLARED }), 'display', 'default', { skinEditUrl: SKIN_URL });
  assert.equal(origin.atPose, true);
  assert.equal(origin.layer, ELEMENT_LAYER);
  assert.equal(origin.color, layerColor(ELEMENT_LAYER));
  assert.equal(origin.header, 'Your inline overrides the text block type declaration\'s block');
  assert.deepEqual(origin.doorways.map((doorway) => [doorway.id, doorway.hint]), [['unset', 'the text block type declaration\'s block returns']]);
  const medium = originAt(stackFor({ map: { [ROOT]: { medium: { display: 'inline' } } }, guide: DECLARED }), 'display', 'medium');
  assert.equal(medium.header, 'Your inline overrides the text block type declaration\'s block at Base');
  assert.deepEqual(doorways(medium), ['unset']);
});

// --- originOf: a write address (an ancestor rule's layer as the editable one)

test('under a write address the element\'s own value sits ABOVE the editable layer: from this element, in the element hue, with the element doorway (drop the target), never the session\'s winner doorway', () => {
  const address = { key: SECTION, selector: '> *' };
  // The rule says nothing yet (a virtual editable layer at its
  // position, style-stack.js); the element's own padding wins.
  const own = { [ROOT]: { default: { 'padding-top': '10px' }, narrow: { 'padding-top': '5px' } } };
  const stack = stackFor({ map: own, tree: treeFor(), editable: address });
  const origin = originAt(stack, 'padding-top', 'default');
  assert.equal(origin.delivers, true);
  assert.equal(origin.layer, ELEMENT_LAYER);
  assert.equal(origin.color, layerColor(ELEMENT_LAYER));
  assert.equal(origin.header, '10px from this element');
  // The doorway carries the selected key: the hook re-selects it on the
  // source, and any selection write clears the target.
  assert.deepEqual(
    origin.doorways.map((doorway) => [doorway.id, doorway.label, doorway.hint, doorway.key]),
    [['element', 'Edit on the element', 'drops the target; writes land on the element', ROOT]],
  );
  // Posed at Medium the element's Base block delivers: the sentence
  // says where, the drive doorway beside; Narrow is "Also set at".
  const medium = originAt(stack, 'padding-top', 'medium');
  assert.equal(medium.header, '10px from this element at Base');
  assert.deepEqual(doorways(medium), ['element', 'drive']);
  assert.deepEqual(medium.alsoAt.map((entry) => entry.label), ['Narrow']);
  // The rule delivers too and the element's own beats it: overridden,
  // in the element hue, the element doorway; no "on this page" (that
  // sentence is the session's, about a document's wearer).
  const both = { ...own, [`${SECTION}::> *`]: { default: { 'padding-top': '8px' } } };
  const overridden = originAt(stackFor({ map: both, tree: treeFor(), editable: address }), 'padding-top', 'default');
  assert.equal(overridden.delivers, true);
  assert.equal(overridden.layer, ELEMENT_LAYER);
  assert.equal(overridden.header, 'Overridden by this element');
  assert.deepEqual(doorways(overridden), ['element']);
  // A draft being typed at the rule stands in for its block.
  assert.equal(originAt(stack, 'padding-top', 'default', { staged: true }).header, 'Overridden by this element');
  // The rule's own value with nothing on the element: the editable
  // layer delivers at the pose, set here on the rule's layer, no doorway.
  const ruleOnly = originAt(stackFor({ map: { [`${SECTION}::> *`]: { default: { 'padding-top': '8px' } } }, tree: treeFor(), editable: address }), 'padding-top', 'default');
  assert.equal(ruleOnly.atPose, true);
  assert.equal(ruleOnly.layer, ancestorLayerId(SECTION, '> *'));
  assert.equal(ruleOnly.tier, 'ancestor');
  assert.equal(ruleOnly.color, SKIN_COLOR);
  assert.equal(ruleOnly.header, '8px set here');
  assert.deepEqual(ruleOnly.doorways, []);
  // A variant the element wears also sits above the address's layer,
  // and reads as the variant (its document doorway), not as the element.
  const wornAbove = originAt(stackFor({ map: worn(['stuff']), tree: treeFor(), editable: address }), 'padding-top', 'default');
  assert.equal(wornAbove.delivers, true);
  assert.equal(wornAbove.layer, variantLayerId('stuff'));
  assert.equal(wornAbove.header, '22px from Stuff');
  assert.deepEqual(doorways(wornAbove), ['document']);
});

// --- originOf: the session (the document as the editable layer) ------------

const SESSION = { reference: 'stuff', pending: null };

test('overridden in a session: the wearer\'s own entry above the document wins, in the element hue, with the winner doorway', () => {
  const stack = stackFor({ map: worn(['stuff'], { default: { 'padding-top': '3rem' } }), session: SESSION });
  const origin = originAt(stack, 'padding-top', 'default');
  assert.equal(origin.delivers, true);
  assert.equal(origin.layer, ELEMENT_LAYER);
  assert.equal(origin.color, layerColor(ELEMENT_LAYER));
  assert.equal(origin.header, 'Overridden by this element on this page');
  assert.deepEqual(origin.doorways.map((doorway) => [doorway.id, doorway.label]), [['winner', 'Go to element']]);
  assert.deepEqual(origin.doorways[0].winner, { variant: null });
});

test('overridden in a session by a higher reference: Calm above the Stuff document wears Calm\'s hue and opens Calm', () => {
  const origin = originAt(stackFor({ map: worn(['stuff', 'calm']), session: SESSION }), 'padding-top', 'default');
  assert.equal(origin.delivers, true);
  assert.equal(origin.layer, variantLayerId('calm'));
  assert.equal(origin.color, layerColor(variantLayerId('calm')));
  assert.equal(origin.header, 'Overridden by Calm on this page');
  assert.deepEqual(origin.doorways.map((doorway) => [doorway.id, doorway.label, doorway.winner]), [['winner', 'Edit Calm…', { variant: 'calm' }]]);
});

test('in a session a layer above an EMPTY document is inherited, not overridden, unless something is staged', () => {
  // The document says nothing about display; the wearer's own does.
  const stack = stackFor({ map: worn(['stuff'], { default: { display: 'flex' } }), session: SESSION });
  const origin = originAt(stack, 'display', 'default');
  assert.equal(origin.delivers, true);
  assert.equal(origin.color, layerColor(ELEMENT_LAYER));
  assert.equal(origin.header, 'flex from this element');
  assert.deepEqual(origin.doorways.map((doorway) => [doorway.id, doorway.winner]), [['winner', { variant: null }]]);
  assert.equal(originAt(stack, 'display', 'medium').header, 'flex from this element at Base');
  // A draft being typed, or a pending line, stands in for the document's
  // block: the higher layer is then overriding it.
  const staged = originAt(stack, 'display', 'default', { staged: true });
  assert.equal(staged.delivers, true);
  assert.equal(staged.header, 'Overridden by this element on this page');
});

test('in a session the editable layer is the document: its value from another condition wears the variant hue; a skin beneath it reads as inherited', () => {
  const stack = stackFor({ map: worn(['stuff']), guide: SKIN, session: SESSION });
  const origin = originAt(stack, 'padding-top', 'medium');
  assert.equal(origin.delivers, true);
  assert.equal(origin.layer, variantLayerId('stuff'));
  assert.equal(origin.color, layerColor(variantLayerId('stuff')));
  assert.equal(origin.header, '22px from Base');
  assert.deepEqual(doorways(origin), ['drive']);
  // The document at the pose over the skin beneath it: overriding.
  const over = originAt(stack, 'padding-top', 'default', { skinEditUrl: SKIN_URL });
  assert.equal(over.atPose, true);
  assert.equal(over.header, 'Your 22px overrides the active skin\'s 1.125rem');
  // The staged document (a pending line) is what the row reads.
  const pending = { reference: 'stuff', pending: { kind: 'update', lines: [{ id: 'p1', relative: '', condition: 'default', property: 'padding-top', value: '30px' }] } };
  assert.equal(originAt(stackFor({ map: worn(['stuff']), guide: SKIN, session: pending }), 'padding-top', 'default').header, 'Your 30px overrides the active skin\'s 1.125rem');
});

// --- originOf: the roster and the faces ------------------------------------

test('alsoAt: every other stored condition the stack delivers the property at, the winner\'s condition excluded, prelabeled', () => {
  const origin = originAt(stackFor({ map: worn(['stuff'], { 'narrow+dark': { 'padding-top': '8px' } }) }), 'padding-top', 'medium');
  assert.equal(origin.header, '22px from Stuff at Base');
  assert.deepEqual(origin.alsoAt, [
    { condition: 'narrow', label: 'Narrow', value: '12px' },
    { condition: 'narrow+dark', label: 'Narrow + Dark', value: '8px' },
  ]);
  // Nothing delivers at Base: every set condition is an "also".
  const none = originAt(stackFor({ map: { [ROOT]: { narrow: { 'padding-top': '12px' } } } }), 'padding-top', 'default');
  assert.equal(none.delivers, false);
  assert.deepEqual(none.alsoAt.map((entry) => entry.condition), ['narrow']);
  // The skin's other conditions ride the roster too: a style that
  // applies shows, whichever tier sets it.
  const skin = originAt(stackFor({ guide: SKIN }), 'padding-top', 'default');
  assert.deepEqual(skin.alsoAt, [{ condition: 'narrow', label: 'Narrow', value: '100%' }]);
});

test('faceOf: the sentences and the roster speak display faces, never spellings', () => {
  const faceOf = (value) => ({ '22px': 'Spacing 4', '12px': 'Spacing 2', '24px': 'Spacing 5' })[value] ?? value;
  const inherited = originAt(stackFor({ map: worn(['stuff']) }), 'padding-top', 'medium', { faceOf });
  assert.equal(inherited.header, 'Spacing 4 from Stuff at Base');
  assert.deepEqual(inherited.alsoAt.map((entry) => entry.value), ['Spacing 2']);
  const overriding = originAt(stackFor({ map: worn(['stuff'], { default: { 'padding-top': '24px' } }) }), 'padding-top', 'default', { faceOf });
  assert.equal(overriding.header, 'Your Spacing 5 overrides Stuff\'s Spacing 4');
  assert.equal(overriding.doorways[0].hint, 'Stuff\'s Spacing 4 returns');
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
  const origin = originAt(stackFor({ map: worn(['stuff']) }), 'padding-top', 'medium');
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
  const origin = originAt(stackFor({ guide: DECLARED }), 'display', 'default');
  const menu = rowMenu({ origin, tokens: [], face: 'block', onDoorway: () => assert.fail('an inert doorway never dispatches') });
  assert.equal(menu.header, origin.header);
  assert.deepEqual(menu.items.map((item) => [item.label, item.disabled, typeof item.reason, 'run' in item]), [['Declared in code', true, 'string', false]]);
});

test('rowMenu: the origin\'s sentence heads the menu whenever there is an origin, a set-here row included; tokens under no origin wear the "Where should" header; nothing at all is null', () => {
  const own = originAt(stackFor({ map: { [ROOT]: { default: { 'padding-top': '19px' } } } }), 'padding-top', 'default');
  const tokensOnly = rowMenu({ origin: own, tokens: [{ label: 'Spacing · 5', checked: false, run: () => {} }], face: '1.5rem' });
  assert.equal(tokensOnly.header, '19px set here');
  assert.equal(tokensOnly.items.length, 1);
  assert.equal(rowMenu({ origin: own, tokens: [] }), null, 'a header alone is no menu: the primitive refuses an empty list');
  assert.equal(rowMenu({ origin: null, tokens: [] }), null);
  const noOrigin = rowMenu({ origin: null, tokens: [{ label: 'x', checked: null, run: () => {} }], face: '1.5rem' });
  assert.equal(noOrigin.header, 'Where should 1.5rem come from?');
  const noFace = rowMenu({ origin: null, tokens: [{ label: 'x', checked: null, run: () => {} }], face: '' });
  assert.equal(noFace.header, null);
  // A "Not set" row with a roster still opens on its sentence.
  const elsewhere = originAt(stackFor({ map: { [ROOT]: { narrow: { 'padding-top': '12px' } } } }), 'padding-top', 'default');
  const menu = rowMenu({ origin: elsewhere, tokens: [] });
  assert.equal(menu.header, 'Set at Narrow, not here');
  assert.deepEqual(menu.items.map((item) => item.label), ['Also set at', 'Narrow']);
});

// --- runner -----------------------------------------------------------------

// --- sidesSentence (the family row's words over sidesOrigin's facts) --------

const SIDE_LAYER = { element: { id: 'element', tier: 'element', label: null, variant: null, scope: null }, button: { id: 'variant:button', tier: 'variant', label: 'Button', variant: 'button', scope: null }, skin: { id: 'skin:block_type:text', tier: 'skin', label: null, variant: null, scope: 'block_type:text' } };
const group = (sides, supplier, condition, pose, state = supplier === SIDE_LAYER.element ? 'authored' : supplier.tier) =>
  ({ sides, id: supplier.id, index: 0, state, reference: supplier.variant, condition, atPose: condition === pose, supplier });
const facts = (groups, extra = {}) => ({ delivers: groups.length > 0, atPose: groups.length === 1 && groups[0].atPose, layer: groups[0]?.id ?? null, supplier: groups[0]?.supplier ?? null, named: [], groups, ...extra });

test('supplierName names a layer in the delivering sentences\' words', () => {
  assert.equal(supplierName(SIDE_LAYER.element), 'this element');
  assert.equal(supplierName(SIDE_LAYER.button), 'Button');
  assert.equal(supplierName({ ...SIDE_LAYER.button, label: null }), 'button');
  assert.equal(supplierName(SIDE_LAYER.skin), 'the active skin');
  assert.equal(supplierName({ id: declaredLayerId('block_type:text'), tier: 'declared', scope: 'block_type:text' }), 'the text block type declaration');
});

test('sidesSentence: one group speaks as a property row would, the uniform value leading', () => {
  const at = (pose, value, ...groups) => sidesSentence(facts(groups), { pose, conditions: CONDITIONS, value });
  assert.equal(at('medium', '1rem', group(['top', 'right', 'bottom', 'left'], SIDE_LAYER.element, 'medium', 'medium')), '1rem set here at Medium');
  assert.equal(at('default', '1rem', group(['top', 'right', 'bottom', 'left'], SIDE_LAYER.element, 'default', 'default')), '1rem set here');
  assert.equal(at('medium', null, group(['top', 'bottom'], SIDE_LAYER.element, 'medium', 'medium')), 'Set here at Medium', 'sides that differ: the story alone, capitalized');
  assert.equal(at('medium', '1rem', group(['top', 'right', 'bottom', 'left'], SIDE_LAYER.element, 'default', 'medium')), '1rem from Base');
  assert.equal(at('default', '1rem', group(['top', 'right', 'bottom', 'left'], SIDE_LAYER.button, 'default', 'default')), '1rem from Button');
  assert.equal(at('dark', '1rem', group(['top', 'right', 'bottom', 'left'], SIDE_LAYER.button, 'default', 'dark')), '1rem from Button at Base');
  assert.equal(at('default', null, group(['top', 'right', 'bottom', 'left'], SIDE_LAYER.skin, 'default', 'default')), 'From the active skin');
});

test('sidesSentence: a split names each group\'s sides and story and points at the diagram', () => {
  const sentence = sidesSentence(
    facts([group(['top', 'bottom'], SIDE_LAYER.element, 'medium', 'medium'), group(['right', 'left'], SIDE_LAYER.element, 'default', 'medium')]),
    { pose: 'medium', conditions: CONDITIONS, value: null },
  );
  assert.equal(sentence, 'Top, bottom set here at Medium; right, left from Base. Open the diagram for each side');
  const worn = sidesSentence(
    facts([group(['top'], SIDE_LAYER.element, 'default', 'default'), group(['right', 'bottom', 'left'], SIDE_LAYER.button, 'default', 'default')]),
    { pose: 'default', conditions: CONDITIONS, value: null },
  );
  assert.equal(worn, 'Top set here; right, bottom, left from Button. Open the diagram for each side');
});

test('sidesSentence: no set side names the topmost author\'s conditions, or "Not set"', () => {
  assert.equal(sidesSentence(facts([], { supplier: SIDE_LAYER.element, named: ['medium', 'dark'] }), { pose: 'default', conditions: CONDITIONS }), 'Set at Medium, Dark, not here');
  assert.equal(sidesSentence(facts([], { supplier: SIDE_LAYER.button, named: ['narrow'] }), { pose: 'default', conditions: CONDITIONS }), 'Set by Button at Narrow, not here');
  assert.equal(sidesSentence(facts([]), { pose: 'medium', conditions: CONDITIONS }), 'Not set');
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
  console.error(`${failures} provenance-model test(s) failed.`);
  process.exit(1);
}
console.log(`${tests.length} provenance-model tests passed.`);
