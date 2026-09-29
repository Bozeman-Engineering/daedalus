// cspell:ignore paddington
/**
 * Self-asserting tests for the box-model value logic behind the spacing
 * diagram: shorthand expansion (the CSS 1/2/3/4 rule, a lone token
 * reference onto every side, the server's other structural
 * pass-throughs honored as opaque), per-side effective resolution
 * (last-declaration-wins within a layer, the logical longhands read
 * onto their physical pair), five-state provenance over
 * the style stack (authored / variant with its supplying reference /
 * skin / declared / computed: the per-side read of the stack's layers
 * at a pose),
 * the family row's one origin over its offered sides (sidesOrigin: one
 * (layer, at-pose) group reads as a row's mark, a split is hollow in the
 * topmost hue, no set side lends the topmost author's hue),
 * the per-side commit shapes (plain
 * longhand write vs the one-time shorthand expansion), the Figma
 * modifier split (Shift coarse, Alt mirror, Alt+Shift all four), and the
 * side cells' value-space gestures (sideScrubSeed/sideScrubMove/
 * sideStepValue dispatching on closed/detented/free, settleSpelling's
 * reference commits).
 *
 * Run directly: `node tests/js/box-model.test.mjs`. Exits non-zero on
 * failure.
 */
import { strict as assert } from 'node:assert';
import { materialsFrom } from './materials-fixture.mjs';
import { layerColor } from '../../js/src/code/layers.js';
import {
  BOX_FAMILIES,
  OPPOSITE_SIDE,
  SIDES,
  diagramModifiers,
  diagramScrub,
  diagramStep,
  expandShorthand,
  familyLonghand,
  longhandGrain,
  propertySides,
  resolveSides,
  settleSpelling,
  sideAnchor,
  sideCommit,
  sideLonghands,
  sideMeasured,
  sideProvenance,
  sideScrubMove,
  sideScrubSeed,
  sideStepValue,
  sharesGrain,
  sidesOrigin,
  sidesPatch,
  spreadSides,
  uniformCommit,
  uniformState,
} from '../../js/src/widgets/lib/box-model.js';
import { SCRUB_BAND, SCRUB_PER_PIXEL, valueSpaceFor } from '../../js/src/value-space.js';
import { COMPUTED, layer, stackOf, stacksOf } from './stack-fixture.mjs';
import { ancestorLayerId, ancestorWearLayerId } from '../../js/src/code/layers.js';

const tests = [];
const test = (name, run) => tests.push([name, run]);

// --- expandShorthand ------------------------------------------------------

test('expandShorthand applies the CSS 1/2/3/4 rule', () => {
  assert.deepEqual(expandShorthand('1rem'), {
    top: '1rem', right: '1rem', bottom: '1rem', left: '1rem',
  });
  assert.deepEqual(expandShorthand('1rem 2rem'), {
    top: '1rem', right: '2rem', bottom: '1rem', left: '2rem',
  });
  assert.deepEqual(expandShorthand('1rem 2rem 3rem'), {
    top: '1rem', right: '2rem', bottom: '3rem', left: '2rem',
  });
  assert.deepEqual(expandShorthand('1px 2px 3px 4px'), {
    top: '1px', right: '2px', bottom: '3px', left: '4px',
  });
});

test('expandShorthand keeps the value\'s own spelling', () => {
  // Components are display/edit material, never normalized (the honesty
  // rule: what expands is exactly what was committed).
  assert.deepEqual(expandShorthand('.5rem  0'), {
    top: '.5rem', right: '0', bottom: '.5rem', left: '0',
  }, 'whitespace runs split; spellings survive');
  assert.deepEqual(expandShorthand('  1rem 2rem  '), {
    top: '1rem', right: '2rem', bottom: '1rem', left: '2rem',
  }, 'surrounding whitespace is trimmed');
  assert.deepEqual(expandShorthand('0 auto'), {
    top: '0', right: 'auto', bottom: '0', left: 'auto',
  }, 'keyword components pass through (margin\'s auto)');
});

test('expandShorthand splits a lone token reference into four copies of itself', () => {
  // One var(--name) addresses every side identically: the one splitter
  // the read (resolveSides) and the write (sideCommit) share.
  assert.deepEqual(expandShorthand('var(--space-m)'), { top: 'var(--space-m)', right: 'var(--space-m)', bottom: 'var(--space-m)', left: 'var(--space-m)' });
  assert.deepEqual(expandShorthand('  var( --space-m )  '), { top: 'var( --space-m )', right: 'var( --space-m )', bottom: 'var( --space-m )', left: 'var( --space-m )' }, 'trimmed, its own inner spelling kept');
});

test('expandShorthand refuses what it cannot split honestly', () => {
  // The server's structural pass-throughs (case-sensitive, mirroring
  // PropertyGroupBase) beyond the lone reference: one opaque value,
  // spaces and all.
  assert.equal(expandShorthand('calc(100% - 2rem)'), null);
  assert.equal(expandShorthand('1rem var(--space-m)'), null);
  assert.equal(expandShorthand('var(--a) var(--b)'), null, 'a multi-component var() stays opaque');
  assert.equal(expandShorthand('var(--s, 1rem)'), null, 'a reference with a fallback tail is not a lone reference');
  assert.equal(expandShorthand('max(var(--g), 1rem)'), null, 'a function over a reference is not a lone reference');
  assert.equal(expandShorthand('1px 2px 3px 4px 5px'), null, 'beyond repeat: 4');
  assert.equal(expandShorthand(''), null);
  assert.equal(expandShorthand('   '), null);
  assert.equal(expandShorthand(null), null);
  assert.equal(expandShorthand(undefined), null);
});

// --- property→sides helpers -----------------------------------------------

test('propertySides maps a box property to the sides it addresses', () => {
  assert.deepEqual(propertySides('padding', 'padding'), SIDES);
  assert.deepEqual(propertySides('padding', 'padding-top'), ['top']);
  assert.deepEqual(propertySides('margin', 'margin-left'), ['left']);
});

test('propertySides answers logical longhands with the physical pair they move', () => {
  // Horizontal writing mode, the only one the canvas poses in: the
  // inline pair is right and left (SIDES order), block is top and bottom.
  assert.deepEqual(propertySides('padding', 'padding-inline'), ['right', 'left']);
  assert.deepEqual(propertySides('padding', 'padding-block'), ['top', 'bottom']);
  assert.deepEqual(propertySides('margin', 'margin-inline'), ['right', 'left']);
});

test('propertySides rejects other families and spellings outside the vocabulary', () => {
  assert.equal(propertySides('padding', 'margin-top'), null);
  assert.equal(propertySides('margin', 'gap'), null);
  assert.equal(propertySides('padding', 'padding-inline-start'), null, 'the -start/-end longhands are not in the site\'s spacing vocabulary');
  assert.equal(propertySides('padding', 'paddington'), null);
});

test('longhandGrain resolves a declaration to the side longhands it addresses', () => {
  assert.deepEqual(longhandGrain('padding'), ['padding-top', 'padding-right', 'padding-bottom', 'padding-left']);
  assert.deepEqual(longhandGrain('padding-inline'), ['padding-right', 'padding-left']);
  assert.deepEqual(longhandGrain('margin-block'), ['margin-top', 'margin-bottom']);
  assert.deepEqual(longhandGrain('padding-top'), ['padding-top']);
  // Outside the box families a property is its own grain.
  assert.deepEqual(longhandGrain('color'), ['color']);
  assert.deepEqual(longhandGrain('gap'), ['gap']);
});

test('sharesGrain: two declarations shadow each other when their grains meet', () => {
  // The shadow read's predicate: an own padding masks a worn
  // padding-inline, an own padding-top leaves it showing.
  assert.equal(sharesGrain('padding', 'padding-inline'), true);
  assert.equal(sharesGrain('padding-inline', 'padding'), true);
  assert.equal(sharesGrain('padding-top', 'padding-inline'), false);
  assert.equal(sharesGrain('padding-left', 'padding-inline'), true);
  assert.equal(sharesGrain('padding-block', 'padding-inline'), false);
  // Families never meet; other properties match by name only.
  assert.equal(sharesGrain('margin', 'padding'), false);
  assert.equal(sharesGrain('color', 'color'), true);
  assert.equal(sharesGrain('color', 'background'), false);
});

test('sideLonghands maps sides back to longhand names', () => {
  assert.deepEqual(sideLonghands('padding', ['top', 'left']), ['padding-top', 'padding-left']);
  assert.deepEqual(
    sideLonghands('margin', SIDES),
    ['margin-top', 'margin-right', 'margin-bottom', 'margin-left'],
  );
  assert.deepEqual(sideLonghands('padding', []), []);
});

// --- resolveSides ---------------------------------------------------------

test('resolveSides says nothing about an empty layer', () => {
  const empty = { top: null, right: null, bottom: null, left: null };
  assert.deepEqual(resolveSides(null), empty);
  assert.deepEqual(resolveSides({}), empty);
  assert.deepEqual(resolveSides({ gap: '1rem', color: 'red' }), empty, 'unrelated properties are ignored');
});

test('resolveSides reads longhands and expands the shorthand', () => {
  assert.deepEqual(resolveSides({ 'padding-top': '1rem' }), {
    top: { value: '1rem', property: 'padding-top' },
    right: null,
    bottom: null,
    left: null,
  });
  assert.deepEqual(resolveSides({ padding: '1rem 2rem' }), {
    top: { value: '1rem', property: 'padding' },
    right: { value: '2rem', property: 'padding' },
    bottom: { value: '1rem', property: 'padding' },
    left: { value: '2rem', property: 'padding' },
  });
  assert.deepEqual(resolveSides({ 'padding-left': '  8px ' }).left,
    { value: '8px', property: 'padding-left' }, 'longhand values are trimmed');
});

