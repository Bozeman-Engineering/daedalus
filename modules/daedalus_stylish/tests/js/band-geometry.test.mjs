/**
 * Self-asserting tests for the canvas spacing-band arithmetic: per-side
 * padding band rects inside the border box, row clustering of child
 * rects, gap strips (thickness = the computed gap, centered in the actual
 * space, nothing painted on a zero-gap axis), and the computed-read px
 * digests the bands are fed from.
 *
 * Run directly: `node tests/js/band-geometry.test.mjs`. Exits non-zero on
 * failure.
 */
import { strict as assert } from 'node:assert';
import { paddingBandRects, marginBandRects, borderBandRects, rowClusters, gapStripRects } from '../../js/src/band-geometry.js';
import { paddingPx, borderPx, marginPx, gapPx } from '../../js/src/computed-read.js';

const tests = [];
const test = (name, run) => tests.push([name, run]);

const rect = (left, top, width, height) => ({ left, top, width, height });

// --- paddingBandRects -----------------------------------------------------

test('paddingBandRects places four non-overlapping bands inside the border box', () => {
  const bands = paddingBandRects(
    rect(100, 50, 200, 100),
    { top: 0, right: 0, bottom: 0, left: 0 },
    { top: 10, right: 20, bottom: 30, left: 40 },
  );
  assert.deepEqual(bands.top, rect(100, 50, 200, 10));
  assert.deepEqual(bands.bottom, rect(100, 120, 200, 30));
  // Left/right bands fill the middle only: corners belong to top/bottom.
  assert.deepEqual(bands.left, rect(100, 60, 40, 60));
  assert.deepEqual(bands.right, rect(280, 60, 20, 60));
});

test('paddingBandRects starts inside the borders', () => {
  const bands = paddingBandRects(
    rect(0, 0, 100, 100),
    { top: 5, right: 5, bottom: 5, left: 5 },
    { top: 10, right: 10, bottom: 10, left: 10 },
  );
  assert.deepEqual(bands.top, rect(5, 5, 90, 10));
  assert.deepEqual(bands.left, rect(5, 15, 10, 70));
});

test('paddingBandRects yields zero-thickness rects for unpadded sides', () => {
  const bands = paddingBandRects(
    rect(0, 0, 100, 100),
    { top: 0, right: 0, bottom: 0, left: 0 },
    { top: 12, right: 0, bottom: 12, left: 0 },
  );
  assert.equal(bands.top.height, 12);
  assert.equal(bands.right.width, 0);
  assert.equal(bands.left.width, 0);
});

// --- marginBandRects ------------------------------------------------------

test('marginBandRects places four non-overlapping bands outside the border box', () => {
  const bands = marginBandRects(
    rect(100, 50, 200, 100),
    { top: 10, right: 20, bottom: 30, left: 40 },
  );
  // Corners belong to top/bottom, same convention as the padding bands.
  assert.deepEqual(bands.top, rect(60, 40, 260, 10));
  assert.deepEqual(bands.bottom, rect(60, 150, 260, 30));
  assert.deepEqual(bands.left, rect(60, 50, 40, 100));
  assert.deepEqual(bands.right, rect(300, 50, 20, 100));
});

test('marginBandRects renders nothing for negative, auto, or zero margins', () => {
  const bands = marginBandRects(
    rect(0, 0, 100, 100),
    { top: -16, right: null, bottom: 12, left: 0 },
  );
  assert.equal(bands.top.height, 0);
  assert.equal(bands.right.width, 0);
  assert.equal(bands.left.width, 0);
  // The honest side still renders, claiming no absent-side corners.
  assert.deepEqual(bands.bottom, rect(0, 100, 100, 12));
});

// --- rowClusters ----------------------------------------------------------

test('rowClusters groups vertically overlapping rects into one row', () => {
  const rows = rowClusters([
    rect(0, 0, 50, 40),
    rect(60, 5, 50, 30),
    rect(0, 60, 110, 40),
  ]);
  assert.equal(rows.length, 2);
  assert.equal(rows[0].members.length, 2);
  assert.deepEqual(
    { top: rows[0].top, bottom: rows[0].bottom, left: rows[0].left, right: rows[0].right },
    { top: 0, bottom: 40, left: 0, right: 110 },
  );
  assert.equal(rows[1].members.length, 1);
});

