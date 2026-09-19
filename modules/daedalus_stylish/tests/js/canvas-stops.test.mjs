/**
 * @file
 * Locks the canvas width-band projection (canvas-stops.js): the payload's
 * structured `band` facts become ruler stops; the wrapper text is never
 * read.
 * Run with: node --test tests/js/canvas-stops.test.mjs
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { stopsFromConditions, stopPosePx } from '../../js/src/canvas-stops.js';

test('width conditions with a band fact project to sorted bands', () => {
  const stops = stopsFromConditions({
    default: 'always applies',
    medium: { label: 'Medium', wrapper: '@container (min-width: 768px) and (max-width: 1023px)', axis: 'width', band: { min: 768, max: 1023 } },
    narrow: { label: 'Narrow', wrapper: '@container (max-width: 767px)', axis: 'width', band: { max: 767 } },
  });
  assert.deepEqual(stops, [
    { label: 'Narrow', name: 'narrow', max: 767 },
    { label: 'Medium', name: 'medium', min: 768, max: 1023 },
  ]);
});

test('a floor-only band fact projects a floor-only band', () => {
  const stops = stopsFromConditions({
    huge: { label: 'Huge', wrapper: '@container (min-width: 1920px)', axis: 'width', band: { min: 1920 } },
  });
  assert.deepEqual(stops, [{ label: 'Huge', name: 'huge', min: 1920 }]);
});

test('conditions without a band fact do not register, whatever their wrapper says', () => {
  const stops = stopsFromConditions({
    // A site-provided viewport vocabulary: canvas resize cannot
    // re-evaluate @media, so its type declares no band and it claims none.
    mobile: { label: 'Mobile', wrapper: '@media (max-width: 767px)', axis: 'width' },
    // A container wrapper whose type declared no band registers nothing
    // either: the fact is the contract, the wrapper text is the printer's.
    bare: { label: 'Bare', wrapper: '@container (max-width: 500px)', axis: 'width' },
    // Other axes never register, band or not.
    dark: { label: 'Dark mode', wrapper: '@media (prefers-color-scheme: dark)', axis: 'scheme', preference: { feature: 'prefers-color-scheme', value: 'dark' } },
    hover: { label: 'Hover', wrapper: ':hover', axis: 'state' },
    odd: { label: 'Odd', wrapper: '@media (prefers-color-scheme: dark)', axis: 'scheme', band: { max: 100 } },
  });
  assert.deepEqual(stops, []);
});

test('a band fact with no finite edge registers nothing', () => {
  const stops = stopsFromConditions({
    empty: { label: 'Empty', wrapper: '@container (max-width: 500px)', axis: 'width', band: {} },
    bogus: { label: 'Bogus', wrapper: '@container (max-width: 500px)', axis: 'width', band: { min: 'wide', max: null } },
  });
  assert.deepEqual(stops, []);
});

test('configured thresholds ride the band fact, whatever the wrapper spelling', () => {
  // A named query container spelling the old wrapper regex never matched:
  // the band fact carries the edges regardless.
  const stops = stopsFromConditions({
    narrow: { label: 'Narrow', wrapper: '@container page (max-width: 720px)', axis: 'width', band: { max: 720 } },
    medium: { label: 'Medium', wrapper: '@container page (min-width: 721px) and (max-width: 1100px)', axis: 'width', band: { min: 721, max: 1100 } },
  });
  assert.deepEqual(stops[0], { label: 'Narrow', name: 'narrow', max: 720 });
  assert.deepEqual(stops[1], { label: 'Medium', name: 'medium', min: 721, max: 1100 });
});

test('an empty or missing table projects to no bands', () => {
  assert.deepEqual(stopsFromConditions(undefined), []);
  assert.deepEqual(stopsFromConditions({}), []);
});

test('a band pick poses the midpoint, a floor-only band its floor', () => {
  // narrow 0..767 and medium 768..1023, the shipped base-at-top bands.
  assert.equal(stopPosePx({ max: 767 }), 384);
  assert.equal(stopPosePx({ min: 768, max: 1023 }), 896);
  assert.equal(stopPosePx({ min: 1920 }), 1920);
});
