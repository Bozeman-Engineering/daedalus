/**
 * Self-asserting tests for the value-space spine (js/src/value-space.js):
 * space shapes (closed / detented / free with collision sets), the
 * discrete and magnet scrub math, magnetic capture at settle, and
 * ref→display resolution at the editing condition.
 *
 * Run directly: `node tests/js/value-space.test.mjs`. Exits non-zero on
 * failure.
 */
import { strict as assert } from 'node:assert';
import { EMPTY_MATERIALS, animationRecord, materialsFrom, roster, tokenRecord } from './materials-fixture.mjs';
import {
  CHIP_BUDGET,
  FACE_BUDGET,
  MAGNET_RADIUS,
  SCRUB_BAND,
  SCRUB_PER_PIXEL,
  STEP_GRAIN,
  detentsFor,
  discreteScrubMove,
  discreteScrubStart,
  displayAt,
  magnetAdvance,
  magneticCapture,
  referenceMatch,
  referenceVariable,
  spaceClosed,
  stepAcross,
  stopFace,
  stopLabel,
  surfaceFor,
  valueSpaceFor,
  valuesEqual,
} from '../../js/src/value-space.js';
import { FALLBACKS } from '../../js/src/widget-values.js';

const tests = [];
const test = (name, run) => tests.push([name, run]);

// --- fixtures -------------------------------------------------------------

// A guide payload in the served shape: two dimension scales (spacing
// deliberately collides 6 with gutter at the default condition; gutter
// separates under dark), a condition-keyed type token, an empty scale,
// and one dimension-valued swatch.
const makeGuide = (enforcement) => ({
  enforcement,
  conditions: {
    narrow: { label: 'Narrow', axis: 'width' },
    dark: { label: 'Dark', axis: 'scheme' },
  },
  tokenScales: {
    spacing: { label: 'Spacing', prefix: 'space', shape: 'dimension' },
    type: { label: 'Type', prefix: 'type', shape: 'dimension' },
    ghost: { label: 'Ghost', prefix: 'ghost', shape: 'dimension' },
  },
  tokens: {
    spacing: {
      1: '0.25rem',
      2: '0.5rem',
      4: '1rem',
      6: '1.5rem',
      8: '2rem',
      gutter: { default: '1.5rem', dark: '1.25rem' },
    },
    type: {
      body: '1rem',
      'title-2': { default: '1.5rem', dark: '1.75rem', 'narrow+dark': '2rem' },
    },
  },
  swatches: [
    {
      machine_name: 'hero_gap',
      label: 'Hero gap',
      kind: 'dimension',
      variable: '--swatch-hero_gap',
      value: { default: '1rem', dark: '2rem' },
    },
  ],
});

// The fixture's tokens and swatch as the materials store carries them
// (enforcement is a guide fact; the roster is the same on every posture).
const MATERIALS = materialsFrom(makeGuide('guarded'));

const DIMENSION = { type: 'dimension' };

// A dimension property with no built-in table (margin-top), so the
// constraints alone shape the space; `padding` is the property whose one
// fallback (FALLBACKS.padding, the spacing steps) the fallback cases read.
const BARE = 'margin-top';

// A color guide: one color scale and one color swatch, the materials a
// color-shaped property's space reads (the store is its fallback).
const colorGuide = {
  enforcement: 'guarded',
  tokenScales: { color: { label: 'Color', prefix: 'color', shape: 'color' } },
  tokens: { color: { accent: '#1a1a2e' } },
  swatchKinds: { color: { label: 'Colors' }, gradient: { label: 'Gradients' } },
  swatches: [
    { machine_name: 'lime_green', label: 'Lime green', kind: 'color', value: '#4dff8c' },
    { machine_name: 'hero_fade', label: 'Hero fade', kind: 'gradient', value: 'linear-gradient(#000, #fff)' },
  ],
};
const COLOR_MATERIALS = materialsFrom(colorGuide);

const spacingSpace = (enforcement = 'guarded', condition = 'default') => valueSpaceFor(
  makeGuide(enforcement),
  MATERIALS,
  BARE,
  DIMENSION,
  { scale: 'spacing' },
  condition,
);

// --- valuesEqual ----------------------------------------------------------

test('valuesEqual is parse-normalized within one unit', () => {
  assert.equal(valuesEqual('.5rem', '0.5rem'), true);
  assert.equal(valuesEqual('1.50rem', '1.5rem'), true);
  assert.equal(valuesEqual('0', '0.0'), true, 'bare numbers compare');
});

test('valuesEqual never unit-converts', () => {
  assert.equal(valuesEqual('8px', '0.5rem'), false, 'a 16px root was never promised');
  assert.equal(valuesEqual('1rem', '1em'), false);
  assert.equal(valuesEqual('1rem', '1'), false, 'a bare number is not a rem');
});

test('valuesEqual treats zero as every unit\'s zero', () => {
  // The grammar's unitless-zero rule: a zero length needs no root to
  // convert, so a scale's `0` stop captures a zero settled in rem.
  assert.equal(valuesEqual('0', '0rem'), true);
  assert.equal(valuesEqual('0px', '0rem'), true);
  assert.equal(valuesEqual('0.0', '0em'), true);
  assert.equal(valuesEqual('0rem', '1rem'), false, 'only zero crosses units');
});

test('valuesEqual falls back to normalized strings outside the grammar', () => {
  assert.equal(valuesEqual(' AUTO ', 'auto'), true);
  assert.equal(valuesEqual('auto', '0'), false);
  assert.equal(valuesEqual(null, null), false, 'unset equals nothing');
  assert.equal(valuesEqual('', ''), false);
});

// --- referenceVariable ----------------------------------------------------

test('referenceVariable extracts one whole var() and nothing else', () => {
  assert.equal(referenceVariable('var(--space-4)'), '--space-4');
  assert.equal(referenceVariable('  var( --space-4 )  '), '--space-4');
  assert.equal(referenceVariable('var(--space-4, 1rem)'), null, 'commit spellings carry no fallback tail');
  assert.equal(referenceVariable('0.5rem'), null);
  assert.equal(referenceVariable('calc(var(--space-4) * 2)'), null);
  assert.equal(referenceVariable(null), null);
});

// --- valueSpaceFor: shapes ------------------------------------------------

