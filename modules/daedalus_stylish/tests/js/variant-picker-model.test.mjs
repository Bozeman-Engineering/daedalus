/**
 * @file
 * Locks the variant picker's pure half (variant-picker-model.js): the
 * offer regrouped by axis in declaration order, worn variants marked,
 * fit hints, the owner heading (a stencil's curated looks ahead of the
 * axis's generic ones), the axis preset (the swap) and type-to-filter.
 * The offer itself is variant-scope's (applicableVariants / rankVariants, locked
 * in variant-scope.test.mjs); the picker derives none. Run with:
 * node --test tests/js/*.test.mjs
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { matchesNeedle, pickerGroups, pickerHome, pickerIds } from '../../js/src/variant-picker-model.js';
import { listboxTarget } from '../../js/src/palette-picker-model.js';
import { roster, stencilRecord, variantRecord } from './materials-fixture.mjs';

const GUIDE = { axisLabels: { container_layout: 'Layout' } };
const VOCABULARY = ['style', 'container_layout', 'animation', 'hover'];
// The owners: a content stencil and a file-shipped one, both named by
// their machine name in the roster (the two ownership lanes).
const MATERIALS = roster(
  stencilRecord('card', { label: 'Card' }),
  stencilRecord('hero', { label: 'Hero', updatable: false }),
);

const card = variantRecord('card', { label: 'Card', description: 'Padded surface with a soft shadow.' });
const quiet = variantRecord('quiet', { label: 'Quiet' });
const columns = variantRecord('columns', { label: 'Columns', group: 'container_layout', description: 'Children side by side.' });
const fadeIn = variantRecord('fade_in', { label: 'Fade in', group: 'animation' });
const rise = variantRecord('rise', { label: 'Rise', group: 'animation', description: 'Enters from below.' });
const slideIn = variantRecord('slide_in', { label: 'Slide in', group: 'animation' });
const lift = variantRecord('lift', { label: 'Lift', group: 'hover', description: 'Rises 4px with a shadow on hover.' });

// rankVariants' shape, in a deliberately non-vocabulary order with the
// hover entry first: grouping follows the axes, ranking holds within one.
const RANKED = [
  { variant: lift, resolved: 1, total: 1 },
  { variant: card, resolved: 2, total: 3 },
  { variant: quiet, resolved: 1, total: 1 },
  { variant: fadeIn, resolved: 1, total: 1 },
  { variant: rise, resolved: 1, total: 1 },
  { variant: slideIn, resolved: 1, total: 1 },
  { variant: columns, resolved: 1, total: 1 },
];
const REFS = [{ variant: 'fade_in', fragment: '' }, { variant: 'card', fragment: 'block(text,1)' }];

const shape = (groups) => groups.map((group) => [group.label, group.options.map((option) => option.id)]);
const groupsOf = (context = {}) => pickerGroups({ ranked: RANKED, vocabulary: VOCABULARY, refs: REFS, guide: GUIDE, materials: MATERIALS, ...context });

test('pickerGroups: one group per axis with an offer, vocabulary order, ranked order within', () => {
  const groups = groupsOf();
  assert.deepEqual(shape(groups), [
    ['Style', ['card', 'quiet']],
    ['Layout', ['columns']],
    ['Animation', ['fade_in', 'rise', 'slide_in']],
    ['Hover', ['lift']],
  ]);
  assert.deepEqual(groups.map((group) => group.axis), VOCABULARY);
  // Unowned offer: every group is its axis's own, keyed by the axis.
  assert.deepEqual(groups.map((group) => group.key), VOCABULARY);
  assert.deepEqual(groups.map((group) => group.owner), [null, null, null, null]);
});

// The owned offer: a card placement's stencil curates two style looks and
// a hover look; a file-shipped hero stencil curates one style look. The
// offer gate (applicableVariants) admits only the placement's own owner
// in production; the model groups whatever it is handed.
const cardBold = variantRecord('card_bold', { label: 'Bold', owned_by: 'card', description: 'Heavy border, loud shadow.' });
const cardQuiet = variantRecord('card_quiet', { label: 'Muted', owned_by: 'card' });
const cardLift = variantRecord('card_lift', { label: 'Lift', group: 'hover', owned_by: 'card' });
const heroDark = variantRecord('hero_dark', { label: 'Dark', owned_by: 'hero' });
const OWNED = [
  { variant: cardBold, resolved: 1, total: 1 },
  ...RANKED,
  { variant: heroDark, resolved: 1, total: 1 },
  { variant: cardQuiet, resolved: 1, total: 1 },
  { variant: cardLift, resolved: 1, total: 1 },
];

test('pickerGroups: an owner heading leads its axis, both ownership lanes, keyed uniquely', () => {
  const groups = groupsOf({ ranked: OWNED, refs: [] });
  assert.deepEqual(shape(groups), [
    ['Card', ['card_bold', 'card_quiet']],
    ['Hero', ['hero_dark']],
    ['Style', ['card', 'quiet']],
    ['Layout', ['columns']],
    ['Animation', ['fade_in', 'rise', 'slide_in']],
    ['Card', ['card_lift']],
    ['Hover', ['lift']],
  ]);
  // The owner group stays on its real axis (the competition rule) and
  // names its owner; the axis group names none.
  assert.deepEqual(groups.map((group) => [group.axis, group.owner]), [
    ['style', 'card'], ['style', 'hero'], ['style', null], ['container_layout', null], ['animation', null], ['hover', 'card'], ['hover', null],
  ]);
  // Two "Card" headings (style, hover) are two groups: keys never collide.
  const keys = groups.map((group) => group.key);
  assert.deepEqual(keys, ['style:card', 'style:hero', 'style', 'container_layout', 'animation', 'hover:card', 'hover']);
  assert.equal(new Set(keys).size, keys.length);
  // The swap's preset keeps the owner split within the one axis.
  assert.deepEqual(shape(groupsOf({ ranked: OWNED, refs: [], axis: 'style' })), [
    ['Card', ['card_bold', 'card_quiet']],
    ['Hero', ['hero_dark']],
    ['Style', ['card', 'quiet']],
  ]);
  // An owner the roster does not name (the offer never carries one, the
  // gate drops orphans) still heads by its id rather than failing.
  const stray = variantRecord('stray', { owned_by: 'gone' });
  assert.deepEqual(shape(groupsOf({ ranked: [{ variant: stray, resolved: 1, total: 1 }], refs: [] })), [['gone', ['stray']]]);
});

test('pickerGroups: one axis, two groups: the owner split alone earns headings', () => {
  const styleOnly = OWNED.filter(({ variant }) => variant.group === 'style');
  const groups = groupsOf({ ranked: styleOnly, vocabulary: ['style'], refs: [] });
  assert.equal(groups.length, 3);
  assert.deepEqual(groups.map((group) => group.key), ['style:card', 'style:hero', 'style']);
  // Without owned looks the one axis makes the one group (the flat posture).
  assert.equal(groupsOf({ ranked: RANKED, vocabulary: ['style'], refs: [] }).length, 1);
});

test('pickerGroups: search matches the owner label, and an owned option still answers by its own fields', () => {
  const at = (search) => shape(groupsOf({ ranked: OWNED, refs: [], search }));
  // "hero": the owner label alone reaches the hero look.
  assert.deepEqual(at('hero'), [['Hero', ['hero_dark']]]);
  // "card": every Card-owned look (owner label) plus Style's Card (label).
  assert.deepEqual(at('card'), [['Card', ['card_bold', 'card_quiet']], ['Style', ['card']], ['Card', ['card_lift']]]);
  // The owner label counts as a token beside the axis label and description.
  assert.deepEqual(at('card loud'), [['Card', ['card_bold']]]);
  assert.deepEqual(at('card hover'), [['Card', ['card_lift']]]);
  // A generic option never answers to a stencil's name.
  assert.deepEqual(at('hero quiet'), []);
});

test('pickerGroups: worn variants are marked, not hidden; fit hints ride a partial fit only', () => {
  const groups = groupsOf();
  const options = Object.fromEntries(groups.flatMap((group) => group.options).map((option) => [option.id, option]));
  // Worn by any reference, root or fragment.
  assert.equal(options.fade_in.worn, true);
  assert.equal(options.card.worn, true);
  assert.equal(options.rise.worn, false);
  assert.deepEqual(options.card.hint, { resolved: 2, total: 3 });
  assert.equal(options.rise.hint, null);
  assert.equal(options.rise.description, 'Enters from below.');
  assert.equal(options.quiet.description, '');
  assert.equal(options.rise.label, 'Rise');
});

test('pickerGroups: an axis with nothing applicable is absent', () => {
  const ranked = RANKED.filter(({ variant }) => variant.group !== 'hover');
  assert.deepEqual(
    shape(groupsOf({ ranked, refs: [] })).map(([label]) => label),
    ['Style', 'Layout', 'Animation'],
  );
  assert.deepEqual(groupsOf({ ranked: [], refs: [] }), []);
});

test('pickerGroups: an offer on an axis outside the vocabulary is not offered (depth enters through the vocabulary)', () => {
  // A nested container's vocabulary drops the root-only width axis while
  // the width variants still scope to kind:container: no Width group, no
  // option, whether every axis or that one is asked for.
  const siteWidth = variantRecord('container_site', { label: 'Site width', group: 'width' });
  const ranked = [...RANKED, { variant: siteWidth, resolved: 1, total: 1 }];
  const nested = VOCABULARY;
  assert.deepEqual(
    shape(groupsOf({ ranked, vocabulary: nested, refs: [] })).map(([label]) => label),
    ['Style', 'Layout', 'Animation', 'Hover'],
  );
  assert.deepEqual(groupsOf({ ranked, vocabulary: nested, refs: [], axis: 'width' }), []);
  // The root's vocabulary speaks it, so the root offers it.
  assert.deepEqual(
    shape(groupsOf({ ranked, vocabulary: ['width', ...VOCABULARY], refs: [] }))[0],
    ['Width', ['container_site']],
  );
});

test('pickerGroups: the axis preset narrows the offer to one axis (the swap)', () => {
  const groups = groupsOf({ axis: 'animation' });
  assert.deepEqual(shape(groups), [['Animation', ['fade_in', 'rise', 'slide_in']]]);
  // A preset with search narrows further; a preset nothing offers is empty.
  assert.deepEqual(shape(groupsOf({ axis: 'animation', search: 'ri' })), [['Animation', ['rise']]]);
  assert.deepEqual(groupsOf({ axis: 'emphasis' }), []);
});

test('pickerGroups: search filters over label, axis label and description, keeping group order', () => {
  const at = (search) => shape(groupsOf({ search }));
  // "li": Slide in (label), Lift (label); Style's Card carries no "li".
  assert.deepEqual(at('li'), [['Animation', ['slide_in']], ['Hover', ['lift']]]);
  // The axis label matches: every animation, none of the others.
  assert.deepEqual(at('anim'), [['Animation', ['fade_in', 'rise', 'slide_in']]]);
  // The description matches; case is ignored; every token must land.
  assert.deepEqual(at('SHADOW'), [['Style', ['card']], ['Hover', ['lift']]]);
  assert.deepEqual(at('shadow hover'), [['Hover', ['lift']]]);
  assert.deepEqual(at('nothing-like-this'), []);
  // Whitespace alone is no needle.
  assert.equal(at('   ').length, 4);
});

test('matchesNeedle: the four fields, tokenized, case-insensitive', () => {
  assert.equal(matchesNeedle(lift, 'Hover', ''), true);
  assert.equal(matchesNeedle(lift, 'Hover', 'LIFT'), true);
  assert.equal(matchesNeedle(lift, 'Hover', 'hov'), true);
  assert.equal(matchesNeedle(lift, 'Hover', '4px shadow'), true);
  assert.equal(matchesNeedle(lift, 'Hover', 'rise fade'), false);
  // A record without a label answers by its id.
  assert.equal(matchesNeedle({ id: 'bare' }, 'Style', 'bar'), true);
  // The owner label is the fourth field, absent when unowned.
  assert.equal(matchesNeedle(cardBold, 'Style', 'card'), false);
  assert.equal(matchesNeedle(cardBold, 'Style', 'card', 'Card'), true);
  assert.equal(matchesNeedle(cardBold, 'Style', 'card loud', 'Card'), true);
  assert.equal(matchesNeedle(cardBold, 'Style', 'hero', 'Card'), false);
});

test('pickerIds: the roving roster crosses group boundaries under listboxTarget', () => {
  const ids = pickerIds(groupsOf());
  assert.deepEqual(ids, ['card', 'quiet', 'columns', 'fade_in', 'rise', 'slide_in', 'lift']);
  // ArrowDown from a group's last option lands on the next group's first.
  assert.equal(listboxTarget(ids, 'quiet', 'ArrowDown'), 'columns');
  assert.equal(listboxTarget(ids, 'columns', 'ArrowUp'), 'quiet');
  assert.equal(listboxTarget(ids, 'lift', 'ArrowDown'), 'lift');
  assert.equal(listboxTarget(ids, null, 'ArrowDown'), 'card');
  assert.deepEqual(pickerIds([]), []);
});

test('pickerHome: the worn option without a search, the best label match with one', () => {
  const groups = groupsOf();
  // No search: the first option, or the worn one when asked to prefer it.
  assert.equal(pickerHome(groups, ''), 'card');
  assert.equal(pickerHome(groups, '  ', 'fade_in'), 'fade_in');
  assert.equal(pickerHome(groups, '', 'not-offered'), 'card');
  // "lift" also sits in Lift's own description and would match an earlier
  // group's description: the LABEL hit wins over group order.
  const withDescription = [{ variant: variantRecord('bounce', { label: 'Bounce', group: 'animation', description: 'A little lift on entry.' }), resolved: 1, total: 1 }, ...RANKED];
  const searched = (search) => pickerHome(groupsOf({ ranked: withDescription, refs: [], search }), search);
  assert.equal(searched('lift'), 'lift');
  assert.equal(searched('LIFT '), 'lift');
  // Starts-with beats contains: "s" lands on Slide in, not Rise.
  assert.equal(searched('s'), 'slide_in');
  // A description-only hit still lands somewhere: the first survivor.
  assert.equal(searched('entry'), 'bounce');
  assert.equal(pickerHome([], 'x'), null);
});
