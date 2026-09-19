/**
 * Self-asserting tests for the widget value mapping and custom-state
 * detection (the honesty rule as logic).
 *
 * Run directly (`node tests/js/widget-values.test.mjs`) or via
 * PrinterEquivalenceTest, which executes this file inside the PHPUnit
 * suite so the mapping contract has backpressure. Exits non-zero on
 * failure.
 */
import { strict as assert } from 'node:assert';
import { EMPTY_MATERIALS, materialsFrom } from './materials-fixture.mjs';
import {
  boundChildCount,
  boundGapGate,
  FALLBACKS,
  fallbackFor,
  gapGate,
  gradientFallback,
  normalizeValue,
  paletteFallback,
  parentAlignment,
  positionAxes,
  positionGate,
  positionUpgrade,
  propertyStops,
  segmentState,
  stopsFor,
  textGate,
  triggerPairWrite,
} from '../../js/src/widget-values.js';
import { resolvedConstraintsFor } from '../../js/src/style-guide.js';

// The one fallback map's entries, by the names the rows below read them
// under (each is the property's built-in vocabulary in both modes).
const TEXT_ALIGN_STOPS = FALLBACKS['text-align'];
const FONT_SIZE_STOPS = FALLBACKS['font-size'];
const FONT_WEIGHT_STOPS = FALLBACKS['font-weight'];
const LINE_HEIGHT_STOPS = FALLBACKS['line-height'];
const RADIUS_STOPS = FALLBACKS['border-radius'];
const BORDER_STOPS = FALLBACKS['border-width'];
const ELEVATION_STOPS = FALLBACKS['box-shadow'];
const SPACING_STEPS = FALLBACKS.padding;
const GAP_STOPS = FALLBACKS.gap;
const WIDTH_STOPS = FALLBACKS.width;

const tests = [];
const test = (name, run) => tests.push([name, run]);

// The guide payload's palette half, as ClientStyleGuide serves it: token
// scale prefixes resolved, swatches label-sorted with resolved variables.
const paletteGuide = {
  conditions: {
    narrow: { label: 'Narrow', wrapper: '@container page (max-width: 500px)', axis: 'width' },
    dark: { label: 'Dark', wrapper: '@media (prefers-color-scheme: dark)', axis: 'scheme' },
  },
  tokenScales: {
    color: { label: 'Color', shape: 'color', prefix: 'color' },
    space: { label: 'Space', shape: 'dimension', prefix: 'space' },
  },
  tokens: {
    color: { accent: { default: '#1a1a2e', dark: '#e8e8f0' }, surface: '#ffffff' },
    space: { 4: '1rem' },
  },
  swatchKinds: { color: { label: 'Colors' }, gradient: { label: 'Gradients' } },
  swatches: [
    { machine_name: 'brand_red', label: 'Brand red', kind: 'color', value: '#b91c1c', variable: '--swatch-brand_red' },
    { machine_name: 'hero_fade', label: 'Hero fade', kind: 'gradient', value: 'linear-gradient(#000, #fff)', variable: '--swatch-hero_fade' },
  ],
};

// The same palette as the materials store carries it: the roster every
// stop derivation reads beside the guide's declarations.
const paletteMaterials = materialsFrom(paletteGuide);

// The color widgets' unconstrained vocabulary (the fallback argument
// color rows hand stopsFor) — reused by the generic round-trip and
// uniqueness sweeps below.
const colorPalette = stopsFor(paletteGuide, paletteMaterials, null, paletteFallback(paletteGuide, paletteMaterials));

// A dimension scale as a site would mint one: numeric token names (YAML
// integer keys serialize as strings), skin declaration order.
const scaledGuide = {
  tokenScales: {
    space: { label: 'Space', shape: 'dimension', prefix: 'space' },
  },
  tokens: {
    space: { 2: '0.5rem', 4: '1rem', 8: '2rem', 16: '4rem' },
  },
};
const scaledMaterials = materialsFrom(scaledGuide);

// The position widget's two property rows (labels vary by orientation,
// values by property; the grid-parent shape carries both properties) —
// also the generic segmentState fixtures below (an axis row with aliases,
// including the justify-only left/right).
const alignSelfStops = positionAxes({ display: 'grid' }).vertical.stops;
const justifySelfStops = positionAxes({ display: 'grid' }).horizontal.stops;

test('round trip: every stop re-reads its own canonical write as itself', () => {
  // What a click commits, the mapping must light — otherwise a stop would
  // normalize its own writes into the custom state.
  const rows = [TEXT_ALIGN_STOPS, SPACING_STEPS, GAP_STOPS, WIDTH_STOPS,
    FONT_SIZE_STOPS, FONT_WEIGHT_STOPS, LINE_HEIGHT_STOPS, colorPalette,
    RADIUS_STOPS, ELEVATION_STOPS,
    alignSelfStops, justifySelfStops];
  rows.forEach((stops) => stops.forEach((stop) => {
    assert.deepEqual(
      segmentState(stop.value, stops),
      { selected: stop.id, custom: false },
      `${stop.id} does not re-read its own value "${stop.value}"`,
    );
  }));
});

test('stop ids are unique and aliases unambiguous within each row', () => {
  [TEXT_ALIGN_STOPS, SPACING_STEPS, GAP_STOPS, WIDTH_STOPS,
    FONT_SIZE_STOPS, FONT_WEIGHT_STOPS, LINE_HEIGHT_STOPS, colorPalette,
    RADIUS_STOPS, BORDER_STOPS, ELEVATION_STOPS,
    alignSelfStops, justifySelfStops].forEach((stops) => {
    const ids = stops.map((stop) => stop.id);
    assert.equal(new Set(ids).size, ids.length);
    const aliases = stops.flatMap((stop) => stop.matches);
    assert.equal(new Set(aliases).size, aliases.length, 'an alias maps to two stops');
  });
});

test('normalizeValue: unset forms, trimming, case, whitespace runs', () => {
  assert.equal(normalizeValue(null), null);
  assert.equal(normalizeValue(undefined), null);
  assert.equal(normalizeValue(''), null);
  assert.equal(normalizeValue('   '), null);
  assert.equal(normalizeValue('  Center '), 'center');
  assert.equal(normalizeValue('0.5rem   0.5rem'), '0.5rem 0.5rem');
});

test('unset is honestly nothing: no selection, NOT custom', () => {
  assert.deepEqual(segmentState(null, justifySelfStops), { selected: null, custom: false });
  assert.deepEqual(segmentState(undefined, WIDTH_STOPS), { selected: null, custom: false });
  assert.deepEqual(segmentState('', alignSelfStops), { selected: null, custom: false });
});

test('aliases light the stop that represents them', () => {
  // AI/advanced-mode spellings of the same idea select the button.
  assert.equal(segmentState('start', justifySelfStops).selected, 'start');
  assert.equal(segmentState('left', justifySelfStops).selected, 'start');
  assert.equal(segmentState('END', justifySelfStops).selected, 'end');
  assert.equal(segmentState(' right ', justifySelfStops).selected, 'end');
  assert.equal(segmentState('end', alignSelfStops).selected, 'end');
});

test('axis vocabularies differ: left/right are justify-only aliases', () => {
  // 'left' is not a valid align-self value; the vertical axis must not
  // claim to represent it.
  assert.deepEqual(segmentState('left', alignSelfStops), { selected: null, custom: true });
});

test('unrepresentable values are EXPLICITLY custom, never "nothing set"', () => {
  // The canonical honesty case: space-between under a three-stop row.
  assert.deepEqual(segmentState('space-between', justifySelfStops), { selected: null, custom: true });
  assert.deepEqual(segmentState('stretch', alignSelfStops), { selected: null, custom: true });
  assert.deepEqual(segmentState('42%', WIDTH_STOPS), { selected: null, custom: true });
  // 'auto' is content-driven, not the Full preset.
  assert.deepEqual(segmentState('auto', WIDTH_STOPS), { selected: null, custom: true });
});

