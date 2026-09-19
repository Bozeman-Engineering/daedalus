/**
 * Self-asserting tests for the island line-marks model (line-marks.js):
 * the one decoration source both postures consume — lost strikethrough +
 * winner chip, variant provenance tints (on/off), pending dismiss marks
 * on document islands, and the sheet/element mode gates.
 *
 * Run directly (`node --test tests/js/line-marks.test.mjs`) or via
 * JsSuitesTest, which globs every suite into the PHPUnit run. Exits
 * non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import test from 'node:test';
import { lineMarks } from '../../js/src/code/line-marks.js';
import {
  DECLARED_LAYER, ELEMENT_LAYER, SKIN_COLOR, SKIN_LAYER, layerColor, variantLayerId,
} from '../../js/src/code/layers.js';

// The models call Drupal.t at mark time; the node harness substitutes
// placeholders the way Drupal.t does, so titles assert readable.
globalThis.Drupal = {
  t: (text, args = {}) => Object.entries(args).reduce(
    (result, [placeholder, value]) => result.replaceAll(placeholder, String(value)),
    text,
  ),
};

const library = [
  { id: 'card_left', label: 'Card left' },
  { id: 'hero_band', label: 'Hero band' },
];

const ownLine = { property: 'padding', value: '3rem', layer: ELEMENT_LAYER, variant: null, lost: false, winner: null };
const variantLine = { property: 'color', value: 'red', layer: variantLayerId('card_left'), variant: 'card_left', lost: false, winner: null };

test('a plain own line wears nothing', () => {
  const [mark] = lineMarks([ownLine], { library, sheet: false, tints: 'on' });
  assert.deepEqual(mark, { classes: [], attributes: null, winner: null, pending: null });
});

test('a hole (unmatched, mid-edit declaration) yields a null mark', () => {
  assert.deepEqual(lineMarks([undefined, ownLine], { library })[0], null);
  assert.deepEqual(lineMarks(null, { library }), []);
});

// --- lost: the strikethrough and the winner chip ----------------------------

test('a lost line strikes through and names the winning variant in its hue', () => {
  const lost = { ...ownLine, lost: true, winner: { layer: variantLayerId('card_left'), variant: 'card_left' } };
  const [mark] = lineMarks([lost], { library, sheet: false, tints: 'on' });
  assert.deepEqual(mark.classes, ['daedalus-stylish-island__line--lost']);
  assert.deepEqual(mark.winner, {
    label: 'Card left',
    color: layerColor(variantLayerId('card_left')),
  });
});

test('a lost line beaten by the element layer names "this element" in the element blue', () => {
  const lost = { ...variantLine, lost: true, winner: { layer: ELEMENT_LAYER, variant: null } };
  const [mark] = lineMarks([lost], { library, sheet: false, tints: 'off' });
  assert.deepEqual(mark.classes, ['daedalus-stylish-island__line--lost']);
  assert.deepEqual(mark.winner, { label: 'this element', color: layerColor(ELEMENT_LAYER) });
});

test('a winner outside the library falls back to the raw reference', () => {
  const lost = { ...ownLine, lost: true, winner: { layer: variantLayerId('gone_variant'), variant: 'gone_variant' } };
  const [mark] = lineMarks([lost], { library, sheet: false, tints: 'on' });
  assert.equal(mark.winner.label, 'gone_variant');
  assert.equal(mark.winner.color, layerColor(variantLayerId('gone_variant')));
});

test('a lost line strikes through on a document island too', () => {
  const lost = { ...ownLine, lost: true, winner: { layer: variantLayerId('hero_band'), variant: 'hero_band' } };
  const [mark] = lineMarks([lost], { library, sheet: true, tints: 'on' });
  assert.deepEqual(mark.classes, ['daedalus-stylish-island__line--lost']);
  assert.equal(mark.winner.label, 'Hero band');
});

// --- variant provenance tints, on and off -----------------------------------

test('a variant-supplied line washes in its layer hue with the supplier title (tints on)', () => {
  const [mark] = lineMarks([variantLine], { library, sheet: false, tints: 'on' });
  assert.deepEqual(mark.classes, ['daedalus-stylish-island__line--variant']);
  assert.deepEqual(mark.attributes, {
    style: `--daedalus-stylish-line-hue: ${layerColor(variantLayerId('card_left'))}`,
    title: 'Supplied by the variant "Card left".',
  });
});

test('the supplier title falls back to the reference when the library lacks the variant', () => {
  const line = { ...variantLine, layer: variantLayerId('gone_variant'), variant: 'gone_variant' };
  const [mark] = lineMarks([line], { library, sheet: false, tints: 'on' });
  assert.equal(mark.attributes.title, 'Supplied by the variant "gone_variant".');
});

test('tints off prints variant lines plain (the wear bar still names them)', () => {
  const [mark] = lineMarks([variantLine], { library, sheet: false, tints: 'off' });
  assert.deepEqual(mark, { classes: [], attributes: null, winner: null, pending: null });
});

test('a lost variant line keeps both marks: lost first, tint beside it', () => {
  const lost = { ...variantLine, lost: true, winner: { layer: variantLayerId('hero_band'), variant: 'hero_band' } };
  const [mark] = lineMarks([lost], { library, sheet: false, tints: 'on' });
  assert.deepEqual(mark.classes, ['daedalus-stylish-island__line--lost', 'daedalus-stylish-island__line--variant']);
  assert.equal(mark.attributes.title, 'Supplied by the variant "Card left".');
  assert.equal(mark.winner.label, 'Hero band');
});

// --- floor provenance tints: the skin's and the declaration's lines ---------

const skinLine = { property: 'gap', value: 'var(--space-gutter)', layer: SKIN_LAYER, variant: null, lost: false, winner: null };
const declaredLine = { property: 'margin', value: '0', layer: DECLARED_LAYER, variant: null, lost: false, winner: null };

test('a skin line washes in the floor hue and names the active skin (tints on)', () => {
  const [mark] = lineMarks([skinLine], { library, sheet: false, tints: 'on' });
  assert.deepEqual(mark.classes, ['daedalus-stylish-island__line--floor']);
  assert.deepEqual(mark.attributes, {
    style: `--daedalus-stylish-line-hue: ${SKIN_COLOR}`,
    title: 'Supplied by the active skin.',
  });
  assert.equal(mark.winner, null);
});

test('a declared line wears the same hue and names the declaration', () => {
  const [mark] = lineMarks([declaredLine], { library, sheet: false, tints: 'on' });
  assert.deepEqual(mark.classes, ['daedalus-stylish-island__line--floor']);
  assert.equal(mark.attributes.style, `--daedalus-stylish-line-hue: ${layerColor(DECLARED_LAYER)}`);
  assert.equal(mark.attributes.title, "Supplied by the declaration's defaults.");
});

test('a floor line beaten by the element strikes with the "this element" chip', () => {
  const lost = { ...skinLine, lost: true, winner: { layer: ELEMENT_LAYER, variant: null } };
  const [mark] = lineMarks([lost], { library, sheet: false, tints: 'on' });
  assert.deepEqual(mark.classes, ['daedalus-stylish-island__line--lost', 'daedalus-stylish-island__line--floor']);
  assert.deepEqual(mark.winner, { label: 'this element', color: layerColor(ELEMENT_LAYER) });
});

test('a floor line beaten by a variant names it; a declared line beaten by the skin names the skin', () => {
  const byVariant = { ...skinLine, lost: true, winner: { layer: variantLayerId('card_left'), variant: 'card_left' } };
  assert.deepEqual(lineMarks([byVariant], { library })[0].winner, {
    label: 'Card left',
    color: layerColor(variantLayerId('card_left')),
  });
  const bySkin = { ...declaredLine, lost: true, winner: { layer: SKIN_LAYER, variant: null } };
  assert.deepEqual(lineMarks([bySkin], { library })[0].winner, { label: 'the active skin', color: SKIN_COLOR });
});

test('tints off prints floor lines plain too; a document island never tints them', () => {
  assert.deepEqual(lineMarks([skinLine], { library, sheet: false, tints: 'off' })[0], { classes: [], attributes: null, winner: null, pending: null });
  assert.deepEqual(lineMarks([declaredLine], { library, sheet: true, tints: 'on' })[0], { classes: [], attributes: null, winner: null, pending: null });
});

// --- pending: the dismiss mark, and the restore after it --------------------

test('a staged document line wears the pending treatment and the dismiss mark', () => {
  const staged = { ...ownLine, pending: true, pendingId: 'p1' };
  const [mark] = lineMarks([staged], { library, sheet: true, tints: 'on' });
  assert.deepEqual(mark.classes, ['daedalus-stylish-island__line--pending']);
  assert.deepEqual(mark.pending, { kind: 'dismiss', id: 'p1' });
});

test('a dismissed line restores plain: the reverted sheet wears no pending mark', () => {
  // Dismissal re-prints the sheet without the staged line; the restored
  // line shape carries no pending flag and must wear nothing again.
  const restored = { ...ownLine };
  const [mark] = lineMarks([restored], { library, sheet: true, tints: 'on' });
  assert.deepEqual(mark, { classes: [], attributes: null, winner: null, pending: null });
});

// --- the mode gates: sheet vs element ---------------------------------------

test('element mode never marks pending (staged edits are a document-island lane)', () => {
  const staged = { ...ownLine, pending: true, pendingId: 'p1' };
  const [mark] = lineMarks([staged], { library, sheet: false, tints: 'on' });
  assert.deepEqual(mark, { classes: [], attributes: null, winner: null, pending: null });
});

test('sheet mode never tints provenance (a document island prints one sheet)', () => {
  const [mark] = lineMarks([variantLine], { library, sheet: true, tints: 'on' });
  assert.deepEqual(mark, { classes: [], attributes: null, winner: null, pending: null });
});
