/**
 * @file
 * Locks the ruler's pure band vocabulary (ruler-bands.js): contributed-
 * entry normalization, band-edge tick derivation, the containing-band
 * lookup the effective-width highlight rides, and the constrained rule
 * that decides whether the ruler shows at all.
 * Run with: node --test tests/js/ruler-bands.test.mjs
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import {
  normalizeBands,
  bandEdgeTicks,
  bandFor,
  constrained,
} from '../../js/canvas_vector/ruler-bands.js';

// The shipped base-at-top vocabulary a daedalus_stylish contributor pushes:
// narrow ≤767, medium 768-1023, the space above bandless (base).
const SHIPPED = [
  { max: 767, label: 'Narrow', name: 'narrow' },
  { min: 768, max: 1023, label: 'Medium', name: 'medium' },
];

test('contributed bands normalize and sort ascending', () => {
  const bands = normalizeBands([
    { min: 768, max: 1023, label: 'Medium', name: 'medium' },
    { max: 767, label: 'Narrow', name: 'narrow' },
  ]);
  assert.deepEqual(bands, [
    { label: 'Narrow', name: 'narrow', max: 767 },
    { label: 'Medium', name: 'medium', min: 768, max: 1023 },
  ]);
});

test('an entry bounding nothing is dropped, junk edges are ignored', () => {
  assert.deepEqual(normalizeBands([{ label: 'Nothing', name: 'nope' }]), []);
  assert.deepEqual(
    normalizeBands([{ min: 'abc', max: -5, label: 'Junk', name: 'junk' }]),
    [],
  );
  assert.deepEqual(normalizeBands(undefined), []);
  // A junk min beside a real max keeps the band, ceiling only.
  assert.deepEqual(
    normalizeBands([{ min: NaN, max: 500, label: 'Half', name: 'half' }]),
    [{ label: 'Half', name: 'half', max: 500 }],
  );
});

test('two unbounded-max bands still sort stably by floor', () => {
  const bands = normalizeBands([
    { min: 1920, label: 'Huge', name: 'huge' },
    { min: 1280, label: 'Big', name: 'big' },
  ]);
  assert.deepEqual(bands.map((band) => band.name), ['big', 'huge']);
});

test('every finite ceiling ticks; an abutting floor does not re-tick', () => {
  // medium's floor 768 abuts narrow's ceiling 767: one boundary, one tick.
  assert.deepEqual(bandEdgeTicks(normalizeBands(SHIPPED)), [767, 1023]);
});

test('a non-abutting floor ticks on its own', () => {
  const bands = normalizeBands([
    { max: 500, label: 'Small', name: 'small' },
    { min: 1920, label: 'Huge', name: 'huge' },
  ]);
  assert.deepEqual(bandEdgeTicks(bands), [500, 1920]);
});

test('bandFor finds the containing band, inclusive on both edges', () => {
  const bands = normalizeBands(SHIPPED);
  assert.equal(bandFor(bands, 375)?.name, 'narrow');
  assert.equal(bandFor(bands, 767)?.name, 'narrow');
  assert.equal(bandFor(bands, 768)?.name, 'medium');
  assert.equal(bandFor(bands, 1023)?.name, 'medium');
});

test('above every band, and with no width at all, nothing matches', () => {
  const bands = normalizeBands(SHIPPED);
  // 1024 is the bandless space above the ceilings: the contributor's
  // unconditioned base, which the ruler leaves unlit.
  assert.equal(bandFor(bands, 1024), null);
  assert.equal(bandFor(bands, null), null);
  assert.equal(bandFor(bands, undefined), null);
});

test('a floor-only band matches upward without bound', () => {
  const bands = normalizeBands([{ min: 1920, label: 'Huge', name: 'huge' }]);
  assert.equal(bandFor(bands, 5000)?.name, 'huge');
  assert.equal(bandFor(bands, 1919), null);
});

test('a posed width always constrains, even in the bandless base space', () => {
  const bands = normalizeBands(SHIPPED);
  assert.equal(constrained(bands, 375, 1400), true);
  // Posing 1200 on a 1400px window lands in the bandless base space and
  // STILL constrains: a scrub crossing base's floor must not blink the
  // ruler away.
  assert.equal(constrained(bands, 1200, 1400), true);
  // Even with no bands contributed at all, a pose is a constraint.
  assert.equal(constrained([], 375, 1400), true);
});

test('unposed, only a stage measured inside a band constrains', () => {
  const bands = normalizeBands(SHIPPED);
  assert.equal(constrained(bands, null, 900), true);
  assert.equal(constrained(bands, null, 500), true);
  // At or above base's floor the stage is at its natural full width in
  // the bandless base: nothing constrains, the ruler rests.
  assert.equal(constrained(bands, null, 1024), false);
  assert.equal(constrained(bands, null, 1400), false);
  // Nothing measured yet, nothing posed: nothing to say.
  assert.equal(constrained(bands, null, null), false);
  // No bands contributed: there is no band for the stage to sit in.
  assert.equal(constrained([], null, 500), false);
});