test('text-align: absolute keywords are aliases of the relative stops', () => {
  // The widget writes direction-relative values; advanced-mode or AI
  // spellings of the absolute keywords light the stop that represents
  // them in an LTR document.
  assert.equal(segmentState('start', TEXT_ALIGN_STOPS).selected, 'start');
  assert.equal(segmentState('left', TEXT_ALIGN_STOPS).selected, 'start');
  assert.equal(segmentState('end', TEXT_ALIGN_STOPS).selected, 'end');
  assert.equal(segmentState(' Right ', TEXT_ALIGN_STOPS).selected, 'end');
  assert.equal(segmentState('justify', TEXT_ALIGN_STOPS).selected, 'justify');
});

test('text-align: values outside the stop vocabulary are custom', () => {
  // match-parent and the non-standard -webkit-center are real committed
  // values a stop cannot honestly claim.
  assert.deepEqual(segmentState('match-parent', TEXT_ALIGN_STOPS), { selected: null, custom: true });
  assert.deepEqual(segmentState('-webkit-center', TEXT_ALIGN_STOPS), { selected: null, custom: true });
  assert.deepEqual(segmentState(null, TEXT_ALIGN_STOPS), { selected: null, custom: false });
});

test('width presets map exactly; Full is 100%', () => {
  assert.equal(segmentState('25%', WIDTH_STOPS).selected, 'quarter');
  assert.equal(segmentState('50%', WIDTH_STOPS).selected, 'half');
  assert.equal(segmentState('75%', WIDTH_STOPS).selected, 'three-quarters');
  assert.equal(segmentState('100%', WIDTH_STOPS).selected, 'full');
});

test('spacing: each row maps its own property independently', () => {
  // The split rows (padding always, gap gated) share one vocabulary but
  // never read each other: a padding step lights regardless of gap and
  // vice versa.
  assert.deepEqual(segmentState('1rem', SPACING_STEPS), { selected: 'm', custom: false });
  assert.deepEqual(segmentState('.5rem', SPACING_STEPS), { selected: 's', custom: false });
  assert.deepEqual(segmentState(null, SPACING_STEPS), { selected: null, custom: false });
});

test('spacing: zero forms all map to the none step', () => {
  ['0', '0px', '0rem', '0em'].forEach((zero) => {
    assert.deepEqual(segmentState(zero, SPACING_STEPS), { selected: 'none', custom: false });
  });
});

test('typography: weight stops are bare values, keywords their aliases', () => {
  // Values, never names, on every face: the stops are the 400-700 ramp
  // labeled as themselves ('semibold' left basic mode). The spec
  // equivalences (normal=400, bold=700) light the stop that represents
  // them; the numeric write is canonical.
  assert.deepEqual(FONT_WEIGHT_STOPS.map((stop) => stop.label), ['400', '500', '600', '700']);
  assert.equal(segmentState('normal', FONT_WEIGHT_STOPS).selected, '400');
  assert.equal(segmentState('bold', FONT_WEIGHT_STOPS).selected, '700');
  assert.equal(segmentState('400', FONT_WEIGHT_STOPS).selected, '400');
  assert.equal(segmentState(700, FONT_WEIGHT_STOPS).selected, '700');
  assert.equal(segmentState('500', FONT_WEIGHT_STOPS).selected, '500');
  // Relative and off-ramp weights are real values no stop can claim.
  assert.deepEqual(segmentState('bolder', FONT_WEIGHT_STOPS), { selected: null, custom: true });
  assert.deepEqual(segmentState('300', FONT_WEIGHT_STOPS), { selected: null, custom: true });
  assert.deepEqual(segmentState('550', FONT_WEIGHT_STOPS), { selected: null, custom: true });
});

test('typography: font sizes map rem spellings only, px stays custom', () => {
  // A px equivalence would assume a 16px root the guide never promised.
  assert.equal(segmentState('.875rem', FONT_SIZE_STOPS).selected, 's');
  assert.equal(segmentState('1rem', FONT_SIZE_STOPS).selected, 'm');
  assert.deepEqual(segmentState('16px', FONT_SIZE_STOPS), { selected: null, custom: true });
  assert.deepEqual(segmentState('1.2rem', FONT_SIZE_STOPS), { selected: null, custom: true });
});

test('typography: the line-height keyword normal is honestly custom', () => {
  // UA-dependent (~1.2), NOT the Normal stop's 1.5 — no stop may claim it.
  assert.deepEqual(segmentState('normal', LINE_HEIGHT_STOPS), { selected: null, custom: true });
  assert.equal(segmentState('1.5', LINE_HEIGHT_STOPS).selected, 'normal');
  assert.equal(segmentState('2.0', LINE_HEIGHT_STOPS).selected, 'loose');
  // A length spelling is a different idea from the unitless multiplier.
  assert.deepEqual(segmentState('1.5rem', LINE_HEIGHT_STOPS), { selected: null, custom: true });
});

test('typography: a guide type scale becomes the size row (stopsFor)', () => {
  const stops = stopsFor(null, EMPTY_MATERIALS, { allowed: ['1rem', '1.333rem', '1.777rem'] }, FONT_SIZE_STOPS);
  assert.deepEqual(stops.map((stop) => stop.value), ['1rem', '1.333rem', '1.777rem']);
  // A twin adopts the built-in id/label; unknown values label as themselves.
  assert.equal(stops[0].id, 'm');
  assert.equal(stops[1].label, '1.333rem');
  assert.deepEqual(segmentState('1.777rem', stops), { selected: '1.777rem', custom: false });
  assert.deepEqual(segmentState('2.25rem', stops), { selected: null, custom: true });
});

test('palette: an unconstrained color row is the whole color palette', () => {
  // Skin tokens first (color-shaped scales only — no dimensions), then
  // color-kind swatches; gradients never offer on a lone color slot.
  assert.deepEqual(colorPalette.map((stop) => stop.value), [
    'var(--color-accent)', 'var(--color-surface)', 'var(--swatch-brand_red)',
  ]);
  // Faces paint from payload data — a condition-keyed value shows its
  // default entry — never from var() resolution.
  assert.deepEqual(colorPalette.map((stop) => stop.paint), ['#1a1a2e', '#ffffff', '#b91c1c']);
  // Scheme-paired entries thread their dark half onto the stop (the split
  // face's other panel); single-valued stops carry null and stay whole.
  assert.deepEqual(colorPalette.map((stop) => stop.paintDark), ['#e8e8f0', null, null]);
  // Token stops label as their token name, swatch stops as their label.
  assert.deepEqual(colorPalette.map((stop) => stop.label), ['accent', 'surface', 'Brand red']);
  // The committed reference lights its stop.
  assert.equal(segmentState('var(--swatch-brand_red)', colorPalette).selected, 'var(--swatch-brand_red)');
  assert.equal(segmentState('VAR(--color-accent)', colorPalette).selected, 'var(--color-accent)');
});

test('palette: a literal equal to a palette value stays custom (honesty)', () => {
  // '#b91c1c' shows what the swatch shows TODAY, but it is a different
  // statement: it will not track a swatch edit or a skin swap.
  assert.deepEqual(segmentState('#b91c1c', colorPalette), { selected: null, custom: true });
  assert.deepEqual(segmentState('#1a1a2e', colorPalette), { selected: null, custom: true });
});

test('palette: no palette and no constraint is the honest empty vocabulary', () => {
  assert.deepEqual(stopsFor({}, EMPTY_MATERIALS, null, paletteFallback({}, EMPTY_MATERIALS)), []);
  assert.deepEqual(stopsFor(undefined, EMPTY_MATERIALS, undefined, paletteFallback(undefined, EMPTY_MATERIALS)), []);
});

test('palette: a scale constraint closes the row to its tokens, allowed literals beside', () => {
  const stops = stopsFor(paletteGuide, paletteMaterials, { scale: 'color', allowed: ['#111'] }, paletteFallback(paletteGuide, paletteMaterials));
  // The scale's minted tokens as var() references, then the allowed
  // literals — the exact closed space both validators enforce; user
  // swatches never extend a scale-locked option set.
  assert.deepEqual(stops.map((stop) => stop.value), [
    'var(--color-accent)', 'var(--color-surface)', '#111',
  ]);
  assert.equal(segmentState('#111', stops).selected, '#111');
  assert.deepEqual(segmentState('var(--swatch-brand_red)', stops), { selected: null, custom: true });
});