test('a guarded scale closes the space to value-labeled reference stops', () => {
  const space = spacingSpace('guarded');
  assert.equal(space.kind, 'closed');
  assert.deepEqual(space.stops.map((stop) => stop.value), ['0.25rem', '0.5rem', '1rem', '1.5rem', '2rem']);
  assert.deepEqual(space.stops.map((stop) => stop.ref), [
    'var(--space-1)', 'var(--space-2)', 'var(--space-4)', 'var(--space-6)', 'var(--space-8)',
  ]);
  assert.equal(space.unit, 'rem', 'tokens carry their unit');
  assert.equal(space.stops[1].number, 0.5);
  assert.equal(space.stops[1].unit, 'rem');
});

test('strict closes the space too, per property, never per site', () => {
  assert.equal(spacingSpace('strict').kind, 'closed');
});

test('colliding tokens collapse into one stop carrying the twins', () => {
  const space = spacingSpace('guarded');
  const stop = space.stops.find((candidate) => candidate.value === '1.5rem');
  assert.equal(stop.ref, 'var(--space-6)', 'the first token is the default commit');
  assert.deepEqual(stop.tokens.map((token) => token.name), ['6', 'gutter']);
  assert.deepEqual(stop.tokens.map((token) => token.scale), ['spacing', 'spacing']);
  assert.equal(stop.tokens[1].ref, 'var(--space-gutter)');
});

test('collisions regroup at the editing condition', () => {
  const dark = spacingSpace('guarded', 'dark');
  const six = dark.stops.find((candidate) => candidate.value === '1.5rem');
  const gutter = dark.stops.find((candidate) => candidate.value === '1.25rem');
  assert.equal(six.tokens.length, 1, 'under dark the twins honestly separate');
  assert.equal(gutter.tokens[0].name, 'gutter');
  assert.equal(dark.stops.length, 6);
});

test('an allowed list under law is a closed space of literal stops', () => {
  const space = valueSpaceFor(makeGuide('strict'), MATERIALS, BARE, DIMENSION, { allowed: ['0.5rem', '1rem'] });
  assert.equal(space.kind, 'closed');
  assert.deepEqual(space.stops.map((stop) => stop.ref), ['0.5rem', '1rem'], 'the guide\'s exact spelling is the write');
  assert.deepEqual(space.stops.map((stop) => stop.tokens), [[], []]);
});

test('an allowed var() entry stays a reference stop with a resolved display', () => {
  const space = valueSpaceFor(makeGuide('guarded'), MATERIALS, BARE, DIMENSION, { allowed: ['var(--space-2)', '12px'] });
  assert.equal(space.kind, 'closed');
  assert.equal(space.stops[0].value, '0.5rem');
  assert.equal(space.stops[0].ref, 'var(--space-2)');
  assert.equal(space.stops[0].tokens[0].scale, 'spacing');
  assert.equal(space.unit, null, 'mixed units yield no space unit');
});

test('open enforcement demotes the same vocabulary to detented', () => {
  const space = spacingSpace('open');
  assert.equal(space.kind, 'detented');
  assert.equal(space.stops.length, 5, 'the stops still shape the offer');
});

test('no closing constraint plus the property\'s built-in table is detented', () => {
  // The one fallback per property (widget-values.js FALLBACKS): padding
  // reads the spacing steps whichever surface asks, Basic or Advanced.
  const space = valueSpaceFor(makeGuide('guarded'), MATERIALS, 'padding', DIMENSION, null);
  assert.equal(space.kind, 'detented');
  assert.deepEqual(space.stops.map((stop) => stop.value), ['0', '0.5rem', '1rem', '2rem', '4rem']);
  assert.deepEqual(space.stops.map((stop) => stop.tokens), [[], [], [], [], []], 'built-in stops wear no token');
  assert.equal(space.unit, 'rem', 'the unitless 0 is every unit\'s zero: the rem stops name the space\'s unit, so the field opens on rem, never px');
});

test('a table of bare zeros alone names the bare unit, mixed units still none', () => {
  const zeros = valueSpaceFor(makeGuide('open'), MATERIALS, BARE, DIMENSION, { allowed: ['0', '0.0'] });
  assert.equal(zeros.unit, '', 'nothing but zero: the space stays bare');
  const mixed = valueSpaceFor(makeGuide('open'), MATERIALS, BARE, DIMENSION, { allowed: ['0', '8px', '0.5rem'] });
  assert.equal(mixed.unit, null, 'a zero settles no disagreement between px and rem');
});

test('gap reads its one rem table: detented in both modes', () => {
  // The Advanced gap row and the Layout section's gap row derive the
  // same space; nothing hands the field a vocabulary of its own. The
  // table speaks the spacing tables' rem (one unit per value space), a
  // bare zero settling nothing.
  const space = valueSpaceFor(makeGuide('strict'), MATERIALS, 'gap', DIMENSION, null);
  assert.equal(space.kind, 'detented');
  assert.deepEqual(space.stops.map((stop) => stop.value), ['0', '0.5rem', '1rem', '1.5rem', '2.5rem']);
  assert.equal(space.unit, 'rem', 'the space names rem: the field shows the label, never a select');
  assert.equal(space.stops[1].number, 0.5);
  assert.equal(FALLBACKS.gap.length, 5);
});