test('resolveSides is last-declaration-wins, like CssPrinter output', () => {
  // Longhand after shorthand overrides its side.
  assert.deepEqual(resolveSides({ padding: '1rem', 'padding-top': '2rem' }), {
    top: { value: '2rem', property: 'padding-top' },
    right: { value: '1rem', property: 'padding' },
    bottom: { value: '1rem', property: 'padding' },
    left: { value: '1rem', property: 'padding' },
  });
  // Shorthand after longhand resets all four.
  assert.deepEqual(resolveSides({ 'padding-top': '2rem', padding: '1rem' }), {
    top: { value: '1rem', property: 'padding' },
    right: { value: '1rem', property: 'padding' },
    bottom: { value: '1rem', property: 'padding' },
    left: { value: '1rem', property: 'padding' },
  });
});

test('resolveSides treats null draft values as unsetting', () => {
  // The expansion commit's `padding: null` layered as a draft clears
  // what the shorthand supplied, mid-gesture.
  assert.deepEqual(resolveSides({ padding: '1rem', 'padding-top': null }).top, null);
  assert.deepEqual(resolveSides({ 'padding-top': '1rem', padding: null }), {
    top: null, right: null, bottom: null, left: null,
  }, 'a null shorthand clears all four');
});

test('resolveSides marks an unsplittable shorthand set-but-unshowable', () => {
  const sides = resolveSides({ padding: 'calc(1rem + 2px)' });
  SIDES.forEach((side) => {
    assert.deepEqual(sides[side], { value: null, property: 'padding' }, side);
  });
  assert.deepEqual(resolveSides({ padding: 'var(--a) var(--b)' }).left, { value: null, property: 'padding' }, 'a multi-component var() stays opaque');
  // A later longhand still overrides its side.
  assert.deepEqual(
    resolveSides({ padding: 'calc(1rem + 2px)', 'padding-top': '1rem' }).top,
    { value: '1rem', property: 'padding-top' },
  );
});

test('resolveSides reads a lone reference shorthand as the reference on every side', () => {
  // The one splitter: the read answers what sideCommit writes.
  const sides = resolveSides({ padding: 'var(--s)' });
  SIDES.forEach((side) => {
    assert.deepEqual(sides[side], { value: 'var(--s)', property: 'padding' }, side);
  });
  const later = resolveSides({ padding: 'var(--s)', 'padding-top': '1rem' });
  assert.deepEqual(later.top, { value: '1rem', property: 'padding-top' }, 'a later longhand still wins its side');
  assert.deepEqual(later.right, { value: 'var(--s)', property: 'padding' }, 'the other sides keep the reference');
});

test('resolveSides expands a logical longhand onto its physical pair', () => {
  const inset = 'max(var(--space-gutter), calc((100% - var(--layout-max-width)) / 2))';
  assert.deepEqual(resolveSides({ 'padding-inline': inset }), {
    top: null,
    right: { value: inset, property: 'padding-inline' },
    bottom: null,
    left: { value: inset, property: 'padding-inline' },
  }, 'padding-inline is right and left, its whole value on each, top and bottom untouched');
  assert.deepEqual(resolveSides({ 'padding-block': '2rem' }), {
    top: { value: '2rem', property: 'padding-block' },
    right: null,
    bottom: { value: '2rem', property: 'padding-block' },
    left: null,
  }, 'padding-block is top and bottom');
  assert.deepEqual(resolveSides({ 'margin-inline': 'auto' }, 'margin').left, { value: 'auto', property: 'margin-inline' });
  assert.deepEqual(resolveSides({ 'margin-block': '0' }, 'margin').bottom, { value: '0', property: 'margin-block' });
  assert.deepEqual(resolveSides({ 'padding-inline': '1rem' }, 'margin'), { top: null, right: null, bottom: null, left: null }, 'a padding logical never leaks into a margin read');
  assert.deepEqual(resolveSides({ 'padding-inline-start': '1rem' }), { top: null, right: null, bottom: null, left: null }, 'the -start/-end spellings stay outside the vocabulary');
  assert.deepEqual(resolveSides({ 'padding-inline': null }).left, null, 'a null draft unsets the pair');
});

test('resolveSides: a physical longhand after a logical one wins its side, and a logical one after a physical resets the pair', () => {
  const later = resolveSides({ 'padding-inline': '1rem', 'padding-left': '3rem' });
  assert.deepEqual(later.left, { value: '3rem', property: 'padding-left' });
  assert.deepEqual(later.right, { value: '1rem', property: 'padding-inline' });
  const reset = resolveSides({ 'padding-left': '3rem', 'padding-inline': '1rem' });
  assert.deepEqual(reset.left, { value: '1rem', property: 'padding-inline' });
  assert.deepEqual(reset.right, { value: '1rem', property: 'padding-inline' });
  // The shorthand after a logical longhand resets all four.
  assert.deepEqual(resolveSides({ 'padding-inline': '1rem', padding: '2rem' }).left, { value: '2rem', property: 'padding' });
  assert.deepEqual(resolveSides({ padding: '2rem', 'padding-inline': '1rem' }).top, { value: '2rem', property: 'padding' }, 'a logical longhand after the shorthand leaves the other pair alone');
});

test('resolveSides handles other box properties via the parameter', () => {
  assert.deepEqual(resolveSides({ margin: '0 auto' }, 'margin').right,
    { value: 'auto', property: 'margin' });
  assert.deepEqual(resolveSides({ 'margin-top': '-4px', padding: '1rem' }, 'margin'), {
    top: { value: '-4px', property: 'margin-top' },
    right: null,
    bottom: null,
    left: null,
  }, 'padding never leaks into a margin read');
});

// --- sideProvenance (the per-side read of the stack) -----------------------

// A member's stack under a section rule that wears a variant (T10's
// group: the worn ancestor layer beneath the rule's own), served for
// every box family the way stacksOf serves a hand-built stack.
const OWNER = 'container:cccccccc-1111-2222-3333-444444444444';
const RULE_OWN = ancestorLayerId(OWNER, '> pb-text');
const RULE_WORN = ancestorWearLayerId(OWNER, '> pb-text', 'button');
const underRule = (ownEntry, wornEntry, own = null) => {
  const stack = {
    ...stackOf(),
    layers: [
      layer(RULE_WORN, 'ancestor', wornEntry, { scope: OWNER, variant: 'button', label: 'Button', carrier: { key: OWNER, selector: '> pb-text' } }),
      layer(RULE_OWN, 'ancestor', ownEntry, { scope: OWNER, label: 'Hero' }),
      layer('element', 'element', own),
    ],
  };
  return Object.fromEntries(
    Object.keys(BOX_FAMILIES)
      .flatMap((family) => [family, ...SIDES.map((side) => familyLonghand(family, side))])
      .map((name) => [name, stack]),
  );
};

test('sideProvenance: a variant an ancestor\'s rule wears reads as a VARIANT side with the variant as its reference; the rule\'s own entry reads as the floor', () => {
  const states = sideProvenance(underRule({ 'padding-top': '2rem' }, { padding: '1rem' }));
  assert.deepEqual(states.top, { state: 'skin', layer: 1, id: RULE_OWN, reference: null, condition: 'default', atPose: true, value: '2rem', property: 'padding-top' }, 'own beats worn inside the group, as the floor');
  const worn = { state: 'variant', layer: 0, id: RULE_WORN, reference: 'button', condition: 'default', atPose: true, value: '1rem', property: 'padding' };
  ['right', 'bottom', 'left'].forEach((side) => {
    assert.deepEqual(states[side], worn, side);
  });
  // The element's own longhand beats both.
  assert.equal(sideProvenance(underRule({ 'padding-top': '2rem' }, { padding: '1rem' }, { 'padding-left': '4px' })).left.state, 'authored');
});

test('sideProvenance is computed when no layer styles a side', () => {
  const states = sideProvenance(stacksOf());
  SIDES.forEach((side) => {
    assert.deepEqual(states[side], COMPUTED, side);
  });
  assert.deepEqual(sideProvenance(null).top, COMPUTED, 'no stack at all reads computed');
});

test('sideProvenance splits authored from variant-supplied per side', () => {
  // The variant supplies the shorthand; the element authored one override.
  const stack = stacksOf({ variants: [{ id: 'stuff', styles: { padding: '1rem' } }], own: { 'padding-top': '2rem' } });
  const states = sideProvenance(stack);
  assert.deepEqual(states.top, { state: 'authored', layer: 1, id: 'element', reference: null, condition: 'default', atPose: true, value: '2rem', property: 'padding-top' });
  const supplied = { state: 'variant', layer: 0, id: 'variant:stuff', reference: 'stuff', condition: 'default', atPose: true, value: '1rem', property: 'padding' };
  ['right', 'bottom', 'left'].forEach((side) => {
    assert.deepEqual(states[side], supplied, side);
  });
});

test('sideProvenance lets an own shorthand beat variant longhands', () => {
  // The own layer is the topmost: an own `padding` resets a variant's
  // `padding-top` exactly as CSS would — every side reads authored.
  const stack = stacksOf({ variants: [{ id: 'stuff', styles: { 'padding-top': '3rem' } }], own: { padding: '1rem' } });
  const states = sideProvenance(stack);
  SIDES.forEach((side) => {
    assert.equal(states[side].state, 'authored', side);
    assert.equal(states[side].value, '1rem', side);
  });
});

test('sideProvenance reports a wholly variant-supplied ring', () => {
  const states = sideProvenance(stacksOf({ variants: [{ id: 'stuff', styles: { padding: '1rem 2rem' } }] }));
  assert.deepEqual(states.top, { state: 'variant', layer: 0, id: 'variant:stuff', reference: 'stuff', condition: 'default', atPose: true, value: '1rem', property: 'padding' });
  assert.deepEqual(states.left, { state: 'variant', layer: 0, id: 'variant:stuff', reference: 'stuff', condition: 'default', atPose: true, value: '2rem', property: 'padding' });
});

