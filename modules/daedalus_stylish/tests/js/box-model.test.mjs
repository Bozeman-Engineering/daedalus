// cspell:ignore paddington
/**
 * Self-asserting tests for the box-model value logic behind the spacing
 * diagram: shorthand expansion (the CSS 1/2/3/4 rule, the server's
 * structural pass-throughs honored), per-side effective resolution
 * (last-declaration-wins within a layer), four-state provenance
 * (authored / variant with its supplying reference / skin / computed),
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
  sideCommit,
  sideLonghands,
  sideProvenance,
  sideScrubMove,
  sideScrubSeed,
  sideStepValue,
  sharesGrain,
  sidesPatch,
  spreadSides,
  uniformCommit,
  uniformState,
} from '../../js/src/widgets/lib/box-model.js';
import { SCRUB_BAND, valueSpaceFor } from '../../js/src/value-space.js';

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

test('expandShorthand refuses what it cannot split honestly', () => {
  // The server's structural pass-throughs (case-sensitive, mirroring
  // PropertyGroupBase): one opaque value, spaces and all.
  assert.equal(expandShorthand('calc(100% - 2rem)'), null);
  assert.equal(expandShorthand('var(--space-m)'), null);
  assert.equal(expandShorthand('1rem var(--space-m)'), null);
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
  // A later longhand still overrides its side.
  assert.deepEqual(
    resolveSides({ padding: 'var(--s)', 'padding-top': '1rem' }).top,
    { value: '1rem', property: 'padding-top' },
  );
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

// --- sideProvenance -------------------------------------------------------

test('sideProvenance is computed when no layer styles a side', () => {
  const states = sideProvenance(null, null);
  SIDES.forEach((side) => {
    assert.deepEqual(states[side], { state: 'computed', reference: null, condition: null, value: null, property: null }, side);
  });
});

test('sideProvenance splits authored from variant-supplied per side', () => {
  // Variant supplies the shorthand; the element authored one override.
  const own = { 'padding-top': '2rem' };
  const effective = { padding: '1rem', 'padding-top': '2rem' };
  const states = sideProvenance(own, effective);
  assert.deepEqual(states.top, { state: 'authored', reference: null, condition: 'default', value: '2rem', property: 'padding-top' });
  assert.deepEqual(states.right, { state: 'variant', reference: null, condition: 'default', value: '1rem', property: 'padding' });
  assert.deepEqual(states.bottom, { state: 'variant', reference: null, condition: 'default', value: '1rem', property: 'padding' });
  assert.deepEqual(states.left, { state: 'variant', reference: null, condition: 'default', value: '1rem', property: 'padding' });
});

test('sideProvenance lets an own shorthand beat variant longhands', () => {
  // The own entry's declarations land after the variant's in the
  // cascade, so an own `padding` resets a variant's `padding-top`
  // exactly as CSS would — every side reads authored.
  const own = { padding: '1rem' };
  const effective = { 'padding-top': '3rem', padding: '1rem' };
  const states = sideProvenance(own, effective);
  SIDES.forEach((side) => {
    assert.equal(states[side].state, 'authored', side);
    assert.equal(states[side].value, '1rem', side);
  });
});

test('sideProvenance reports a wholly variant-supplied ring', () => {
  const states = sideProvenance({}, { padding: '1rem 2rem' });
  assert.deepEqual(states.top, { state: 'variant', reference: null, condition: 'default', value: '1rem', property: 'padding' });
  assert.deepEqual(states.left, { state: 'variant', reference: null, condition: 'default', value: '2rem', property: 'padding' });
});

test('sideProvenance keeps unsplittable authored shorthand honest', () => {
  const states = sideProvenance({ padding: 'var(--s)' }, { padding: 'var(--s)' });
  SIDES.forEach((side) => {
    assert.deepEqual(states[side], { state: 'authored', reference: null, condition: 'default', value: null, property: 'padding' }, side);
  });
});

test('sideProvenance names the supplying reference per resolving declaration', () => {
  // Two worn variants: `stuff` supplies the shorthand, `big` a top
  // longhand on top of it (the suppliers table names the delivery
  // winner per property); the element authors the left side. Each
  // side's reference is the supplier of the DECLARATION that resolved
  // it, so top wears big's hue and right/bottom stuff's.
  const own = { 'padding-left': '4px' };
  const effective = { padding: '1rem', 'padding-top': '2rem', 'padding-left': '4px' };
  const suppliers = { default: { padding: 'stuff', 'padding-top': 'big' }, medium: { padding: 'other' } };
  const states = sideProvenance(own, effective, 'padding', { suppliers, condition: 'default' });
  assert.deepEqual(states.top, { state: 'variant', reference: 'big', condition: 'default', value: '2rem', property: 'padding-top' });
  assert.deepEqual(states.right, { state: 'variant', reference: 'stuff', condition: 'default', value: '1rem', property: 'padding' });
  assert.deepEqual(states.bottom, { state: 'variant', reference: 'stuff', condition: 'default', value: '1rem', property: 'padding' });
  assert.deepEqual(states.left, { state: 'authored', reference: null, condition: 'default', value: '4px', property: 'padding-left' });
});

test('sideProvenance reads the supplier at the editing condition only', () => {
  // The diagram edits one compound; a variant attributed only at Base
  // says nothing about a Medium read (the effective input is already
  // the pose's resolution).
  const suppliers = { default: { padding: 'stuff' } };
  const states = sideProvenance(null, { padding: '1rem' }, 'padding', { suppliers, condition: 'medium' });
  assert.deepEqual(states.top, { state: 'variant', reference: null, condition: 'medium', value: '1rem', property: 'padding' });
});

const CONDITIONS = {
  medium: { label: 'Medium', axis: 'width' },
  narrow: { label: 'Narrow', axis: 'width' },
  dark: { label: 'Dark', axis: 'scheme' },
};

test('sideProvenance classifies a skin default as the weakest layer, read at the pose through the cascade', () => {
  const skinEntry = { default: { padding: '1rem 2rem' }, medium: { 'padding-top': '3rem' } };
  const states = sideProvenance(null, null, 'padding', { skinEntry, condition: 'default', conditions: CONDITIONS });
  assert.deepEqual(states.top, { state: 'skin', reference: null, condition: 'default', value: '1rem', property: 'padding' });
  assert.deepEqual(states.left, { state: 'skin', reference: null, condition: 'default', value: '2rem', property: 'padding' });
  // Posed at Medium the skin's Base shorthand still delivers (the
  // canvas paints it there); Medium's longhand overrides top alone, so
  // top is the skin's from Medium and the other sides from Base.
  const medium = sideProvenance(null, null, 'padding', { skinEntry, condition: 'medium', conditions: CONDITIONS });
  assert.deepEqual(medium.top, { state: 'skin', reference: null, condition: 'medium', value: '3rem', property: 'padding-top' });
  assert.deepEqual(medium.left, { state: 'skin', reference: null, condition: 'default', value: '2rem', property: 'padding' });
  assert.deepEqual(medium.right, { state: 'skin', reference: null, condition: 'default', value: '2rem', property: 'padding' });
  assert.deepEqual(medium.bottom, { state: 'skin', reference: null, condition: 'default', value: '1rem', property: 'padding' });
  // Posed at Narrow, Medium's block is not applicable: every side from Base.
  const narrow = sideProvenance(null, null, 'padding', { skinEntry, condition: 'narrow', conditions: CONDITIONS });
  assert.deepEqual(narrow.top, { state: 'skin', reference: null, condition: 'default', value: '1rem', property: 'padding' });
  // A later block's shorthand resets an earlier longhand, as the
  // printed document does: a Medium `padding` after a Base `padding-top`.
  const reset = sideProvenance(null, null, 'padding', {
    skinEntry: { default: { 'padding-top': '9rem' }, medium: { padding: '1rem' } },
    condition: 'medium',
    conditions: CONDITIONS,
  });
  assert.deepEqual(reset.top, { state: 'skin', reference: null, condition: 'medium', value: '1rem', property: 'padding' });
  // Own and variant values both beat the skin.
  const beaten = sideProvenance({ 'padding-top': '5px' }, { padding: '1px', 'padding-top': '5px' }, 'padding', {
    suppliers: { default: { padding: 'stuff' } },
    skinEntry,
    condition: 'default',
    conditions: CONDITIONS,
  });
  assert.equal(beaten.top.state, 'authored');
  assert.deepEqual(beaten.left, { state: 'variant', reference: 'stuff', condition: 'default', value: '1px', property: 'padding' });
  // An unsplittable skin shorthand is set-but-opaque, like the other layers.
  const opaque = sideProvenance(null, null, 'padding', { skinEntry: { default: { padding: 'var(--s)' } } });
  assert.deepEqual(opaque.top, { state: 'skin', reference: null, condition: 'default', value: null, property: 'padding' });
});

test('sideProvenance classifies the declaration\'s own default beneath the skin\'s', () => {
  const declaredEntry = { default: { padding: '4px 8px' } };
  // Declared alone: every side the declaration's, from Base at any pose.
  const alone = sideProvenance(null, null, 'padding', { declaredEntry, condition: 'medium', conditions: CONDITIONS });
  assert.deepEqual(alone.top, { state: 'declared', reference: null, condition: 'default', value: '4px', property: 'padding' });
  assert.deepEqual(alone.left, { state: 'declared', reference: null, condition: 'default', value: '8px', property: 'padding' });
  // The skin's delivering block beats the declaration's for the sides it
  // resolves, whatever the conditions (the declared tier prints first);
  // the declaration supplies the rest.
  const both = sideProvenance(null, null, 'padding', {
    skinEntry: { medium: { 'padding-top': '3rem' } },
    declaredEntry,
    condition: 'medium',
    conditions: CONDITIONS,
  });
  assert.deepEqual(both.top, { state: 'skin', reference: null, condition: 'medium', value: '3rem', property: 'padding-top' });
  assert.deepEqual(both.left, { state: 'declared', reference: null, condition: 'default', value: '8px', property: 'padding' });
  // A declared block at an unrelated condition reaches nothing.
  const away = sideProvenance(null, null, 'padding', { declaredEntry: { narrow: { padding: '0' } }, condition: 'medium', conditions: CONDITIONS });
  assert.equal(away.top.state, 'computed');
});

test('uniformState never counts a skin default as set', () => {
  // The field's face is what the element and its variants SET; the skin
  // is a ghost. uniformState takes no tables, so a skin-only ring reads
  // unset however the diagram classifies it.
  assert.deepEqual(uniformState(null, null), { set: false, uniform: null, text: null });
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
  // One var(--name) addresses all four sides identically (uniformState's
  // reading), so a guide-scale pick or a skin color never blocks the
  // ring: the shorthand unsets and the unedited sides keep the reference.
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
  assert.deepEqual(uniformState(null, null), { set: false, uniform: null, text: null });
  assert.deepEqual(uniformState({}, {}), { set: false, uniform: null, text: null });
  assert.deepEqual(uniformState({ color: 'red' }, { color: 'red' }), { set: false, uniform: null, text: null });
});

test('uniformState finds the uniform value whatever the spelling', () => {
  const shorthand = { padding: '1rem' };
  assert.deepEqual(uniformState(shorthand, shorthand), { set: true, uniform: '1rem', text: '1rem' });
  const longhands = {
    'padding-top': '1rem', 'padding-right': '1rem', 'padding-bottom': '1rem', 'padding-left': '1rem',
  };
  assert.deepEqual(uniformState(longhands, longhands), { set: true, uniform: '1rem', text: '1rem' },
    'four equal longhands light the chip a shorthand would');
  const variantOnly = uniformState(null, { padding: '.5rem' });
  assert.deepEqual(variantOnly, { set: true, uniform: '.5rem', text: '.5rem' },
    'a variant-supplied shorthand reads uniform in its own spelling');
});

test('uniformState reads per-side values as explicit custom', () => {
  const mixed = { padding: '1rem', 'padding-top': '2rem' };
  assert.deepEqual(uniformState(mixed, mixed), { set: true, uniform: null, text: '2rem 1rem 1rem' },
    'differing sides serialize as the minimal shorthand');
  const partial = { 'padding-top': '2rem' };
  assert.deepEqual(uniformState(partial, partial), { set: true, uniform: null, text: 'top 2rem' },
    'partly-set sides list themselves — no shorthand can honestly claim unset sides');
  const axis = {
    'padding-top': '1rem', 'padding-right': '2rem', 'padding-bottom': '1rem', 'padding-left': '2rem',
  };
  assert.deepEqual(uniformState(axis, axis), { set: true, uniform: null, text: '1rem 2rem' },
    'the minimal-shorthand serialization runs the 1/2/3/4 rule backwards');
});

test('uniformState reads an unsplittable shorthand as opaque custom', () => {
  const opaque = { padding: 'calc(1rem + 2px)' };
  assert.deepEqual(uniformState(opaque, opaque), { set: true, uniform: null, text: null });
});

test('uniformState surfaces a whole-shorthand token reference as the uniform value', () => {
  // One var(--name) reference addresses all four sides identically, so its
  // spelling IS the box's single value: the chips light a scale token pick
  // by reference-spelling matching instead of degrading to custom.
  const reference = { padding: 'var(--space-4)' };
  assert.deepEqual(uniformState(reference, reference),
    { set: true, uniform: 'var(--space-4)', text: 'var(--space-4)' });
  assert.deepEqual(uniformState(null, { padding: 'var(--space-4)' }),
    { set: true, uniform: 'var(--space-4)', text: 'var(--space-4)' },
    'a variant-supplied reference shorthand reads uniform too');
  // Multi-component var() shorthands stay opaque: no one spelling honestly
  // represents sides that differ per component.
  const multi = { padding: 'var(--space-2) var(--space-4)' };
  assert.deepEqual(uniformState(multi, multi), { set: true, uniform: null, text: null });
  // A later own longhand shadows its side out of the reference shorthand,
  // so no single spelling covers the box any more.
  const mixed = { padding: 'var(--space-4)', 'padding-top': '1rem' };
  assert.deepEqual(uniformState(mixed, mixed), { set: true, uniform: null, text: null });
});

test('uniformState lets the own layer win whole-side cross-spelling', () => {
  // Own shorthand over a variant longhand: the own declaration lands after
  // the variant's in the cascade and resets all four sides.
  const own = { padding: '1rem' };
  const effective = { 'padding-top': '2rem', padding: '1rem' };
  assert.deepEqual(uniformState(own, effective), { set: true, uniform: '1rem', text: '1rem' });
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
  assert.deepEqual(diagramModifiers({}), { shiftKey: false });
  assert.deepEqual(diagramModifiers({ shiftKey: true }), { shiftKey: true });
  assert.deepEqual(diagramModifiers({ shiftKey: true, altKey: true }), { shiftKey: false });
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
  assert.deepEqual(uniformState({ 'border-color': 'rgb(0 0 0 / 50%)' }, null, 'border-color'), {
    set: true, uniform: 'rgb(0 0 0 / 50%)', text: 'rgb(0 0 0 / 50%)',
  });
});

test('sideProvenance classifies the three border families independently over one layer set', () => {
  // The blockquote posture skins ship: the skin strokes the left edge
  // alone (width, style, color), the element authors one width for
  // every side, a worn variant colors the top.
  const own = { 'border-width': '1px 2px' };
  const effective = { 'border-top-color': 'red', 'border-width': '1px 2px' };
  const skinEntry = { default: { 'border-left-width': '4px', 'border-left-style': 'solid', 'border-left-color': 'var(--accent)' } };
  const tables = { suppliers: { default: { 'border-top-color': 'accent' } }, skinEntry, condition: 'default' };
  const width = sideProvenance(own, effective, 'border-width', tables);
  assert.deepEqual(width.top, { state: 'authored', reference: null, condition: 'default', value: '1px', property: 'border-width' });
  assert.deepEqual(width.left, { state: 'authored', reference: null, condition: 'default', value: '2px', property: 'border-width' }, 'the own shorthand beats the skin\'s left width');
  const style = sideProvenance(own, effective, 'border-style', tables);
  assert.deepEqual(style.left, { state: 'skin', reference: null, condition: 'default', value: 'solid', property: 'border-left-style' });
  assert.equal(style.top.state, 'computed', 'a width says nothing about a style');
  const color = sideProvenance(own, effective, 'border-color', tables);
  assert.deepEqual(color.top, { state: 'variant', reference: 'accent', condition: 'default', value: 'red', property: 'border-top-color' });
  assert.deepEqual(color.left, { state: 'skin', reference: null, condition: 'default', value: 'var(--accent)', property: 'border-left-color' });
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