test('the trigger pair reads its curated tables: detented, the spelled draft grammar as the stops', () => {
  // The Motion trigger control's two rows and the advanced Motion rows
  // derive the same spaces; every stop names the one `--in-view`
  // trigger so an override of either property stays bound to the other.
  const TEXT = { type: 'text', keywords: ['none'] };
  const start = valueSpaceFor(makeGuide('strict'), MATERIALS, 'timeline-trigger', TEXT, null);
  assert.equal(start.kind, 'detented');
  assert.deepEqual(start.stops.map((stop) => stop.value), [
    'none',
    '--in-view view() entry 20% exit 100%',
    '--in-view view() entry 50% exit 100%',
    '--in-view view() contain 0% exit 100%',
  ]);
  assert.deepEqual(start.stops.map((stop) => stop.tokens), [[], [], [], []], 'literal stops wear no token');
  assert.equal(start.unit, null, 'a range is not a dimension the row can scrub');
  assert.deepEqual(FALLBACKS['timeline-trigger'].map((stop) => stop.label), ['On load', 'When visible', 'When half visible', 'When fully visible']);

  const play = valueSpaceFor(makeGuide('guarded'), MATERIALS, 'animation-trigger', TEXT, null);
  assert.equal(play.kind, 'detented');
  assert.deepEqual(play.stops.map((stop) => stop.value), [
    '--in-view play-once',
    '--in-view replay reset',
    '--in-view play-forwards play-backwards',
    'none',
  ]);
  assert.deepEqual(FALLBACKS['animation-trigger'].map((stop) => stop.label), ['Once', 'Every time', 'Forwards and back', 'Off']);
  // The tables' labels are the stops' NAMES, carried into the space so
  // the stop picker's faces read "When visible · --in-view view() entry
  // 20%" (the Motion trigger control's labelled stops, a token's
  // `name · value` shape); a label that only respells the value (gap's
  // px steps) is no name and the face stays the bare value.
  assert.deepEqual(start.stops.map(stopLabel), [
    'On load · none',
    'When visible · --in-view view() entry 20% exit 100%',
    'When half visible · --in-view view() entry 50% exit 100%',
    'When fully visible · --in-view view() contain 0% exit 100%',
  ]);
  assert.deepEqual(play.stops.map((stop) => stop.label), ['Once', 'Every time', 'Forwards and back', 'Off']);
  const gap = valueSpaceFor(makeGuide('guarded'), MATERIALS, 'gap', DIMENSION, null);
  assert.deepEqual(gap.stops.map((stop) => stop.label), [null, null, null, null, null], 'a label equal to the value is no name');
  assert.equal(stopLabel(gap.stops[1]), '0.5rem');
  // Each table's matches cover its own spelled value and nothing else,
  // so a stored stop reads back as itself on every face and a bare start
  // (`entry 20%` alone, a narrower native range) reads as its own value
  // (widget-values.test pins the read).
  [...FALLBACKS['timeline-trigger'], ...FALLBACKS['animation-trigger']].forEach((stop) => {
    assert.deepEqual(stop.matches, [stop.value], `${stop.id} matches its canonical value alone`);
  });
});

test('no vocabulary at all is free', () => {
  const space = valueSpaceFor(makeGuide('guarded'), MATERIALS, BARE, DIMENSION, null);
  assert.equal(space.kind, 'free');
  assert.deepEqual(space.stops, []);
});

test('a scale minting nothing is the honest empty closed space', () => {
  const space = valueSpaceFor(makeGuide('guarded'), MATERIALS, BARE, DIMENSION, { scale: 'ghost' });
  assert.equal(space.kind, 'closed');
  assert.deepEqual(space.stops, [], 'the hide-empty-row rule keys off this');
  // Shape-agnostic: a color or keyword property bound to an empty scale
  // is the same empty closed space (the advanced hiddenFor rule).
  assert.equal(valueSpaceFor(makeGuide('guarded'), MATERIALS, 'color', { type: 'color' }, { scale: 'ghost' }).kind, 'closed');
  assert.deepEqual(valueSpaceFor(makeGuide('guarded'), MATERIALS, 'display', { type: 'keyword', keywords: ['flex'] }, { scale: 'ghost' }).stops, []);
});

test('a scale row is the offered tokens, or the curated stops with site-minted tokens after them outside strict', () => {
  // The olivero shape: four shipped steps offered, the gutter shipped
  // but opted out of the rows, a token the site minted (origin user)
  // and offered, and one the site minted and opted out.
  const materials = roster(
    tokenRecord('spacing', 'sm', '0.5rem', 'space'),
    tokenRecord('spacing', 'md', '1rem', 'space'),
    tokenRecord('spacing', 'lg', '2rem', 'space'),
    tokenRecord('spacing', 'xl', '4rem', 'space'),
    tokenRecord('spacing', 'gutter', '3rem', 'space', { offered: false }),
    tokenRecord('spacing', 'hero', '6rem', 'space', { origin: 'user' }),
    tokenRecord('spacing', 'quiet', '7rem', 'space', { origin: 'user', offered: false }),
  );
  const refs = (space) => space.stops.map((stop) => stop.ref);
  // Uncurated: the offered flag alone, in stored order, in every mode
  // (a site-minted token is offered by its own flag).
  for (const enforcement of ['open', 'guarded', 'strict']) {
    const space = valueSpaceFor(makeGuide(enforcement), materials, BARE, DIMENSION, { scale: 'spacing', allowed: ['0'] });
    assert.deepEqual(refs(space), ['var(--space-sm)', 'var(--space-md)', 'var(--space-lg)', 'var(--space-xl)', 'var(--space-hero)', '0'], enforcement);
    assert.deepEqual(space.stops.map((stop) => stop.value), ['0.5rem', '1rem', '2rem', '4rem', '6rem', '0'], 'faces are the display values');
  }
  // Curated (an element's stops): the list, in its order, exact for the
  // shipped tokens; the site-minted offered token follows on open and
  // guarded systems and is withheld on strict.
  const curated = { scale: 'spacing', stops: ['lg', 'sm', 'gutter'] };
  assert.deepEqual(refs(valueSpaceFor(makeGuide('open'), materials, BARE, DIMENSION, curated)), ['var(--space-lg)', 'var(--space-sm)', 'var(--space-gutter)', 'var(--space-hero)']);
  assert.deepEqual(refs(valueSpaceFor(makeGuide('guarded'), materials, BARE, DIMENSION, curated)), ['var(--space-lg)', 'var(--space-sm)', 'var(--space-gutter)', 'var(--space-hero)']);
  assert.deepEqual(refs(valueSpaceFor(makeGuide('strict'), materials, BARE, DIMENSION, curated)), ['var(--space-lg)', 'var(--space-sm)', 'var(--space-gutter)']);
  // The kind is the constraint's, not the row's: closed under law,
  // detented on open, whichever tokens the row leads with.
  assert.equal(valueSpaceFor(makeGuide('strict'), materials, BARE, DIMENSION, curated).kind, 'closed');
  assert.equal(valueSpaceFor(makeGuide('open'), materials, BARE, DIMENSION, curated).kind, 'detented');
});

test('the space passes definition and constraints through', () => {
  const constraints = { scale: 'spacing', min: '0' };
  const space = valueSpaceFor(makeGuide('guarded'), MATERIALS, BARE, DIMENSION, constraints);
  assert.equal(space.definition, DIMENSION);
  assert.equal(space.constraints, constraints);
});