test('sideProvenance keeps unsplittable authored shorthand honest', () => {
  const states = sideProvenance(stacksOf({ own: { padding: 'calc(1rem + 2px)' } }));
  SIDES.forEach((side) => {
    assert.deepEqual(states[side], { state: 'authored', layer: 0, id: 'element', reference: null, condition: 'default', atPose: true, value: null, property: 'padding' }, side);
  });
});

test('sideProvenance reads a lone reference shorthand as the reference on every side', () => {
  // The read and the write share expandShorthand, so the ring's cells
  // show the token where the field wears it blessed (nuance 2 of the
  // padding-box-model-widget note: one rule, one answer).
  const states = sideProvenance(stacksOf({ own: { padding: 'var(--s)' } }));
  SIDES.forEach((side) => {
    assert.deepEqual(states[side], { state: 'authored', layer: 0, id: 'element', reference: null, condition: 'default', atPose: true, value: 'var(--s)', property: 'padding' }, side);
  });
});

test('sideProvenance reads the Site width variant\'s padding-inline as the variant on right and left', () => {
  // The container_site variant (daedalus_stylish_layouts variants.yml)
  // insets the band with one logical longhand: the ring's inline cells
  // read it as supplied by the variant, with its reference, never as
  // "computed from the theme"; top and bottom stay computed.
  const inset = 'max(var(--space-gutter), calc((100% - var(--layout-max-width)) / 2))';
  const states = sideProvenance(stacksOf({ variants: [{ id: 'container_site', label: 'Site width', styles: { 'padding-inline': inset } }] }));
  const supplied = { state: 'variant', layer: 0, id: 'variant:container_site', reference: 'container_site', condition: 'default', atPose: true, value: inset, property: 'padding-inline' };
  assert.deepEqual(states.right, supplied, 'right');
  assert.deepEqual(states.left, supplied, 'left');
  assert.deepEqual(states.top, COMPUTED, 'top');
  assert.deepEqual(states.bottom, COMPUTED, 'bottom');
  // An own physical longhand beats the worn logical one on its side only
  // (the decision: a dialed-in padding beats the width variant, and the
  // wear bar, not the ring, announces it).
  const dialed = sideProvenance(stacksOf({ variants: [{ id: 'container_site', styles: { 'padding-inline': inset } }], own: { 'padding-left': '2rem' } }));
  assert.deepEqual(dialed.left, { state: 'authored', layer: 1, id: 'element', reference: null, condition: 'default', atPose: true, value: '2rem', property: 'padding-left' });
  assert.deepEqual(dialed.right, supplied, 'the right side still reads the variant');
  // A worn logical pair beneath a variant-supplied shorthand: the
  // topmost layer wins the whole pair.
  const layered = sideProvenance(stacksOf({ variants: [{ id: 'container_site', styles: { 'padding-inline': inset } }, { id: 'tight', styles: { padding: '1rem' } }] }));
  assert.equal(layered.left.reference, 'tight');
  assert.equal(layered.left.value, '1rem');
});

test('sideProvenance names the delivering layer per resolving declaration', () => {
  // Two worn variants in delivery order: `stuff` supplies the shorthand,
  // `big` a top longhand over it; the element authors the left side.
  // Each side's layer is the one whose declaration resolved it, so top
  // wears big's hue and right/bottom stuff's.
  const stack = stacksOf({
    variants: [{ id: 'stuff', styles: { padding: '1rem' } }, { id: 'big', styles: { 'padding-top': '2rem' } }],
    own: { 'padding-left': '4px' },
  });
  const states = sideProvenance(stack);
  assert.deepEqual(states.top, { state: 'variant', layer: 1, id: 'variant:big', reference: 'big', condition: 'default', atPose: true, value: '2rem', property: 'padding-top' });
  assert.deepEqual(states.right, { state: 'variant', layer: 0, id: 'variant:stuff', reference: 'stuff', condition: 'default', atPose: true, value: '1rem', property: 'padding' });
  assert.deepEqual(states.bottom, { state: 'variant', layer: 0, id: 'variant:stuff', reference: 'stuff', condition: 'default', atPose: true, value: '1rem', property: 'padding' });
  assert.deepEqual(states.left, { state: 'authored', layer: 2, id: 'element', reference: null, condition: 'default', atPose: true, value: '4px', property: 'padding-left' });
  // A later reference's shorthand resets an earlier one's longhand, as
  // the printed document does.
  const reset = sideProvenance(stacksOf({ variants: [{ id: 'big', styles: { 'padding-top': '2rem' } }, { id: 'stuff', styles: { padding: '1rem' } }] }));
  SIDES.forEach((side) => {
    assert.equal(reset[side].reference, 'stuff', side);
    assert.equal(reset[side].value, '1rem', side);
  });
});

const CONDITIONS = {
  medium: { label: 'Medium', axis: 'width' },
  narrow: { label: 'Narrow', axis: 'width' },
  dark: { label: 'Dark', axis: 'scheme' },
};

test('sideProvenance reads every layer through the cascade at the pose', () => {
  // An own Base shorthand still delivers at Medium (the canvas paints it
  // there), lit as the own layer's from Base; Medium's longhand
  // overrides top alone. The Unset gestures read the delivering
  // condition against the pose, so the Base sides are not clearable
  // at Medium (box-model-diagram.js).
  const stack = stacksOf({ own: { default: { padding: '1rem 2rem' }, medium: { 'padding-top': '3rem' } } });
  assert.deepEqual(sideProvenance(stack, 'padding', 'default', CONDITIONS).top, { state: 'authored', layer: 0, id: 'element', reference: null, condition: 'default', atPose: true, value: '1rem', property: 'padding' });
  const medium = sideProvenance(stack, 'padding', 'medium', CONDITIONS);
  assert.deepEqual(medium.top, { state: 'authored', layer: 0, id: 'element', reference: null, condition: 'medium', atPose: true, value: '3rem', property: 'padding-top' });
  assert.deepEqual(medium.left, { state: 'authored', layer: 0, id: 'element', reference: null, condition: 'default', atPose: false, value: '2rem', property: 'padding' });
  // Posed at Narrow, Medium's block is not applicable: every side from Base.
  assert.equal(sideProvenance(stack, 'padding', 'narrow', CONDITIONS).top.value, '1rem');
  // A variant's Base block delivers at Medium too, as the variant's from Base.
  const worn = sideProvenance(stacksOf({ variants: [{ id: 'stuff', styles: { padding: '1rem' } }] }), 'padding', 'medium', CONDITIONS);
  assert.deepEqual(worn.top, { state: 'variant', layer: 0, id: 'variant:stuff', reference: 'stuff', condition: 'default', atPose: false, value: '1rem', property: 'padding' });
});

test('sideProvenance classifies a skin default as a lit layer beneath the map, read at the pose through the cascade', () => {
  const skin = { default: { padding: '1rem 2rem' }, medium: { 'padding-top': '3rem' } };
  const states = sideProvenance(stacksOf({ skin }), 'padding', 'default', CONDITIONS);
  assert.deepEqual(states.top, { state: 'skin', layer: 0, id: 'skin:block_type:text', reference: null, condition: 'default', atPose: true, value: '1rem', property: 'padding' });
  assert.deepEqual(states.left, { state: 'skin', layer: 0, id: 'skin:block_type:text', reference: null, condition: 'default', atPose: true, value: '2rem', property: 'padding' });
  // Posed at Medium the skin's Base shorthand still delivers (the
  // canvas paints it there); Medium's longhand overrides top alone, so
  // top is the skin's from Medium and the other sides from Base.
  const medium = sideProvenance(stacksOf({ skin }), 'padding', 'medium', CONDITIONS);
  assert.deepEqual(medium.top, { state: 'skin', layer: 0, id: 'skin:block_type:text', reference: null, condition: 'medium', atPose: true, value: '3rem', property: 'padding-top' });
  assert.deepEqual(medium.left, { state: 'skin', layer: 0, id: 'skin:block_type:text', reference: null, condition: 'default', atPose: false, value: '2rem', property: 'padding' });
  assert.equal(medium.right.value, '2rem');
  assert.equal(medium.bottom.value, '1rem');
  // Posed at Narrow, Medium's block is not applicable: every side from Base.
  assert.equal(sideProvenance(stacksOf({ skin }), 'padding', 'narrow', CONDITIONS).top.value, '1rem');
  // A later block's shorthand resets an earlier longhand, as the
  // printed document does: a Medium `padding` after a Base `padding-top`.
  const reset = sideProvenance(stacksOf({ skin: { default: { 'padding-top': '9rem' }, medium: { padding: '1rem' } } }), 'padding', 'medium', CONDITIONS);
  assert.deepEqual(reset.top, { state: 'skin', layer: 0, id: 'skin:block_type:text', reference: null, condition: 'medium', atPose: true, value: '1rem', property: 'padding' });
  // Own and variant values both beat the skin.
  const beaten = sideProvenance(stacksOf({ skin, variants: [{ id: 'stuff', styles: { padding: '1px' } }], own: { 'padding-top': '5px' } }), 'padding', 'default', CONDITIONS);
  assert.equal(beaten.top.state, 'authored');
  assert.deepEqual(beaten.left, { state: 'variant', layer: 1, id: 'variant:stuff', reference: 'stuff', condition: 'default', atPose: true, value: '1px', property: 'padding' });
  // An unsplittable skin shorthand is set-but-opaque, like the other
  // layers; a skin's lone reference is the reference on every side.
  const opaque = sideProvenance(stacksOf({ skin: { padding: 'calc(1rem + 2px)' } }));
  assert.deepEqual(opaque.top, { state: 'skin', layer: 0, id: 'skin:block_type:text', reference: null, condition: 'default', atPose: true, value: null, property: 'padding' });
  assert.equal(sideProvenance(stacksOf({ skin: { padding: 'var(--s)' } })).top.value, 'var(--s)');
});