test('palette: a bare allowed list IS the row, verbatim spellings', () => {
  const stops = stopsFor(paletteGuide, paletteMaterials, { allowed: ['#16213e', '#B91C1C', '#16213E'] }, paletteFallback(paletteGuide, paletteMaterials));
  // Guide order, guide spellings kept verbatim (allowed checks are
  // verbatim on both validators); equivalent respellings collapse into
  // the first occurrence; literals paint as themselves.
  assert.deepEqual(stops.map((stop) => stop.value), ['#16213e', '#B91C1C']);
  assert.deepEqual(stops.map((stop) => stop.paint), ['#16213e', '#B91C1C']);
  assert.equal(segmentState('#16213E', stops).selected, '#16213e');
  // Palette references outside the list never offer — the server would
  // reject the commit (the allowed list is the complete value space).
  assert.ok(!stops.some((stop) => stop.value.startsWith('var(')));
  assert.deepEqual(segmentState('#ffffff', stops), { selected: null, custom: true });
});

test('palette: an allowed var() entry paints from roster data', () => {
  const stops = stopsFor(paletteGuide, paletteMaterials, { allowed: ['var(--swatch-brand_red)', 'var(--color-accent)', 'var(--color-unminted)'] }, paletteFallback(paletteGuide, paletteMaterials));
  assert.deepEqual(stops.map((stop) => stop.paint), ['#b91c1c', '#1a1a2e', 'var(--color-unminted)']);
  // The referenced token's scheme pair rides along; single-valued and
  // unknown references carry none (a plain literal never has a pair).
  assert.deepEqual(stops.map((stop) => stop.paintDark), [null, '#e8e8f0', null]);
  assert.deepEqual(stops.map((stop) => stop.label), ['Brand red', 'accent', 'var(--color-unminted)']);
  assert.equal(segmentState('var(--swatch-brand_red)', stops).selected, 'var(--swatch-brand_red)');
});

test('palette: stops carry their palette-side group label', () => {
  // The swatch-well popover sections by group: scale labels for tokens,
  // kind labels for swatches; an allowed literal has none (a curated
  // list is its own one section).
  assert.deepEqual(colorPalette.map((stop) => stop.group), ['Color', 'Color', 'Colors']);
  const scaled = stopsFor(paletteGuide, paletteMaterials, { scale: 'color', allowed: ['#111'] }, paletteFallback(paletteGuide, paletteMaterials));
  assert.deepEqual(scaled.map((stop) => stop.group), ['Color', 'Color', undefined]);
  const curated = stopsFor(paletteGuide, paletteMaterials, { allowed: ['#16213e', 'var(--swatch-brand_red)'] }, paletteFallback(paletteGuide, paletteMaterials));
  assert.deepEqual(curated.map((stop) => stop.group), [undefined, undefined]);
});

test('corners: rem spellings map, px and per-axis shapes stay custom', () => {
  assert.equal(segmentState('.25rem', RADIUS_STOPS).selected, 's');
  assert.equal(segmentState('0.5rem', RADIUS_STOPS).selected, 'm');
  assert.equal(segmentState('9999px', RADIUS_STOPS).selected, 'round');
  // An explicit 0 is the None stop — squared corners, a statement — while
  // unset is honestly nothing.
  assert.deepEqual(segmentState('0', RADIUS_STOPS), { selected: 'none', custom: false });
  assert.deepEqual(segmentState(null, RADIUS_STOPS), { selected: null, custom: false });
  // A px equivalence would assume a 16px root; '50%' rounds per-axis into
  // an ellipse, a different shape from Round; shorthand corners are real
  // values no stop can claim.
  assert.deepEqual(segmentState('8px', RADIUS_STOPS), { selected: null, custom: true });
  assert.deepEqual(segmentState('50%', RADIUS_STOPS), { selected: null, custom: true });
  assert.deepEqual(segmentState('1rem 0', RADIUS_STOPS), { selected: null, custom: true });
});

test('border-width: the built-in None is an explicit 0, last', () => {
  // Under the layer stack an explicit 0 beats a skin- or variant-supplied
  // border where unset would reveal it: a distinct statement, so it is a
  // stop, and it sits LAST so the ramp reads thin -> thick -> none.
  assert.equal(BORDER_STOPS.at(-1).id, 'none');
  assert.equal(BORDER_STOPS.at(-1).label, 'None');
  assert.deepEqual(BORDER_STOPS.map((stop) => stop.id), ['thin', 'medium', 'thick', 'none']);
  assert.deepEqual(segmentState('0px', BORDER_STOPS), { selected: 'none', custom: false });
  // The UA-defined width keywords may not light a px stop (the
  // line-height 'normal' precedent).
  assert.deepEqual(segmentState('thin', BORDER_STOPS), { selected: null, custom: true });
});

test('stroke fallbacks: the width ramp is one table across the border sides and outline-width', () => {
  // The stroke row, the ring's side editors and the outline row scrub
  // one ramp (the padding-top precedent), and the first stop is the
  // width the completion rule lands with, so it must be a visible one.
  ['border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width', 'outline-width'].forEach((property) => {
    assert.equal(FALLBACKS[property], BORDER_STOPS, `${property} reads the border-width ramp`);
    assert.equal(fallbackFor(null, EMPTY_MATERIALS, property, { type: 'dimension' }), BORDER_STOPS);
  });
  assert.equal(BORDER_STOPS[0].value, '1px');
});

test('stroke fallbacks: the four line styles, the same table for border-style, its sides and outline-style', () => {
  const styles = FALLBACKS['border-style'];
  assert.deepEqual(styles.map((stop) => [stop.id, stop.value, stop.label]), [
    ['solid', 'solid', 'Solid'],
    ['dashed', 'dashed', 'Dashed'],
    ['dotted', 'dotted', 'Dotted'],
    ['double', 'double', 'Double'],
  ]);
  ['border-top-style', 'border-right-style', 'border-bottom-style', 'border-left-style', 'outline-style'].forEach((property) => {
    assert.equal(FALLBACKS[property], styles, `${property} reads the style table`);
  });
  // The curated table beats the keyword definition's whole grammar:
  // none is the stroke's unset, not a style choice, and the wide
  // keywords stay custom (honest, still typeable in advanced mode).
  const keywords = ['none', 'hidden', 'dotted', 'dashed', 'solid', 'double', 'groove', 'ridge', 'inset', 'outset'];
  assert.equal(fallbackFor(null, EMPTY_MATERIALS, 'border-style', { type: 'keyword', keywords }), styles);
  assert.deepEqual(segmentState('Dashed', styles), { selected: 'dashed', custom: false });
  assert.deepEqual(segmentState('none', styles), { selected: null, custom: true });
  assert.deepEqual(segmentState('groove', styles), { selected: null, custom: true });
  assert.deepEqual(segmentState('auto', FALLBACKS['outline-style']), { selected: null, custom: true }, 'outline\'s auto is typed, never a stop');
});

test('stroke fallbacks: outline-offset is px steps outward, values as labels, a negative offset typed', () => {
  const offsets = FALLBACKS['outline-offset'];
  assert.deepEqual(offsets.map((stop) => stop.value), ['0px', '2px', '4px', '8px']);
  assert.deepEqual(offsets.map((stop) => stop.label), ['0px', '2px', '4px', '8px']);
  assert.deepEqual(segmentState('0', offsets), { selected: '0px', custom: false });
  assert.deepEqual(segmentState('4px', offsets), { selected: '4px', custom: false });
  assert.deepEqual(segmentState('-2px', offsets), { selected: null, custom: true });
});

test('elevation: an explicit none is a statement; unset is honestly nothing', () => {
  // none flattens an inherited theme shadow — the RADIUS_STOPS None
  // precedent, a different statement from unset.
  assert.deepEqual(segmentState('none', ELEVATION_STOPS), { selected: 'none', custom: false });
  assert.deepEqual(segmentState('NONE', ELEVATION_STOPS), { selected: 'none', custom: false });
  assert.deepEqual(segmentState(null, ELEVATION_STOPS), { selected: null, custom: false });
});