test('a color property\'s space reads the materials store', () => {
  // Unconstrained: every color token then every color swatch, as
  // reference stops displaying their values (the store IS the color
  // fallback, so a swatch minted mid-session is in every color space at
  // once); the gradient swatch never enters a color space.
  const space = valueSpaceFor(colorGuide, COLOR_MATERIALS, 'color', { type: 'color' }, null);
  assert.equal(space.kind, 'detented');
  assert.deepEqual(space.stops.map((stop) => stop.value), ['#1a1a2e', '#4dff8c']);
  assert.deepEqual(space.stops.map((stop) => stop.ref), ['var(--color-accent)', 'var(--swatch-lime_green)']);
  assert.deepEqual(space.stops.map((stop) => stop.tokens[0].name), ['accent', 'Lime green']);
  assert.deepEqual(space.stops.map((stop) => stop.tokens[0].kind), ['token', 'swatch']);
  // Native option text: the material's name beside the display value.
  assert.deepEqual(space.stops.map(stopLabel), ['accent · #1a1a2e', 'Lime green · #4dff8c']);
  // The presentation payload rides every stop (the widget stop table's
  // paint / paintDark / group / media carried through, not dropped): the
  // color field's grid and the wells paint from it.
  assert.deepEqual(space.stops.map((stop) => stop.paint), ['#1a1a2e', '#4dff8c']);
  assert.deepEqual(space.stops.map((stop) => stop.paintDark), [null, null]);
  assert.deepEqual(space.stops.map((stop) => stop.group), ['Color', 'Colors']);
  assert.deepEqual(space.stops.map((stop) => stop.media), [false, false]);
  assert.deepEqual(space.stops.map((stop) => stop.thumbnail), [null, null]);
  // Typing the swatch's value IS reaching the swatch (case-insensitive).
  assert.equal(magneticCapture(space.stops, '#4DFF8C').ref, 'var(--swatch-lime_green)');
  assert.equal(magneticCapture(space.stops, '#123456'), null);
  // A closing constraint under law: the curated set, literals labeled as
  // themselves, references by their material.
  const closed = valueSpaceFor(colorGuide, COLOR_MATERIALS, 'color', { type: 'color' }, { allowed: ['#111', 'var(--swatch-lime_green)'] });
  assert.equal(closed.kind, 'closed');
  assert.deepEqual(closed.stops.map(stopLabel), ['#111', 'Lime green · #4dff8c']);
  assert.equal(stopLabel(closed.stops[0]), '#111');
  // A curated list is its own one section (no group on either entry:
  // the grid renders it headerless); a curated reference still paints
  // from its record.
  assert.deepEqual(closed.stops.map((stop) => [stop.paint, stop.group]), [['#111', null], ['#4dff8c', null]]);
});

test('an image property\'s space reads the store\'s gradient swatches', () => {
  const space = valueSpaceFor(colorGuide, COLOR_MATERIALS, 'background-image', { type: 'text', media: true }, null);
  assert.equal(space.kind, 'detented');
  assert.deepEqual(space.stops.map((stop) => stop.ref), ['var(--swatch-hero_fade)']);
  assert.equal(space.stops[0].value, 'linear-gradient(#000, #fff)');
  assert.equal(space.stops[0].tokens[0].name, 'Hero fade');
});

test('a keyword property is detented around its keywords; law closes it only with a constraint', () => {
  const definition = { type: 'keyword', keywords: ['block', 'flex'] };
  // Free entry stays around the keywords (the server accepts var(),
  // calc() and the CSS-wide keywords on every keyword property).
  const open = valueSpaceFor(makeGuide('strict'), MATERIALS, 'display', definition, null);
  assert.equal(open.kind, 'detented');
  assert.deepEqual(open.stops.map((stop) => stop.ref), ['block', 'flex']);
  assert.deepEqual(open.stops.map((stop) => stop.tokens), [[], []]);
  assert.equal(magneticCapture(open.stops, 'FLEX').ref, 'flex');
  // A constraint under law closes the row to the guide's list.
  const closed = valueSpaceFor(makeGuide('guarded'), MATERIALS, 'display', definition, { allowed: ['flex'] });
  assert.equal(closed.kind, 'closed');
  assert.deepEqual(closed.stops.map((stop) => stop.ref), ['flex']);
  assert.equal(valueSpaceFor(makeGuide('open'), MATERIALS, 'display', definition, { allowed: ['flex'] }).kind, 'detented');
});

test('spaceClosed: a closing constraint while constraints are law, per property', () => {
  // The one closedness question (custom values, the gradient builder, a
  // media pick are admissible exactly while the space is open); open
  // enforcement demotes the same constraint to offer-shaping.
  const guarded = makeGuide('guarded');
  assert.equal(spaceClosed(guarded, null), false);
  assert.equal(spaceClosed(guarded, undefined), false);
  assert.equal(spaceClosed(guarded, {}), false);
  assert.equal(spaceClosed(guarded, { allowed: [] }), false);
  assert.equal(spaceClosed(guarded, { min: '0' }), false);
  assert.equal(spaceClosed(guarded, { allowed: ['var(--swatch-hero_fade)'] }), true);
  assert.equal(spaceClosed(guarded, { scale: 'color' }), true);
  assert.equal(spaceClosed(makeGuide('strict'), { scale: 'color' }), true);
  assert.equal(spaceClosed(makeGuide('open'), { scale: 'color' }), false);
  assert.equal(spaceClosed(makeGuide('open'), { allowed: ['flex'] }), false);
});

// --- discrete scrub (closed spaces) ---------------------------------------

test('discrete scrub switches detents at half-band', () => {
  const start = discreteScrubStart(3);
  assert.equal(start, 3 * SCRUB_BAND);
  assert.equal(discreteScrubMove(start, 31, 6).index, 3, 'under half a band holds');
  assert.equal(discreteScrubMove(start, 33, 6).index, 4, 'past half a band ka-chunks');
  assert.equal(discreteScrubMove(start, -33, 6).index, 2);
});

test('discrete scrub accumulates sub-band travel across ticks', () => {
  let state = { raw: discreteScrubStart(0), index: 0 };
  for (let tick = 0; tick < 4; tick += 1) {
    state = discreteScrubMove(state.raw, 10, 6);
  }
  assert.equal(state.index, 1, 'four 10px ticks cross the first half-band');
});

test('discrete scrub pins at both ends, no rubber band', () => {
  const bottom = discreteScrubMove(discreteScrubStart(3), -10000, 6);
  assert.equal(bottom.index, 0);
  assert.equal(bottom.raw, 0, 'the accumulator pins, so one right tick moves again');
  const top = discreteScrubMove(bottom.raw, 10000, 6);
  assert.equal(top.index, 5);
  assert.equal(top.raw, 5 * SCRUB_BAND);
});

// --- detents + magnet scrub (detented spaces) -----------------------------