test('sideProvenance classifies the declaration\'s own default beneath the skin\'s', () => {
  const declared = { padding: '4px 8px' };
  // Declared alone: every side the declaration's, from Base at any pose.
  const alone = sideProvenance(stacksOf({ declared }), 'padding', 'medium', CONDITIONS);
  assert.deepEqual(alone.top, { state: 'declared', layer: 0, id: 'declared:block_type:text', reference: null, condition: 'default', atPose: false, value: '4px', property: 'padding' });
  assert.deepEqual(alone.left, { state: 'declared', layer: 0, id: 'declared:block_type:text', reference: null, condition: 'default', atPose: false, value: '8px', property: 'padding' });
  // The skin's delivering block beats the declaration's for the sides it
  // resolves, whatever the conditions (the skin layer sits above the
  // declared one); the declaration supplies the rest.
  const both = sideProvenance(stacksOf({ declared, skin: { medium: { 'padding-top': '3rem' } } }), 'padding', 'medium', CONDITIONS);
  assert.deepEqual(both.top, { state: 'skin', layer: 1, id: 'skin:block_type:text', reference: null, condition: 'medium', atPose: true, value: '3rem', property: 'padding-top' });
  assert.deepEqual(both.left, { state: 'declared', layer: 0, id: 'declared:block_type:text', reference: null, condition: 'default', atPose: false, value: '8px', property: 'padding' });
  // A declared block at an unrelated condition reaches nothing.
  const away = sideProvenance(stacksOf({ declared: { narrow: { padding: '0' } } }), 'padding', 'medium', CONDITIONS);
  assert.equal(away.top.state, 'computed');
});

test('uniformState counts every delivering layer as set: a skin default lights the field', () => {
  // The display rule: the field's face is the winner's, whichever tier
  // delivers it, so a skin-only ring reads set and uniform, never a
  // ghost.
  assert.deepEqual(uniformState(stacksOf({ skin: { padding: '1rem' } })), { set: true, uniform: '1rem', text: '1rem' });
  assert.deepEqual(uniformState(stacksOf({ declared: { padding: '4px 8px' } })), { set: true, uniform: null, text: '4px 8px' });
});

// --- sideCommit -----------------------------------------------------------

test('sideCommit without an own shorthand writes plain longhands', () => {
  assert.deepEqual(sideCommit({}, ['top'], '2rem'), { 'padding-top': '2rem' });
  assert.deepEqual(sideCommit(null, ['top', 'bottom'], '2rem'), {
    'padding-top': '2rem',
    'padding-bottom': '2rem',
  }, 'a mirror spread writes both sides one value');
  assert.deepEqual(sideCommit({ 'padding-top': '1rem' }, ['top'], null),
    { 'padding-top': null }, 'null clears (the Alt-click unset)');
});

test('sideCommit never expands a variant-supplied shorthand', () => {
  // `own` carries nothing: the variant's shorthand keeps supplying the
  // other sides while the new longhand shadows one — detachable.
  assert.deepEqual(sideCommit({}, ['left'], '4px'), { 'padding-left': '4px' });
});

test('sideCommit expands an own shorthand in one atomic patch', () => {
  assert.deepEqual(sideCommit({ padding: '1rem 2rem' }, ['top'], '3rem'), {
    padding: null,
    'padding-top': '3rem',
    'padding-right': '2rem',
    'padding-bottom': '1rem',
    'padding-left': '2rem',
  }, 'unset the shorthand, edited side new, the rest at current effective');
  assert.deepEqual(sideCommit({ padding: '1rem' }, [...SIDES], '2rem'), {
    padding: null,
    'padding-top': '2rem',
    'padding-right': '2rem',
    'padding-bottom': '2rem',
    'padding-left': '2rem',
  }, 'an all-four spread still expands — the shorthand must not linger');
});

test('sideCommit expansion honors own longhand overrides and clears', () => {
  assert.deepEqual(
    sideCommit({ padding: '1rem', 'padding-left': '5px' }, ['top'], '2rem'),
    {
      padding: null,
      'padding-top': '2rem',
      'padding-right': '1rem',
      'padding-bottom': '1rem',
      'padding-left': '5px',
    },
    'unedited sides keep their layer-effective values, not the raw expansion',
  );
  assert.deepEqual(sideCommit({ padding: '1rem' }, ['top'], null), {
    padding: null,
    'padding-top': null,
    'padding-right': '1rem',
    'padding-bottom': '1rem',
    'padding-left': '1rem',
  }, 'clearing one side of a shorthand releases just that side');
});

test('sideCommit splits a whole-reference own shorthand, the reference on every side', () => {
  // One var(--name) addresses all four sides identically (expandShorthand,
  // the splitter the read shares), so a guide-scale pick or a skin color
  // never blocks the ring: the shorthand unsets and the unedited sides
  // keep the reference.
  assert.deepEqual(sideCommit({ padding: 'var(--s)' }, ['top'], null), {
    padding: null,
    'padding-top': null,
    'padding-right': 'var(--s)',
    'padding-bottom': 'var(--s)',
    'padding-left': 'var(--s)',
  });
  assert.deepEqual(sideCommit({ padding: 'var(--s)', 'padding-left': '5px' }, ['top'], '2rem'), {
    padding: null,
    'padding-top': '2rem',
    'padding-right': 'var(--s)',
    'padding-bottom': 'var(--s)',
    'padding-left': '5px',
  }, 'a later own longhand still wins its side');
  // The diagram's blocked probe (an empty spread over the own layer) is a
  // patch, not null: a one-token own padding no longer blocks the ring.
  assert.notEqual(sideCommit({ padding: 'var(--s)' }, [], null), null);
});

test('sideCommit refuses to guess at an unsplittable own shorthand', () => {
  assert.equal(sideCommit({ padding: 'calc(1rem + 2px)' }, ['top'], '2rem'), null);
  assert.equal(sideCommit({ padding: 'var(--a) var(--b)' }, ['top'], '2rem'), null, 'a multi-component var() stays opaque');
  assert.equal(sideCommit({ padding: 'var(--s, 1rem)' }, ['top'], '2rem'), null, 'a reference with a fallback tail is not the stored spelling');
  assert.equal(sideCommit({ padding: 'calc(1rem + 2px)' }, [], null), null, 'the diagram stays blocked');
  // ...unless own longhands already override every side, so no guessed
  // value can reach the patch.
  assert.deepEqual(
    sideCommit({
      padding: 'calc(1rem + 2px)',
      'padding-top': '1px',
      'padding-right': '2px',
      'padding-bottom': '3px',
      'padding-left': '4px',
    }, ['top'], '9px'),
    {
      padding: null,
      'padding-top': '9px',
      'padding-right': '2px',
      'padding-bottom': '3px',
      'padding-left': '4px',
    },
  );
});

// --- sidesPatch -----------------------------------------------------------

test('sidesPatch writes plain longhands per side without an own shorthand', () => {
  assert.deepEqual(sidesPatch({}, { top: '1rem', left: '2rem' }), {
    'padding-top': '1rem',
    'padding-left': '2rem',
  });
  assert.deepEqual(sidesPatch(null, { top: null }), { 'padding-top': null },
    'null unsets a side');
  assert.deepEqual(sidesPatch({ padding: '1rem' }, {}), {}, 'an empty gesture patches nothing');
  assert.deepEqual(sidesPatch({ padding: '1rem' }, null), {});
});

test('sidesPatch expands an own shorthand once, per-side values intact', () => {
  // The accumulated-gesture case sideCommit cannot express: a mirror
  // scrub whose Alt was released mid-drag leaves two sides at
  // DIFFERENT values, one atomic patch.
  assert.deepEqual(sidesPatch({ padding: '1rem' }, { top: '2rem', bottom: '3rem' }), {
    padding: null,
    'padding-top': '2rem',
    'padding-right': '1rem',
    'padding-bottom': '3rem',
    'padding-left': '1rem',
  });
  // Later steps see the already-expanded layer: the expansion happens
  // exactly once and never resurrects the shorthand's values over an
  // edited side.
  assert.deepEqual(sidesPatch({ padding: '1rem 2rem' }, { left: '9px', top: null }), {
    padding: null,
    'padding-top': null,
    'padding-right': '2rem',
    'padding-bottom': '1rem',
    'padding-left': '9px',
  });
});

test('sidesPatch inherits sideCommit\'s degradation on unsplittable shorthands', () => {
  assert.equal(sidesPatch({ padding: 'calc(1rem + 2px)' }, { top: '1px', bottom: '2px' }), null);
  assert.equal(sidesPatch({ padding: 'var(--a) var(--b)' }, { top: '1px' }), null);
  // The block is judged before the gesture: the diagram's blocked probe
  // is the empty gesture, null over an unsplittable layer, {} otherwise.
  assert.equal(sidesPatch({ padding: 'calc(1rem + 2px)' }, {}), null);
  assert.deepEqual(sidesPatch({ padding: 'var(--s)' }, {}), {});
  // A whole-reference shorthand splits (sideCommit's rule): the
  // expansion writes the reference on every unedited side.
  assert.deepEqual(sidesPatch({ padding: 'var(--s)' }, { top: '1px' }), {
    padding: null,
    'padding-top': '1px',
    'padding-right': 'var(--s)',
    'padding-bottom': 'var(--s)',
    'padding-left': 'var(--s)',
  });
  // A VARIANT shorthand lives outside `own`: plain longhand shadows, no
  // expansion, no degradation.
  assert.deepEqual(sidesPatch({}, { top: '1px', bottom: '2px' }), {
    'padding-top': '1px',
    'padding-bottom': '2px',
  });
});

