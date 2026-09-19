/**
 * Self-asserting tests for the gradient builder's pure model half
 * (gradient-builder-model.js): the palette-first seed rule and the
 * track's math — positions, neighbor sampling, selection survival.
 *
 * Run directly (`node tests/js/gradient-builder-model.test.mjs`) or with
 * the rest of the suite (`node --test tests/js/*.test.mjs`). Exits
 * non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import { EMPTY_MATERIALS, materialsFrom } from './materials-fixture.mjs';
import { gradientSwatchOf } from '../../js/src/materials.js';
import {
  addStop,
  defaultGradientValue,
  knobAngle,
  moveStop,
  positionPercent,
  removeStop,
  sampleColor,
  seedGradient,
  trackPositions,
} from '../../js/src/gradient-builder-model.js';
import { defaultGradient, parseGradient, serializeGradient } from '../../js/src/gradient-model.js';
import { paletteFallback, stopsFor } from '../../js/src/widget-values.js';

const tests = [];
const test = (name, run) => tests.push([name, run]);

// The guide payload's palette half, as ClientStyleGuide serves it (the
// widget-values fixture's shape).
const paletteGuide = {
  tokenScales: {
    color: { label: 'Color', shape: 'color', prefix: 'color' },
  },
  tokens: {
    color: { accent: { default: '#1a1a2e', dark: '#e8e8f0' }, surface: '#ffffff' },
  },
  swatchKinds: { color: { label: 'Colors' } },
  swatches: [
    { machine_name: 'brand_red', label: 'Brand red', kind: 'color', value: '#b91c1c', variable: '--swatch-brand_red' },
  ],
};
const paletteMaterials = materialsFrom(paletteGuide);

test('seedGradient: palette choices seed the first two stops when any exist', () => {
  // The seed speaks the same vocabulary the builder renders with:
  // whatever stopsFor offers first is what a fresh gradient wears.
  const choices = stopsFor(paletteGuide, paletteMaterials, null, paletteFallback(paletteGuide, paletteMaterials));
  assert.ok(choices.length >= 2, 'fixture must offer at least two choices');
  const seed = seedGradient(paletteGuide, paletteMaterials, null);
  assert.equal(seed.stops.length, 2);
  assert.equal(seed.stops[0].color, choices[0].value);
  assert.equal(seed.stops[1].color, choices[1].value);
});

test('seedGradient: seeding ignores the enforcement mode entirely', () => {
  // The strict wheel gate is dead: strict and free sites seed
  // identically, from the palette when it offers anything.
  assert.deepEqual(
    seedGradient({ ...paletteGuide, enforcement: 'strict' }, paletteMaterials, null),
    seedGradient({ ...paletteGuide, enforcement: 'guarded' }, paletteMaterials, null),
  );
});

test('seedGradient: constraints narrow the seed vocabulary', () => {
  // A bare allowed: list is the complete value space; the seed draws
  // from it verbatim, exactly like a color row would.
  const seed = seedGradient(paletteGuide, paletteMaterials, { allowed: ['#111111', '#eeeeee'] });
  assert.equal(seed.stops[0].color, '#111111');
  assert.equal(seed.stops[1].color, '#eeeeee');
});

test('seedGradient: one palette choice fades itself', () => {
  // A single choice seeds both stops rather than inventing a second
  // color the palette never offered.
  const oneChoice = {
    swatchKinds: { color: { label: 'Colors' } },
    swatches: [
      { machine_name: 'brand_red', label: 'Brand red', kind: 'color', value: '#b91c1c', variable: '--swatch-brand_red' },
    ],
  };
  const seed = seedGradient(oneChoice, materialsFrom(oneChoice), null);
  assert.equal(seed.stops[0].color, 'var(--swatch-brand_red)');
  assert.equal(seed.stops[1].color, 'var(--swatch-brand_red)');
});

test('seedGradient: no palette and no constraint seeds the neutral scrim', () => {
  assert.deepEqual(seedGradient({}, EMPTY_MATERIALS, null), defaultGradient());
  assert.deepEqual(seedGradient(undefined, EMPTY_MATERIALS, undefined), defaultGradient());
});

test('defaultGradientValue: the serialized seed, parseable by the model', () => {
  const value = defaultGradientValue(paletteGuide, paletteMaterials, null);
  assert.equal(value, serializeGradient(seedGradient(paletteGuide, paletteMaterials, null)));
  assert.notEqual(parseGradient(value), null);
});

const stopsOf = (...pairs) => pairs.map(([color, position]) => ({ color, position }));

test('positionPercent: only the percent spelling maps onto the track math', () => {
  assert.equal(positionPercent('50%'), 50);
  assert.equal(positionPercent(' 12.5% '), 12.5);
  assert.equal(positionPercent('-10%'), -10);
  // Every other spelling stays raw — the numeric controls must not
  // silently rewrite a unit.
  assert.equal(positionPercent('10px'), null);
  assert.equal(positionPercent('20% 80%'), null);
  assert.equal(positionPercent(null), null);
  assert.equal(positionPercent(''), null);
});

test('trackPositions: the CSS default distribution, mirrored for display', () => {
  // Positionless ends pin to the track's ends; interior runs space
  // evenly between their resolved neighbors.
  assert.deepEqual(trackPositions(stopsOf(['a', null], ['b', null])), [0, 100]);
  assert.deepEqual(trackPositions(stopsOf(['a', null], ['b', null], ['c', null])), [0, 50, 100]);
  assert.deepEqual(
    trackPositions(stopsOf(['a', '20%'], ['b', null], ['c', null], ['d', '80%'])),
    [20, 40, 60, 80],
  );
  // Non-percent spellings resolve like positionless ones (display only —
  // the raw string is untouched data).
  assert.deepEqual(trackPositions(stopsOf(['a', '10px'], ['b', null])), [0, 100]);
});

test('trackPositions: display mirrors CSS render clamping', () => {
  // Out-of-order and out-of-range positions display exactly where CSS
  // paints them: never before an earlier stop, never off the track.
  assert.deepEqual(trackPositions(stopsOf(['a', '80%'], ['b', '20%'])), [80, 80]);
  assert.deepEqual(trackPositions(stopsOf(['a', '-20%'], ['b', '120%'])), [0, 100]);
});

test('trackPositions: pure — the stops keep their raw positions', () => {
  const stops = stopsOf(['a', null], ['b', null]);
  trackPositions(stops);
  assert.deepEqual(stops, stopsOf(['a', null], ['b', null]));
});

test('moveStop: a drag writes an explicit percent and keeps array order visual', () => {
  const stops = stopsOf(['red', '0%'], ['lime', '50%'], ['blue', '100%']);
  const moved = moveStop(stops, 1, 30.04);
  assert.equal(moved.stops[1].position, '30%');
  assert.equal(moved.stops[1].color, 'lime');
  assert.equal(moved.selected, 1);
  // The clamp and the one-decimal spelling.
  assert.equal(moveStop(stops, 1, 130).stops[2].position, '100%');
  assert.equal(moveStop(stops, 1, 33.333).stops[1].position, '33.3%');
});

test('moveStop: crossing a neighbor reorders, and the selection follows', () => {
  const stops = stopsOf(['red', '0%'], ['lime', '50%'], ['blue', '100%']);
  const moved = moveStop(stops, 0, 70);
  assert.deepEqual(moved.stops.map((stop) => stop.color), ['lime', 'red', 'blue']);
  assert.equal(moved.stops[1].position, '70%');
  assert.equal(moved.selected, 1);
});

test('moveStop: untouched stops keep their raw (even null) positions', () => {
  // The round-trip honesty rule: only the dragged stop's position is
  // rewritten; a positionless neighbor serializes exactly as before.
  const moved = moveStop(stopsOf(['a', null], ['b', null], ['c', null]), 1, 80);
  assert.deepEqual(moved.stops.map((stop) => stop.position), [null, '80%', null]);
});

test('addStop: inserts at the click, sorted, selected', () => {
  const stops = stopsOf(['#000000', '0%'], ['#ffffff', '100%']);
  const added = addStop(stops, ['#000000', '#ffffff'], 25);
  assert.equal(added.stops.length, 3);
  assert.equal(added.selected, 1);
  assert.equal(added.stops[1].position, '25%');
  assert.equal(added.stops[1].color, '#404040');
});

test('sampleColor: identical neighbors keep their own spelling', () => {
  // A click between two var(--accent) stops yields the reference, never
  // a resolved hex — palette references survive the sampler.
  const stops = stopsOf(['var(--accent)', '0%'], ['var(--accent)', '100%']);
  assert.equal(sampleColor(stops, ['#a78bfa', '#a78bfa'], 50), 'var(--accent)');
});

test('sampleColor: rgba interpolation carries alpha', () => {
  const stops = stopsOf(['rgba(0, 0, 0, 0.5)', '0%'], ['rgba(0, 0, 0, 0)', '100%']);
  const sampled = sampleColor(stops, ['rgba(0, 0, 0, 0.5)', 'rgba(0, 0, 0, 0)'], 50);
  assert.equal(sampled, 'rgba(0, 0, 0, 0.25)');
});

test('sampleColor: an unmixable paint falls back to the nearer neighbor', () => {
  const stops = stopsOf(['color-mix(in oklch, red, blue)', '0%'], ['#ffffff', '100%']);
  const paints = ['color-mix(in oklch, red, blue)', '#ffffff'];
  assert.equal(sampleColor(stops, paints, 20), 'color-mix(in oklch, red, blue)');
  assert.equal(sampleColor(stops, paints, 80), '#ffffff');
});

test('sampleColor: positionless stops sample at their display positions', () => {
  const stops = stopsOf(['#000000', null], ['#ffffff', null]);
  assert.equal(sampleColor(stops, ['#000000', '#ffffff'], 50), '#808080');
});

test('removeStop: the two-stop floor holds', () => {
  assert.equal(removeStop(stopsOf(['a', null], ['b', null]), 0, 0), null);
});

test('removeStop: selection survives a removal', () => {
  const stops = stopsOf(['a', '0%'], ['b', '50%'], ['c', '100%']);
  // Removing before the selection shifts it down.
  assert.equal(removeStop(stops, 0, 2).selected, 1);
  // Removing after leaves it alone.
  assert.equal(removeStop(stops, 2, 0).selected, 0);
  // Removing the selection itself picks the nearest survivor.
  assert.equal(removeStop(stops, 2, 2).selected, 1);
  assert.deepEqual(removeStop(stops, 1, 1), {
    stops: stopsOf(['a', '0%'], ['c', '100%']),
    selected: 1,
  });
});

test('knobAngle: pointer offsets read as CSS gradient degrees', () => {
  assert.equal(knobAngle(0, -1), 0);
  assert.equal(knobAngle(1, 0), 90);
  assert.equal(knobAngle(0, 1), 180);
  assert.equal(knobAngle(-1, 0), 270);
  assert.equal(knobAngle(1, -1), 45);
});

// The edit-in-place gate's roster: a gradient swatch beside a color one.
const sessionGuide = { swatchKinds: { color: { label: 'Colors' }, gradient: { label: 'Gradients' } } };
const sessionMaterials = materialsFrom({
  swatches: [
    { machine_name: 'brand_red', label: 'Brand red', kind: 'color', value: '#b91c1c' },
    { machine_name: 'hero_fade', label: 'Hero fade', kind: 'gradient', value: 'linear-gradient(90deg, #111 0%, #eee 100%)' },
  ],
});

test('gradientSwatchOf: exactly one var() naming a gradient swatch answers its entry', () => {
  const entry = gradientSwatchOf(sessionGuide, sessionMaterials, 'var(--swatch-hero_fade)');
  assert.equal(entry?.name, 'hero_fade');
  assert.equal(entry?.ref, 'var(--swatch-hero_fade)');
  assert.equal(entry?.record, sessionMaterials.get('swatch', 'hero_fade'));
  // Whitespace and the var keyword's case keep it one var() and nothing
  // else (the grammar's whole-value read, head canonicalized).
  assert.equal(gradientSwatchOf(sessionGuide, sessionMaterials, '  VAR( --swatch-hero_fade )  ')?.name, 'hero_fade');
});

test('gradientSwatchOf: anything but one gradient-swatch var() answers null', () => {
  // A color-kind swatch: the chip's ordinary builder, not the session.
  assert.equal(gradientSwatchOf(sessionGuide, sessionMaterials, 'var(--swatch-brand_red)'), null);
  // A name the roster does not carry.
  assert.equal(gradientSwatchOf(sessionGuide, sessionMaterials, 'var(--swatch-missing)'), null);
  // A variable outside the reserved swatch namespace.
  assert.equal(gradientSwatchOf(sessionGuide, sessionMaterials, 'var(--color-accent)'), null);
  // A fallback makes the value a literal, not a reference (the grammar's
  // rule: the whole value is the reference, or it is not one).
  assert.equal(gradientSwatchOf(sessionGuide, sessionMaterials, 'var(--swatch-hero_fade, none)'), null);
  // A layered value: the reference is not ALONE.
  assert.equal(
    gradientSwatchOf(sessionGuide, sessionMaterials, "var(--swatch-hero_fade), url('media:1')"),
    null,
  );
  // A literal gradient, an empty value, a non-string.
  assert.equal(gradientSwatchOf(sessionGuide, sessionMaterials, 'linear-gradient(#111, #eee)'), null);
  assert.equal(gradientSwatchOf(sessionGuide, sessionMaterials, ''), null);
  assert.equal(gradientSwatchOf(sessionGuide, sessionMaterials, null), null);
  // No swatches rostered at all.
  assert.equal(gradientSwatchOf(sessionGuide, EMPTY_MATERIALS, 'var(--swatch-hero_fade)'), null);
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
  console.error(`${failures} of ${tests.length} gradient-builder-model tests failed.`);
  process.exit(1);
}
console.log(`${tests.length} gradient-builder-model tests passed.`);