test('detentsFor keeps only same-unit stops, ascending', () => {
  const space = valueSpaceFor(makeGuide('open'), MATERIALS, BARE, DIMENSION, { allowed: ['1.5rem', '12px', '0.5rem', 'auto'] });
  const detents = detentsFor(space.stops, 'rem');
  assert.deepEqual(detents.map((detent) => detent.number), [0.5, 1.5]);
  assert.deepEqual(detentsFor(space.stops, 'px').map((detent) => detent.number), [12]);
  assert.deepEqual(detentsFor(space.stops, ''), [], 'keywords never magnetize');
});

test('a zero stop magnetizes in every unit and commits its own spelling', () => {
  // padding's built-in table leads with `0`: a rem scrub finds a magnet
  // at 0 and the release captures the stop, committing `0`, never `0rem`.
  const space = valueSpaceFor(makeGuide('open'), MATERIALS, 'padding', DIMENSION, null);
  assert.deepEqual(detentsFor(space.stops, 'rem').map((detent) => detent.number), [0, 0.5, 1, 2, 4]);
  assert.deepEqual(detentsFor(space.stops, 'px').map((detent) => detent.number), [0], 'the rem stops still never magnetize a px scrub');
  assert.equal(magneticCapture(space.stops, '0rem').ref, '0');
  assert.equal(magneticCapture(space.stops, '0px').ref, '0');
});

test('magnet scrub advances per pixel and pins at bounds', () => {
  const detents = detentsFor(spacingSpace('open').stops, 'rem');
  const free = magnetAdvance(0, 100, detents, { min: 0, max: 4 });
  assert.equal(free.raw, 100 * SCRUB_PER_PIXEL);
  const pinned = magnetAdvance(0.1, -200, detents, { min: 0, max: 4 });
  assert.equal(pinned.raw, 0, 'the accumulator pins at the floor');
  assert.equal(pinned.number, 0);
  assert.equal(pinned.stop, null, '0 is no stop on this scale');
});

test('magnet scrub captures the nearest stop inside the radius', () => {
  const detents = detentsFor(spacingSpace('open').stops, 'rem');
  const caught = magnetAdvance(1, 75, detents);
  assert.equal(caught.raw, 1.45, 'the accumulator keeps the true travel');
  assert.equal(caught.number, 1.5, 'the shown value snaps to the magnet');
  assert.equal(caught.stop.ref, 'var(--space-6)');
  const between = magnetAdvance(1, 50, detents);
  assert.equal(between.number, 1.3);
  assert.equal(between.stop, null);
  assert.equal(MAGNET_RADIUS, 0.12, 'the mock\'s first-guess feel knob');
});

test('magnet scrub rounds the shown number between magnets to the unit\'s precision', () => {
  const detents = detentsFor(spacingSpace('open').stops, 'rem');
  const rem = magnetAdvance(1, 51, detents, { precision: 2 });
  assert.equal(rem.raw, 1.306, 'the accumulator keeps the true travel');
  assert.equal(rem.number, 1.31, 'hundredths of a rem, never a third decimal');
  assert.equal(rem.stop, null);
  const px = magnetAdvance(12, 4, [], { perPixel: SCRUB_PER_PIXEL * 16, precision: 0 });
  assert.equal(px.number, 12, 'a pixel scrub shows whole pixels (12.384 traveled)');
  assert.equal(px.raw, 12.384);
  const caught = magnetAdvance(1, 75, detents, { precision: 0 });
  assert.equal(caught.number, 1.5, 'a magnet\'s number is always the stop\'s own, whatever the precision');
  assert.equal(caught.stop.ref, 'var(--space-6)');
});

test('magnet scrub rate scales the travel per pixel, bounds and precision untouched', () => {
  // The modifier ladder reaches the scrub as a plain multiplier over the
  // unit-scaled base (scrubRate: Cmd/Ctrl 100, Shift 10, Alt 0.1).
  const base = magnetAdvance(0, 10, [], { min: 0, max: 100 });
  assert.equal(base.raw, 10 * SCRUB_PER_PIXEL, 'no rate is x1');
  const shift = magnetAdvance(0, 10, [], { min: 0, max: 100, rate: 10 });
  assert.equal(shift.raw, 10 * SCRUB_PER_PIXEL * 10, 'Shift travels ten times as far per pixel');
  const cmd = magnetAdvance(0, 10, [], { min: 0, max: 100, rate: 100 });
  assert.equal(cmd.raw, 10 * SCRUB_PER_PIXEL * 100, 'Cmd a hundred times');
  const alt = magnetAdvance(0, 10, [], { min: 0, max: 100, rate: 0.1 });
  assert.equal(alt.raw, 10 * SCRUB_PER_PIXEL * 0.1, 'Alt a tenth');
  const px = magnetAdvance(12, 4, [], { perPixel: SCRUB_PER_PIXEL * 16, rate: 10 });
  assert.equal(px.raw, 12 + 4 * (SCRUB_PER_PIXEL * 16) * 10, 'the rate multiplies the unit-scaled base, not the rem knob');
  const pinned = magnetAdvance(0.1, -1, [], { min: 0, max: 4, rate: 100 });
  assert.equal(pinned.raw, 0, 'a x100 tick still pins at the floor');
  const ceiling = magnetAdvance(3.9, 1, [], { min: 0, max: 4, rate: 100 });
  assert.equal(ceiling.raw, 4, 'and at the ceiling');
  const rounded = magnetAdvance(1, 7, [], { rate: 10, precision: 2 });
  assert.equal(rounded.number, 1.42, 'between magnets the shown number keeps the unit\'s precision at every rate');
});