test('rowClusters orders rows top-to-bottom regardless of input order', () => {
  const rows = rowClusters([rect(0, 100, 10, 10), rect(0, 0, 10, 10)]);
  assert.equal(rows[0].top, 0);
  assert.equal(rows[1].top, 100);
});

// --- gapStripRects --------------------------------------------------------

test('gapStripRects paints a row strip between stacked children', () => {
  const strips = gapStripRects(
    [rect(0, 0, 100, 40), rect(0, 56, 100, 40)],
    { row: 16, column: 0 },
  );
  assert.deepEqual(strips, [rect(0, 40, 100, 16)]);
});

test('gapStripRects paints column strips between row members', () => {
  const strips = gapStripRects(
    [rect(0, 0, 40, 30), rect(52, 0, 40, 30)],
    { row: 0, column: 12 },
  );
  assert.deepEqual(strips, [rect(40, 0, 12, 30)]);
});

test('gapStripRects centers the strip when distribution widens the space', () => {
  // space-between: 40px of space but only 10px of it is gap.
  const strips = gapStripRects(
    [rect(0, 0, 30, 20), rect(70, 0, 30, 20)],
    { row: 0, column: 10 },
  );
  assert.deepEqual(strips, [rect(45, 0, 10, 20)]);
});

test('gapStripRects paints nothing on a zero-gap axis', () => {
  // Vertical space exists (margins, not gap): a zero row-gap stays silent.
  assert.deepEqual(
    gapStripRects([rect(0, 0, 100, 40), rect(0, 60, 100, 40)], { row: 0, column: 0 }),
    [],
  );
});

test('gapStripRects handles a wrapped grid on both axes', () => {
  const strips = gapStripRects(
    [
      rect(0, 0, 40, 40), rect(50, 0, 40, 40),
      rect(0, 50, 40, 40), rect(50, 50, 40, 40),
    ],
    { row: 10, column: 10 },
  );
  // One row strip spanning both columns, one column strip per row.
  assert.equal(strips.length, 3);
  assert.deepEqual(strips[0], rect(0, 40, 90, 10));
  assert.deepEqual(strips[1], rect(40, 0, 10, 40));
  assert.deepEqual(strips[2], rect(40, 50, 10, 40));
});

test('gapStripRects skips touching or overlapping children', () => {
  assert.deepEqual(
    gapStripRects([rect(0, 0, 50, 20), rect(50, 0, 50, 20)], { row: 8, column: 8 }),
    [],
  );
});

// --- computed-read px digests ---------------------------------------------

const fakeComputed = (map) => ({ getPropertyValue: (property) => map[property] ?? '' });

test('paddingPx and borderPx digest per-side px', () => {
  const computed = fakeComputed({
    'padding-top': '12px', 'padding-right': '0px', 'padding-bottom': '24.5px', 'padding-left': '8px',
    'border-top-width': '2px', 'border-right-width': '0px',
    'border-bottom-width': '2px', 'border-left-width': '0px',
  });
  assert.deepEqual(paddingPx(computed), { top: 12, right: 0, bottom: 24.5, left: 8 });
  assert.deepEqual(borderPx(computed), { top: 2, right: 0, bottom: 2, left: 0 });
});

test('marginPx keeps signs and digests non-lengths to null', () => {
  assert.deepEqual(
    marginPx(fakeComputed({
      'margin-top': '12px', 'margin-right': '-16px', 'margin-bottom': 'auto', 'margin-left': '0px',
    })),
    { top: 12, right: -16, bottom: null, left: 0 },
  );
});

test('gapPx treats normal (and anything non-length) as zero', () => {
  assert.deepEqual(
    gapPx(fakeComputed({ 'row-gap': '16px', 'column-gap': 'normal' })),
    { row: 16, column: 0 },
  );
  assert.deepEqual(gapPx(fakeComputed({})), { row: 0, column: 0 });
});

// --- borderBandRects ------------------------------------------------------

test('borderBandRects rings the border box down to the padding box', () => {
  const bands = borderBandRects(rect(100, 50, 200, 100), { top: 5, right: 2, bottom: 0, left: 10 });
  assert.deepEqual(bands.top, rect(100, 50, 200, 5));
  // Corners belong to top/bottom; a borderless side is a zero-thickness rect.
  assert.deepEqual(bands.bottom, rect(100, 150, 200, 0));
  assert.deepEqual(bands.left, rect(100, 55, 10, 95));
  assert.deepEqual(bands.right, rect(298, 55, 2, 95));
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
