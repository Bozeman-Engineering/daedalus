/**
 * Self-asserting tests for the stroke model behind the stroke row and
 * the border ring: a stroke's lit state three properties wide
 * (strokeState, the honesty rule over width, style and color), the
 * completion rule (strokeWrite: a part set on an unset stroke lands a
 * whole stroke, an unset clears the whole stroke), the four border
 * sides read across the three families (strokeSides, the per-side read
 * of the style stack), the two patch shapes that keep the padding
 * invariant three families wide (strokeSidesPatch's expansion and
 * collapse, strokeUniformPatch's shorthand write over own longhands),
 * and the Unset gate's spellings (strokeUnsetProperties).
 *
 * Run directly: `node tests/js/stroke-model.test.mjs`. Exits non-zero on
 * failure.
 */
import { strict as assert } from 'node:assert';
import { EMPTY_MATERIALS, materialsFrom } from './materials-fixture.mjs';
import { FALLBACKS, propertyStops, stopsFor } from '../../js/src/widget-values.js';
import {
  STROKES,
  STROKE_PARTS,
  strokeParts,
  strokeSideLonghands,
  strokeSideValues,
  strokeSides,
  strokeSidesPatch,
  strokeState,
  strokeUniformPatch,
  strokeUnsetProperties,
  strokeWrite,
} from '../../js/src/widgets/lib/stroke-model.js';
import { stackOf, stacksOf } from './stack-fixture.mjs';

const tests = [];
const test = (name, run) => tests.push([name, run]);

const WIDTHS = FALLBACKS['border-width'];
const STYLES = FALLBACKS['border-style'];

// A site minting one color token: the color well's whole vocabulary.
const guide = {
  tokenScales: { color: { label: 'Color', shape: 'color', prefix: 'color' } },
  tokens: { color: { accent: '#b91c1c' } },
};
const materials = materialsFrom(guide);
const COLORS = propertyStops(guide, materials, 'border-color', { type: 'color' }, null);
const ACCENT = 'var(--color-accent)';

// The built-in vocabulary the border row reads on an unconstrained site.
const STOPS = { width: WIDTHS, style: STYLES, color: COLORS };
const CONDITIONS = { medium: { label: 'Medium', axis: 'width' } };
const BORDER = STROKES.border;

test('strokeParts spells the shorthand strokes and a border side through the box-model naming seam', () => {
  assert.deepEqual(STROKE_PARTS, ['width', 'style', 'color']);
  assert.deepEqual(strokeParts('border'), { width: 'border-width', style: 'border-style', color: 'border-color' });
  assert.deepEqual(strokeParts('outline'), { width: 'outline-width', style: 'outline-style', color: 'outline-color' });
  assert.deepEqual(strokeParts('border', 'top'), { width: 'border-top-width', style: 'border-top-style', color: 'border-top-color' });
  assert.deepEqual(strokeParts('border', 'left'), { width: 'border-left-width', style: 'border-left-style', color: 'border-left-color' });
  assert.throws(() => strokeParts('outline', 'top'), /has no sides/);
  assert.throws(() => strokeParts('text-decoration'), /Unknown stroke/);
});

test('strokeState: a preset lights only for a fully representable stroke', () => {
  WIDTHS.forEach((stop) => {
    const state = strokeState({ 'border-width': stop.value, 'border-style': 'solid' }, BORDER, STOPS);
    assert.equal(state.selected, stop.id, `${stop.id} does not re-read its own pair`);
    assert.deepEqual([state.set, state.custom, state.value], [true, false, null]);
  });
  // Spelling is normalized; any listed style is representable, so is a
  // color on the palette, and an unset color is currentColor.
  assert.equal(strokeState({ 'border-width': ' 1PX ', 'border-style': 'Solid' }, BORDER, STOPS).selected, 'thin');
  assert.equal(strokeState({ 'border-width': '2px', 'border-style': 'dashed' }, BORDER, STOPS).selected, 'medium');
  const coloured = strokeState({ 'border-width': '2px', 'border-style': 'dashed', 'border-color': ACCENT }, BORDER, STOPS);
  assert.equal(coloured.selected, 'medium');
  assert.deepEqual(coloured.parts.color, { value: ACCENT, selected: ACCENT, custom: false });
  assert.deepEqual(coloured.parts.style, { value: 'dashed', selected: 'dashed', custom: false });
  // The outline stroke reads the same rule over its own parts.
  const outline = strokeState({ 'outline-width': '4px', 'outline-style': 'dotted' }, STROKES.outline, STOPS);
  assert.deepEqual([outline.selected, outline.custom], ['thick', false]);
});

