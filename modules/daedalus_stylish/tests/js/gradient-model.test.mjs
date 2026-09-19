/**
 * Self-asserting tests for the gradient builder's value grammar
 * (gradient-model.js): parse-or-degrade honesty, layer-verbatim
 * reassembly, and the serialize round trip.
 *
 * Run directly (`node tests/js/gradient-model.test.mjs`) or with the rest
 * of the suite (`node --test tests/js/*.test.mjs`). Exits non-zero on
 * failure.
 */
import { strict as assert } from 'node:assert';
import {
  buildValue,
  defaultGradient,
  gradientTargetIndex,
  isGradientLayer,
  parseGradient,
  preludeAngle,
  serializeGradient,
  splitLayers,
} from '../../js/src/gradient-model.js';

const tests = [];
const test = (name, run) => tests.push([name, run]);

test('splitLayers: top-level commas only, function commas intact', () => {
  assert.deepEqual(
    splitLayers("linear-gradient(180deg, rgb(0, 0, 0), transparent), url('media:abc')"),
    ['linear-gradient(180deg, rgb(0, 0, 0), transparent)', "url('media:abc')"],
  );
  assert.deepEqual(splitLayers('var(--swatch-hero_fade)'), ['var(--swatch-hero_fade)']);
  // The empty value is zero layers, not a malformed list.
  assert.deepEqual(splitLayers(''), []);
  assert.deepEqual(splitLayers('   '), []);
});

test('splitLayers: malformed lists answer null', () => {
  // Unbalanced parentheses in either direction.
  assert.equal(splitLayers('linear-gradient(180deg, red'), null);
  assert.equal(splitLayers('red)'), null);
  // Empty comma segments (leading, doubled, trailing).
  assert.equal(splitLayers(',url(x)'), null);
  assert.equal(splitLayers('url(x),,url(y)'), null);
  assert.equal(splitLayers('url(x),'), null);
  assert.equal(splitLayers(null), null);
});

test('gradient layer detection: gradients yes, references and media no', () => {
  assert.equal(isGradientLayer('linear-gradient(red, blue)'), true);
  assert.equal(isGradientLayer('REPEATING-RADIAL-GRADIENT(circle, red, blue)'), true);
  // A var() layer is opaque even when it references a gradient swatch:
  // the builder must never unfold a reference.
  assert.equal(isGradientLayer('var(--swatch-hero_fade)'), false);
  assert.equal(isGradientLayer("url('media:abc')"), false);
  assert.equal(gradientTargetIndex(['url(x)', 'conic-gradient(red, blue)']), 1);
  assert.equal(gradientTargetIndex(['url(x)', 'var(--swatch-a)']), -1);
});

test('parseGradient: linear with angle prelude and positioned stops', () => {
  const gradient = parseGradient('linear-gradient(135deg, #ff0000 0%, rgba(0, 0, 0, 0.5) 40% 60%, var(--color-accent))');
  assert.deepEqual(gradient, {
    repeating: false,
    type: 'linear',
    prelude: '135deg',
    stops: [
      { color: '#ff0000', position: '0%' },
      { color: 'rgba(0, 0, 0, 0.5)', position: '40% 60%' },
      { color: 'var(--color-accent)', position: null },
    ],
  });
  // The exact inverse over everything the model represents.
  assert.equal(
    serializeGradient(gradient),
    'linear-gradient(135deg, #ff0000 0%, rgba(0, 0, 0, 0.5) 40% 60%, var(--color-accent))',
  );
});

test('parseGradient: preludeless linear, direction keywords stay raw', () => {
  assert.deepEqual(parseGradient('linear-gradient(red, blue)'), {
    repeating: false,
    type: 'linear',
    prelude: '',
    stops: [{ color: 'red', position: null }, { color: 'blue', position: null }],
  });
  const directed = parseGradient('linear-gradient(to bottom right, red, blue)');
  // "to bottom right" passes through verbatim — the angle control must
  // not claim it (rewriting it in degrees would silently normalize).
  assert.equal(directed.prelude, 'to bottom right');
  assert.equal(serializeGradient(directed), 'linear-gradient(to bottom right, red, blue)');
});

test('parseGradient: radial and conic preludes, repeating flag', () => {
  const radial = parseGradient('radial-gradient(circle at top left, #fff, #000)');
  assert.equal(radial.type, 'radial');
  assert.equal(radial.prelude, 'circle at top left');
  const conic = parseGradient('repeating-conic-gradient(from 45deg, gold, red 20%)');
  assert.equal(conic.repeating, true);
  assert.equal(conic.prelude, 'from 45deg');
  assert.deepEqual(conic.stops[1], { color: 'red', position: '20%' });
  assert.equal(
    serializeGradient(conic),
    'repeating-conic-gradient(from 45deg, gold, red 20%)',
  );
  // A radial whose first argument is a stop takes no prelude.
  assert.equal(parseGradient('radial-gradient(red, blue)').prelude, '');
});

test('parseGradient: grammar beyond the model degrades to null', () => {
  // An interpolation hint is a bare number segment — not representable.
  assert.equal(parseGradient('linear-gradient(red, 30%, blue)'), null);
  // Unbalanced inner text.
  assert.equal(parseGradient('linear-gradient(red, rgb(0,0)'), null);
  // Not a gradient at all.
  assert.equal(parseGradient("url('media:abc')"), null);
  assert.equal(parseGradient('var(--swatch-hero_fade)'), null);
  // A prelude-only body carries no stops.
  assert.equal(parseGradient('linear-gradient(180deg)'), null);
});

test('buildValue: one layer edited, every other layer verbatim', () => {
  const layers = splitLayers("linear-gradient(red, blue), url('media:ABC-def'), var(--swatch-x, none)");
  assert.equal(
    buildValue(layers, 0, 'linear-gradient(0deg, red, blue)'),
    "linear-gradient(0deg, red, blue), url('media:ABC-def'), var(--swatch-x, none)",
  );
  // Index -1 prepends: the scrim layer paints above the media beneath it.
  assert.equal(
    buildValue(['url(x)'], -1, 'linear-gradient(red, blue)'),
    'linear-gradient(red, blue), url(x)',
  );
  assert.equal(buildValue([], -1, 'linear-gradient(red, blue)'), 'linear-gradient(red, blue)');
});

test('preludeAngle: only the Ndeg spelling (and the default) map', () => {
  assert.equal(preludeAngle(''), 180);
  assert.equal(preludeAngle('135deg'), 135);
  assert.equal(preludeAngle('-45deg'), -45);
  assert.equal(preludeAngle('.5turn'), null);
  assert.equal(preludeAngle('to top'), null);
  assert.equal(preludeAngle('135deg in oklch'), null);
});

test('defaultGradient: a serializable two-stop linear', () => {
  const fresh = defaultGradient();
  assert.equal(fresh.stops.length, 2);
  assert.equal(parseGradient(serializeGradient(fresh)) !== null, true);
  const seeded = defaultGradient('var(--color-bg)', 'var(--color-accent)');
  assert.equal(
    serializeGradient(seeded),
    'linear-gradient(180deg, var(--color-bg), var(--color-accent))',
  );
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
  console.error(`${failures} of ${tests.length} gradient-model tests failed.`);
  process.exit(1);
}
console.log(`${tests.length} gradient-model tests passed.`);