test('elevation: whitespace runs collapse, commas and grammar variants stay custom', () => {
  // normalizeValue collapses whitespace but keeps commas, so a preset
  // re-reads a loosely spaced spelling of itself...
  assert.equal(
    segmentState('0 1px  2px   rgb(0 0 0 / 0.1)', ELEVATION_STOPS).selected,
    'low',
  );
  // ...while exactly-equivalent grammar variants (rgba() commas, hex
  // alpha), multi-shadow stacks and inset are real committed values no
  // preset can honestly claim: custom, never "nothing set".
  assert.deepEqual(
    segmentState('0 1px 2px rgba(0, 0, 0, 0.1)', ELEVATION_STOPS),
    { selected: null, custom: true },
  );
  assert.deepEqual(
    segmentState('0 1px 2px rgb(0 0 0 / 0.1), 0 2px 8px rgb(0 0 0 / 0.15)', ELEVATION_STOPS),
    { selected: null, custom: true },
  );
  assert.deepEqual(
    segmentState('inset 0 1px 2px rgb(0 0 0 / 0.1)', ELEVATION_STOPS),
    { selected: null, custom: true },
  );
});

test('elevation: a guide shadow scale becomes the preset row (stopsFor)', () => {
  const stops = stopsFor(
    null,
    EMPTY_MATERIALS,
    { allowed: ['none', '0 1px 3px rgb(20 20 40 / 0.2)'] },
    ELEVATION_STOPS,
  );
  // Guide order, guide spellings verbatim; a twin adopts the built-in
  // id/label; unknown shadows label as themselves.
  assert.equal(stops[0].id, 'none');
  assert.equal(stops[1].label, '0 1px 3px rgb(20 20 40 / 0.2)');
  assert.equal(segmentState('0 1px 3px RGB(20 20 40 / 0.2)', stops).selected, '0 1px 3px rgb(20 20 40 / 0.2)');
  // A built-in outside the scale is custom now.
  assert.deepEqual(segmentState('0 1px 2px rgb(0 0 0 / 0.1)', stops), { selected: null, custom: true });
  // A minted shadow SCALE (a text-shaped scale: its values are whole
  // shadow declarations) labels by token NAME: sm/md/lg read where the
  // shadow CSS does not; the value still rides as the paint. An allowed
  // `none` beside it appends last and faces as its twin.
  const shadowGuide = {
    tokenScales: { shadow: { label: 'Shadow', shape: 'text', prefix: 'shadow' } },
    tokens: {
      shadow: { sm: '0 1px 2px rgb(0 0 0 / 0.1)', md: '0 2px 8px rgb(0 0 0 / 0.15)', lg: '0 8px 24px rgb(0 0 0 / 0.2)' },
    },
  };
  const scaled = stopsFor(shadowGuide, materialsFrom(shadowGuide), { scale: 'shadow', allowed: ['none'] }, ELEVATION_STOPS);
  assert.deepEqual(scaled.map((stop) => stop.value), ['var(--shadow-sm)', 'var(--shadow-md)', 'var(--shadow-lg)', 'none']);
  assert.deepEqual(scaled.map((stop) => stop.label), ['sm', 'md', 'lg', 'None']);
  assert.equal(scaled[0].paint, '0 1px 2px rgb(0 0 0 / 0.1)');
  assert.equal(segmentState('var(--shadow-md)', scaled).selected, 'var(--shadow-md)');
});

test('positionAxes: a grid parent unlocks both axes as local writes', () => {
  // The payoff of grid as the canonical container upgrade: align-self
  // vertical, justify-self horizontal, no companion needed.
  [{ display: 'grid' }, { display: 'inline-grid' }].forEach((parent) => {
    const axes = positionAxes(parent);
    assert.equal(axes.vertical.property, 'align-self');
    assert.equal(axes.vertical.write, 'local');
    assert.equal(axes.horizontal.property, 'justify-self');
    assert.equal(axes.horizontal.write, 'local');
  });
});

test('positionAxes: a flex parent yields only its cross axis to the child', () => {
  // align-self is the cross axis (vertical under row, horizontal under
  // column); justify-self is ignored by flex, so it takes the leftover
  // axis as 'none' — a committed value keeps a (disabled) row.
  const row = positionAxes({ display: 'flex', flexDirection: 'row' });
  assert.equal(row.vertical.property, 'align-self');
  assert.equal(row.vertical.write, 'local');
  assert.equal(row.horizontal.property, 'justify-self');
  assert.equal(row.horizontal.write, 'none');
  // Direction defaults to row when unreported; reverse stays on the axis.
  assert.equal(positionAxes({ display: 'inline-flex' }).vertical.write, 'local');
  assert.equal(positionAxes({ display: 'flex', flexDirection: 'row-reverse' }).vertical.property, 'align-self');
  const column = positionAxes({ display: 'Flex', flexDirection: ' Column-Reverse ' });
  assert.equal(column.horizontal.property, 'align-self');
  assert.equal(column.horizontal.write, 'local');
  assert.equal(column.vertical.property, 'justify-self');
  assert.equal(column.vertical.write, 'none');
});

test('positionAxes: a plain block parent marks both axes for the grid upgrade', () => {
  // The gorilla scenario: nothing places the child yet, so a pick must
  // ride in with the parent's display: grid (the compound gesture).
  const axes = positionAxes({ display: 'block' });
  assert.equal(axes.vertical.write, 'upgrade');
  assert.equal(axes.horizontal.write, 'upgrade');
  assert.equal(axes.vertical.property, 'align-self');
  assert.equal(axes.horizontal.property, 'justify-self');
});

test('positionAxes: exotic parent displays place nothing basic can offer', () => {
  // Upgrading a non-block box to grid is not a near-no-op; both rows
  // render disabled rather than guessing.
  ['inline', 'inline-block', 'flow-root', 'table', 'list-item'].forEach((display) => {
    const axes = positionAxes({ display });
    assert.equal(axes.vertical.write, 'none', display);
    assert.equal(axes.horizontal.write, 'none', display);
  });
});

test('positionAxes: unknown parent facts degrade to plain local writes', () => {
  // Reached only when a value is already set (positionGate hides the
  // widget otherwise): the set value stays visible and unsettable, and no
  // companion is ever guessed.
  [null, undefined, {}, { display: '' }].forEach((parent) => {
    const axes = positionAxes(parent);
    assert.equal(axes.vertical.write, 'local');
    assert.equal(axes.horizontal.write, 'local');
  });
});

test('positionAxes: labels follow the screen axis, not the property', () => {
  // Under a flex column align-self IS the horizontal axis: its stops must
  // read Left/Center/Right while the same property reads Top/Middle/
  // Bottom under grid.
  assert.deepEqual(
    positionAxes({ display: 'flex', flexDirection: 'column' }).horizontal.stops.map((stop) => stop.label),
    ['Left', 'Center', 'Right'],
  );
  assert.deepEqual(alignSelfStops.map((stop) => stop.label), ['Top', 'Middle', 'Bottom']);
  assert.deepEqual(justifySelfStops.map((stop) => stop.label), ['Left', 'Center', 'Right']);
});

test('position stops: flex and self-* spellings are aliases, stretch stays custom', () => {
  // Canonical writes are the modern start/end spellings; the legacy and
  // self-relative forms light the stop that represents them.
  assert.equal(segmentState('flex-start', alignSelfStops).selected, 'start');
  assert.equal(segmentState('self-end', alignSelfStops).selected, 'end');
  assert.equal(segmentState('left', justifySelfStops).selected, 'start');
  assert.deepEqual(segmentState('stretch', alignSelfStops), { selected: null, custom: true });
  assert.deepEqual(segmentState('auto', justifySelfStops), { selected: null, custom: true });
  // 'left' is not a valid align-self value; the vertical row must not
  // claim to represent it.
  assert.deepEqual(segmentState('left', alignSelfStops), { selected: null, custom: true });
});