// --- spreadSides ----------------------------------------------------------

test('spreadSides implements the Figma modifier split', () => {
  assert.deepEqual(spreadSides('top'), ['top']);
  assert.deepEqual(spreadSides('top', { shiftKey: true }), ['top'], 'Shift alone is grain, not spread');
  SIDES.forEach((side) => {
    assert.deepEqual(spreadSides(side, { altKey: true }), [side, OPPOSITE_SIDE[side]], side);
  });
  assert.deepEqual(spreadSides('left', { altKey: true, shiftKey: true }), SIDES);
});

// --- uniformState (the chips' longhand-aware view) ------------------------

test('uniformState reads nothing set as honestly nothing', () => {
  assert.deepEqual(uniformState(null), { set: false, uniform: null, text: null });
  assert.deepEqual(uniformState(stacksOf()), { set: false, uniform: null, text: null });
  assert.deepEqual(uniformState(stacksOf({ own: { color: 'red' } })), { set: false, uniform: null, text: null });
});

test('uniformState finds the uniform value whatever the spelling', () => {
  assert.deepEqual(uniformState(stacksOf({ own: { padding: '1rem' } })), { set: true, uniform: '1rem', text: '1rem' });
  const longhands = {
    'padding-top': '1rem', 'padding-right': '1rem', 'padding-bottom': '1rem', 'padding-left': '1rem',
  };
  assert.deepEqual(uniformState(stacksOf({ own: longhands })), { set: true, uniform: '1rem', text: '1rem' },
    'four equal longhands light the chip a shorthand would');
  const variantOnly = uniformState(stacksOf({ variants: [{ id: 'v', styles: { padding: '.5rem' } }] }));
  assert.deepEqual(variantOnly, { set: true, uniform: '.5rem', text: '.5rem' },
    'a variant-supplied shorthand reads uniform in its own spelling');
});

test('uniformState reads per-side values as explicit custom', () => {
  assert.deepEqual(uniformState(stacksOf({ own: { padding: '1rem', 'padding-top': '2rem' } })), { set: true, uniform: null, text: '2rem 1rem 1rem' },
    'differing sides serialize as the minimal shorthand');
  assert.deepEqual(uniformState(stacksOf({ own: { 'padding-top': '2rem' } })), { set: true, uniform: null, text: 'top 2rem' },
    'partly-set sides list themselves — no shorthand can honestly claim unset sides');
  const axis = {
    'padding-top': '1rem', 'padding-right': '2rem', 'padding-bottom': '1rem', 'padding-left': '2rem',
  };
  assert.deepEqual(uniformState(stacksOf({ own: axis })), { set: true, uniform: null, text: '1rem 2rem' },
    'the minimal-shorthand serialization runs the 1/2/3/4 rule backwards');
});

test('uniformState reads an unsplittable shorthand as opaque custom', () => {
  assert.deepEqual(uniformState(stacksOf({ own: { padding: 'calc(1rem + 2px)' } })), { set: true, uniform: null, text: null });
});

test('uniformState reads a whole-shorthand token reference as the uniform value through the per-side read', () => {
  // One var(--name) reference is the reference on every side
  // (expandShorthand), so its spelling IS the box's single value with no
  // exception in the field's read: the select lights a scale token pick
  // by reference-spelling matching instead of degrading to custom.
  assert.deepEqual(uniformState(stacksOf({ own: { padding: 'var(--space-4)' } })),
    { set: true, uniform: 'var(--space-4)', text: 'var(--space-4)' });
  assert.deepEqual(uniformState(stacksOf({ variants: [{ id: 'v', styles: { padding: 'var(--space-4)' } }] })),
    { set: true, uniform: 'var(--space-4)', text: 'var(--space-4)' },
    'a variant-supplied reference shorthand reads uniform too');
  // Multi-component var() shorthands stay opaque: no one spelling honestly
  // represents sides that differ per component.
  assert.deepEqual(uniformState(stacksOf({ own: { padding: 'var(--space-2) var(--space-4)' } })), { set: true, uniform: null, text: null });
  // A later own longhand shadows its side out of the reference shorthand:
  // the sides differ, and the honest custom text is the minimal
  // shorthand over the per-side spellings, not an opaque blank.
  assert.deepEqual(uniformState(stacksOf({ own: { padding: 'var(--space-4)', 'padding-top': '1rem' } })), { set: true, uniform: null, text: '1rem var(--space-4) var(--space-4)' });
});

test('uniformState reads a logical pair per side', () => {
  // The Site width variant's padding-inline is outside the grammar: the
  // field measures it as the ring's cells do (sideMeasured), so the
  // band reads unset (the detented field over the canvas's placeholder,
  // never a listing of the max() expression), while a worn logical pair
  // the field can spell lists the two sides it moves.
  const inset = 'max(var(--space-gutter), calc((100% - var(--layout-max-width)) / 2))';
  assert.deepEqual(uniformState(stacksOf({ variants: [{ id: 'container_site', styles: { 'padding-inline': inset } }] })),
    { set: false, uniform: null, text: null });
  assert.deepEqual(uniformState(stacksOf({ variants: [{ id: 'v', styles: { 'padding-inline': 'var(--space-md)' } }] })),
    { set: true, uniform: null, text: 'right var(--space-md), left var(--space-md)' },
    'a lone reference is a value the field spells, not a measurement');
  assert.deepEqual(uniformState(stacksOf({ own: { 'padding-inline': inset } })),
    { set: true, uniform: null, text: `right ${inset}, left ${inset}` },
    'an own side outside the grammar is authored, never measured');
  // A logical pair plus its complement covering all four with one value
  // is uniform, however spelled.
  assert.deepEqual(uniformState(stacksOf({ own: { 'padding-inline': '1rem', 'padding-block': '1rem' } })),
    { set: true, uniform: '1rem', text: '1rem' });
  assert.deepEqual(uniformState(stacksOf({ own: { 'padding-inline': '2rem', 'padding-block': '1rem' } })),
    { set: true, uniform: null, text: '1rem 2rem' }, 'differing pairs collapse to the minimal shorthand');
  // A vertical-only grant judges the block pair alone.
  assert.deepEqual(uniformState(stacksOf({ own: { 'padding-inline': '2rem', 'padding-block': '1rem' } }), 'padding', ['top', 'bottom']),
    { set: true, uniform: '1rem', text: '1rem' });
});

test('uniformState lets the own layer win whole-side cross-spelling', () => {
  // Own shorthand over a variant longhand: the own layer is the topmost
  // and resets all four sides.
  const stack = stacksOf({ variants: [{ id: 'v', styles: { 'padding-top': '2rem' } }], own: { padding: '1rem' } });
  assert.deepEqual(uniformState(stack), { set: true, uniform: '1rem', text: '1rem' });
});

// --- uniformCommit (the chips' collapse-to-shorthand pick) ----------------

test('uniformCommit stays a plain shorthand write without own longhands', () => {
  assert.deepEqual(uniformCommit(null, '1rem'), { padding: '1rem' });
  assert.deepEqual(uniformCommit({ padding: '2rem' }, '1rem'), { padding: '1rem' });
  // Variant-supplied longhands live on the variant layer, not the own
  // entry, so nothing collapses: the own shorthand shadows them wholesale.
  assert.deepEqual(uniformCommit({}, null), { padding: null });
});

test('uniformCommit collapses own longhands back into the shorthand', () => {
  const own = { 'padding-top': '2rem', 'padding-right': '1rem', 'padding-bottom': '1rem', 'padding-left': '1rem' };
  assert.deepEqual(uniformCommit(own, '1rem'), {
    padding: '1rem',
    'padding-top': null,
    'padding-right': null,
    'padding-bottom': null,
    'padding-left': null,
  }, 'shorthand and authored longhands never coexist — the expansion invariant, inverted');
  assert.deepEqual(uniformCommit({ 'padding-top': '2rem' }, null), {
    padding: null,
    'padding-top': null,
  }, 'toggling off clears the sides the chips claimed to clear');
});

// --- grain ----------------------------------------------------------------

test('diagram grain is Shift-coarse only, Alt spends Shift on spread', () => {
  assert.deepEqual(diagramModifiers({}), { shiftKey: false, altKey: false });
  assert.deepEqual(diagramModifiers({ shiftKey: true }), { shiftKey: true, altKey: false });
  assert.deepEqual(diagramModifiers({ shiftKey: true, altKey: true }), { shiftKey: false, altKey: false });
  assert.deepEqual(diagramModifiers({ altKey: true }), { shiftKey: false, altKey: false }, 'Alt is spread on the ring, never the fine ladder');
  assert.equal(diagramStep({}), 1);
  assert.equal(diagramStep({ shiftKey: true }), 10);
  assert.equal(diagramStep({ altKey: true }), 1, 'no 0.1 fine grain in the diagram');
  assert.equal(diagramStep({ shiftKey: true, altKey: true }), 1);
  assert.equal(diagramStep({ metaKey: true }), 1, 'no 100 grain in the diagram');
  assert.equal(diagramStep({ ctrlKey: true }), 1);
  assert.equal(diagramStep({}, 'rem'), 0.1, 'the unit\'s grain: a tenth of a rem');
  assert.equal(diagramStep({ shiftKey: true }, 'rem'), 10);
  assert.equal(diagramStep({ altKey: true }, 'rem'), 0.1, 'no fine grain in the diagram, in any unit');
});

test('diagramScrub shares scrubAdvance\'s accumulator and snapping', () => {
  assert.deepEqual(diagramScrub(16, 3, {}), { raw: 17, number: 17, step: 1 });
  assert.deepEqual(diagramScrub(16, 3, { shiftKey: true }), { raw: 26, number: 30, step: 10 }, 'coarse snaps to 10s');
  assert.deepEqual(diagramScrub(16, 3, { shiftKey: true, altKey: true }), { raw: 17, number: 17, step: 1 }, 'Alt+Shift scrubs fine while spreading');
  assert.deepEqual(diagramScrub(16, 3, { metaKey: true }), { raw: 17, number: 17, step: 1 });
});