test('strokeState: unset is honestly nothing, a half-set stroke is custom', () => {
  const unset = strokeState({}, BORDER, STOPS);
  assert.deepEqual([unset.set, unset.selected, unset.custom, unset.value], [false, null, false, null]);
  assert.deepEqual(unset.parts.width, { value: null, selected: null, custom: false });
  assert.equal(strokeState({ 'border-width': '', 'border-color': null }, BORDER, STOPS).set, false);
  // A width without a style paints nothing (initial border-style is
  // none); a bare style paints at the UA's medium width; a bare color
  // paints nothing. Real committed states no preset claims: custom,
  // never "nothing set".
  assert.deepEqual(
    strokeState({ 'border-width': '1px' }, BORDER, STOPS),
    {
      set: true, selected: null, custom: true, value: '1px',
      parts: {
        width: { value: '1px', selected: 'thin', custom: false },
        style: { value: null, selected: null, custom: false },
        color: { value: null, selected: null, custom: false },
      },
    },
    'the representable part still lights its own face',
  );
  assert.deepEqual(strokeState({ 'border-style': 'solid' }, BORDER, STOPS).value, 'solid');
  assert.deepEqual(strokeState({ 'border-color': ACCENT }, BORDER, STOPS).value, ACCENT);
  // A zero width without the solid style is still a half-set stroke.
  assert.deepEqual(strokeState({ 'border-width': '0' }, BORDER, STOPS).custom, true);
});

test('strokeState: an off-scale width, an unlisted style or a literal color is the custom face', () => {
  const dashed3 = strokeState({ 'border-width': '3px', 'border-style': 'dashed' }, BORDER, STOPS);
  assert.deepEqual([dashed3.selected, dashed3.custom, dashed3.value], [null, true, '3px dashed']);
  assert.deepEqual(dashed3.parts.style, { value: 'dashed', selected: 'dashed', custom: false });
  const groove = strokeState({ 'border-width': '1px', 'border-style': 'groove' }, BORDER, STOPS);
  assert.deepEqual([groove.selected, groove.custom, groove.value], [null, true, '1px groove']);
  assert.deepEqual(groove.parts.style, { value: 'groove', selected: null, custom: true });
  const literal = strokeState({ 'border-width': '1px', 'border-style': 'solid', 'border-color': '#f00' }, BORDER, STOPS);
  assert.deepEqual([literal.selected, literal.custom, literal.value], [null, true, '1px solid #f00']);
  assert.deepEqual(literal.parts.color, { value: '#f00', selected: null, custom: true });
  // The UA-defined width keywords may not light a px stop (the
  // line-height 'normal' precedent).
  assert.deepEqual(strokeState({ 'border-width': 'thin', 'border-style': 'solid' }, BORDER, STOPS).value, 'thin solid');
  // A missing stop row is the empty vocabulary: any set part is custom.
  assert.equal(strokeState({ 'border-width': '1px', 'border-style': 'solid' }, BORDER, { width: WIDTHS }).custom, true);
});

