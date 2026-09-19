/**
 * Self-asserting tests for element drop targets (element-targets.js).
 *
 * The second target kind beside gaps: a source's `elements` face declares
 * what elements it can land on; resolution claims the element's INTERIOR
 * and yields its edge bands (min(28px, 25% of the extent) per axis) to gap
 * resolution, with a grow parameter for the controller's hysteresis
 * stickiness. Targets carry {kind: 'element', element, label, gapId} with
 * a stable per-element gapId.
 *
 * The module is browser code with an `instanceof Element` guard — install
 * the stub Element global BEFORE import, like gaps.test.mjs.
 *
 * Run with `node --test tests/js/*.test.mjs` from the module root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { registerHooks } from 'node:module';
import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

registerHooks({
  resolve(specifier, context, nextResolve) {
    try {
      return nextResolve(specifier, context);
    } catch (error) {
      if (
        (specifier.startsWith('.') || specifier.startsWith('/')) &&
        existsSync(fileURLToPath(new URL(`${specifier}.js`, context.parentURL)))
      ) {
        return nextResolve(`${specifier}.js`, context);
      }
      throw error;
    }
  },
});

/**
 * A stub element: attribute presence, a rect, and just enough DOM surface
 * for element-targets.js (matches, closest, getBoundingClientRect).
 */
class El {
  constructor(rect, attrs = {}) {
    this.rect = rect;
    this.attrs = attrs;
    this.parentElement = null;
    this.children = [];
  }

  add(child) {
    child.parentElement = this;
    this.children.push(child);
    return child;
  }

  matches(selector) {
    const presence = selector.match(/^\[([a-z-]+)\]$/);
    return presence ? presence[1] in this.attrs : false;
  }

  closest(selector) {
    for (let node = this; node; node = node.parentElement) {
      if (node.matches(selector)) {
        return node;
      }
    }
    return null;
  }

  getBoundingClientRect() {
    const { left, top, width, height } = this.rect;
    return { left, top, width, height, right: left + width, bottom: top + height };
  }
}

globalThis.Element = El;

const { matchElementTarget, elementInterior, elementTargetAt } =
  await import('../../js/edit_mode/drag/element-targets.js');

// A 400x200 media element at (100, 100) with an inner img, inside a plain
// block wrapper. Bands: x = min(28, 100) = 28, y = min(28, 50) = 28.
const buildFixture = () => {
  const block = new El({ left: 0, top: 0, width: 600, height: 400 });
  const media = block.add(new El({ left: 100, top: 100, width: 400, height: 200 }, { 'data-media-reference': '42' }));
  const img = media.add(new El({ left: 100, top: 100, width: 400, height: 200 }));
  return { block, media, img };
};

const spec = { elements: { selector: '[data-media-reference]' } };

test('matchElementTarget: the closest declared element claims the cursor target', () => {
  const { media, img, block } = buildFixture();
  assert.equal(matchElementTarget(img, spec, {}), media);
  assert.equal(matchElementTarget(media, spec, {}), media);
  assert.equal(matchElementTarget(block, spec, {}), null);
  assert.equal(matchElementTarget(null, spec, {}), null);
  assert.equal(matchElementTarget(img, { elements: undefined }, {}), null);
});

test('matchElementTarget: the accepts filter rejects incompatible elements', () => {
  const { img, media } = buildFixture();
  const data = { extension: '.pdf' };
  const filtered = {
    elements: {
      selector: '[data-media-reference]',
      accepts: (element, dragData) => element === media && dragData.extension === '.png',
    },
  };
  assert.equal(matchElementTarget(img, filtered, data), null);
  assert.equal(matchElementTarget(img, filtered, { extension: '.png' }), media);
});

test('elementInterior: interior claims, edge bands yield', () => {
  const { media } = buildFixture();
  // Dead center.
  assert.equal(elementInterior(media, 300, 200), true);
  // Just inside the band boundary (band = 28).
  assert.equal(elementInterior(media, 130, 200), true);
  // In the left band.
  assert.equal(elementInterior(media, 110, 200), false);
  // In the top band although x is interior.
  assert.equal(elementInterior(media, 300, 110), false);
  // Outside the rect entirely.
  assert.equal(elementInterior(media, 50, 200), false);
});

test('elementInterior: the band is 25% of a small extent, capped at 28px', () => {
  // 40px tall: y band = min(28, 10) = 10 per edge, leaving a 20px interior.
  const small = new El({ left: 0, top: 0, width: 400, height: 40 }, { 'data-media-reference': '1' });
  assert.equal(elementInterior(small, 200, 20), true);
  assert.equal(elementInterior(small, 200, 5), false);
  assert.equal(elementInterior(small, 200, 12), true);
});

test('elementInterior: grow extends the interior into the band, never past the rect', () => {
  const { media } = buildFixture();
  // 110 sits in the left band (band 28); grow 20 shrinks it to 8, so the
  // current target keeps its claim there.
  assert.equal(elementInterior(media, 110, 200, 20), true);
  assert.equal(elementInterior(media, 105, 200, 20), false);
  // grow beyond the band clamps at the rect edge — no claim outside it.
  assert.equal(elementInterior(media, 100, 200, 100), true);
  assert.equal(elementInterior(media, 99, 200, 100), false);
});

test('elementTargetAt: interior resolves the element target shape', () => {
  const { img, media } = buildFixture();
  const target = elementTargetAt(img, 300, 200, spec, {});
  assert.equal(target.kind, 'element');
  assert.equal(target.element, media);
  assert.equal(target.label, null);
  assert.match(target.gapId, /^element\|\d+$/);
});

test('elementTargetAt: edge band and non-candidates fall through (null)', () => {
  const { img, block } = buildFixture();
  assert.equal(elementTargetAt(img, 110, 200, spec, {}), null);
  assert.equal(elementTargetAt(block, 20, 20, spec, {}), null);
});

test('elementTargetAt: gapId is stable per element, distinct across elements', () => {
  const { img, media, block } = buildFixture();
  const other = block.add(new El({ left: 0, top: 300, width: 400, height: 100 }, { 'data-media-reference': '43' }));
  const first = elementTargetAt(img, 300, 200, spec, {});
  const again = elementTargetAt(media, 300, 200, spec, {});
  const different = elementTargetAt(other, 200, 350, spec, {});
  assert.equal(first.gapId, again.gapId);
  assert.notEqual(first.gapId, different.gapId);
});

test('elementTargetAt: label resolves from a string or a function', () => {
  const { img } = buildFixture();
  const withString = { elements: { selector: '[data-media-reference]', label: 'Replace media' } };
  const withFunction = {
    elements: {
      selector: '[data-media-reference]',
      label: (element, data) => `Replace ${element.attrs['data-media-reference']} with ${data.name}`,
    },
  };
  assert.equal(elementTargetAt(img, 300, 200, withString, {}).label, 'Replace media');
  assert.equal(
    elementTargetAt(img, 300, 200, withFunction, { name: 'cat.png' }).label,
    'Replace 42 with cat.png',
  );
});
