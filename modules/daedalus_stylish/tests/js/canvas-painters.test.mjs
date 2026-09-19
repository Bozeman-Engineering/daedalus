/**
 * Self-asserting tests for the canvas-highlight painter registry:
 * property → painter resolution (padding/margin sides, gap axes,
 * painterless properties resolve to nothing) and the per-match
 * highlight arithmetic (per-painter grouping and dedup, canonical part
 * order, axis masking, zero-size rects dropped, the lazy childRects
 * contract).
 *
 * Run directly: `node tests/js/canvas-painters.test.mjs`. Exits
 * non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import { painterFor, highlightRects } from '../../js/src/canvas-painters.js';
import { SIDES } from '../../js/src/widgets/lib/box-model.js';

const tests = [];
const test = (name, run) => tests.push([name, run]);

const rect = (left, top, width, height) => ({ left, top, width, height });
const fakeComputed = (map) => ({ getPropertyValue: (property) => map[property] ?? '' });

/**
 * A measurement context whose childRects trap asserts the lazy
 * contract: painters that do not need children must never walk them.
 */
const context = ({ box = rect(100, 50, 200, 100), computed = {}, childRects = null } = {}) => ({
  rect: box,
  computed: fakeComputed(computed),
  childRects: childRects ?? (() => {
    throw new Error('childRects walked by a painter that does not need children');
  }),
});

const PADDED = {
  'padding-top': '10px', 'padding-right': '20px', 'padding-bottom': '0px', 'padding-left': '40px',
};

// --- painterFor -------------------------------------------------------------

test('painterFor maps box properties to their painter and sides', () => {
  assert.deepEqual(painterFor('padding'), { kind: 'padding', parts: SIDES });
  assert.deepEqual(painterFor('padding-top'), { kind: 'padding', parts: ['top'] });
  assert.deepEqual(painterFor('margin'), { kind: 'margin', parts: SIDES });
  assert.deepEqual(painterFor('margin-left'), { kind: 'margin', parts: ['left'] });
});

test('painterFor maps logical box longhands to their physical sides', () => {
  assert.deepEqual(painterFor('padding-inline'), { kind: 'padding', parts: ['right', 'left'] });
  assert.deepEqual(painterFor('padding-block'), { kind: 'padding', parts: ['top', 'bottom'] });
  assert.deepEqual(painterFor('margin-inline'), { kind: 'margin', parts: ['right', 'left'] });
  assert.equal(painterFor('padding-inline-start'), null);
});

test('painterFor maps gap properties to their axes', () => {
  assert.deepEqual(painterFor('gap'), { kind: 'gap', parts: ['row', 'column'] });
  assert.deepEqual(painterFor('row-gap'), { kind: 'gap', parts: ['row'] });
  assert.deepEqual(painterFor('column-gap'), { kind: 'gap', parts: ['column'] });
});

test('painterFor resolves nothing for painterless properties', () => {
  ['color', 'font-size', 'background', 'box-shadow', 'border-radius']
    .forEach((property) => {
      assert.equal(painterFor(property), null, property);
    });
});

// --- highlightRects: padding ------------------------------------------------

test('highlightRects paints only the addressed padding sides', () => {
  const bands = highlightRects(['padding-top', 'padding-left'], context({ computed: PADDED }));
  assert.deepEqual(bands, [
    { kind: 'padding', ...rect(100, 50, 200, 10) },
    { kind: 'padding', ...rect(100, 60, 40, 90) },
  ]);
});

test('highlightRects drops zero-size bands rather than paint empties', () => {
  assert.deepEqual(highlightRects(['padding-bottom'], context({ computed: PADDED })), []);
});

test('highlightRects merges a family into one deduped pass in canonical order', () => {
  // Shorthand + a longhand it already covers: four bands, not five, and
  // sides come out in SIDES order regardless of property order.
  const bands = highlightRects(['padding-left', 'padding'], context({
    computed: {
      'padding-top': '10px', 'padding-right': '10px', 'padding-bottom': '10px', 'padding-left': '10px',
    },
  }));
  assert.equal(bands.length, 4);
  assert.deepEqual(bands[0], { kind: 'padding', ...rect(100, 50, 200, 10) });
  assert.ok(bands.every((band) => band.kind === 'padding'));
});

test('highlightRects starts padding bands inside the borders', () => {
  const bands = highlightRects(['padding-top'], context({
    box: rect(0, 0, 100, 100),
    computed: {
      'padding-top': '10px',
      'border-top-width': '5px', 'border-right-width': '5px',
      'border-bottom-width': '5px', 'border-left-width': '5px',
    },
  }));
  assert.deepEqual(bands, [{ kind: 'padding', ...rect(5, 5, 90, 10) }]);
});

// --- highlightRects: margin -------------------------------------------------