test('strokeState: a guide width scale becomes the preset ramp (stopsFor)', () => {
  const width = stopsFor(null, EMPTY_MATERIALS, { allowed: ['1px', '0.5rem', '0'] }, WIDTHS);
  const stops = { ...STOPS, width };
  // A twin adopts the built-in id/label; unknown widths label as
  // themselves, and the stroke mapping follows the derived row.
  assert.equal(width[0].id, 'thin');
  assert.equal(width[1].label, '0.5rem');
  assert.equal(strokeState({ 'border-width': '0.5rem', 'border-style': 'solid' }, BORDER, stops).selected, '0.5rem');
  assert.deepEqual(strokeState({ 'border-width': '4px', 'border-style': 'solid' }, BORDER, stops).value, '4px solid');
  // The guide's `allowed: ['0']` twins the built-in None: it faces
  // "None", never "0", the box-shadow precedent.
  assert.equal(width[2].label, 'None');
  assert.equal(strokeState({ 'border-width': '0', 'border-style': 'solid' }, BORDER, stops).selected, 'none');
});

test('strokeWrite: a part set on an unset stroke completes it', () => {
  // A width lands solid beside it; a style lands the first width; a
  // color lands both; the color is never completed (currentColor).
  assert.deepEqual(strokeWrite({ 'border-width': '2px' }, {}, BORDER, STOPS), { 'border-width': '2px', 'border-style': 'solid' });
  assert.deepEqual(strokeWrite({ 'border-style': 'dashed' }, {}, BORDER, STOPS), { 'border-style': 'dashed', 'border-width': '1px' });
  assert.deepEqual(
    strokeWrite({ 'border-color': ACCENT }, {}, BORDER, STOPS),
    { 'border-color': ACCENT, 'border-style': 'solid', 'border-width': '1px' },
  );
  // The completing width is the ramp's FIRST stop, whichever ramp the
  // guide hands the row; with no width offered the width stays unset.
  const scaled = { ...STOPS, width: stopsFor(null, EMPTY_MATERIALS, { allowed: ['0.5rem', '1px'] }, WIDTHS) };
  assert.deepEqual(strokeWrite({ 'border-color': ACCENT }, {}, BORDER, scaled), { 'border-color': ACCENT, 'border-style': 'solid', 'border-width': '0.5rem' });
  assert.deepEqual(strokeWrite({ 'border-color': ACCENT }, {}, BORDER, { ...STOPS, width: [] }), { 'border-color': ACCENT, 'border-style': 'solid' });
  // The outline stroke completes over its own parts.
  assert.deepEqual(strokeWrite({ 'outline-width': '2px' }, {}, STROKES.outline, STOPS), { 'outline-width': '2px', 'outline-style': 'solid' });
  // A per-side stroke (the ring's editor) completes its side's longhands.
  const top = strokeParts('border', 'top');
  assert.deepEqual(strokeWrite({ 'border-top-color': ACCENT }, {}, top, STOPS), { 'border-top-color': ACCENT, 'border-top-style': 'solid', 'border-top-width': '1px' });
});

test('strokeWrite: a stroke already carrying a part keeps it, and only what is missing is completed', () => {
  // A pick over a custom stroke replaces the part it names, nothing more.
  assert.deepEqual(
    strokeWrite({ 'border-width': 'var(--stroke-thin)' }, { 'border-width': '2px', 'border-style': 'dashed', 'border-color': '#f00' }, BORDER, STOPS),
    { 'border-width': 'var(--stroke-thin)' },
  );
  assert.deepEqual(strokeWrite({ 'border-color': ACCENT }, { 'border-width': '2px', 'border-style': 'dashed' }, BORDER, STOPS), { 'border-color': ACCENT });
  // A half-set stroke (an AI or code-view write) is completed by the
  // next part set on it, so the pick paints.
  assert.deepEqual(strokeWrite({ 'border-color': ACCENT }, { 'border-width': '2px' }, BORDER, STOPS), { 'border-color': ACCENT, 'border-style': 'solid' });
  assert.deepEqual(strokeWrite({ 'border-color': ACCENT }, { 'border-style': 'dotted' }, BORDER, STOPS), { 'border-color': ACCENT, 'border-width': '1px' });
  // A whole-stroke write is already the gesture.
  const whole = { 'border-width': '1px', 'border-style': 'double', 'border-color': ACCENT };
  assert.deepEqual(strokeWrite(whole, {}, BORDER, STOPS), whole);
});