// --- the side cells' value-space gestures ---------------------------------

// A guide in the served shape: one spacing scale minting three tokens.
// Guarded closes the vocabulary (constraints are law); open leaves it
// detented; no constraint at all is free.
const SPACE_GUIDE = {
  enforcement: 'guarded',
  tokenScales: { spacing: { label: 'Spacing', prefix: 'space', shape: 'dimension' } },
  tokens: { spacing: { s: '0.5rem', m: '1rem', l: '2rem' } },
};
const SPACE_MATERIALS = materialsFrom(SPACE_GUIDE);
const DIMENSION = { type: 'dimension' };
// A longhand with no built-in table (margin-top), so the fixture's
// constraints alone shape each space.
const closedSpace = valueSpaceFor(SPACE_GUIDE, SPACE_MATERIALS, 'margin-top', DIMENSION, { scale: 'spacing' });
const detentedSpace = valueSpaceFor({ ...SPACE_GUIDE, enforcement: 'open' }, SPACE_MATERIALS, 'margin-top', DIMENSION, { scale: 'spacing' });
const freeSpace = valueSpaceFor(SPACE_GUIDE, SPACE_MATERIALS, 'margin-top', DIMENSION, null);

test('the fixture spaces have the kinds the cells dispatch on', () => {
  assert.equal(closedSpace.kind, 'closed');
  assert.equal(detentedSpace.kind, 'detented');
  assert.equal(freeSpace.kind, 'free');
  assert.equal(detentedSpace.unit, 'rem', 'the rem stops name the vocabulary\'s unit');
  assert.equal(freeSpace.unit, null, 'a free space names none');
});

const UNITS_OFFERED = ['px', 'em', 'rem', '%'];
// The canvas's measured bases (readCanvas): what a placeholder converts
// through.
const ROOT_16 = { rootPx: 16, fontPx: 16 };
const ROOT_20 = { rootPx: 20, fontPx: 12 };

test('sideMeasured: computed sides and supplied sides outside the grammar measure; own ones never', () => {
  assert.equal(sideMeasured('computed', null), true, 'nothing styles it: the canvas is all there is');
  const inset = 'max(var(--space-gutter), calc((100% - var(--layout-max-width)) / 2))';
  assert.equal(sideMeasured('variant', inset), true, 'the Site width variant\'s inset: shown as what it measures, in the variant\'s hue');
  assert.equal(sideMeasured('skin', null), true, 'a skin\'s calc() shorthand the splitter cannot open: its measurement, not an opaque blank');
  assert.equal(sideMeasured('declared', 'calc(1rem + 2px)'), true);
  assert.equal(sideMeasured('variant', '0.5rem'), false, 'a supplied dimension is the value itself (a lone reference faces as one)');
  assert.equal(sideMeasured('variant', '0'), false, 'a bare zero is a dimension');
  assert.equal(sideMeasured('authored', 'calc(1rem + 2px)'), false, 'an own value outside the grammar is the caller\'s opaque state');
  assert.equal(sideMeasured('authored', null), false, 'an own unsplittable shorthand likewise: the ring blocks, it never measures over it');
  assert.equal(sideMeasured('authored', '2rem'), false);
});

test('sideAnchor: a measured side beneath a rem scale works in rem, seeded from the px placeholder through the measured root', () => {
  // The trigger: the theme's 16px beneath olivero's rem ladder. The
  // vocabulary's unit is the gesture's (contextUnit), and the seed is
  // the placeholder converted through the canvas's root (seedAnchor),
  // so the ring-label scrub, the focused cell's arrows and the side
  // editor beneath all start at 1rem and write rem.
  const anchor = sideAnchor(detentedSpace, '16px', true, UNITS_OFFERED, ROOT_16);
  assert.deepEqual(anchor, { mode: 'dimension', number: 1, unit: 'rem' });
  assert.deepEqual(sideScrubSeed(detentedSpace, '16px', anchor.number, anchor.unit), { raw: 1, unit: 'rem' });
  const stepped = sideStepValue(detentedSpace, { number: anchor.number, unit: anchor.unit }, 1, {});
  assert.equal(stepped.text, '1.1rem', 'ArrowUp nudges the theme\'s padding, never collapses it to 0.1rem');
  assert.equal(stepped.stop, null);
  assert.equal(sideAnchor(detentedSpace, '17px', true, UNITS_OFFERED, ROOT_16).number, 1.1, 'the seed carries the ring\'s rem precision: tenths, never the editor\'s hundredths');
  assert.equal(sideAnchor(detentedSpace, '32px', true, UNITS_OFFERED, ROOT_20).number, 1.6, 'a 20px root: the same placeholder seeds 1.6rem, never 2');
  assert.equal(sideAnchor(closedSpace, '16px', true, UNITS_OFFERED, ROOT_16).unit, 'rem', 'a closed ring speaks its stops\' unit too');
  assert.deepEqual(sideAnchor(detentedSpace, '16px', true, UNITS_OFFERED), { mode: 'dimension', number: 0, unit: 'rem' }, 'no bases (the satellite): rem still, seeded 0 rather than through an assumed root');
});

test('sideAnchor: a measured side with no vocabulary unit works in the first offered unit, the placeholder converted into it', () => {
  assert.deepEqual(sideAnchor(freeSpace, '16px', true, UNITS_OFFERED, ROOT_16), { mode: 'dimension', number: 16, unit: 'px' }, 'px first by default: the placeholder passes through');
  assert.deepEqual(sideAnchor(freeSpace, '16px', true, ['em', 'px'], ROOT_20), { mode: 'dimension', number: 1.3, unit: 'em' }, 'the offered list decides, never the placeholder\'s px; the seed converts through the element\'s font size, to the ring\'s tenths (1.333 is no em a cell gesture carries)');
  assert.deepEqual(sideAnchor(freeSpace, '50%', true, ['em', 'px'], ROOT_16), { mode: 'dimension', number: 0, unit: 'em' }, 'an incomparable placeholder seeds 0 (its face shows whole)');
});

test('sideAnchor: no placeholder (the satellite) seeds 0 in the vocabulary\'s unit', () => {
  assert.deepEqual(sideAnchor(detentedSpace, null, true, UNITS_OFFERED), { mode: 'empty', number: 0, unit: 'rem' });
  assert.deepEqual(sideAnchor(freeSpace, null, true, ['em', 'px']), { mode: 'empty', number: 0, unit: 'em' }, 'nothing at all: the first offered unit');
  assert.deepEqual(sideAnchor(freeSpace, null, true, []), { mode: 'empty', number: 0, unit: 'px' }, 'the built-in floor');
});

test('sideAnchor: a set side works in its own unit from its own number', () => {
  assert.deepEqual(sideAnchor(detentedSpace, '2rem', false, UNITS_OFFERED), { mode: 'dimension', number: 2, unit: 'rem' });
  assert.deepEqual(sideAnchor(detentedSpace, '40px', false, UNITS_OFFERED), { mode: 'dimension', number: 40, unit: 'px' }, 'an own px value beneath a rem scale stays px: no seed, no conversion');
  assert.deepEqual(sideAnchor(detentedSpace, '0', false, UNITS_OFFERED), { mode: 'dimension', number: 0, unit: 'rem' }, 'a bare zero names no unit and takes the vocabulary\'s');
  assert.deepEqual(sideAnchor(detentedSpace, 'calc(1rem + 2px)', false, UNITS_OFFERED), { mode: 'text', number: 0, unit: 'rem' }, 'degraded text: click-to-edit, the unit still the vocabulary\'s');
});

test('sideScrubSeed anchors a closed cell on its stop, low end when unset', () => {
  const onStop = sideScrubSeed(closedSpace, '1rem', 1, 'rem');
  assert.equal(onStop.index, 1);
  assert.equal(onStop.raw, 1 * SCRUB_BAND);
  const unset = sideScrubSeed(closedSpace, null, 0, 'px');
  assert.equal(unset.index, -1, 'no stop matched — the seed remembers unset');
  assert.equal(unset.raw, 0, 'travel starts at the value-low end');
  assert.deepEqual(sideScrubSeed(freeSpace, '16px', 16, 'px'), { raw: 16, unit: 'px' });
});

test('sideScrubMove jumps detent to detent on a closed space, pinned ends', () => {
  const seed = sideScrubSeed(closedSpace, '0.5rem', 0.5, 'rem');
  const next = sideScrubMove(closedSpace, seed, 40, {});
  assert.equal(next.index, 1, 'half-band travel switches to the next stop');
  assert.equal(next.text, '1rem', 'the preview text is the stop\'s display value');
  assert.equal(next.stop.ref, 'var(--space-m)');
  const pinned = sideScrubMove(closedSpace, next, -1000, {});
  assert.equal(pinned.index, 0, 'the accumulator pins at the first stop');
  assert.equal(pinned.raw, 0, 'no rubber-band distance to scrub back through');
});

test('closed cell scrub speaks value direction on a largest-first scale', () => {
  const descending = valueSpaceFor(
    SPACE_GUIDE,
    materialsFrom({ ...SPACE_GUIDE, tokens: { spacing: { l: '2rem', m: '1rem', s: '0.5rem' } } }),
    'margin-top',
    DIMENSION,
    { scale: 'spacing' },
  );
  const seed = sideScrubSeed(descending, '1rem', 1, 'rem');
  const grew = sideScrubMove(descending, seed, 64, {});
  assert.equal(grew.text, '2rem', 'a rightward scrub grows the VALUE, walking the list backwards');
});

