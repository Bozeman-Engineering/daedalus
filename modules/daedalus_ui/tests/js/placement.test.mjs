/**
 * Self-asserting tests for the floating surface's placement math
 * (js/popover/placement.js), the pure half of the pop-up primitive every
 * chrome pop-up rides (menus, wells, pickers).
 *
 * Covered: pointer placement (clampPosition: in place, pulled back at
 * the far edges, pinned at the margin when oversized); anchored
 * placement (anchoredPosition: hanging below with start edges aligned,
 * end alignment for a trigger at the inline end of its panel, the flip
 * above when only above fits, the clamp when neither side fits, and the
 * interplay of end alignment with the flip and the clamp); the side
 * preference (side: 'above' hangs over the anchor, flips below when
 * above is short, stays above clamped when neither fits; the default
 * side is 'below' and unchanged by the grammar's arrival); the explicit
 * side and align grammars (anything but 'below'/'above' or
 * 'start'/'end' throws). The plain-DOM
 * host (js/popover/popover.js) measures and applies this; its dismissal,
 * Escape, and focus-return are DOM concerns covered by the FJS tier.
 *
 * Run with `node --test tests/js/*.test.mjs` from the module root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';

const { clampPosition, anchoredPosition } = await import('../../js/popover/placement.js');

const viewport = { viewportWidth: 1000, viewportHeight: 800 };
const size = { width: 200, height: 150 };

test('pointer placement: fits in place, pulls back at the far edges, never past the margin', () => {
  assert.deepEqual(
    clampPosition({ x: 100, y: 100, ...size, ...viewport }),
    { left: 100, top: 100 },
  );
  assert.deepEqual(
    clampPosition({ x: 950, y: 780, ...size, ...viewport }),
    { left: 1000 - 200 - 4, top: 800 - 150 - 4 },
    'a pointer near the bottom-right corner: pulled back inside the margin',
  );
  assert.deepEqual(
    clampPosition({ x: 0, y: 0, width: 2000, height: 2000, ...viewport }),
    { left: 4, top: 4 },
    'an oversized surface pins to the top-left margin',
  );
  assert.deepEqual(
    clampPosition({ x: 500, y: 400, ...size, ...viewport, margin: 10 }),
    { left: 500, top: 400 },
    'the margin is a parameter',
  );
  assert.deepEqual(
    clampPosition({ x: 995, y: 795, ...size, ...viewport, margin: 10 }),
    { left: 1000 - 200 - 10, top: 800 - 150 - 10 },
  );
});

test('anchored, start: hangs below the anchor with left edges aligned', () => {
  const rect = { left: 100, right: 130, top: 100, bottom: 130 };
  assert.deepEqual(
    anchoredPosition({ rect, ...size, ...viewport }),
    { left: 100, top: 132 },
    'the default: start alignment, the default 2px gap',
  );
  assert.deepEqual(
    anchoredPosition({ rect, align: 'start', ...size, ...viewport }),
    { left: 100, top: 132 },
    'explicit start is the default',
  );
  assert.deepEqual(
    anchoredPosition({ rect, ...size, ...viewport, gap: 8 }),
    { left: 100, top: 138 },
    'the gap is a parameter',
  );
});

test('anchored, end: hangs below the anchor with right edges aligned', () => {
  // A kebab at the inline end of a 300px panel: its surface hangs
  // inward, over the panel, not outward over the canvas.
  const rect = { left: 270, right: 300, top: 100, bottom: 130 };
  assert.deepEqual(
    anchoredPosition({ rect, align: 'end', ...size, ...viewport }),
    { left: 300 - 200, top: 132 },
    'the surface ends where the anchor ends',
  );
  assert.deepEqual(
    anchoredPosition({ rect, align: 'start', ...size, ...viewport }),
    { left: 270, top: 132 },
    'start alignment on the same anchor hangs outward: align is an intent, not a layout inference',
  );
});

test('anchored: flips above when only above fits', () => {
  const rect = { left: 100, right: 130, top: 700, bottom: 730 };
  assert.deepEqual(
    anchoredPosition({ rect, ...size, ...viewport }),
    { left: 100, top: 700 - 2 - 150 },
    'no room below: above the anchor, the gap on the other side',
  );
  assert.deepEqual(
    anchoredPosition({ rect: { left: 600, right: 900, top: 700, bottom: 730 }, align: 'end', ...size, ...viewport }),
    { left: 900 - 200, top: 700 - 2 - 150 },
    'end alignment and the flip compose: right edges aligned, above',
  );
  assert.deepEqual(
    anchoredPosition({ rect, align: 'end', ...size, ...viewport }),
    { left: 4, top: 700 - 2 - 150 },
    'end alignment flips the same way, and a surface wider than the anchor is from the left edge clamps to the margin',
  );
});

test('anchored: the below/above threshold counts the gap and the margin', () => {
  // Below fits when bottom + gap + height + margin <= viewportHeight:
  // 644 + 2 + 150 + 4 = 800 fits exactly; one more pixel does not.
  assert.deepEqual(
    anchoredPosition({ rect: { left: 100, right: 130, top: 614, bottom: 644 }, ...size, ...viewport }),
    { left: 100, top: 646 },
    'fits below exactly',
  );
  assert.deepEqual(
    anchoredPosition({ rect: { left: 100, right: 130, top: 615, bottom: 645 }, ...size, ...viewport }),
    { left: 100, top: 615 - 2 - 150 },
    'one pixel short below, room above: flips',
  );
});

test('anchored: clamps into the viewport when neither side fits', () => {
  const rect = { left: 100, right: 130, top: 100, bottom: 130 };
  assert.deepEqual(
    anchoredPosition({ rect, width: 200, height: 700, ...viewport }),
    { left: 100, top: 800 - 700 - 4 },
    'room neither side: below, clamped into the viewport',
  );
  assert.deepEqual(
    anchoredPosition({ rect: { left: 900, right: 930, top: 100, bottom: 130 }, ...size, ...viewport }),
    { left: 1000 - 200 - 4, top: 132 },
    'start alignment near the right edge: pulled in from the right',
  );
  assert.deepEqual(
    anchoredPosition({ rect: { left: 20, right: 50, top: 100, bottom: 130 }, align: 'end', ...size, ...viewport }),
    { left: 4, top: 132 },
    'end alignment near the left edge: pulled in from the left',
  );
  assert.deepEqual(
    anchoredPosition({ rect: { left: 700, right: 1000, top: 100, bottom: 130 }, align: 'end', width: 2000, height: 2000, ...viewport }),
    { left: 4, top: 4 },
    'an oversized surface pins to the top-left margin whatever the alignment',
  );
});

test('anchored, side above: hangs over the anchor when above fits', () => {
  // A widget popover over a selection chip mid-viewport: it must never
  // cover the editing surface under the chip, so it prefers above even
  // though below has plenty of room.
  const rect = { left: 100, right: 130, top: 400, bottom: 430 };
  assert.deepEqual(
    anchoredPosition({ rect, side: 'above', ...size, ...viewport }),
    { left: 100, top: 400 - 2 - 150 },
    'above preferred and fitting: over the anchor, the gap between',
  );
  assert.deepEqual(
    anchoredPosition({ rect, side: 'above', ...size, ...viewport, gap: 6 }),
    { left: 100, top: 400 - 6 - 150 },
    'the gap applies on the above side too',
  );
  assert.deepEqual(
    anchoredPosition({ rect: { left: 600, right: 900, top: 400, bottom: 430 }, side: 'above', align: 'end', ...size, ...viewport }),
    { left: 900 - 200, top: 400 - 2 - 150 },
    'side and align compose: right edges aligned, above',
  );
});

test('anchored, side above: flips below when above is short', () => {
  // The reported bug: a chip just under the top rail, whose popover
  // has no room above; it lands below the chip instead of under the bar.
  const rect = { left: 100, right: 130, top: 60, bottom: 90 };
  assert.deepEqual(
    anchoredPosition({ rect, side: 'above', ...size, ...viewport }),
    { left: 100, top: 92 },
    'no room above: below the anchor, the gap on the other side',
  );
  // The above threshold counts the gap and the margin: top - gap - height
  // >= margin; 156 - 2 - 150 = 4 fits exactly, one pixel less does not.
  assert.deepEqual(
    anchoredPosition({ rect: { left: 100, right: 130, top: 156, bottom: 186 }, side: 'above', ...size, ...viewport }),
    { left: 100, top: 4 },
    'fits above exactly',
  );
  assert.deepEqual(
    anchoredPosition({ rect: { left: 100, right: 130, top: 155, bottom: 185 }, side: 'above', ...size, ...viewport }),
    { left: 100, top: 187 },
    'one pixel short above, room below: flips',
  );
  // 90 + 2 + 750 + 4 = 846 > 800: no room below either.
  assert.deepEqual(
    anchoredPosition({ rect, side: 'above', width: 200, height: 750, ...viewport }),
    { left: 100, top: 4 },
    'room neither side: stays above, clamped to the top margin',
  );
});

test('anchored, side below: the default is unchanged by the side grammar', () => {
  const rect = { left: 100, right: 130, top: 100, bottom: 130 };
  assert.deepEqual(
    anchoredPosition({ rect, side: 'below', ...size, ...viewport }),
    anchoredPosition({ rect, ...size, ...viewport }),
    'explicit below is the default',
  );
  assert.deepEqual(
    anchoredPosition({ rect, side: 'below', ...size, ...viewport }),
    { left: 100, top: 132 },
    'below preferred and fitting: under the anchor',
  );
  assert.deepEqual(
    anchoredPosition({ rect: { left: 100, right: 130, top: 700, bottom: 730 }, side: 'below', ...size, ...viewport }),
    { left: 100, top: 700 - 2 - 150 },
    'below preferred and short: flips above, as before',
  );
});

test('anchored: side is an explicit grammar; anything else throws', () => {
  const rect = { left: 100, right: 130, top: 100, bottom: 130 };
  assert.throws(
    () => anchoredPosition({ rect, side: 'top', ...size, ...viewport }),
    /sides 'below' or 'above', not "top"/,
  );
  assert.throws(
    () => anchoredPosition({ rect, side: null, ...size, ...viewport }),
    /sides 'below' or 'above'/,
  );
});

test('anchored: align is an explicit grammar; anything else throws', () => {
  const rect = { left: 100, right: 130, top: 100, bottom: 130 };
  assert.throws(
    () => anchoredPosition({ rect, align: 'center', ...size, ...viewport }),
    /aligns 'start' or 'end', not "center"/,
  );
  assert.throws(
    () => anchoredPosition({ rect, align: null, ...size, ...viewport }),
    /aligns 'start' or 'end'/,
  );
});