test('strokeWrite: an unset clears the whole stroke, retiring only what it has set', () => {
  assert.deepEqual(
    strokeWrite({ 'border-width': null }, { 'border-width': '1px', 'border-style': 'solid', 'border-color': ACCENT }, BORDER, STOPS),
    { 'border-width': null, 'border-style': null, 'border-color': null },
    'a whole stroke has three',
  );
  assert.deepEqual(
    strokeWrite({ 'border-color': null }, { 'border-width': '1px', 'border-style': 'solid' }, BORDER, STOPS),
    { 'border-width': null, 'border-style': null },
    'a cleared color is a cleared stroke, never a part',
  );
  assert.deepEqual(strokeWrite({ 'border-width': null }, { 'border-width': '1px' }, BORDER, STOPS), { 'border-width': null }, 'a half-set stroke carries one');
  assert.deepEqual(strokeWrite({ 'border-width': '' }, { 'border-style': 'dashed' }, BORDER, STOPS), { 'border-style': null }, 'an emptied field is an unset too');
  assert.deepEqual(strokeWrite({ 'border-width': null }, {}, BORDER, STOPS), {}, 'nothing set, nothing to retire');
});

test('strokeWrite: properties outside the stroke pass through beside it', () => {
  const radius = { 'border-radius': '0.5rem' };
  assert.equal(strokeWrite(radius, { 'border-width': '1px' }, BORDER, STOPS), radius);
  assert.deepEqual(
    strokeWrite({ 'border-radius': '0.5rem', 'border-width': '2px' }, {}, BORDER, STOPS),
    { 'border-radius': '0.5rem', 'border-width': '2px', 'border-style': 'solid' },
  );
  assert.deepEqual(
    strokeWrite({ 'outline-offset': '2px', 'border-width': null }, { 'border-width': '1px' }, BORDER, STOPS),
    { 'outline-offset': '2px', 'border-width': null },
  );
  // Outline parts are outside the BORDER stroke, and vice versa.
  const outline = { 'outline-width': '2px' };
  assert.equal(strokeWrite(outline, {}, BORDER, STOPS), outline);
});

test('strokeSides: each side carries a provenance per family, read once per family and merged', () => {
  // The blockquote posture skins ship: the skin strokes the left edge
  // alone, the element authors one width for every side, a worn variant
  // colors the top.
  const stack = stacksOf({
    skin: { 'border-left-width': '4px', 'border-left-style': 'solid', 'border-left-color': ACCENT },
    variants: [{ id: 'accent', styles: { 'border-top-color': 'red' } }],
    own: { 'border-width': '1px 2px' },
  });
  const { sides } = strokeSides(stack);
  assert.deepEqual(Object.keys(sides), ['top', 'right', 'bottom', 'left']);
  assert.deepEqual(Object.keys(sides.top), ['width', 'style', 'color']);
  assert.deepEqual(sides.top.width, { state: 'authored', layer: 2, id: 'element', reference: null, condition: 'default', atPose: true, value: '1px', property: 'border-width' });
  assert.deepEqual(sides.left.width, { state: 'authored', layer: 2, id: 'element', reference: null, condition: 'default', atPose: true, value: '2px', property: 'border-width' });
  assert.deepEqual(sides.left.style, { state: 'skin', layer: 0, id: 'skin:block_type:text', reference: null, condition: 'default', atPose: true, value: 'solid', property: 'border-left-style' });
  assert.equal(sides.top.style.state, 'computed', 'a width says nothing about a style');
  assert.deepEqual(sides.top.color, { state: 'variant', layer: 1, id: 'variant:accent', reference: 'accent', condition: 'default', atPose: true, value: 'red', property: 'border-top-color' });
  assert.equal(sides.left.color.value, ACCENT);
  assert.equal(sides.right.color.state, 'computed');
});