test('positionGate: the widget needs a KNOWN parent context', () => {
  // Every position row is parent-relative, so unknown facts HIDE rather
  // than degrade to offering (a set value still offers through the
  // registry's value-set rule).
  assert.equal(positionGate(null, { display: 'block' }), true);
  assert.equal(positionGate({ hasText: true }, { display: 'grid' }), true);
  assert.equal(positionGate(null, null), false);
  assert.equal(positionGate({ hasText: true }, undefined), false);
  assert.equal(positionGate(null, {}), false);
  assert.equal(positionGate(null, { display: '' }), false);
});

test('positionUpgrade: the companion needs the real parent box and a free display', () => {
  const parent = { key: 'region:s1:content', isSelectable: true, display: 'block' };
  assert.equal(positionUpgrade(parent, null), true);
  assert.equal(positionUpgrade(parent, undefined), true);
  assert.equal(positionUpgrade(parent, ''), true);
  // A committed display — even 'block' — was chosen deliberately and is
  // never clobbered (the layout widget's stack-upgrade rule).
  assert.equal(positionUpgrade(parent, 'block'), false);
  assert.equal(positionUpgrade(parent, 'flex'), false);
  // Wrapper markup between the child and the selectable ancestor: the
  // grid would land on the ancestor, not the layout parent. No companion.
  assert.equal(positionUpgrade({ ...parent, isSelectable: false }, null), false);
  assert.equal(positionUpgrade({ ...parent, key: null }, null), false);
  assert.equal(positionUpgrade(null, null), false);
});

test('parentAlignment: a local align-self owns the axis, no attribution', () => {
  const layout = { alignItems: 'center' };
  assert.equal(parentAlignment(layout, 'flex-end', alignSelfStops), null);
});

test('parentAlignment: parent defaults are not control', () => {
  // normal/stretch is the browser doing nothing anyone chose; unset too.
  const stops = alignSelfStops;
  assert.equal(parentAlignment({ alignItems: 'normal' }, null, stops), null);
  assert.equal(parentAlignment({ alignItems: 'stretch' }, null, stops), null);
  assert.equal(parentAlignment({}, null, stops), null);
  assert.equal(parentAlignment(null, null, stops), null);
});

test('parentAlignment: container-driven alignment is attributed to the parent', () => {
  // The honesty extension: a value the widget does not own shows as
  // "controlled by <parent>" rather than as nothing.
  const stops = alignSelfStops;
  assert.deepEqual(parentAlignment({ alignItems: 'center' }, null, stops), { selected: 'center', value: 'center' });
  assert.deepEqual(parentAlignment({ alignItems: 'flex-end' }, undefined, stops), { selected: 'end', value: 'flex-end' });
  // A local 'auto' explicitly defers to the parent: still the parent's.
  assert.deepEqual(parentAlignment({ alignItems: 'start' }, 'auto', stops), { selected: 'start', value: 'start' });
  // Real alignment the stops cannot represent still attributes honestly.
  assert.deepEqual(parentAlignment({ alignItems: 'baseline' }, null, stops), { selected: null, value: 'baseline' });
});

test('spacing: off-scale values are custom, shorthand included', () => {
  assert.deepEqual(segmentState('3rem', SPACING_STEPS), { selected: null, custom: true });
  assert.deepEqual(segmentState('1rem 2rem', SPACING_STEPS), { selected: null, custom: true });
  assert.deepEqual(segmentState('var(--space-m)', SPACING_STEPS), { selected: null, custom: true });
});

test('textGate: text widgets hide only when the canvas SAYS there is no text', () => {
  assert.equal(textGate({ display: 'block', isContainer: false, childCount: 3, hasText: true }), true);
  assert.equal(textGate({ display: 'block', isContainer: false, childCount: 3, hasText: false }), false);
  // Unknown facts degrade to offering — never hide a control on a guess.
  assert.equal(textGate(null), true);
  assert.equal(textGate(undefined), true);
});

test('gapGate: gap needs a container actually laying out sibling boxes', () => {
  assert.equal(gapGate({ isContainer: true, childCount: 2 }), true);
  assert.equal(gapGate({ isContainer: true, childCount: 5 }), true);
  // One child (or none): gap is a no-op a lay user cannot see.
  assert.equal(gapGate({ isContainer: true, childCount: 1 }), false);
  assert.equal(gapGate({ isContainer: true, childCount: 0 }), false);
  // A text field of paragraphs lays out markup, not page structure.
  assert.equal(gapGate({ isContainer: false, childCount: 4 }), false);
  assert.equal(gapGate(null), true);
});

test('boundChildCount: the BOUND element\'s own child boxes, null when unknown', () => {
  // The page selected: the entity root's one child box is the layout
  // root; the layout root itself lays out two bands. A root binding reads
  // the selection's count, an element binding the part's.
  const self = { isContainer: true, childCount: 1, partChildCount: { '.daedalus-layout-layout': 2, '.field__items': 1 } };
  assert.equal(boundChildCount(self, null), 1);
  assert.equal(boundChildCount(self, undefined), 1);
  assert.equal(boundChildCount(self, '.daedalus-layout-layout'), 2);
  assert.equal(boundChildCount(self, '.field__items'), 1);
  // A part the canvas shows no match for, a context without the map, a
  // null context: unknown, never zero.
  assert.equal(boundChildCount(self, '.missing'), null);
  assert.equal(boundChildCount({ isContainer: true, childCount: 3 }, '.daedalus-layout-layout'), null);
  assert.equal(boundChildCount(null, '.daedalus-layout-layout'), null);
  assert.equal(boundChildCount(null, null), null);
});

test('boundGapGate: the page\'s Gap opens on two bands and closes on one', () => {
  const self = { isContainer: true, childCount: 1, partChildCount: { '.daedalus-layout-layout': 2, '.field__items': 1 } };
  assert.equal(boundGapGate(self, '.daedalus-layout-layout'), true);
  assert.equal(boundGapGate(self, '.field__items'), false);
  assert.equal(boundGapGate({ ...self, partChildCount: { '.daedalus-layout-layout': 1 } }, '.daedalus-layout-layout'), false);
  assert.equal(boundGapGate({ ...self, partChildCount: { '.daedalus-layout-layout': 0 } }, '.daedalus-layout-layout'), false);
  // Root-bound: the selection's own count decides, so the entity root's
  // single child box (the layout root) never opens a root gap.
  assert.equal(boundGapGate(self, null), false);
  assert.equal(boundGapGate({ ...self, childCount: 2 }, null), true);
  // Unknown degrades to offering, like every gate.
  assert.equal(boundGapGate(self, '.missing'), true);
  assert.equal(boundGapGate(null, '.daedalus-layout-layout'), true);
  assert.equal(boundGapGate(null, null), true);
});

test('FALLBACKS: one built-in vocabulary per property, both modes', () => {
  // The padding family shares the spacing steps (the field row and the
  // box-model diagram's side cells scrub one scale); gap has its own px
  // steps, value-only (px spelling as id, commit and only alias), the
  // table the Layout section's row and the Advanced gap row both read.
  ['padding-top', 'padding-right', 'padding-bottom', 'padding-left'].forEach((longhand) => {
    assert.equal(FALLBACKS[longhand], SPACING_STEPS, `${longhand} shares padding's steps`);
  });
  assert.deepEqual(GAP_STOPS, [0, 8, 16, 24, 40].map((px) => ({
    id: `${px}px`, value: `${px}px`, matches: [`${px}px`], label: `${px}px`,
  })));
  // No entry for the properties that never had a built-in vocabulary.
  assert.equal(FALLBACKS.margin, undefined);
  assert.equal(FALLBACKS.color, undefined);
});

