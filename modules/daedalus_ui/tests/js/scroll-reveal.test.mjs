/**
 * Self-asserting tests for the selection reveal rule's pure half
 * (js/scroll-reveal-model.js revealBlock), the one decision every surface
 * that reveals the selection (the canvas from a panel gesture, the page
 * elements row, the code view's row) applies through scrollIntoView.
 *
 * Covered: an element that fits its scrollport centers; one taller than
 * the scrollport aligns its top edge (start); the scroll margins count as
 * part of the element for both the fit and the visibility reading (an
 * element under the fixed chrome's margin is not in view; margins can
 * make a short element too tall to center); an element already fully in
 * view (margins included) does not move; the scrollport's own offset (a
 * panel body below the window's top) is honored. The DOM host
 * (js/scroll-reveal.js) measures the element, its margins and its nearest
 * scrolling ancestor, a browser concern covered by the FJS tier.
 *
 * Run with `node --test tests/js/*.test.mjs` from the module root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';

const { revealBlock } = await import('../../js/scroll-reveal-model.js');

const port = { top: 0, height: 800 };

test('an element out of view that fits the scrollport centers', () => {
  assert.equal(revealBlock({ top: 1200, height: 300, scrollport: port }), 'center');
  assert.equal(revealBlock({ top: -500, height: 300, scrollport: port }), 'center');
});

test('an element taller than the scrollport aligns its top edge', () => {
  assert.equal(revealBlock({ top: 900, height: 1000, scrollport: port }), 'start');
  assert.equal(revealBlock({ top: -200, height: 1000, scrollport: port }), 'start');
});

test('a partly visible element is revealed whole: centered when it fits', () => {
  // Its bottom past the scrollport's edge.
  assert.equal(revealBlock({ top: 700, height: 300, scrollport: port }), 'center');
  // Its top above the scrollport's edge.
  assert.equal(revealBlock({ top: -100, height: 300, scrollport: port }), 'center');
});

test('an element fully in view does not move', () => {
  assert.equal(revealBlock({ top: 100, height: 300, scrollport: port }), null);
  // Exactly filling the scrollport is in view.
  assert.equal(revealBlock({ top: 0, height: 800, scrollport: port }), null);
});

test('the scroll margins count as part of the element', () => {
  const margins = { marginTop: 80, marginBottom: 160 };
  // Inside the viewport but under the fixed chrome's margin: not in view.
  assert.equal(
    revealBlock({ top: 40, height: 300, scrollport: port, ...margins }),
    'center',
  );
  // Inside the viewport but under the bottom margin: not in view.
  assert.equal(
    revealBlock({ top: 400, height: 300, scrollport: port, ...margins }),
    'center',
  );
  // Clear of both margins: in view.
  assert.equal(
    revealBlock({ top: 100, height: 300, scrollport: port, ...margins }),
    null,
  );
  // Short enough alone, too tall with its margins: the top edge aligns.
  assert.equal(
    revealBlock({ top: 900, height: 700, scrollport: port, ...margins }),
    'start',
  );
});

test('a scrollport below the window top is measured from its own edge', () => {
  const panel = { top: 200, height: 600 };
  assert.equal(revealBlock({ top: 250, height: 40, scrollport: panel }), null);
  // Above the panel's top edge (inside the window) is out of the panel's view.
  assert.equal(revealBlock({ top: 150, height: 40, scrollport: panel }), 'center');
  // Below the panel's bottom edge.
  assert.equal(revealBlock({ top: 790, height: 40, scrollport: panel }), 'center');
  assert.equal(revealBlock({ top: 900, height: 700, scrollport: panel }), 'start');
});