test('strokeSides: uniform when every family is unset or one value on all four sides, every delivering layer counting', () => {
  const unset = strokeSides(stacksOf());
  assert.equal(unset.uniform, true);
  assert.deepEqual(unset.values, { 'border-width': null, 'border-style': null, 'border-color': null });
  assert.deepEqual(unset.families.width, { set: false, uniform: null, text: null });
  // A skin's left stroke is a lit per-side value (the display rule):
  // set, and not uniform.
  const skinned = strokeSides(stacksOf({ skin: { 'border-left-width': '4px', 'border-left-style': 'solid' } }));
  assert.equal(skinned.uniform, false);
  assert.deepEqual(skinned.families.width, { set: true, uniform: null, text: 'left 4px' });
  // A skin's whole border reads as one stroke, lit from the skin.
  const skinBorder = strokeSides(stacksOf({ skin: { 'border-width': '1px', 'border-style': 'solid', 'border-color': ACCENT } }));
  assert.equal(skinBorder.uniform, true);
  assert.deepEqual(skinBorder.values, { 'border-width': '1px', 'border-style': 'solid', 'border-color': ACCENT });
  assert.equal(skinBorder.sides.top.width.state, 'skin');
  // Shorthands, expanded longhands and a variant's supply all read as
  // one stroke when the sides agree.
  const longhands = {
    'border-top-width': '1px', 'border-right-width': '1px', 'border-bottom-width': '1px', 'border-left-width': '1px',
    'border-style': 'solid',
  };
  const worn = strokeSides(stacksOf({ variants: [{ id: 'v', styles: { 'border-color': ACCENT } }], own: longhands }));
  assert.equal(worn.uniform, true);
  assert.deepEqual(worn.values, { 'border-width': '1px', 'border-style': 'solid', 'border-color': ACCENT });
  // One family disagreeing across the sides breaks uniformity; the
  // agreeing families still read, the disagreeing one lists its sides.
  const perSide = strokeSides(stacksOf({ own: { 'border-width': '1px 2px', 'border-style': 'solid' } }));
  assert.equal(perSide.uniform, false);
  assert.deepEqual(perSide.values, { 'border-width': null, 'border-style': 'solid', 'border-color': null });
  assert.deepEqual(perSide.families.width, { set: true, uniform: null, text: '1px 2px' });
  const partial = strokeSides(stacksOf({ own: { 'border-top-color': 'red' } }));
  assert.equal(partial.uniform, false);
  assert.equal(partial.families.color.text, 'top red');
  // A one-reference shorthand is the reference on every side (the one
  // splitter, box-model.js expandShorthand), so a scale token lights the
  // row and each side's value is the reference itself.
  const token = strokeSides(stacksOf({ own: { 'border-width': 'var(--stroke-thin)' } }));
  assert.equal(token.uniform, true);
  assert.equal(token.values['border-width'], 'var(--stroke-thin)');
  // The read is posed: a Base border delivers at Medium as the own
  // layer's from Base (lit, not unsettable there), and a Medium block
  // overrides the parts it spells.
  const posed = stacksOf({ own: { default: { 'border-width': '1px', 'border-style': 'solid' }, medium: { 'border-style': 'dashed' } } });
  const medium = strokeSides(posed, 'medium', CONDITIONS);
  assert.deepEqual(medium.values, { 'border-width': '1px', 'border-style': 'dashed', 'border-color': null });
  assert.equal(medium.sides.top.width.condition, 'default');
  assert.equal(medium.sides.top.style.condition, 'medium');
});