test('sideScrubMove rides sticky magnets on a detented space', () => {
  const seed = sideScrubSeed(detentedSpace, '0.9rem', 0.9, 'rem');
  const captured = sideScrubMove(detentedSpace, seed, 5, {});
  // 0.9 + 5 * 0.006 = 0.93, within the 0.12rem magnet of the 1rem stop.
  assert.equal(captured.number, 1);
  assert.equal(captured.text, '1rem');
  assert.equal(captured.stop.ref, 'var(--space-m)');
});

test('Shift scrubs a detented cell ten times faster, magnets still capture', () => {
  const seed = sideScrubSeed(detentedSpace, '1rem', 1, 'rem');
  const base = sideScrubMove(detentedSpace, seed, 10, {});
  assert.equal(base.raw, 1 + 10 * SCRUB_PER_PIXEL * 1, 'unmodified: 0.06rem of travel');
  assert.equal(base.text, '1rem', 'still inside the 1rem stop\'s magnet');
  const coarse = sideScrubMove(detentedSpace, seed, 10, { shiftKey: true });
  assert.equal(coarse.raw, 1 + 10 * SCRUB_PER_PIXEL * 10, 'Shift: the same ten pixels travel 0.6rem');
  assert.equal(coarse.text, '2rem', 'the widened magnet captures the 2rem stop, no bypass');
  assert.equal(coarse.stop.ref, 'var(--space-l)');
  const px = sideScrubMove(detentedSpace, sideScrubSeed(detentedSpace, '40px', 40, 'px'), 10, { shiftKey: true });
  assert.equal(px.text, '50px', 'the rate multiplies the unit-scaled feel (x16 for px), 49.6 shown whole');
});

test('Alt spends Shift on a detented cell; Cmd/Ctrl and Alt never change its rate', () => {
  const seed = sideScrubSeed(detentedSpace, '1rem', 1, 'rem');
  const base = 1 + 10 * SCRUB_PER_PIXEL * 1;
  const spread = sideScrubMove(detentedSpace, seed, 10, { shiftKey: true, altKey: true });
  assert.equal(spread.raw, base, 'Alt+Shift is spread at the base rate, not coarse');
  assert.equal(spread.text, '1rem');
  assert.equal(sideScrubMove(detentedSpace, seed, 10, { altKey: true }).raw, base, 'no 0.1 fine rate in the diagram');
  assert.equal(sideScrubMove(detentedSpace, seed, 10, { metaKey: true }).raw, base, 'no 100 rate in the diagram');
  assert.equal(sideScrubMove(detentedSpace, seed, 10, { ctrlKey: true }).raw, base);
});

test('a detented cell shows tenths of a rem between magnets, Alt spreads rather than refines', () => {
  const seed = sideScrubSeed(detentedSpace, '1.5rem', 1.5, 'rem');
  const moved = sideScrubMove(detentedSpace, seed, 10, {});
  assert.equal(moved.raw, 1.5 + 10 * SCRUB_PER_PIXEL, 'the accumulator keeps the true travel (1.56)');
  assert.equal(moved.number, 1.6, 'shown to the tenth: 1.41rem is nothing a padding wants');
  assert.equal(moved.text, '1.6rem');
  assert.equal(moved.stop, null, 'between the 1rem and 2rem magnets');
  const alt = sideScrubMove(detentedSpace, seed, 10, { altKey: true });
  assert.equal(alt.number, 1.6, 'Alt is spread on the ring: the precision stays tenths, the hundredths ladder is the editor\'s');
  assert.equal(alt.text, '1.6rem');
});

test('detented feel scales for px-magnitude units, magnets same-unit only', () => {
  const seed = sideScrubSeed(detentedSpace, '40px', 40, 'px');
  const moved = sideScrubMove(detentedSpace, seed, 10, {});
  assert.equal(moved.text, '41px', 'per-pixel feel is x16 for px, shown as whole pixels (40.96 traveled)');
  assert.equal(moved.raw, 40.96, 'the accumulator keeps the true travel');
  assert.equal(moved.stop, null, 'a rem stop never magnetizes a px scrub');
});

test('sideScrubMove keeps the diagram grain on a free space', () => {
  const seed = sideScrubSeed(freeSpace, '16px', 16, 'px');
  const fine = sideScrubMove(freeSpace, seed, 3, {});
  assert.equal(fine.number, 17);
  assert.equal(fine.text, '17px');
  assert.equal(fine.stop, null);
  const coarse = sideScrubMove(freeSpace, seed, 3, { shiftKey: true });
  assert.equal(coarse.number, 30, 'Shift stays the diagram\'s coarse grain');
});

test('sideStepValue steps one stop on a closed space, entering from unset', () => {
  assert.equal(sideStepValue(closedSpace, { index: 0, unit: 'rem' }, 1, {}).text, '1rem');
  const entered = sideStepValue(closedSpace, { index: -1, unit: 'rem' }, 1, {});
  assert.equal(entered.text, '0.5rem', 'unset enters at the value-low end');
  assert.equal(entered.index, 0);
  assert.equal(sideStepValue(closedSpace, { index: 2, unit: 'rem' }, 1, {}), null, 'the ramp pins at its end');
});

test('sideStepValue lands on crossed stops when detented, plain grain when free', () => {
  const across = sideStepValue(detentedSpace, { number: 0.9, unit: 'rem' }, 1, {});
  assert.equal(across.text, '1rem', 'the diagram grain crosses 1rem and lands ON it');
  assert.equal(across.stop.ref, 'var(--space-m)');
  const free = sideStepValue(freeSpace, { number: 16, unit: 'px' }, 1, { shiftKey: true });
  assert.equal(free.text, '26px');
  assert.equal(free.stop, null);
});

test('settleSpelling commits the reference on a stop landing, the literal otherwise', () => {
  assert.equal(settleSpelling(closedSpace, '1rem'), 'var(--space-m)', 'closed commits are always references');
  assert.equal(settleSpelling(detentedSpace, '.5rem'), 'var(--space-s)', 'value-matching capture, spelling-normalized');
  assert.equal(settleSpelling(detentedSpace, '0.9rem'), '0.9rem', 'between stops the literal stands');
  assert.equal(settleSpelling(freeSpace, '17px'), '17px', 'a free space captures nothing');
});

// --- the family descriptor ------------------------------------------------

test('familyLonghand spells each family\'s side longhand its own way', () => {
  assert.deepEqual(Object.keys(BOX_FAMILIES), ['padding', 'margin', 'border-width', 'border-style', 'border-color']);
  assert.equal(familyLonghand('padding', 'top'), 'padding-top');
  assert.equal(familyLonghand('margin', 'inline'), 'margin-inline');
  // The border families infix the side between family and part.
  assert.equal(familyLonghand('border-width', 'top'), 'border-top-width');
  assert.equal(familyLonghand('border-style', 'left'), 'border-left-style');
  assert.equal(familyLonghand('border-color', 'bottom'), 'border-bottom-color');
  assert.equal(familyLonghand('border-width', 'inline'), 'border-inline-width');
  assert.throws(() => familyLonghand('border', 'top'), /Unknown box family: border/, 'the cross-family shorthand is no family');
});

test('propertySides and sideLonghands read the border families through the descriptor', () => {
  assert.deepEqual(propertySides('border-width', 'border-width'), SIDES);
  assert.deepEqual(propertySides('border-width', 'border-top-width'), ['top']);
  assert.deepEqual(propertySides('border-color', 'border-left-color'), ['left']);
  assert.deepEqual(propertySides('border-style', 'border-inline-style'), ['right', 'left']);
  assert.equal(propertySides('border-width', 'border-top-color'), null, 'another family');
  assert.equal(propertySides('border-width', 'border-width-top'), null, 'the padding spelling is not a border spelling');
  assert.equal(propertySides('border-width', 'border'), null, 'the border shorthand belongs to no side family');
  assert.deepEqual(sideLonghands('border-width', ['top', 'left']), ['border-top-width', 'border-left-width']);
  assert.deepEqual(sideLonghands('border-color', SIDES), ['border-top-color', 'border-right-color', 'border-bottom-color', 'border-left-color']);
});

test('longhandGrain and sharesGrain know the border families', () => {
  assert.deepEqual(longhandGrain('border-color'), ['border-top-color', 'border-right-color', 'border-bottom-color', 'border-left-color']);
  assert.deepEqual(longhandGrain('border-top-width'), ['border-top-width']);
  assert.deepEqual(longhandGrain('border-inline-style'), ['border-right-style', 'border-left-style']);
  assert.deepEqual(longhandGrain('border'), ['border'], 'the cross-family shorthand is its own grain');
  assert.equal(sharesGrain('border-width', 'border-top-width'), true);
  assert.equal(sharesGrain('border-left-width', 'border-inline-width'), true);
  assert.equal(sharesGrain('border-top-width', 'border-top-color'), false, 'the three families never meet');
  assert.equal(sharesGrain('border-width', 'padding'), false);
});

test('expandShorthand keeps a function\'s own spaces inside one component', () => {
  // A border-color shorthand carries color functions whose inner
  // spaces are not component boundaries.
  assert.deepEqual(expandShorthand('rgb(0 0 0 / 50%)'), {
    top: 'rgb(0 0 0 / 50%)', right: 'rgb(0 0 0 / 50%)', bottom: 'rgb(0 0 0 / 50%)', left: 'rgb(0 0 0 / 50%)',
  });
  assert.deepEqual(expandShorthand('red rgb(1 2 3)'), {
    top: 'red', right: 'rgb(1 2 3)', bottom: 'red', left: 'rgb(1 2 3)',
  });
  assert.deepEqual(expandShorthand('solid dashed'), {
    top: 'solid', right: 'dashed', bottom: 'solid', left: 'dashed',
  });
  assert.deepEqual(uniformState(stacksOf({ own: { 'border-color': 'rgb(0 0 0 / 50%)' } }), 'border-color'), {
    set: true, uniform: 'rgb(0 0 0 / 50%)', text: 'rgb(0 0 0 / 50%)',
  });
});