test('magnet scrub magnets keep capturing at x100 because the radius scales with the rate', () => {
  // A stop is 20 pointer pixels wide at every rate (0.12 rem at 0.006
  // rem/px, 12 rem at 0.6 rem/px): 19 pixels off a lone stop still sits
  // on it, 21 pixels off has left it, whatever the modifier.
  const detents = detentsFor(spacingSpace('open').stops, 'rem');
  const lone = detents.filter((detent) => detent.number === 1.5);
  for (const rate of [1, 10, 100, 0.1]) {
    const on = magnetAdvance(1.5, 19, lone, { rate });
    assert.equal(on.number, 1.5, `19 pixels off the stop is still captured at x${rate}`);
    assert.equal(on.stop.ref, 'var(--space-6)');
    const off = magnetAdvance(1.5, 21, lone, { rate, precision: 2 });
    assert.equal(off.stop, null, `21 pixels off the stop has left it at x${rate}`);
  }
  // Under Cmd one pixel is 0.6 rem on the whole scale: a two-pixel tick
  // from 0.5 lands at 1.7, 0.2 rem past the 1.5 stop, which a radius
  // fixed at 0.12 would step over; the scaled radius holds it.
  const caught = magnetAdvance(0.5, 2, detents, { rate: 100 });
  assert.equal(caught.number, 1.5, 'a x100 tick lands on the stop rather than stepping over it');
  assert.equal(caught.stop.ref, 'var(--space-6)');
  const nearest = magnetAdvance(0.5, 1, detents, { rate: 100 });
  assert.equal(nearest.raw, 0.5 + 1 * SCRUB_PER_PIXEL * 100, 'the accumulator keeps the true travel');
  assert.equal(nearest.number, 1, 'inside several widened magnets the nearest stop captures');
  assert.equal(nearest.stop.ref, 'var(--space-4)');
  assert.equal(MAGNET_RADIUS * 100 / (SCRUB_PER_PIXEL * 100), MAGNET_RADIUS / SCRUB_PER_PIXEL, 'the stop\'s width in pointer pixels is rate-invariant');
});

// --- steppers (detented spaces) -------------------------------------------

test('steppers step the grain but land ON a crossed stop', () => {
  const detents = detentsFor(spacingSpace('open').stops, 'rem');
  assert.equal(STEP_GRAIN, 0.1);
  const up = stepAcross(1.4, 1, detents);
  assert.equal(up.number, 1.5);
  assert.equal(up.stop.ref, 'var(--space-6)');
  const past = stepAcross(1.5, 1, detents);
  assert.equal(past.number, 1.6, 'leaving a stop is a plain grain step');
  assert.equal(past.stop, null);
  const down = stepAcross(0.55, -1, detents);
  assert.equal(down.number, 0.5);
  assert.equal(down.stop.ref, 'var(--space-2)');
});

test('a step crossing two stops lands on the nearest one', () => {
  const detents = detentsFor(spacingSpace('open').stops, 'rem');
  const jump = stepAcross(0.4, 1, detents, 1);
  assert.equal(jump.number, 0.5, 'never overshoots the first system value');
});

test('stepper math carries no float noise', () => {
  const detents = detentsFor(spacingSpace('open').stops, 'rem');
  assert.equal(stepAcross(1.1, 1, detents).number, 1.2, 'no 1.2000000000000002');
});

// --- magnetic capture at settle -------------------------------------------

test('a typed value that matches a token captures its reference', () => {
  const space = spacingSpace('open');
  const captured = magneticCapture(space.stops, '.5rem');
  assert.equal(captured.stop.value, '0.5rem');
  assert.equal(captured.token.name, '2');
  assert.equal(captured.ref, 'var(--space-2)', 'the settle commits the ref, shows the value');
});

test('capture on a collision takes the first token; the menu separates twins', () => {
  const captured = magneticCapture(spacingSpace('open').stops, '1.5rem');
  assert.equal(captured.token.name, '6');
  assert.equal(captured.ref, 'var(--space-6)');
  assert.equal(captured.stop.tokens.length, 2, 'the collision set rides along for the menu');
});

test('capture never unit-converts and never invents a match', () => {
  const space = spacingSpace('open');
  assert.equal(magneticCapture(space.stops, '8px'), null, '8px is not 0.5rem');
  assert.equal(magneticCapture(space.stops, '0.6rem'), null);
  assert.equal(magneticCapture(space.stops, 'auto'), null);
});

test('capture on a literal-only stop commits its canonical spelling', () => {
  const space = valueSpaceFor(makeGuide('open'), MATERIALS, 'padding', DIMENSION, null);
  const captured = magneticCapture(space.stops, '.5rem');
  assert.equal(captured.token, null, 'nothing to bless: no token behind the stop');
  assert.equal(captured.ref, '0.5rem');
});

// --- referenceMatch (committed refs) --------------------------------------

test('a committed reference wears its exact token, twins included', () => {
  const stops = spacingSpace('guarded').stops;
  const worn = referenceMatch(stops, 'var(--space-gutter)');
  assert.equal(worn.stop.value, '1.5rem');
  assert.equal(worn.token.name, 'gutter', 'the variable names ONE token, not the collision default');
});

test('referenceMatch ignores literals and unknown variables', () => {
  const stops = spacingSpace('guarded').stops;
  assert.equal(referenceMatch(stops, '1.5rem'), null, 'a committed literal stays literal until touched');
  assert.equal(referenceMatch(stops, 'var(--space-99)'), null);
  assert.equal(referenceMatch(stops, null), null);
});

// --- displayAt: ref→display at the editing condition ----------------------

test('a literal displays as itself at every condition', () => {
  assert.equal(displayAt(makeGuide('open'), MATERIALS, '0.75rem', 'dark'), '0.75rem');
});

test('a plain token value is one value everywhere', () => {
  const guide = makeGuide('open');
  assert.equal(displayAt(guide, MATERIALS, 'var(--space-4)'), '1rem');
  assert.equal(displayAt(guide, MATERIALS, 'var(--space-4)', 'dark'), '1rem');
});

test('a condition-keyed token resolves its cascade winner at the pose', () => {
  const guide = makeGuide('open');
  const ref = 'var(--type-title-2)';
  assert.equal(displayAt(guide, MATERIALS, ref), '1.5rem', 'default at the base pose');
  assert.equal(displayAt(guide, MATERIALS, ref, 'dark'), '1.75rem');
  assert.equal(displayAt(guide, MATERIALS, ref, 'narrow'), '1.5rem', 'the dark entry does not apply at narrow');
  assert.equal(displayAt(guide, MATERIALS, ref, 'narrow+dark'), '2rem', 'the compound entry beats its single part');
});

test('a swatch reference resolves the same way', () => {
  const guide = makeGuide('open');
  assert.equal(displayAt(guide, MATERIALS, 'var(--swatch-hero_gap)'), '1rem');
  assert.equal(displayAt(guide, MATERIALS, 'var(--swatch-hero_gap)', 'dark'), '2rem');
});

test('an unknown reference answers null; the face paints the raw spelling', () => {
  const guide = makeGuide('open');
  assert.equal(displayAt(guide, MATERIALS, 'var(--theme-thing)'), null);
  assert.equal(displayAt(guide, MATERIALS, 'var(--space-99)'), null);
});