test('strokeSidesPatch: a side edit expands every own shorthand into longhands in one patch, three families wide', () => {
  // The ring's editor writes the top stroke through the completion rule;
  // the own layer carries the three shorthands, so all twelve longhands
  // land and the three shorthands unset, one atomic op: the untouched
  // style family follows the written ones, the border is spelled one way.
  const own = { 'border-width': '1px', 'border-style': 'solid', 'border-color': '#abc' };
  const top = strokeWrite({ 'border-top-width': '4px' }, { 'border-top-width': '1px', 'border-top-style': 'solid', 'border-top-color': '#abc' }, strokeParts('border', 'top'), STOPS);
  assert.deepEqual(top, { 'border-top-width': '4px' });
  const patch = strokeSidesPatch(own, { top: { ...top, 'border-top-color': 'red' } });
  assert.deepEqual(patch, {
    'border-width': null,
    'border-top-width': '4px',
    'border-right-width': '1px',
    'border-bottom-width': '1px',
    'border-left-width': '1px',
    'border-style': null,
    'border-top-style': 'solid',
    'border-right-style': 'solid',
    'border-bottom-style': 'solid',
    'border-left-style': 'solid',
    'border-color': null,
    'border-top-color': 'red',
    'border-right-color': '#abc',
    'border-bottom-color': '#abc',
    'border-left-color': '#abc',
  }, 'the untouched style family expands with the written ones');
  // A width-only drag on the right cell (the ring's scrub) does the same:
  // three shorthands unset, twelve longhands written.
  const drag = strokeSidesPatch(own, { right: { 'border-right-width': '4px' } });
  assert.equal(Object.keys(drag).length, 15);
  assert.equal(drag['border-right-width'], '4px');
  assert.equal(drag['border-left-color'], '#abc');
  assert.equal(drag['border-style'], null);
  // No own shorthand: a plain longhand write per side, two sides at once.
  assert.deepEqual(
    strokeSidesPatch({ 'border-top-width': '1px' }, { top: { 'border-top-width': '2px' }, bottom: { 'border-bottom-width': '2px', 'border-bottom-style': 'dashed' } }),
    { 'border-top-width': '2px', 'border-bottom-width': '2px', 'border-bottom-style': 'dashed' },
  );
  // A null side value unsets that side's longhand (the Alt-click unset).
  assert.deepEqual(strokeSidesPatch({ 'border-top-width': '1px', 'border-top-style': 'solid' }, { top: { 'border-top-width': null, 'border-top-style': null } }), { 'border-top-width': null, 'border-top-style': null });
  // A token-coloured border (the most common one: a skin color, a
  // guide-scale width) expands like any other: one whole reference is
  // every side's value (sideCommit's lone-reference rule, uniformState's
  // reading), so the shorthand unsets and the unedited sides keep it.
  assert.deepEqual(strokeSidesPatch({ 'border-color': 'var(--color-accent)' }, { top: { 'border-top-color': 'red' } }), {
    'border-color': null,
    'border-top-color': 'red',
    'border-right-color': 'var(--color-accent)',
    'border-bottom-color': 'var(--color-accent)',
    'border-left-color': 'var(--color-accent)',
  });
  assert.deepEqual(
    strokeSidesPatch({ 'border-width': 'var(--stroke-thin)', 'border-style': 'solid', 'border-color': 'var(--color-accent)' }, { left: { 'border-left-width': '4px' } }),
    {
      'border-width': null,
      'border-top-width': 'var(--stroke-thin)',
      'border-right-width': 'var(--stroke-thin)',
      'border-bottom-width': 'var(--stroke-thin)',
      'border-left-width': '4px',
      'border-style': null,
      'border-top-style': 'solid',
      'border-right-style': 'solid',
      'border-bottom-style': 'solid',
      'border-left-style': 'solid',
      'border-color': null,
      'border-top-color': 'var(--color-accent)',
      'border-right-color': 'var(--color-accent)',
      'border-bottom-color': 'var(--color-accent)',
      'border-left-color': 'var(--color-accent)',
    },
    'the token style and color expand with the edited width, each side keeping its reference',
  );
  // An unsplittable own shorthand (calc(), a multi-component var()) still
  // blocks per-side editing on any family (sideCommit's rule), whichever
  // family the gesture writes, and the empty probe says so too.
  assert.equal(strokeSidesPatch({ 'border-width': 'calc(1px + 1px)' }, { top: { 'border-top-width': '2px' } }), null);
  assert.equal(strokeSidesPatch({ 'border-width': '1px', 'border-color': 'var(--a) var(--b)' }, { top: { 'border-top-width': '2px' } }), null);
  assert.equal(strokeSidesPatch({ 'border-color': 'var(--a) var(--b)' }, {}), null, 'the ring\'s blocked probe');
  // An empty gesture is the empty patch.
  assert.deepEqual(strokeSidesPatch(own, {}), {});
  assert.deepEqual(strokeSidesPatch(own, { top: { 'border-radius': '1rem' } }), {}, 'nothing a family owns');
});