test('highlightRects paints margin bands outside the border box', () => {
  const bands = highlightRects(['margin'], context({
    computed: {
      'margin-top': '10px', 'margin-right': '20px', 'margin-bottom': '30px', 'margin-left': '40px',
    },
  }));
  assert.deepEqual(bands, [
    { kind: 'margin', ...rect(60, 40, 260, 10) },
    { kind: 'margin', ...rect(300, 50, 20, 100) },
    { kind: 'margin', ...rect(60, 150, 260, 30) },
    { kind: 'margin', ...rect(60, 50, 40, 100) },
  ]);
});

test('highlightRects paints nothing for negative or auto margins', () => {
  const bands = highlightRects(['margin'], context({
    computed: {
      'margin-top': '-16px', 'margin-right': 'auto', 'margin-bottom': '12px', 'margin-left': '0px',
    },
  }));
  // Only the honest side renders, and it claims no absent-side corners.
  assert.deepEqual(bands, [{ kind: 'margin', ...rect(100, 150, 200, 12) }]);
});

// --- highlightRects: gap ----------------------------------------------------

const GRID_CHILDREN = [
  rect(0, 0, 40, 40), rect(50, 0, 40, 40),
  rect(0, 50, 40, 40), rect(50, 50, 40, 40),
];
const GRID_GAPS = { 'row-gap': '10px', 'column-gap': '10px' };

test('highlightRects masks gap strips to the addressed axis', () => {
  const grid = () => context({ computed: GRID_GAPS, childRects: () => GRID_CHILDREN });
  assert.equal(highlightRects(['gap'], grid()).length, 3);
  assert.deepEqual(
    highlightRects(['row-gap'], grid()),
    [{ kind: 'gap', ...rect(0, 40, 90, 10) }],
  );
  assert.equal(highlightRects(['column-gap'], grid()).length, 2);
});

test('highlightRects walks children lazily — only gap painters need them', () => {
  // context()'s default childRects throws; padding/margin must not call it.
  const bands = highlightRects(['padding-top', 'margin-top'], context({
    computed: { ...PADDED, 'margin-top': '8px' },
  }));
  assert.equal(bands.length, 2);
});

// --- highlightRects: painterless and empty specs ----------------------------

test('highlightRects silently paints nothing for painterless properties', () => {
  // No painter runs at all: an empty context would throw if one did.
  assert.deepEqual(highlightRects(['color', 'font-size'], {}), []);
  assert.deepEqual(highlightRects([], {}), []);
  assert.deepEqual(highlightRects(null, {}), []);
});

test('highlightRects mixes families in one call, each tagged with its kind', () => {
  const bands = highlightRects(['padding-top', 'margin-top'], context({
    computed: { ...PADDED, 'margin-top': '8px' },
  }));
  assert.deepEqual(bands.map((band) => band.kind), ['padding', 'margin']);
  assert.deepEqual(bands[1], { kind: 'margin', ...rect(100, 42, 200, 8) });
});

// --- border ---------------------------------------------------------------

test('painterFor maps the three border families to the one border painter', () => {
  assert.deepEqual(painterFor('border-width'), { kind: 'border', parts: SIDES });
  assert.deepEqual(painterFor('border-top-width'), { kind: 'border', parts: ['top'] });
  assert.deepEqual(painterFor('border-left-style'), { kind: 'border', parts: ['left'] });
  assert.deepEqual(painterFor('border-color'), { kind: 'border', parts: SIDES });
  assert.deepEqual(painterFor('border-inline-color'), { kind: 'border', parts: ['right', 'left'] });
  assert.equal(painterFor('border'), null, 'the cross-family shorthand is wide tier, unpainted');
  assert.equal(painterFor('border-radius'), null);
  assert.equal(painterFor('outline-width'), null, 'outline has no sides');
});

test('highlightRects paints border bands between the border box and the padding box', () => {
  const computed = {
    'border-top-width': '5px', 'border-right-width': '2px', 'border-bottom-width': '0px', 'border-left-width': '10px',
  };
  const bands = highlightRects(
    ['border-top-width', 'border-left-color', 'border-bottom-style'],
    context({ box: rect(0, 0, 100, 100), computed }),
  );
  assert.deepEqual(bands, [
    { kind: 'border', ...rect(0, 0, 100, 5) },
    { kind: 'border', ...rect(0, 5, 10, 95) },
  ], 'three families, one pass in side order, the borderless bottom dropped');
  // A side's three longhands (the ring's highlight claim) are one band.
  assert.deepEqual(
    highlightRects(['border-top-width', 'border-top-style', 'border-top-color'], context({ box: rect(0, 0, 100, 100), computed })),
    [{ kind: 'border', ...rect(0, 0, 100, 5) }],
  );
});

// --- runner ---------------------------------------------------------------

let failed = 0;
for (const [name, run] of tests) {
  try {
    run();
    console.log(`ok - ${name}`);
  } catch (error) {
    failed += 1;
    console.error(`not ok - ${name}`);
    console.error(error);
  }
}
if (failed > 0) {
  console.error(`${failed} of ${tests.length} tests failed.`);
  process.exit(1);
}
console.log(`${tests.length} tests passed.`);