test('the closed select shows condition-resolved values', () => {
  const guide = makeGuide('guarded');
  const dark = valueSpaceFor(guide, MATERIALS, BARE, DIMENSION, { scale: 'type' }, 'dark');
  assert.deepEqual(dark.stops.map((stop) => stop.value), ['1rem', '1.75rem']);
  assert.equal(dark.stops[1].ref, 'var(--type-title-2)');
});

// --- the keyframes-name space (animation_name) ---------------------------

// Motion's animation-name definition as the guide serves it, and the
// published animations as the roster carries them (label order).
const ANIMATION_NAME = { type: 'animation_name', list: true, keywords: ['none'] };
const ANIMATIONS = roster(
  MATERIALS.all(),
  animationRecord('fade_in', 'Fade in', { '0%': { opacity: '0' }, '100%': { opacity: '1' } }),
  animationRecord('rise', 'Rise', { '0%': { transform: 'translateY(1rem)' }, '100%': { transform: 'none' } }),
);
const animationSpace = (enforcement = 'guarded', constraints = null) => valueSpaceFor(
  makeGuide(enforcement),
  ANIMATIONS,
  'animation-name',
  ANIMATION_NAME,
  constraints,
);

test('a keyframes-name space offers the roster\'s animations by spelling, then none', () => {
  const space = animationSpace('guarded');
  // Detented, not closed: no constraint closes it, the enforcement mode
  // never does (the kind drives the input, so the row is free entry with
  // the offer as its stop picker in every mode).
  assert.equal(space.kind, 'detented');
  assert.deepEqual(space.stops.map((stop) => stop.value), ['motion-fade_in', 'motion-rise', 'none']);
  assert.deepEqual(space.stops.map((stop) => stop.ref), ['motion-fade_in', 'motion-rise', 'none'], 'the spelling is the value and the commit: a keyframes name is an ident the browser reads as written');
  // The animation behind a stop rides as its one-member collision set,
  // labeled by the animation's label; `none` is a pure literal.
  assert.deepEqual(space.stops[0].tokens, [{ ref: 'motion-fade_in', kind: 'animation', scale: null, name: 'Fade in' }]);
  assert.deepEqual(space.stops[2].tokens, []);
  assert.equal(space.unit, null, 'nothing parses as a dimension');
  assert.equal(space.stops[0].number, null);
});

test('the option text names the animation beside its spelling', () => {
  const space = animationSpace('guarded');
  assert.equal(stopLabel(space.stops[0]), 'Fade in · motion-fade_in');
  assert.equal(stopLabel(space.stops[2]), 'none');
});

test('strict keeps the keyframes-name space detented; only a constraint closes it', () => {
  assert.equal(animationSpace('strict').kind, 'detented');
  const closed = animationSpace('strict', { allowed: ['motion-fade_in', 'none'] });
  assert.equal(closed.kind, 'closed');
  assert.deepEqual(closed.stops.map((stop) => stop.ref), ['motion-fade_in', 'none']);
  // An allowed spelling that IS a rostered animation still carries the
  // animation's identity (the allowed literal twins against the offer).
  assert.equal(closed.stops[0].tokens[0].name, 'Fade in');
  assert.equal(animationSpace('open', { allowed: ['motion-fade_in', 'none'] }).kind, 'detented', 'open demotes the constraint to offer-shaping');
});

test('typing an animation\'s spelling captures it; a multi-name value captures nothing', () => {
  const stops = animationSpace('guarded').stops;
  const captured = magneticCapture(stops, 'motion-rise');
  assert.equal(captured.ref, 'motion-rise');
  assert.equal(captured.token.name, 'Rise');
  assert.equal(magneticCapture(stops, 'none').ref, 'none');
  assert.equal(magneticCapture(stops, 'none').token, null);
  // The advanced row edits ONE name: a comma list matches no stop, so the
  // settle commits the raw value and the input keeps showing it (the
  // honesty rule; no list affordance exists on the row).
  assert.equal(magneticCapture(stops, 'motion-fade_in, motion-rise'), null);
  // A theme's keyframes name or an unpublished motion name is a literal
  // the row commits as typed.
  assert.equal(magneticCapture(stops, 'spin'), null);
  assert.equal(magneticCapture(stops, 'motion-missing'), null);
});

test('no animations published: the space is detented around none alone', () => {
  const space = valueSpaceFor(makeGuide('guarded'), EMPTY_MATERIALS, 'animation-name', ANIMATION_NAME, null);
  assert.equal(space.kind, 'detented');
  assert.deepEqual(space.stops.map((stop) => stop.ref), ['none']);
});

test('the animation shorthand stays a free space; the spellings are a completion, not a stop', () => {
  const space = valueSpaceFor(makeGuide('guarded'), ANIMATIONS, 'animation', { type: 'text', keywords: ['none'] }, null);
  assert.equal(space.kind, 'free');
  assert.deepEqual(space.stops, []);
});

test('a bare ident outside the keyframes-name space never resolves an animation', () => {
  // The property-scoped slot rule: an allowed literal spelled like a
  // motion name on an ident property is a literal stop, no material.
  const space = valueSpaceFor(makeGuide('guarded'), ANIMATIONS, 'view-transition-name', { type: 'ident' }, { allowed: ['motion-fade_in'] });
  assert.equal(space.kind, 'closed');
  assert.deepEqual(space.stops[0].tokens, []);
});

// --- stopFace / surfaceFor ------------------------------------------------

// A shadow scale (text-shaped: whole shadow declarations) and a six-token
// size scale, the two vocabularies the face and surface rules turn on.
const faceGuide = {
  enforcement: 'guarded',
  tokenScales: {
    shadow: { label: 'Shadow', prefix: 'shadow', shape: 'text' },
    size: { label: 'Size', prefix: 'size', shape: 'dimension' },
  },
  tokens: {
    shadow: { sm: '0 1px 2px rgb(0 0 0 / 0.1)', md: '0 2px 8px rgb(0 0 0 / 0.15)', lg: '0 8px 24px rgb(0 0 0 / 0.2)' },
    size: { 1: '1px', 2: '2px', 3: '3px', 4: '4px', 5: '5px', 6: '6px' },
  },
};
const FACE_MATERIALS = materialsFrom(faceGuide);
const shadowSpace = (constraints = { scale: 'shadow' }) => valueSpaceFor(faceGuide, FACE_MATERIALS, 'box-shadow', { type: 'text' }, constraints);

