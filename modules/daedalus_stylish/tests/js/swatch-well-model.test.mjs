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

test('wellFace: a lower layer\'s value lights its stop like an own value; nothing delivering is unset', () => {
  // The state is read over the row's LIT value (the stack's winner at
  // the pose, a skin's reference included), so the well has no resting
  // face of its own: the skin's var() lights the stop that commits it,
  // and only a null winner reads as the empty face.
  assert.deepEqual(wellFace(stops, { selected: 'var(--color-accent)', custom: false }), { kind: 'stop', stop: stops[0] });
  assert.deepEqual(wellFace(stops, { selected: null, custom: false }), { kind: 'unset', stop: null });
  // A third argument is not part of the contract any more: ignored.
  assert.deepEqual(wellFace(stops, { selected: null, custom: false }, 'var(--color-accent)'), { kind: 'unset', stop: null });
});

test('wellFace: the synthetic gradient stop wears the painted gradient face', () => {
  // widget-gradients.js gradientImageStop flags itself: a lone gradient
  // literal no offered stop claims is lit in the grid (its toggle the
  // unpick) and the well paints it under the custom marker instead of
  // falling through to the empty face. A gradient swatch's stop stays the
  // plain stop face; custom still outranks.
  const literal = 'linear-gradient(to right, #1b9ae4, #116392)';
  const synthetic = { id: 'gradient:literal', value: literal, matches: [literal], label: 'Custom gradient', paint: literal, paintDark: null, group: 'Gradient', gradient: true };
  assert.deepEqual(wellFace([...stops, synthetic], { selected: 'gradient:literal', custom: false }), { kind: 'gradient', stop: synthetic, gradient: literal });
  assert.deepEqual(wellFace([...stops, synthetic], { selected: 'var(--swatch-hero_fade)', custom: false }), { kind: 'stop', stop: stops[3] });
  assert.deepEqual(wellFace([...stops, synthetic], { selected: null, custom: true }), { kind: 'custom', stop: null });
  assert.deepEqual(wellFace([...stops, synthetic], { selected: null, custom: false }), { kind: 'unset', stop: null });
});
