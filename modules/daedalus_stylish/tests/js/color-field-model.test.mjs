/**
 * @file
 * Locks the color field's pure half (color-field-model.js): the shared
 * color parse, the picker hex rule, the face a value wears over its
 * space, the grid entries (with the closed space's honest extra), the
 * mint gate, and the palette space the swatch editor mounts. Run with:
 * node --test tests/js/*.test.mjs
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { materialsFrom } from './materials-fixture.mjs';
import {
  colorFace,
  formatColor,
  gridEntries,
  literalSettle,
  mintOffered,
  paletteSpace,
  parseColor,
  pickerHex,
} from '../../js/src/color-field-model.js';
import { valueSpaceFor } from '../../js/src/value-space.js';

const guide = {
  enforcement: 'strict',
  conditions: {
    dark: { label: 'Dark', wrapper: '@media (prefers-color-scheme: dark)', axis: 'scheme' },
  },
  tokenScales: { color: { label: 'Color', shape: 'color', prefix: 'color' } },
  tokens: { color: { accent: { default: '#1a1a2e', dark: '#e8e8f0' }, surface: '#ffffff' } },
  swatchKinds: { color: { label: 'Colors' }, gradient: { label: 'Gradients' } },
  swatches: [
    { machine_name: 'brand_red', label: 'Brand red', kind: 'color', value: '#b91c1c' },
    // A swatch twinning a token by value: the collision set case.
    { machine_name: 'paper', label: 'Paper', kind: 'color', value: '#ffffff' },
    { machine_name: 'hero_fade', label: 'Hero fade', kind: 'gradient', value: 'linear-gradient(#000, #fff)' },
  ],
};
const materials = materialsFrom(guide);
const COLOR = { type: 'color' };
const open = valueSpaceFor(guide, materials, 'color', COLOR, null);
const closed = valueSpaceFor(guide, materials, 'color', COLOR, { allowed: ['#111', 'var(--swatch-brand_red)'] });

test('parseColor / formatColor: hex and rgb() spellings round-trip; everything else is null', () => {
  assert.deepEqual(parseColor('#fff'), [255, 255, 255, 1]);
  assert.deepEqual(parseColor('#80808080').map((v) => Math.round(v * 100) / 100), [128, 128, 128, 0.5]);
  assert.deepEqual(parseColor('rgb(255, 136, 0)'), [255, 136, 0, 1]);
  assert.deepEqual(parseColor('rgb(1 2 3 / 50%)'), [1, 2, 3, 0.5]);
  assert.deepEqual(parseColor('rgba(1, 2, 3, 0.25)'), [1, 2, 3, 0.25]);
  assert.equal(parseColor('rebeccapurple'), null);
  assert.equal(parseColor('oklch(70% 0.1 200)'), null);
  assert.equal(parseColor('var(--color-accent)'), null);
  assert.equal(parseColor(''), null);
  assert.equal(parseColor(null), null);
  assert.equal(formatColor([255, 136, 0, 1]), '#ff8800');
  assert.equal(formatColor([1, 2, 3, 0.25]), 'rgba(1, 2, 3, 0.25)');
});

test('pickerHex: any parsable paint becomes the picker\'s #rrggbb, alpha dropped; the resolver covers the rest; null disables', () => {
  assert.equal(pickerHex('#FF8800'), '#ff8800');
  assert.equal(pickerHex('#abc'), '#aabbcc');
  assert.equal(pickerHex('rgb(255 136 0 / 0.5)'), '#ff8800');
  // Beyond the parser without a resolver: null, never black.
  assert.equal(pickerHex('rebeccapurple'), null);
  assert.equal(pickerHex(''), null);
  assert.equal(pickerHex(null), null);
  // The resolver (the browser's parse in the component) answers a hex,
  // normalized; anything else it answers is refused.
  assert.equal(pickerHex('rebeccapurple', () => '#663399'), '#663399');
  assert.equal(pickerHex('rebeccapurple', () => '#66339980'), null);
  assert.equal(pickerHex('oklch(70% 0.1 200)', () => null), null);
  // A parsable paint never consults the resolver.
  assert.equal(pickerHex('#123456', () => { throw new Error('not consulted'); }), '#123456');
});

test('colorFace: the five kinds over an open space', () => {
  // Unset.
  assert.deepEqual(colorFace(guide, materials, open, ''), { kind: 'unset', spelling: '', text: '', paint: null, paintDark: null, stop: null, token: null });
  assert.equal(colorFace(guide, materials, open, null).kind, 'unset');
  // A stop the space offers: the stop's paint pair, the display value as
  // text, the exact token (the twin the reference names, never the
  // collision default).
  const accent = colorFace(guide, materials, open, 'var(--color-accent)');
  assert.equal(accent.kind, 'stop');
  assert.equal(accent.text, '#1a1a2e');
  assert.equal(accent.paint, '#1a1a2e');
  assert.equal(accent.paintDark, '#e8e8f0');
  assert.equal(accent.stop.ref, 'var(--color-accent)');
  assert.equal(accent.token.name, 'accent');
  const paper = colorFace(guide, materials, open, 'var(--swatch-paper)');
  assert.equal(paper.kind, 'stop');
  assert.equal(paper.stop.ref, 'var(--color-surface)', 'the twin collapses onto the first token\'s stop');
  assert.equal(paper.token.name, 'Paper', 'but the worn token is the swatch the reference names');
  // A rostered reference outside the space (a color swatch on a row
  // whose curated list excludes it): blessed, stopless, painted from
  // record data.
  const outside = colorFace(guide, materials, closed, 'var(--color-accent)');
  assert.equal(outside.kind, 'reference');
  assert.equal(outside.text, '#1a1a2e');
  assert.equal(outside.paint, '#1a1a2e');
  assert.equal(outside.paintDark, '#e8e8f0');
  assert.equal(outside.stop, null);
  assert.equal(outside.token.name, 'accent');
  // A literal: its own paint, nested references substituted, the spelling
  // as text.
  const literal = colorFace(guide, materials, open, ' #ff8800 ');
  assert.deepEqual(literal, { kind: 'literal', spelling: '#ff8800', text: '#ff8800', paint: '#ff8800', paintDark: null, stop: null, token: null });
  const nested = colorFace(guide, materials, open, 'color-mix(in srgb, var(--color-accent), white)');
  assert.equal(nested.kind, 'literal');
  assert.equal(nested.paint, 'color-mix(in srgb, #1a1a2e, white)');
  assert.equal(nested.paintDark, 'color-mix(in srgb, #e8e8f0, white)');
  // The fallback form is a literal to the grammar, painted through the
  // referenced record.
  assert.equal(colorFace(guide, materials, open, 'var(--color-accent, red)').kind, 'literal');
  // A reference the roster lacks: unknown, the raw spelling on the face.
  assert.deepEqual(colorFace(guide, materials, open, 'var(--swatch-nope)'), { kind: 'unknown', spelling: 'var(--swatch-nope)', text: 'var(--swatch-nope)', paint: 'var(--swatch-nope)', paintDark: null, stop: null, token: null });
  assert.equal(accent.spelling, 'var(--color-accent)');
  assert.equal(outside.spelling, 'var(--color-accent)');
});

test('gridEntries: every stop as a face chip, the worn stop lit; a closed space appends the honest extra', () => {
  const unset = gridEntries(open, colorFace(guide, materials, open, ''));
  assert.deepEqual(unset.entries.map((entry) => entry.id), ['var(--color-accent)', 'var(--color-surface)', 'var(--swatch-brand_red)']);
  assert.deepEqual(unset.entries.map((entry) => entry.label), ['accent', 'surface', 'Brand red']);
  assert.deepEqual(unset.entries.map((entry) => entry.paint), ['#1a1a2e', '#ffffff', '#b91c1c']);
  assert.deepEqual(unset.entries.map((entry) => entry.paintDark), ['#e8e8f0', null, null]);
  assert.deepEqual(unset.entries.map((entry) => entry.group), ['Color', 'Color', 'Colors']);
  assert.equal(unset.selected, null);
  // A pick commits the entry's value: the stop's reference spelling.
  assert.deepEqual(unset.entries.map((entry) => entry.value), unset.entries.map((entry) => entry.id));
  // The worn stop lights.
  assert.equal(gridEntries(open, colorFace(guide, materials, open, 'var(--swatch-brand_red)')).selected, 'var(--swatch-brand_red)');
  // An open space never grows an extra: the literal lives in the text.
  const literal = gridEntries(open, colorFace(guide, materials, open, '#ff8800'));
  assert.equal(literal.entries.length, 3);
  assert.equal(literal.selected, null);
  // A closed space: the curated entries (a literal labeled as itself, the
  // reference by its material; a curated list is one headerless section,
  // so no group on either), plus the honest extra for an off-vocabulary
  // committed value, lit so its own pick unsets and any other replaces it.
  const curated = gridEntries(closed, colorFace(guide, materials, closed, ''));
  assert.deepEqual(curated.entries.map((entry) => [entry.id, entry.label, entry.paint, entry.group]), [
    ['#111', '#111', '#111', null],
    ['var(--swatch-brand_red)', 'Brand red', '#b91c1c', null],
  ]);
  const extra = gridEntries(closed, colorFace(guide, materials, closed, '#ff8800'));
  assert.equal(extra.entries.length, 3);
  assert.deepEqual(extra.entries[2], { id: '#ff8800', value: '#ff8800', label: '#ff8800', paint: '#ff8800', paintDark: null, group: null, extra: true });
  assert.equal(extra.selected, '#ff8800');
  // A rostered reference outside the closed space is an extra too: it
  // commits as its var() and is named by its material.
  const reference = gridEntries(closed, colorFace(guide, materials, closed, 'var(--color-accent)'));
  assert.equal(reference.entries[2].id, 'var(--color-accent)');
  assert.equal(reference.entries[2].value, 'var(--color-accent)');
  assert.equal(reference.entries[2].label, 'accent');
  assert.equal(reference.selected, 'var(--color-accent)');
  assert.equal(reference.entries[2].paint, '#1a1a2e');
  assert.equal(reference.entries[2].paintDark, '#e8e8f0');
});

test('mintOffered: the grant, an open space, and a literal to save', () => {
  const literal = colorFace(guide, materials, open, '#ff8800');
  assert.equal(mintOffered(open, literal, true), true);
  assert.equal(mintOffered(open, literal, false), false);
  assert.equal(mintOffered(closed, colorFace(guide, materials, closed, '#ff8800'), true), false);
  assert.equal(mintOffered(open, colorFace(guide, materials, open, 'var(--swatch-brand_red)'), true), false);
  assert.equal(mintOffered(open, colorFace(guide, materials, open, 'var(--swatch-nope)'), true), false);
  assert.equal(mintOffered(open, colorFace(guide, materials, open, ''), true), false);
});

test('literalSettle: empty unsets, a swatch\'s own hex captures its reference, anything else commits as typed', () => {
  // The one settle rule behind typing and the native picker's change:
  // capture on a detented space commits the material, previewing the
  // stop's spelling only when the typed one differs from it.
  assert.deepEqual(literalSettle(open, '  '), { spelling: '', preview: null });
  assert.deepEqual(literalSettle(open, '#B91C1C'), { spelling: 'var(--swatch-brand_red)', preview: '#b91c1c' });
  assert.deepEqual(literalSettle(open, '#b91c1c'), { spelling: 'var(--swatch-brand_red)', preview: null });
  assert.deepEqual(literalSettle(open, ' #ff8800 '), { spelling: '#ff8800', preview: null });
  assert.deepEqual(literalSettle({ kind: 'free', stops: [] }, '#b91c1c'), { spelling: '#b91c1c', preview: null });
});

test('paletteSpace: the palette as the swatch editor\'s space, never closed, minus the swatch\'s own spelling', () => {
  // Strict enforcement, no constraints: the whole color palette, open.
  const whole = paletteSpace(guide, materials);
  assert.equal(whole.kind, 'detented');
  assert.deepEqual(whole.stops.map((stop) => stop.ref), ['var(--color-accent)', 'var(--color-surface)', 'var(--swatch-brand_red)']);
  // Excluding a stop's own reference drops the stop.
  const noRed = paletteSpace(guide, materials, 'var(--swatch-brand_red)');
  assert.deepEqual(noRed.stops.map((stop) => stop.ref), ['var(--color-accent)', 'var(--color-surface)']);
  // Excluding a twin drops it from the collision set (the stop stays for
  // the token), so neither the offer nor a capture can reach it.
  const noPaper = paletteSpace(guide, materials, 'var(--swatch-paper)');
  const surface = noPaper.stops.find((stop) => stop.value === '#ffffff');
  assert.deepEqual(surface.tokens.map((token) => token.ref), ['var(--color-surface)']);
  // Excluding the first twin re-points the stop at the survivor.
  const noSurface = paletteSpace(guide, materials, 'var(--color-surface)');
  const paper = noSurface.stops.find((stop) => stop.value === '#ffffff');
  assert.equal(paper.ref, 'var(--swatch-paper)');
  assert.deepEqual(paper.tokens.map((token) => token.ref), ['var(--swatch-paper)']);
  // Nothing excluded when the spelling is not offered.
  assert.equal(paletteSpace(guide, materials, 'var(--swatch-nope)').stops.length, 3);
});
