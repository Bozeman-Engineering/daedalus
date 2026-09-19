/**
 * @file
 * Locks the swatch well's pure half (swatch-well-model.js): the well's
 * face derivation. The popover's palette grouping moved to the shared
 * grid (palette-picker-model.test.mjs). Run with:
 * node --test tests/js/*.test.mjs
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { wellFace } from '../../js/src/widgets/lib/swatch-well-model.js';

const stops = [
  { id: 'var(--color-accent)', value: 'var(--color-accent)', label: 'accent', paint: '#1a1a2e', paintDark: '#e8e8f0', group: 'Color' },
  { id: 'var(--color-surface)', value: 'var(--color-surface)', label: 'surface', paint: '#ffffff', paintDark: null, group: 'Color' },
  { id: 'var(--swatch-brand_red)', value: 'var(--swatch-brand_red)', label: 'Brand red', paint: '#b91c1c', paintDark: null, group: 'Colors' },
  { id: 'var(--swatch-hero_fade)', value: 'var(--swatch-hero_fade)', label: 'Hero fade', paint: 'linear-gradient(#000, #fff)', paintDark: null, group: 'Gradients' },
];

test('wellFace: the selected stop is the face', () => {
  assert.deepEqual(wellFace(stops, { selected: 'var(--color-accent)', custom: false }), { kind: 'stop', stop: stops[0] });
  assert.deepEqual(wellFace(stops, { selected: 'var(--swatch-hero_fade)', custom: false }), { kind: 'stop', stop: stops[3] });
});

test('wellFace: the synthetic media stop wears the media face, not stop', () => {
  // widget-gradients.js mediaImageStop flags itself; the well renders the
  // thumbnail (or photo glyph) instead of a Face paint, so the kind must
  // say so. Custom still outranks: the media posture only exists where
  // the state read lit the stop.
  const media = { id: 'media:abc', value: "url('media:abc')", label: 'Media image', paint: 'url("/files/hero.png") center / cover', paintDark: null, group: 'Image', media: true, thumbnail: '/files/hero.png' };
  assert.deepEqual(wellFace([...stops, media], { selected: 'media:abc', custom: false }), { kind: 'media', stop: media });
  assert.deepEqual(wellFace([...stops, media], { selected: null, custom: true }), { kind: 'custom', stop: null });
});

test('wellFace: custom is the honest face for an unrepresentable value', () => {
  assert.deepEqual(wellFace(stops, { selected: null, custom: true }), { kind: 'custom', stop: null });
});

test('wellFace: no selection and no custom is the empty face', () => {
  assert.deepEqual(wellFace(stops, { selected: null, custom: false }), { kind: 'unset', stop: null });
  assert.deepEqual(wellFace(stops, { selected: undefined, custom: false }), { kind: 'unset', stop: null });
  // A selection the table no longer holds degrades to unset, never a
  // crash: the state read and the stop table always derive together, so
  // this is a belt for transient renders only.
  assert.deepEqual(wellFace(stops, { selected: 'var(--gone)', custom: false }), { kind: 'unset', stop: null });
  assert.deepEqual(wellFace([], { selected: null, custom: false }), { kind: 'unset', stop: null });
});

test('wellFace: a lone inline color wears the painted literal face', () => {
  // The state read's `literal` (a color the grammar calls literal, alone
  // on its property) paints as itself under the custom marker; the "?"
  // custom face stays for compounds and unknown references (literal
  // null). Custom still outranks any selection.
  assert.deepEqual(wellFace(stops, { selected: null, custom: true, literal: '#ff8800' }), { kind: 'literal', stop: null, literal: '#ff8800' });
  assert.deepEqual(wellFace(stops, { selected: null, custom: true, literal: null }), { kind: 'custom', stop: null });
  assert.deepEqual(wellFace(stops, { selected: null, custom: true, literal: '' }), { kind: 'custom', stop: null });
  assert.deepEqual(wellFace(stops, { selected: 'var(--color-accent)', custom: true, literal: '#ff8800' }), { kind: 'literal', stop: null, literal: '#ff8800' });
});

test('wellFace: a ghost matching a stop wears that stop, muted', () => {
  // The resting value beneath an unset row (choice-row-model.js
  // restingValue, raw) lights the stop the way a committed value would:
  // by its commit spelling, its id, or a normalized equivalent in the
  // stop's matches. The kind says ghost, never stop, so the well renders
  // it muted and the grid keeps nothing lit.
  assert.deepEqual(wellFace(stops, { selected: null, custom: false }, 'var(--color-accent)'), { kind: 'ghost', stop: stops[0] });
  assert.deepEqual(wellFace(stops, { selected: undefined, custom: false }, 'var(--swatch-brand_red)'), { kind: 'ghost', stop: stops[2] });
  const matched = [{ ...stops[1], matches: ['var(--color-surface)', '#ffffff', '#fff'] }];
  assert.deepEqual(wellFace(matched, { selected: null, custom: false }, '#FFF'), { kind: 'ghost', stop: matched[0] });
});

test('wellFace: a literal ghost no stop represents carries its raw value', () => {
  // The well paints it through the one face-paint rule (facePair), so a
  // skin's reference the stop table lacks still paints from roster data.
  assert.deepEqual(wellFace(stops, { selected: null, custom: false }, '#ff8800'), { kind: 'ghost', stop: null, literal: '#ff8800' });
  assert.deepEqual(wellFace(stops, { selected: null, custom: false }, 'var(--color-unknown)'), { kind: 'ghost', stop: null, literal: 'var(--color-unknown)' });
});

test('wellFace: nothing resting is the unset face', () => {
  assert.deepEqual(wellFace(stops, { selected: null, custom: false }, null), { kind: 'unset', stop: null });
  assert.deepEqual(wellFace(stops, { selected: null, custom: false }, undefined), { kind: 'unset', stop: null });
  assert.deepEqual(wellFace(stops, { selected: null, custom: false }, ''), { kind: 'unset', stop: null });
  assert.deepEqual(wellFace(stops, { selected: null, custom: false }, '   '), { kind: 'unset', stop: null });
});

test('wellFace: the ghost is ignored while a stop is selected or custom is set', () => {
  // The state reads never learn about ghosts: a set value is the face,
  // and "set" and "resting" stay distinct for every gesture and gate.
  assert.deepEqual(wellFace(stops, { selected: 'var(--color-surface)', custom: false }, 'var(--color-accent)'), { kind: 'stop', stop: stops[1] });
  assert.deepEqual(wellFace(stops, { selected: null, custom: true }, 'var(--color-accent)'), { kind: 'custom', stop: null });
  assert.deepEqual(wellFace(stops, { selected: null, custom: true, literal: '#ff8800' }, 'var(--color-accent)'), { kind: 'literal', stop: null, literal: '#ff8800' });
  const media = { id: 'media:abc', value: "url('media:abc')", label: 'Media image', paint: 'url("/files/hero.png") center / cover', paintDark: null, group: 'Image', media: true, thumbnail: '/files/hero.png' };
  assert.deepEqual(wellFace([...stops, media], { selected: 'media:abc', custom: false }, 'var(--color-accent)'), { kind: 'media', stop: media });
});