test('strokeSidesPatch: the families collapse back into the shorthands together, once every spelled family agrees', () => {
  // Three own widths at 2px, the fourth edited to 2px: the width family
  // agrees, but the color family (one side authored) still spells the
  // border per side, so nothing collapses: the own layer is spelled one
  // way, longhands until the whole border is even.
  const own = { 'border-top-width': '2px', 'border-right-width': '2px', 'border-bottom-width': '2px', 'border-left-width': '1px', 'border-top-color': 'red' };
  assert.deepEqual(
    strokeSidesPatch(own, { left: { 'border-left-width': '2px', 'border-left-color': 'blue' } }),
    { 'border-left-width': '2px', 'border-left-color': 'blue' },
  );
  // Every spelled family agreeing collapses them all in one patch; a
  // family with no own longhand (the unset color) has nothing to fold.
  const even = { 'border-top-width': '2px', 'border-right-width': '2px', 'border-bottom-width': '2px', 'border-left-width': '1px', 'border-top-style': 'solid', 'border-right-style': 'solid', 'border-bottom-style': 'solid', 'border-left-style': 'solid' };
  assert.deepEqual(
    strokeSidesPatch(even, { left: { 'border-left-width': '2px' } }),
    {
      'border-width': '2px',
      'border-top-width': null,
      'border-right-width': null,
      'border-bottom-width': null,
      'border-left-width': null,
      'border-style': 'solid',
      'border-top-style': null,
      'border-right-style': null,
      'border-bottom-style': null,
      'border-left-style': null,
    },
  );
  // An own shorthand re-written at the same value on one side is a
  // no-op: it expands, agrees, collapses back to what the own layer
  // already says, and the restating entries are pruned.
  assert.deepEqual(strokeSidesPatch({ 'border-width': '1px' }, { top: { 'border-top-width': '1px' } }), {});
  assert.deepEqual(strokeSidesPatch({ 'border-width': '1px', 'border-style': 'solid' }, { top: { 'border-top-width': '1px' } }), {}, 'the untouched family expands and folds back untouched');
  // Agreement is judged over the OWN layer only: a variant-supplied side
  // never folds into an own shorthand that would shadow it.
  assert.deepEqual(
    strokeSidesPatch({ 'border-top-width': '2px', 'border-right-width': '2px', 'border-bottom-width': '2px' }, { top: { 'border-top-width': '2px' } }),
    {},
  );
});