test('fallbackFor: the property table wins; the shape rule answers the rest', () => {
  // A curated table beats the shape rule: text-align keeps its iconic
  // direction-relative stops though its definition is a keyword one.
  assert.equal(fallbackFor(null, EMPTY_MATERIALS, 'text-align', { type: 'keyword', keywords: ['left', 'center', 'right'] }), TEXT_ALIGN_STOPS);
  assert.equal(fallbackFor(null, EMPTY_MATERIALS, 'gap', { type: 'dimension' }), GAP_STOPS);
  assert.equal(fallbackFor(null, EMPTY_MATERIALS, 'padding-left', null), SPACING_STEPS, 'the table needs no definition');
  // Color-shaped definitions read the materials store (the whole color
  // palette), image-shaped ones its gradient swatches: a swatch minted
  // mid-session appears in every such space at once.
  assert.deepEqual(fallbackFor(paletteGuide, paletteMaterials, 'color', { type: 'color' }), paletteFallback(paletteGuide, paletteMaterials));
  assert.deepEqual(fallbackFor(paletteGuide, paletteMaterials, 'outline-color', { type: 'color' }).map((stop) => stop.value), ['var(--color-accent)', 'var(--color-surface)', 'var(--swatch-brand_red)']);
  assert.deepEqual(fallbackFor(paletteGuide, paletteMaterials, 'background-image', { type: 'text', media: true }), gradientFallback(paletteGuide, paletteMaterials));
  assert.deepEqual(gradientFallback(paletteGuide, paletteMaterials), [{
    id: 'var(--swatch-hero_fade)',
    value: 'var(--swatch-hero_fade)',
    matches: ['var(--swatch-hero_fade)'],
    label: 'Hero fade',
    paint: 'linear-gradient(#000, #fff)',
    paintDark: null,
    group: 'Gradients',
  }]);
  // A keyword definition offers its keywords, each committing itself.
  assert.deepEqual(fallbackFor(null, EMPTY_MATERIALS, 'display', { type: 'keyword', keywords: ['block', 'Flex'] }), [
    { id: 'block', value: 'block', matches: ['block'], label: 'block' },
    { id: 'Flex', value: 'Flex', matches: ['flex'], label: 'Flex' },
  ]);
  // Everything else has no built-in vocabulary.
  assert.deepEqual(fallbackFor(null, EMPTY_MATERIALS, 'margin', { type: 'dimension', keywords: ['auto'] }), []);
  assert.deepEqual(fallbackFor(null, EMPTY_MATERIALS, 'font-family', { type: 'family' }), []);
  assert.deepEqual(fallbackFor(null, EMPTY_MATERIALS, 'mystery', null), []);
  assert.deepEqual(fallbackFor(null, EMPTY_MATERIALS, 'display', { type: 'keyword', keywords: [] }), []);
});

test('propertyStops: stopsFor over the property\'s one fallback', () => {
  // Unconstrained: the table itself (same array); constrained: the
  // guide's list twin-labeled against that table; a color scale on a
  // color property: its minted tokens.
  assert.equal(propertyStops(null, EMPTY_MATERIALS, 'padding', { type: 'dimension' }, null), SPACING_STEPS);
  assert.equal(propertyStops(null, EMPTY_MATERIALS, 'gap', { type: 'dimension' }, {}), GAP_STOPS);
  const display = propertyStops(null, EMPTY_MATERIALS, 'display', { type: 'keyword', keywords: ['block', 'flex'] }, { allowed: ['flex', 'grid'] });
  assert.deepEqual(display.map((stop) => [stop.id, stop.label]), [['flex', 'flex'], ['grid', 'grid']]);
  const scaled = propertyStops(paletteGuide, paletteMaterials, 'color', { type: 'color' }, { scale: 'color' });
  assert.deepEqual(scaled.map((stop) => stop.value), ['var(--color-accent)', 'var(--color-surface)']);
  assert.deepEqual(propertyStops(paletteGuide, paletteMaterials, 'color', { type: 'color' }, null), paletteFallback(paletteGuide, paletteMaterials));
});

test('stopsFor: without a constraint the built-ins stand', () => {
  // The zero-config fallback half: null, missing, empty, or non-list
  // allowed all mean "the guide says nothing here" — the built-in
  // vocabulary is the row, identically (same array, not a copy).
  assert.equal(stopsFor(null, EMPTY_MATERIALS, null, SPACING_STEPS), SPACING_STEPS);
  assert.equal(stopsFor(null, EMPTY_MATERIALS, undefined, SPACING_STEPS), SPACING_STEPS);
  assert.equal(stopsFor(null, EMPTY_MATERIALS, {}, SPACING_STEPS), SPACING_STEPS);
  assert.equal(stopsFor(null, EMPTY_MATERIALS, { min: '0', max: '4rem' }, SPACING_STEPS), SPACING_STEPS);
  assert.equal(stopsFor(null, EMPTY_MATERIALS, { allowed: [] }, SPACING_STEPS), SPACING_STEPS);
  assert.equal(stopsFor(null, EMPTY_MATERIALS, { allowed: 'nope' }, SPACING_STEPS), SPACING_STEPS);
});

test('stopsFor: the guide list IS the vocabulary, in its order', () => {
  const stops = stopsFor(null, EMPTY_MATERIALS, { allowed: ['0.25rem', '1rem', '3rem'] }, SPACING_STEPS);
  assert.deepEqual(stops.map((stop) => stop.value), ['0.25rem', '1rem', '3rem']);
  // A value no built-in represents labels as itself and paints as itself
  // (a plain literal never carries a scheme pair).
  assert.deepEqual(stops[0], {
    id: '0.25rem', value: '0.25rem', matches: ['0.25rem'], label: '0.25rem', paint: '0.25rem', paintDark: null,
  });
  // A value a built-in stop honestly represents adopts its id and label.
  assert.deepEqual(stops[1], {
    id: 'm', value: '1rem', matches: ['1rem'], label: 'M', paint: '1rem', paintDark: null,
  });
});

test('stopsFor: a twin keeps the guide spelling as the canonical write', () => {
  // Allowed checks are VERBATIM on both validators, so the stop must
  // commit the guide's exact string even when a built-in twin supplies the
  // id/label — and the twin's aliases still light the stop for an
  // equivalent committed value.
  const [stop] = stopsFor(null, EMPTY_MATERIALS, { allowed: ['.5rem'] }, SPACING_STEPS);
  assert.equal(stop.id, 's');
  assert.equal(stop.label, 'S');
  assert.equal(stop.value, '.5rem');
  const stops = stopsFor(null, EMPTY_MATERIALS, { allowed: ['.5rem'] }, SPACING_STEPS);
  assert.deepEqual(segmentState('0.5rem', stops), { selected: 's', custom: false });
  assert.deepEqual(segmentState('.5rem', stops), { selected: 's', custom: false });
});

test('stopsFor: equivalent spellings collapse into the first stop', () => {
  // A redundant list ('0' and '0px' are one value to the built-in None
  // stop) must not render two stops for one value; blanks drop too.
  const stops = stopsFor(null, EMPTY_MATERIALS, { allowed: ['0', '0px', '  ', null] }, SPACING_STEPS);
  assert.equal(stops.length, 1);
  assert.equal(stops[0].id, 'none');
  assert.equal(stops[0].value, '0');
});

test('stopsFor: keyword rows narrow the same way', () => {
  // The same mechanism on an alignment vocabulary: aliases adopt their
  // twin ('left' is the start stop), unknown keywords render as
  // themselves, and everything outside the list reads custom.
  const stops = stopsFor(null, EMPTY_MATERIALS, { allowed: ['left', 'center'] }, TEXT_ALIGN_STOPS);
  assert.deepEqual(stops.map((stop) => stop.id), ['start', 'center']);
  assert.equal(stops[0].value, 'left');
  assert.deepEqual(segmentState('start', stops), { selected: 'start', custom: false });
  assert.deepEqual(segmentState('justify', stops), { selected: null, custom: true });
});

test('stopsFor: an allowed var() entry labels and paints from roster data', () => {
  // A dimension row's curated var() literal resolves through the roster
  // exactly like the color rows' (literalStop): the token's name labels
  // it, its display value paints it, and the write stays the reference.
  const stops = stopsFor(paletteGuide, paletteMaterials, { allowed: ['var(--space-4)', '3rem'] }, SPACING_STEPS);
  assert.deepEqual(stops.map((stop) => stop.value), ['var(--space-4)', '3rem']);
  assert.equal(stops[0].label, '4');
  assert.equal(stops[0].paint, '1rem');
  assert.equal(segmentState('var(--space-4)', stops).selected, 'var(--space-4)');
});

