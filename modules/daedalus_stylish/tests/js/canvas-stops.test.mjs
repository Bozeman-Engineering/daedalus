/**
 * @file
 * Locks the canvas width-band projection (canvas-stops.js): a width-axis
 * condition's wrapper becomes a ruler stop through the clause grammar's
 * derived band (condition-clause.js clauseBand), the one reader of the
 * wrapper text.
 * Run with: node --test tests/js/canvas-stops.test.mjs
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { stopsFromConditions, stopPosePx, bandRangeText, literalStops } from '../../js/src/canvas-stops.js';
import { parseClause } from '../../../daedalus_ui/js/edit_mode/materials/condition-clause.js';

test('container width conditions project to sorted bands derived from their wrappers', () => {
  const stops = stopsFromConditions({
    default: 'always applies',
    medium: { label: 'Medium', wrapper: '@container (min-width: 768px) and (max-width: 1023px)', axis: 'width' },
    narrow: { label: 'Narrow', wrapper: '@container (max-width: 767px)', axis: 'width' },
  });
  assert.deepEqual(stops, [
    { label: 'Narrow', name: 'narrow', max: 767 },
    { label: 'Medium', name: 'medium', min: 768, max: 1023 },
  ]);
});

test('a floor-only wrapper projects a floor-only band', () => {
  const stops = stopsFromConditions({
    huge: { label: 'Huge', wrapper: '@container (min-width: 1920px)', axis: 'width' },
  });
  assert.deepEqual(stops, [{ label: 'Huge', name: 'huge', min: 1920 }]);
});

test('conditions whose wrapper derives no band do not register', () => {
  const stops = stopsFromConditions({
    // A site-provided viewport vocabulary: canvas resize cannot
    // re-evaluate @media, so a @media width query derives no band.
    mobile: { label: 'Mobile', wrapper: '@media (max-width: 767px)', axis: 'width' },
    // An edge in a unit the canvas cannot pose in px derives none.
    ems: { label: 'Ems', wrapper: '@container (max-width: 40em)', axis: 'width' },
    // Other axes never register, whatever their wrapper.
    dark: { label: 'Dark mode', wrapper: '@media (prefers-color-scheme: dark)', axis: 'scheme' },
    hover: { label: 'Hover', wrapper: ':hover', axis: 'state' },
    odd: { label: 'Odd', wrapper: '@container (max-width: 100px)', axis: 'scheme' },
  });
  assert.deepEqual(stops, []);
});

test('configured thresholds ride the wrapper, a named query container included', () => {
  const stops = stopsFromConditions({
    narrow: { label: 'Narrow', wrapper: '@container page (max-width: 720px)', axis: 'width' },
    medium: { label: 'Medium', wrapper: '@container page (min-width: 721px) and (max-width: 1100px)', axis: 'width' },
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

test('a band\'s range text: both edges, a ceiling, a floor', () => {
  assert.equal(bandRangeText({ min: 400, max: 480 }), '400-480px');
  assert.equal(bandRangeText({ max: 767 }), '≤ 767px');
  assert.equal(bandRangeText({ min: 1920 }), '≥ 1920px');
});

test('literal width clauses in use project to ruler bands named by their text', () => {
  const literals = [
    parseClause('@container (min-width: 400px) and (max-width: 480px)', {}),
    // No band: a @media width, a preference, a state project nothing.
    parseClause('@media (min-width: 900px)', {}),
    parseClause('@media (prefers-contrast: more)', {}),
    parseClause(':checked', {}),
    parseClause('@container (min-width: 1200px)', {}),
  ];
  assert.deepEqual(literalStops(literals), [
    { min: 400, max: 480, label: '400-480px', name: '@container (min-width: 400px) and (max-width: 480px)' },
    { min: 1200, label: '≥ 1200px', name: '@container (min-width: 1200px)' },
  ]);
  assert.deepEqual(literalStops([]), []);
  assert.deepEqual(literalStops(undefined), []);
});