test('strokeSideValues reads one side\'s stroke from a strokeSides read, every delivering layer answering', () => {
  const stack = stacksOf({
    skin: { 'border-left-style': 'solid' },
    variants: [{ id: 'v', styles: { 'border-top-style': 'dashed' } }],
    own: { 'border-width': '1px 2px', 'border-color': ACCENT },
  });
  const read = strokeSides(stack);
  // The width is split per side; the token color is opaque per side and
  // read through the family's uniform value; the variant style is set.
  assert.deepEqual(strokeSideValues(read, 'top'), {
    'border-top-width': '1px',
    'border-top-style': 'dashed',
    'border-top-color': ACCENT,
  });
  // The skin's left style is lit like every other winner (the display
  // rule): present on the cell's face and in the editor's seed alike;
  // the editor's Unset reads the own layer, never the tier.
  assert.deepEqual(strokeSideValues(read, 'left'), {
    'border-left-width': '2px',
    'border-left-style': 'solid',
    'border-left-color': ACCENT,
  });
  // Nothing at all: every part null.
  assert.deepEqual(strokeSideValues(strokeSides(stacksOf()), 'right'), {
    'border-right-width': null,
    'border-right-style': null,
    'border-right-color': null,
  });
});

test('strokeUnsetProperties spans every spelling the own layer could carry for a stroke', () => {
  // The Border row's parts: each shorthand plus its four side longhands
  // (a ring-expanded border holds only longhands on the own layer).
  assert.deepEqual(strokeUnsetProperties(STROKES.border), [
    'border-width', 'border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width',
    'border-style', 'border-top-style', 'border-right-style', 'border-bottom-style', 'border-left-style',
    'border-color', 'border-top-color', 'border-right-color', 'border-bottom-color', 'border-left-color',
  ]);
  // A side's parts: each longhand plus its family shorthand (a border
  // not yet expanded delivers the side through the shorthand).
  assert.deepEqual(strokeUnsetProperties(strokeParts('border', 'top')), [
    'border-top-width', 'border-width',
    'border-top-style', 'border-style',
    'border-top-color', 'border-color',
  ]);
  // An outline has no sides: the parts alone.
  assert.deepEqual(strokeUnsetProperties(STROKES.outline), ['outline-width', 'outline-style', 'outline-color']);
});

test('strokeSideLonghands spans the three families for the ring\'s highlight claim', () => {
  assert.deepEqual(strokeSideLonghands(['right']), ['border-right-width', 'border-right-style', 'border-right-color']);
  assert.deepEqual(strokeSideLonghands(['top', 'bottom']), [
    'border-top-width', 'border-bottom-width',
    'border-top-style', 'border-bottom-style',
    'border-top-color', 'border-bottom-color',
  ]);
  assert.deepEqual(strokeSideLonghands([]), []);
});

test('strokeUniformPatch: the shorthand row writes each family over its own longhands', () => {
  const own = { 'border-top-width': '3px', 'border-top-color': 'red', 'border-style': 'solid' };
  // A completed write (strokeWrite) lands as three shorthands, the own
  // longhands unset in the same patch (the ring's expansion, inverted).
  assert.deepEqual(
    strokeUniformPatch(own, { 'border-width': '2px', 'border-style': 'dashed', 'border-color': ACCENT }),
    {
      'border-width': '2px', 'border-top-width': null,
      'border-style': 'dashed',
      'border-color': ACCENT, 'border-top-color': null,
    },
  );
  // A whole-stroke unset clears the shorthands and the own longhands.
  assert.deepEqual(
    strokeUniformPatch(own, { 'border-width': null, 'border-style': null, 'border-color': null }),
    { 'border-width': null, 'border-top-width': null, 'border-style': null, 'border-color': null, 'border-top-color': null },
  );
  // Properties outside the border families pass through as plain writes.
  assert.deepEqual(
    strokeUniformPatch(own, { 'outline-width': '2px', 'outline-style': 'solid', 'border-radius': '1rem' }),
    { 'outline-width': '2px', 'outline-style': 'solid', 'border-radius': '1rem' },
  );
  assert.deepEqual(strokeUniformPatch(own, {}), {});
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
  console.error(`${failures} of ${tests.length} stroke-model tests failed.`);
  process.exit(1);
}
console.log(`${tests.length} stroke-model tests passed.`);