test('stopFace: a numeric stop faces as its value whoever minted it', () => {
  // 0.25rem for a spacing token (numbers read; the name is the hover),
  // 1rem for the dimension swatch the space also carries.
  const space = spacingSpace('guarded');
  assert.deepEqual(space.stops.map(stopFace), ['0.25rem', '0.5rem', '1rem', '1.5rem', '2rem']);
  assert.equal(space.stops[0].tokens[0].name, '1', 'the name still rides the token');
});

test('stopFace: a non-numeric material stop faces as its name', () => {
  // sm/md/lg for a shadow scale: shadow CSS does not read on a chip.
  const space = shadowSpace();
  assert.equal(space.kind, 'closed');
  assert.deepEqual(space.stops.map(stopFace), ['sm', 'md', 'lg']);
  assert.deepEqual(space.stops.map((stop) => stop.label), [null, null, null], 'a token stop carries no literal label; its name rides tokens');
  assert.deepEqual(space.stops.map((stop) => stop.id), ['var(--shadow-sm)', 'var(--shadow-md)', 'var(--shadow-lg)'], 'the widget stop id rides through');
  // A color swatch likewise: the name, not the hex.
  const colors = valueSpaceFor(colorGuide, COLOR_MATERIALS, 'color', { type: 'color' }, null);
  assert.deepEqual(colors.stops.map(stopFace), ['accent', 'Lime green']);
});

test('stopFace: a curated literal faces as its label twin where one exists', () => {
  // The guide's allowed '0' beside a stroke scale twins the width
  // family's None: it faces "None" (the twin beats the numeric rule; an
  // explicit 0 is a statement, not a number) and sits last. box-shadow's
  // `none` twin behaves the same; an unnamed literal is its value.
  const border = valueSpaceFor(makeGuide('guarded'), MATERIALS, 'border-width', DIMENSION, { allowed: ['1px', '0'] });
  assert.deepEqual(border.stops.map(stopFace), ['Thin', 'None']);
  assert.equal(border.stops[1].number, 0);
  const shadow = shadowSpace({ scale: 'shadow', allowed: ['none'] });
  assert.deepEqual(shadow.stops.map(stopFace), ['sm', 'md', 'lg', 'None']);
  const bare = valueSpaceFor(makeGuide('guarded'), MATERIALS, BARE, DIMENSION, { allowed: ['3px', 'auto'] });
  assert.deepEqual(bare.stops.map(stopFace), ['3px', 'auto']);
});

test('surfaceFor: field for a dimension or number space that is not closed', () => {
  // Detented (open enforcement demotes the scale to offer-shaping) and
  // free (no vocabulary) dimension spaces keep the field: typed values
  // are admissible, so a field is the honest surface.
  assert.equal(surfaceFor(spacingSpace('open')), 'field');
  assert.equal(surfaceFor(valueSpaceFor(null, EMPTY_MATERIALS, BARE, DIMENSION, null)), 'field');
  assert.equal(surfaceFor(valueSpaceFor(null, EMPTY_MATERIALS, 'line-height', { type: 'number' }, null)), 'field');
  // Closed never fields, however numeric the definition: the space is
  // the whole vocabulary.
  assert.notEqual(surfaceFor(spacingSpace('guarded')), 'field');
  // A non-dimension property never fields, closed or not: basic mode
  // never renders a free text input for it.
  assert.notEqual(surfaceFor(valueSpaceFor(null, EMPTY_MATERIALS, 'text-align', { type: 'keyword' }, null)), 'field');
  assert.notEqual(surfaceFor(valueSpaceFor(null, EMPTY_MATERIALS, 'box-shadow', { type: 'text' }, null)), 'field');
  // The definition option overrides the space's own.
  assert.equal(surfaceFor(valueSpaceFor(null, EMPTY_MATERIALS, BARE, { type: 'text' }, null), { definition: DIMENSION }), 'field');
});

test('surfaceFor: chips within the budgets, the well beyond them', () => {
  assert.equal(CHIP_BUDGET, 5);
  assert.equal(FACE_BUDGET, 5);
  // Three short names: chips. Add a None twin: still five or fewer,
  // still short: chips.
  assert.equal(surfaceFor(shadowSpace()), 'chips');
  assert.equal(surfaceFor(shadowSpace({ scale: 'shadow', allowed: ['none'] })), 'chips');
  // A closed spacing scale: five stops, but '0.25rem' is seven
  // characters: the well.
  assert.equal(surfaceFor(spacingSpace('guarded')), 'well');
  // Six short faces: one over the chip budget: the well.
  const six = valueSpaceFor(faceGuide, FACE_MATERIALS, BARE, DIMENSION, { scale: 'size' });
  assert.equal(six.stops.length, 6);
  assert.equal(surfaceFor(six), 'well');
  // Five of them curated: chips.
  const five = valueSpaceFor(faceGuide, FACE_MATERIALS, BARE, DIMENSION, { scale: 'size', stops: ['1', '2', '3', '4', '5'] });
  assert.equal(surfaceFor(five), 'chips');
  // Long shadow literals: the well.
  const literals = valueSpaceFor(null, EMPTY_MATERIALS, 'box-shadow', { type: 'text' }, null);
  assert.equal(surfaceFor(literals), 'well');
  // An empty space answers chips (nothing exceeds a budget); hiding it
  // is the row's rule.
  assert.equal(surfaceFor(valueSpaceFor(makeGuide('guarded'), MATERIALS, BARE, DIMENSION, { scale: 'ghost' })), 'chips');
});

test('surfaceFor: an icon face is always within budget', () => {
  // text-align's Center and Justify exceed five characters as text, so
  // the row is the well without icons and chips with them (the icon map
  // is keyed by stop id, the Segments contract).
  const space = valueSpaceFor(null, EMPTY_MATERIALS, 'text-align', { type: 'keyword' }, null);
  assert.deepEqual(space.stops.map(stopFace), ['Left', 'Center', 'Right', 'Justify']);
  assert.equal(surfaceFor(space), 'well');
  const icons = { start: [], center: [], end: [], justify: [] };
  assert.equal(surfaceFor(space, { icons }), 'chips');
  // A partial icon map only covers the stops it names.
  assert.equal(surfaceFor(space, { icons: { start: [], end: [] } }), 'well');
});

// --- runner ---------------------------------------------------------------

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
  console.error(`${failures} of ${tests.length} value-space tests failed.`);
  process.exit(1);
}
console.log(`${tests.length} value-space tests passed.`);
