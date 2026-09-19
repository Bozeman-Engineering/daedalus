/**
 * @file
 * Locks the palette grid's pure half (palette-picker-model.js): the
 * shared grid's palette grouping and its listbox navigation (moved here
 * from the swatch well when the grid became the shared visual offer
 * surface). The offer itself is every property's value space
 * (widget-values.test.mjs fallbackFor / propertyStops); the grid derives
 * none. Run with:
 * node --test tests/js/*.test.mjs
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { groupedStops, listboxHome, listboxTarget } from '../../js/src/palette-picker-model.js';

const stops = [
  { id: 'var(--color-accent)', value: 'var(--color-accent)', label: 'accent', paint: '#1a1a2e', paintDark: '#e8e8f0', group: 'Color' },
  { id: 'var(--color-surface)', value: 'var(--color-surface)', label: 'surface', paint: '#ffffff', paintDark: null, group: 'Color' },
  { id: 'var(--swatch-brand_red)', value: 'var(--swatch-brand_red)', label: 'Brand red', paint: '#b91c1c', paintDark: null, group: 'Colors' },
  { id: 'var(--swatch-hero_fade)', value: 'var(--swatch-hero_fade)', label: 'Hero fade', paint: 'linear-gradient(#000, #fff)', paintDark: null, group: 'Gradients' },
];

test('groupedStops: sections per group label, first-seen order', () => {
  assert.deepEqual(groupedStops(stops), [
    { label: 'Color', stops: [stops[0], stops[1]] },
    { label: 'Colors', stops: [stops[2]] },
    { label: 'Gradients', stops: [stops[3]] },
  ]);
});

test('groupedStops: ungrouped stops collect under the null label', () => {
  // An allowed list's literals carry no group: a curated list is its own
  // one (headerless) section.
  const literals = [
    { id: '#111', value: '#111', label: '#111', paint: '#111' },
    { id: '#222', value: '#222', label: '#222', paint: '#222', group: null },
  ];
  assert.deepEqual(groupedStops(literals), [{ label: null, stops: literals }]);
  assert.deepEqual(groupedStops([]), []);
});

// The listbox half (task: the grid replaced a native select, so it owes
// the select's keyboard story back): roving home and navigation map.
const ids = stops.map((stop) => stop.id);

test('listboxHome: the lit stop when one is, else the first, null when empty', () => {
  assert.equal(listboxHome(ids, 'var(--color-surface)'), 'var(--color-surface)');
  assert.equal(listboxHome(ids, null), 'var(--color-accent)');
  // A selection the offer no longer carries falls back to the first.
  assert.equal(listboxHome(ids, 'var(--gone)'), 'var(--color-accent)');
  assert.equal(listboxHome([], null), null);
});

test('listboxTarget: arrows step the flat offer order in both axes, clamped', () => {
  assert.equal(listboxTarget(ids, ids[0], 'ArrowRight'), ids[1]);
  assert.equal(listboxTarget(ids, ids[1], 'ArrowDown'), ids[2]);
  assert.equal(listboxTarget(ids, ids[2], 'ArrowLeft'), ids[1]);
  assert.equal(listboxTarget(ids, ids[1], 'ArrowUp'), ids[0]);
  // Ends clamp, no wrap (the native select's posture).
  assert.equal(listboxTarget(ids, ids[0], 'ArrowLeft'), ids[0]);
  assert.equal(listboxTarget(ids, ids[3], 'ArrowRight'), ids[3]);
});

test('listboxTarget: Home/End jump, unowned keys and empty offers return null', () => {
  assert.equal(listboxTarget(ids, ids[2], 'Home'), ids[0]);
  assert.equal(listboxTarget(ids, ids[1], 'End'), ids[3]);
  // A stale current id lands on the first stop rather than nowhere.
  assert.equal(listboxTarget(ids, 'var(--gone)', 'ArrowRight'), ids[0]);
  assert.equal(listboxTarget(ids, null, 'ArrowDown'), ids[0]);
  assert.equal(listboxTarget(ids, ids[0], 'Tab'), null);
  assert.equal(listboxTarget(ids, ids[0], 'Enter'), null);
  assert.equal(listboxTarget([], null, 'ArrowRight'), null);
});