test('stopsFor round trip: every derived stop re-reads its own write', () => {
  const rows = [
    stopsFor(null, EMPTY_MATERIALS, { allowed: ['0.25rem', '.5rem', '1rem', '3rem'] }, SPACING_STEPS),
    stopsFor(null, EMPTY_MATERIALS, { allowed: ['left', 'center', 'end'] }, TEXT_ALIGN_STOPS),
    stopsFor(null, EMPTY_MATERIALS, { allowed: ['33%', '50%', '100%'] }, WIDTH_STOPS),
    stopsFor(scaledGuide, scaledMaterials, { scale: 'space' }, SPACING_STEPS),
    stopsFor(scaledGuide, scaledMaterials, { scale: 'space', stops: ['8', '2'], allowed: ['0'] }, SPACING_STEPS),
  ];
  rows.forEach((stops) => stops.forEach((stop) => {
    assert.deepEqual(
      segmentState(stop.value, stops),
      { selected: stop.id, custom: false },
      `${stop.id} does not re-read its own value "${stop.value}"`,
    );
  }));
});

test('stopsFor: a scale constraint closes the row to its minted tokens', () => {
  const stops = stopsFor(scaledGuide, scaledMaterials, { scale: 'space' }, SPACING_STEPS);
  // The whole scale in skin declaration order, committing var() refs.
  assert.deepEqual(stops.map((stop) => stop.value), [
    'var(--space-2)', 'var(--space-4)', 'var(--space-8)', 'var(--space-16)',
  ]);
  // Dimension reference stops label by display VALUE alone (values,
  // never token names, on every face — the `name · value` grammar died
  // with the value-space refactor) and carry the same display value as
  // `paint` for consumers that paint.
  assert.deepEqual(stops.map((stop) => stop.label), [
    '0.5rem', '1rem', '2rem', '4rem',
  ]);
  assert.deepEqual(stops.map((stop) => stop.paint), ['0.5rem', '1rem', '2rem', '4rem']);
  // Matching is reference-spelling ONLY: a literal equal to a token's
  // current resolution is a different statement (it will not track a
  // skin swap), so it stays custom — the palette-row precedent.
  assert.equal(segmentState('var(--space-4)', stops).selected, 'var(--space-4)');
  assert.equal(segmentState('VAR(--space-4)', stops).selected, 'var(--space-4)');
  assert.deepEqual(segmentState('1rem', stops), { selected: null, custom: true });
  // The built-ins do not stand beside a scale: off-scale spellings custom.
  assert.deepEqual(segmentState('0.5rem', stops), { selected: null, custom: true });
});

test('stopsFor: non-numeric scales label by token name alone', () => {
  // Only a dimension or number scale labels by value (the face rule:
  // numbers read). Color rows paint faces from the same stop shape; a
  // value label would duplicate what the face already shows, so the
  // name carries the information the paint cannot.
  const stops = stopsFor(paletteGuide, paletteMaterials, { scale: 'color' }, []);
  assert.deepEqual(stops.map((stop) => stop.label), ['accent', 'surface']);
  assert.deepEqual(stops.map((stop) => stop.paint), ['#1a1a2e', '#ffffff']);
  assert.deepEqual(stops.map((stop) => stop.paintDark), ['#e8e8f0', null]);
  // A number scale is numeric: values, like a dimension scale.
  const weightGuide = {
    tokenScales: { weight: { label: 'Weight', shape: 'number', prefix: 'weight' } },
    tokens: { weight: { regular: '400', bold: '700' } },
  };
  const weights = stopsFor(weightGuide, materialsFrom(weightGuide), { scale: 'weight' }, FONT_WEIGHT_STOPS);
  assert.deepEqual(weights.map((stop) => stop.label), ['400', '700']);
});

test('stopsFor: a stops: list curates the offer, in list order', () => {
  // Offer-shaping, not legality: the row is the curated subset in the
  // list's order; numeric YAML entries arrive as ints and compare as
  // string forms (the guide grammar's rule).
  const stops = stopsFor(scaledGuide, scaledMaterials, { scale: 'space', stops: [8, '2'] }, SPACING_STEPS);
  assert.deepEqual(stops.map((stop) => stop.value), ['var(--space-8)', 'var(--space-2)']);
  // Un-minted names drop client-side (skin data may drift under guide
  // code); the survivors still make the row.
  const drifted = stopsFor(scaledGuide, scaledMaterials, { scale: 'space', stops: ['ghost', '4'] }, SPACING_STEPS);
  assert.deepEqual(drifted.map((stop) => stop.value), ['var(--space-4)']);
});

test('stopsFor: the whole scale stands in when no stops: name survives', () => {
  const stops = stopsFor(scaledGuide, scaledMaterials, { scale: 'space', stops: ['ghost', 'gone'] }, SPACING_STEPS);
  assert.deepEqual(stops.map((stop) => stop.value), [
    'var(--space-2)', 'var(--space-4)', 'var(--space-8)', 'var(--space-16)',
  ]);
});

test('stopsFor: a scale minting nothing is the honest empty row', () => {
  // A declared-but-empty (or undeclared) scale offers nothing rather
  // than falling back to built-ins the server would reject; allowed
  // literals beside it still append (the allowed-beside-scale rule).
  assert.deepEqual(stopsFor(scaledGuide, scaledMaterials, { scale: 'ghost' }, SPACING_STEPS), []);
  assert.deepEqual(stopsFor(null, EMPTY_MATERIALS, { scale: 'space' }, SPACING_STEPS), []);
  const stops = stopsFor(scaledGuide, scaledMaterials, { scale: 'ghost', allowed: ['0'] }, SPACING_STEPS);
  assert.deepEqual(stops.map((stop) => stop.value), ['0']);
  assert.equal(stops[0].id, 'none');
});

test('stopsFor: allowed literals append after the scale tokens', () => {
  // Tier 1 is the scale's minted tokens PLUS the allowed literals, the
  // server's allowed-beside-scale rule mirrored (validateScaleValue);
  // twins still adopt built-in ids and labels.
  const stops = stopsFor(scaledGuide, scaledMaterials, { scale: 'space', stops: ['4'], allowed: ['0'] }, SPACING_STEPS);
  assert.deepEqual(stops.map((stop) => stop.value), ['var(--space-4)', '0']);
  assert.equal(stops[1].id, 'none');
  assert.equal(stops[1].label, 'None');
});

test('resolvedConstraintsFor: the node block wins wholesale over the site default', () => {
  // The validator's node-block-??-default rule mirrored: blocks resolve
  // whole, never key-merged — a node's own {allowed} does not inherit
  // the default's scale.
  const guide = { constraintDefaults: { 'font-size': { scale: 'type' }, padding: { scale: 'space' } } };
  const declaration = { grants: { typography: ['font-size'] }, valueSpaces: { 'font-size': { allowed: ['1rem'] } } };
  assert.deepEqual(resolvedConstraintsFor(guide, declaration, 'font-size'), { allowed: ['1rem'] });
  // Unconstrained on the node: the site-wide default stands in.
  assert.deepEqual(resolvedConstraintsFor(guide, declaration, 'padding'), { scale: 'space' });
  // Neither layer constrains: honestly null, so stopsFor's fallback runs.
  assert.equal(resolvedConstraintsFor(guide, declaration, 'gap'), null);
  // Degenerate payloads stay safe (PHP [] for an empty map, no guide).
  assert.equal(resolvedConstraintsFor({ constraintDefaults: [] }, declaration, 'padding'), null);
  assert.equal(resolvedConstraintsFor(null, null, 'padding'), null);
});