test('sideProvenance classifies the three border families independently over one layer set', () => {
  // The blockquote posture skins ship: the skin strokes the left edge
  // alone (width, style, color), the element authors one width for
  // every side, a worn variant colors the top.
  const stack = stacksOf({
    skin: { 'border-left-width': '4px', 'border-left-style': 'solid', 'border-left-color': 'var(--accent)' },
    variants: [{ id: 'accent', styles: { 'border-top-color': 'red' } }],
    own: { 'border-width': '1px 2px' },
  });
  const width = sideProvenance(stack, 'border-width');
  assert.deepEqual(width.top, { state: 'authored', layer: 2, id: 'element', reference: null, condition: 'default', atPose: true, value: '1px', property: 'border-width' });
  assert.deepEqual(width.left, { state: 'authored', layer: 2, id: 'element', reference: null, condition: 'default', atPose: true, value: '2px', property: 'border-width' }, 'the own shorthand beats the skin\'s left width');
  const style = sideProvenance(stack, 'border-style');
  assert.deepEqual(style.left, { state: 'skin', layer: 0, id: 'skin:block_type:text', reference: null, condition: 'default', atPose: true, value: 'solid', property: 'border-left-style' });
  assert.equal(style.top.state, 'computed', 'a width says nothing about a style');
  const color = sideProvenance(stack, 'border-color');
  assert.deepEqual(color.top, { state: 'variant', layer: 1, id: 'variant:accent', reference: 'accent', condition: 'default', atPose: true, value: 'red', property: 'border-top-color' });
  assert.deepEqual(color.left, { state: 'skin', layer: 0, id: 'skin:block_type:text', reference: null, condition: 'default', atPose: true, value: 'var(--accent)', property: 'border-left-color' });
  assert.equal(color.right.state, 'computed');
});

test('sideCommit writes border-top-width and expands an own border-width shorthand in the border spelling', () => {
  assert.deepEqual(sideCommit({ 'border-top-width': '1px' }, ['top'], '3px', 'border-width'), { 'border-top-width': '3px' });
  assert.deepEqual(sideCommit({ 'border-width': '1px 2px' }, ['top'], '3px', 'border-width'), {
    'border-width': null,
    'border-top-width': '3px',
    'border-right-width': '2px',
    'border-bottom-width': '1px',
    'border-left-width': '2px',
  });
  assert.deepEqual(sidesPatch({ 'border-width': '1px' }, { top: '3px', bottom: null }, 'border-width'), {
    'border-width': null,
    'border-top-width': '3px',
    'border-right-width': '1px',
    'border-bottom-width': null,
    'border-left-width': '1px',
  });
  // The inverse collapses only its own family's longhands.
  assert.deepEqual(uniformCommit({ 'border-top-width': '3px', 'border-top-color': 'red' }, '2px', 'border-width'), {
    'border-width': '2px', 'border-top-width': null,
  });
});

// --- sidesOrigin (the family row's one mark over its sides) -----------------

const ELEMENT_HUE = layerColor('element');

test('sidesOrigin: an own shorthand at the pose is one group, set here, in the element hue', () => {
  const origin = sidesOrigin(stacksOf({ own: { padding: '1rem' } }), 'padding', SIDES, 'default', CONDITIONS);
  assert.deepEqual([origin.delivers, origin.atPose, origin.layer, origin.color, origin.condition], [true, true, 'element', ELEMENT_HUE, 'default']);
  assert.equal(origin.groups.length, 1);
  assert.deepEqual(origin.groups[0].sides, SIDES);
  assert.equal(origin.groups[0].state, 'authored');
  assert.equal(origin.supplier.id, 'element', 'the stack layer object rides along for the sentence');
});

test('sidesOrigin: an own Base shorthand viewed at Medium is one group, hollow (from Base) in the element hue', () => {
  const origin = sidesOrigin(stacksOf({ own: { padding: '1rem' } }), 'padding', SIDES, 'medium', CONDITIONS);
  assert.deepEqual([origin.delivers, origin.atPose, origin.color, origin.condition], [true, false, ELEMENT_HUE, 'default']);
  assert.equal(origin.groups.length, 1);
  assert.equal(origin.groups[0].atPose, false);
});

test('sidesOrigin: a Medium longhand over a Base shorthand splits the set, hollow in the topmost hue, groups topmost first', () => {
  const stacks = stacksOf({ own: { default: { padding: '1rem' }, medium: { 'padding-top': '2rem' } } });
  const origin = sidesOrigin(stacks, 'padding', SIDES, 'medium', CONDITIONS);
  assert.deepEqual([origin.delivers, origin.atPose, origin.color], [true, false, ELEMENT_HUE]);
  assert.equal(origin.groups.length, 2, 'same layer, two pose relations: two groups');
  assert.deepEqual(origin.groups.map((group) => [group.sides, group.atPose, group.condition]), [
    [['top'], true, 'medium'],
    [['right', 'bottom', 'left'], false, 'default'],
  ]);
});

test('sidesOrigin: an own side over a variant\'s shorthand is a split led by the element, the variant beneath', () => {
  const stacks = stacksOf({ variants: [{ id: 'stuff', label: 'Stuff', styles: { padding: '1rem' } }], own: { 'padding-top': '2rem' } });
  const origin = sidesOrigin(stacks, 'padding', SIDES, 'default', CONDITIONS);
  assert.deepEqual([origin.atPose, origin.layer, origin.color], [false, 'element', ELEMENT_HUE]);
  assert.deepEqual(origin.groups.map((group) => [group.id, group.sides, group.state]), [
    ['element', ['top'], 'authored'],
    ['variant:stuff', ['right', 'bottom', 'left'], 'variant'],
  ]);
  assert.equal(origin.groups[1].supplier.label, 'Stuff');
  assert.equal(origin.groups[1].reference, 'stuff');
});

test('sidesOrigin: a wholly variant-supplied ring at its own condition is one group, set at the pose, in the variant hue', () => {
  const stacks = stacksOf({ variants: [{ id: 'stuff', styles: { padding: '1rem 2rem' } }] });
  const origin = sidesOrigin(stacks, 'padding', SIDES, 'default', CONDITIONS);
  assert.deepEqual([origin.delivers, origin.atPose, origin.layer, origin.tier, origin.reference, origin.color], [true, true, 'variant:stuff', 'variant', 'stuff', layerColor('variant:stuff')]);
  assert.equal(origin.groups.length, 1, 'two values, one supplier at one pose relation: one group');
});

test('sidesOrigin judges only the offered sides', () => {
  const stacks = stacksOf({ own: { default: { padding: '1rem' }, medium: { 'padding-left': '2rem' } } });
  const vertical = sidesOrigin(stacks, 'padding', ['top', 'bottom'], 'medium', CONDITIONS);
  assert.equal(vertical.groups.length, 1, 'the split on the left is outside the offer');
  assert.deepEqual(vertical.groups[0].sides, ['top', 'bottom']);
});

test('sidesOrigin: no set side but the family authored at another condition lends the author\'s hue, hollow, naming its conditions', () => {
  const stacks = stacksOf({ own: { medium: { 'padding-top': '2rem' }, dark: { padding: '1rem' } } });
  const origin = sidesOrigin(stacks, 'padding', SIDES, 'default', CONDITIONS);
  assert.deepEqual([origin.delivers, origin.atPose, origin.layer, origin.color, origin.condition, origin.groups], [false, false, 'element', ELEMENT_HUE, null, []]);
  assert.deepEqual(origin.named, ['medium', 'dark'], 'the guide\'s condition order');
  assert.equal(origin.supplier.tier, 'element');
  const worn = sidesOrigin(stacksOf({ variants: [{ id: 'shy', label: 'Shy', styles: { narrow: { padding: '0' } } }] }), 'padding', SIDES, 'default', CONDITIONS);
  assert.deepEqual([worn.delivers, worn.layer, worn.color, worn.named, worn.supplier.label], [false, 'variant:shy', layerColor('variant:shy'), ['narrow'], 'Shy']);
});

test('sidesOrigin: the topmost author wins over a lower one, and a Medium-only own longhand beats a variant\'s Base outside the offer', () => {
  const stacks = stacksOf({ variants: [{ id: 'stuff', styles: { narrow: { padding: '1rem' } } }], own: { medium: { 'padding-top': '2rem' } } });
  const origin = sidesOrigin(stacks, 'padding', SIDES, 'default', CONDITIONS);
  assert.deepEqual([origin.layer, origin.named], ['element', ['medium']]);
  const offered = sidesOrigin(stacks, 'padding', ['left'], 'default', CONDITIONS);
  assert.deepEqual([offered.layer, offered.named], ['variant:stuff', ['narrow']], 'the own longhand does not touch the left');
});

test('sidesOrigin: nothing authored anywhere is hueless, no supplier', () => {
  const origin = sidesOrigin(stacksOf(), 'padding', SIDES, 'default', CONDITIONS);
  assert.deepEqual(origin, { delivers: false, atPose: false, layer: null, tier: null, reference: null, color: null, condition: null, supplier: null, groups: [], named: [] });
  assert.equal(sidesOrigin(null).color, null, 'no stacks at all');
});

let failures = 0;
for (const [name, run] of tests) {
  try {
    run();
    console.log(`ok - ${name}`);
  } catch (error) {
    failures += 1;
    console.error(`not ok - ${name}`);
    console.error(error.message);
  }
}
if (failures) {
  console.error(`${failures} of ${tests.length} box-model tests failed.`);
  process.exit(1);
}
console.log(`${tests.length} box-model tests passed.`);