test('the offer seam: a constraint_defaults binding derives the stop row', () => {
  // The whole lookup chain the widgets ride: guide payload
  // (constraintDefaults + tokens) → resolvedConstraintsFor → stopsFor →
  // token reference stops. The built-in table is reached only with no
  // binding — the honest skinless fallback.
  const guide = {
    ...scaledGuide,
    constraintDefaults: { padding: { scale: 'space' } },
  };
  const bound = stopsFor(guide, scaledMaterials, resolvedConstraintsFor(guide, { grants: { spacing: ['padding', 'gap'] } }, 'padding'), SPACING_STEPS);
  assert.deepEqual(
    bound.map((stop) => stop.value),
    ['var(--space-2)', 'var(--space-4)', 'var(--space-8)', 'var(--space-16)'],
  );
  assert.deepEqual(bound.map((stop) => stop.label), ['0.5rem', '1rem', '2rem', '4rem']);
  // An unbound property on the same guide keeps the built-in vocabulary.
  assert.equal(stopsFor(guide, scaledMaterials, resolvedConstraintsFor(guide, { grants: { spacing: ['padding', 'gap'] } }, 'gap'), SPACING_STEPS), SPACING_STEPS);
});

const VISIBLE = '--in-view view() entry 20% exit 100%';
const HALF = '--in-view view() entry 50% exit 100%';
const ONCE = '--in-view play-once';
const ALWAYS = '--in-view replay reset';

test('trigger stops match their canonical spelling only: a bare start reads as its own value', () => {
  // The stops spell both points of the range (`<start> exit 100%`: from
  // the start until the element has fully left, so an element already in
  // view still plays). A lone start is valid free entry with a narrower
  // native meaning (active only while crossing the entry band), so it is
  // never lit as the stop that behaves differently: it renders as itself.
  const stops = FALLBACKS['timeline-trigger'];
  assert.deepEqual(stops.map((stop) => stop.value), ['none', VISIBLE, HALF, '--in-view view() contain 0% exit 100%']);
  stops.forEach((stop) => assert.deepEqual(stop.matches, [stop.value], `${stop.id} matches its canonical spelling alone`));
  assert.deepEqual(segmentState(VISIBLE, stops), { selected: 'visible', custom: false });
  assert.deepEqual(segmentState(HALF, stops), { selected: 'half', custom: false });
  assert.deepEqual(segmentState('--in-view view() entry 20%', stops), { selected: null, custom: true }, 'the bare start is its own value, not When visible');
  assert.deepEqual(segmentState('--in-view view() entry 50%', stops), { selected: null, custom: true });
  assert.deepEqual(segmentState('--in-view view() contain 0%', stops), { selected: null, custom: true });
  assert.deepEqual(segmentState('--in-view view() entry 20% cover 100%', stops), { selected: null, custom: true }, 'the same end point spelled another way is another spelling, not an alias');
});

test('trigger pair: a settle that changes the range keeps the action, and vice versa', () => {
  // The worn entrance's pair (When visible / Once) with one half moved:
  // the other half is bound already, so nothing else is written.
  assert.deepEqual(triggerPairWrite({ 'timeline-trigger': HALF }, { 'timeline-trigger': VISIBLE, 'animation-trigger': ONCE }), { 'timeline-trigger': HALF });
  assert.deepEqual(triggerPairWrite({ 'animation-trigger': ALWAYS }, { 'timeline-trigger': VISIBLE, 'animation-trigger': ONCE }), { 'animation-trigger': ALWAYS });
});

test('trigger pair: turning either row off clears a bound partner as one gesture', () => {
  // Start "On load" with Play bound would leave the hold printed and
  // nothing to watch; Play "Off" with Start bound would leave a Start
  // the row claims while nothing is bound. Both clear the pair.
  assert.deepEqual(triggerPairWrite({ 'timeline-trigger': 'none' }, { 'timeline-trigger': VISIBLE, 'animation-trigger': ONCE }), { 'timeline-trigger': 'none', 'animation-trigger': 'none' });
  assert.deepEqual(triggerPairWrite({ 'animation-trigger': 'none' }, { 'timeline-trigger': VISIBLE, 'animation-trigger': ONCE }), { 'animation-trigger': 'none', 'timeline-trigger': 'none' });
  assert.deepEqual(triggerPairWrite({ 'animation-trigger': 'NONE' }, { 'timeline-trigger': VISIBLE, 'animation-trigger': ONCE }), { 'animation-trigger': 'NONE', 'timeline-trigger': 'none' }, 'the off test normalizes case');
  // A partner already off (none or unset) is not rewritten: the click
  // said off, and off it already is.
  assert.deepEqual(triggerPairWrite({ 'timeline-trigger': 'none' }, { 'timeline-trigger': VISIBLE, 'animation-trigger': 'none' }), { 'timeline-trigger': 'none' });
  assert.deepEqual(triggerPairWrite({ 'animation-trigger': 'none' }, {}), { 'animation-trigger': 'none' });
});

test('trigger pair: turning either row on completes a missing partner with its default stop', () => {
  // A range with no action gains Once; an action with no range gains
  // When visible, so a Start never claims what nothing binds and a Play
  // never binds what no timeline names.
  assert.deepEqual(triggerPairWrite({ 'timeline-trigger': HALF }, {}), { 'timeline-trigger': HALF, 'animation-trigger': ONCE });
  assert.deepEqual(triggerPairWrite({ 'timeline-trigger': HALF }, { 'animation-trigger': 'none' }), { 'timeline-trigger': HALF, 'animation-trigger': ONCE });
  assert.deepEqual(triggerPairWrite({ 'animation-trigger': ALWAYS }, { 'timeline-trigger': 'none' }), { 'animation-trigger': ALWAYS, 'timeline-trigger': VISIBLE });
  // A typed custom range or action completes the same way: the rule is
  // about the pair's bound state, not the stop vocabulary.
  assert.deepEqual(triggerPairWrite({ 'timeline-trigger': '--in-view view() entry 35%' }, {}), { 'timeline-trigger': '--in-view view() entry 35%', 'animation-trigger': ONCE });
  // The completions are the tables' own stops (one spelling per value).
  assert.equal(FALLBACKS['animation-trigger'].find((stop) => stop.id === 'once').value, ONCE);
  assert.equal(FALLBACKS['timeline-trigger'].find((stop) => stop.id === 'visible').value, VISIBLE);
});

test('trigger pair: an unset writes its own property alone, and other properties pass through', () => {
  // Emptying a row follows the layer beneath; what resurfaces there is
  // the layer stack's business, not a companion write.
  assert.deepEqual(triggerPairWrite({ 'timeline-trigger': null }, { 'timeline-trigger': VISIBLE, 'animation-trigger': ONCE }), { 'timeline-trigger': null });
  assert.deepEqual(triggerPairWrite({ 'animation-trigger': null }, { 'timeline-trigger': VISIBLE, 'animation-trigger': ONCE }), { 'animation-trigger': null });
  const other = { 'font-size': '1rem' };
  assert.equal(triggerPairWrite(other, {}), other);
  const both = { 'timeline-trigger': 'none', 'animation-trigger': 'none' };
  assert.equal(triggerPairWrite(both, { 'timeline-trigger': VISIBLE }), both, 'a whole-pair write is already the gesture');
});

test('propertyStops: an axis-relative table stands in as the fallback, a constraint still displaces it', () => {
  const axis = positionAxes({ display: 'grid' }).vertical;
  assert.equal(propertyStops(null, EMPTY_MATERIALS, 'align-self', { type: 'keyword' }, null, axis.stops), axis.stops);
  assert.deepEqual(
    propertyStops(null, EMPTY_MATERIALS, 'align-self', { type: 'keyword' }, { allowed: ['center'] }, axis.stops).map((stop) => [stop.id, stop.label]),
    [['center', 'Middle']],
    'a twin keeps the axis face',
  );
  // Without the table, propertyStops answers the property's own fallback.
  assert.deepEqual(propertyStops(null, EMPTY_MATERIALS, 'align-self', { type: 'keyword' }, null), []);
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
  console.error(`${failures} of ${tests.length} widget-values tests failed.`);
  process.exit(1);
}
console.log(`${tests.length} widget-values tests passed.`);
