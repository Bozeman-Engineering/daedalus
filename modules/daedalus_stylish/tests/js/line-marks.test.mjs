/**
 * Self-asserting tests for the island line-marks model (line-marks.js):
 * the one decoration source both postures consume — lost strikethrough +
 * winner chip, the provenance lens (every line's layer hue, the delivered
 * flag on non-own lines, the run label on the first line of each layer
 * run), pending dismiss marks on document islands, and the sheet/element
 * mode gates.
 *
 * Run directly (`node --test tests/js/line-marks.test.mjs`) or via
 * JsSuitesTest, which globs every suite into the PHPUnit run. Exits
 * non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import test from 'node:test';
import { lineMarks } from '../../js/src/code/line-marks.js';
import {
  ELEMENT_LAYER, SKIN_COLOR, ancestorLayerId, ancestorWearLayerId, declaredLayerId, layerColor, skinLayerId, variantLayerId,
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

const DELIVERED = 'daedalus-stylish-island__line--delivered';
const LOST = 'daedalus-stylish-island__line--lost';
const hue = (layer) => `--daedalus-stylish-line-hue: ${layerColor(layer)}`;
const run = (label, layer) => ({ label, layer, color: layerColor(layer), first: true });

const ownLine = { property: 'padding', value: '3rem', layer: ELEMENT_LAYER, variant: null, lost: false, winner: null };
const variantLine = { property: 'color', value: 'red', layer: variantLayerId('card_left'), variant: 'card_left', lost: false, winner: null };

test('an own line wears its layer hue and heads its run as "this element"; nothing else', () => {
  const [mark] = lineMarks([ownLine], { library, sheet: false });
  assert.deepEqual(mark, {
    classes: [],
    attributes: { style: hue(ELEMENT_LAYER) },
    winner: null,
    pending: null,
    run: run('this element', ELEMENT_LAYER),
  });
});

test('a hole (unmatched, mid-edit declaration) yields a null mark', () => {
  assert.deepEqual(lineMarks([undefined, ownLine], { library })[0], null);
  assert.deepEqual(lineMarks(null, { library }), []);
});

// --- lost: the strikethrough and the winner chip ----------------------------

test('a lost line strikes through and names the winning variant in its hue', () => {
  const lost = { ...ownLine, lost: true, winner: { layer: variantLayerId('card_left'), variant: 'card_left' } };
  const [mark] = lineMarks([lost], { library, sheet: false });
  assert.deepEqual(mark.classes, [LOST]);
  assert.deepEqual(mark.winner, {
    label: 'Card left',
    color: layerColor(variantLayerId('card_left')),
  });
});

test('a lost line beaten by the element layer names "this element" in the element blue', () => {
  const lost = { ...variantLine, lost: true, winner: { layer: ELEMENT_LAYER, variant: null } };
  const [mark] = lineMarks([lost], { library, sheet: false });
  assert.deepEqual(mark.classes, [LOST, DELIVERED]);
  assert.deepEqual(mark.winner, { label: 'this element', color: layerColor(ELEMENT_LAYER) });
});

test('a winner outside the library falls back to the raw reference', () => {
  const lost = { ...ownLine, lost: true, winner: { layer: variantLayerId('gone_variant'), variant: 'gone_variant' } };
  const [mark] = lineMarks([lost], { library, sheet: false });
  assert.equal(mark.winner.label, 'gone_variant');
  assert.equal(mark.winner.color, layerColor(variantLayerId('gone_variant')));
});

test('a lost line strikes through on a document island too', () => {
  const lost = { ...ownLine, lost: true, winner: { layer: variantLayerId('hero_band'), variant: 'hero_band' } };
  const [mark] = lineMarks([lost], { library, sheet: true });
  assert.deepEqual(mark.classes, [LOST]);
  assert.equal(mark.winner.label, 'Hero band');
});

// --- delivered: a variant's line ---------------------------------------------

test('a variant-supplied line is delivered, in its layer hue, titled by the supplier, headed by the variant\'s label', () => {
  const [mark] = lineMarks([variantLine], { library, sheet: false });
  assert.deepEqual(mark.classes, [DELIVERED]);
  assert.deepEqual(mark.attributes, {
    style: hue(variantLayerId('card_left')),
    title: 'Supplied by the variant "Card left".',
  });
  assert.deepEqual(mark.run, run('Card left', variantLayerId('card_left')));
});

test('the supplier title and the run label fall back to the reference when the library lacks the variant', () => {
  const line = { ...variantLine, layer: variantLayerId('gone_variant'), variant: 'gone_variant' };
  const [mark] = lineMarks([line], { library, sheet: false });
  assert.equal(mark.attributes.title, 'Supplied by the variant "gone_variant".');
  assert.equal(mark.run.label, 'gone_variant');
});

test('a lost variant line keeps both marks: lost first, delivered beside it', () => {
  const lost = { ...variantLine, lost: true, winner: { layer: variantLayerId('hero_band'), variant: 'hero_band' } };
  const [mark] = lineMarks([lost], { library, sheet: false });
  assert.deepEqual(mark.classes, [LOST, DELIVERED]);
  assert.equal(mark.attributes.title, 'Supplied by the variant "Card left".');
  assert.equal(mark.winner.label, 'Hero band');
});

// --- runs: the label on the first line of each layer run ---------------------
// The lines print in cascade order (the floor, the worn layers, the own
// layer); consecutive lines of one layer are a run, and only its first
// line carries the label. Holes are transparent: an edited line inside
// a run does not restart it.

const skinLine = { property: 'gap', value: 'var(--space-gutter)', layer: skinLayerId('kind:block'), variant: null, lost: false, winner: null };
const declaredLine = { property: 'margin', value: '0', layer: declaredLayerId('block_type:text'), variant: null, lost: false, winner: null };

test('run boundaries: the first line of each layer run carries the label; the rest carry none', () => {
  const marks = lineMarks(
    [declaredLine, skinLine, { ...skinLine, property: 'padding', value: '1rem' }, variantLine, ownLine, { ...ownLine, property: 'margin', value: '2rem' }],
    { library, sheet: false },
  );
  assert.deepEqual(marks.map((mark) => mark.run), [
    run('declared, text block type', declaredLayerId('block_type:text')),
    run('skin, block kind', skinLayerId('kind:block')),
    null,
    run('Card left', variantLayerId('card_left')),
    run('this element', ELEMENT_LAYER),
    null,
  ]);
});

test('a hole inside a run is transparent: the run continues past it', () => {
  const marks = lineMarks([skinLine, null, { ...skinLine, property: 'padding', value: '1rem' }], { library, sheet: false });
  assert.deepEqual(marks.map((mark) => mark?.run ?? null), [run('skin, block kind', skinLayerId('kind:block')), null, null]);
});

test('a run returning after another layer starts again (the buffer order is the reading)', () => {
  const marks = lineMarks([ownLine, variantLine, { ...ownLine, property: 'margin', value: '2rem' }], { library, sheet: false });
  assert.deepEqual(marks.map((mark) => Boolean(mark.run)), [true, true, true]);
});

test('an empty island has no run', () => {
  assert.deepEqual(lineMarks([], { library, sheet: false }), []);
});

test('a rule island\'s own run reads "this rule"', () => {
  const [mark] = lineMarks([ownLine], { library, sheet: false, own: 'rule' });
  assert.deepEqual(mark.run, run('this rule', ELEMENT_LAYER));
});

test('a document island has no runs and no delivered lines: it prints one sheet', () => {
  const marks = lineMarks([variantLine, ownLine], { library, sheet: true });
  assert.deepEqual(marks.map((mark) => mark.run), [null, null]);
  assert.deepEqual(marks.map((mark) => mark.classes), [[], []]);
  assert.deepEqual(marks.map((mark) => mark.attributes), [null, null]);
});

// --- delivered: the floor's lines, the skin's and the declaration's ----------
// One layer per declaring scope (skin:kind:block, skin:base:p,
// declared:block_type:text); the hover title names the tier AND the
// scope, so a floor line says which block of the skin or the declaration
// supplies it; the run label spells the scope in the provenance
// sentences' words.

test('a skin line is delivered in the floor hue and names the active skin and its scope', () => {
  const [mark] = lineMarks([skinLine], { library, sheet: false });
  assert.deepEqual(mark.classes, [DELIVERED]);
  assert.deepEqual(mark.attributes, {
    style: `--daedalus-stylish-line-hue: ${SKIN_COLOR}`,
    title: 'Supplied by the active skin (kind:block).',
  });
  assert.equal(mark.winner, null);
  assert.deepEqual(mark.run, { label: 'skin, block kind', layer: skinLayerId('kind:block'), color: SKIN_COLOR, first: true });
});

test('the skin\'s base:{tag} line is a skin line too, its scope the tag block, its run labelled by the tag', () => {
  const base = { ...skinLine, property: 'line-height', value: '1.6', layer: skinLayerId('base:p') };
  const [mark] = lineMarks([base], { library, sheet: false });
  assert.deepEqual(mark.classes, [DELIVERED]);
  assert.equal(mark.attributes.style, `--daedalus-stylish-line-hue: ${SKIN_COLOR}`);
  assert.equal(mark.attributes.title, 'Supplied by the active skin (base:p).');
  assert.equal(mark.run.label, 'skin, p elements');
});

test('a declared line wears the same hue and names the declaration and its scope', () => {
  const [mark] = lineMarks([declaredLine], { library, sheet: false });
  assert.deepEqual(mark.classes, [DELIVERED]);
  assert.equal(mark.attributes.style, `--daedalus-stylish-line-hue: ${layerColor(declaredLayerId('block_type:text'))}`);
  assert.equal(mark.attributes.title, "Supplied by the declaration's defaults (block_type:text).");
  assert.equal(mark.run.label, 'declared, text block type');
});

test('a floor line beaten by the element strikes with the "this element" chip', () => {
  const lost = { ...skinLine, lost: true, winner: { layer: ELEMENT_LAYER, variant: null } };
  const [mark] = lineMarks([lost], { library, sheet: false });
  assert.deepEqual(mark.classes, [LOST, DELIVERED]);
  assert.deepEqual(mark.winner, { label: 'this element', color: layerColor(ELEMENT_LAYER) });
});

test('a floor line beaten by a variant names it; a declared line beaten by the skin names the skin', () => {
  const byVariant = { ...skinLine, lost: true, winner: { layer: variantLayerId('card_left'), variant: 'card_left' } };
  assert.deepEqual(lineMarks([byVariant], { library })[0].winner, {
    label: 'Card left',
    color: layerColor(variantLayerId('card_left')),
  });
  const bySkin = { ...declaredLine, lost: true, winner: { layer: skinLayerId('kind:block'), variant: null } };
  assert.deepEqual(lineMarks([bySkin], { library })[0].winner, { label: 'the active skin', color: SKIN_COLOR });
  // A skin line the refinement's skin block beats names the tier too
  // (the chip is short; the winning line's own title names the scope).
  const byRefinement = { ...skinLine, lost: true, winner: { layer: skinLayerId('block_type:text'), variant: null } };
  assert.deepEqual(lineMarks([byRefinement], { library })[0].winner, { label: 'the active skin', color: SKIN_COLOR });
  const byDeclared = { ...declaredLine, layer: declaredLayerId('kind:block'), lost: true, winner: { layer: declaredLayerId('block_type:text'), variant: null } };
  assert.deepEqual(lineMarks([byDeclared], { library })[0].winner, { label: "the declaration's defaults", color: SKIN_COLOR });
});

// --- delivered: an ancestor rule's own line and a variant it wears ----------
// A rule position is a group (style-stack.js ancestorLayers): the rule's
// own entry reads as the floor does, a variant the rule wears reads as
// that variant (its hue, its name), with the rule position the line's
// id carries named in the title. The tier has no run word of its own:
// it never prints on an island (element-model.js ownIslandLines drops
// it; a rule prints as a rule island whose own run reads "this rule"),
// so the marks here are the winner-chip and title vocabulary alone.

const OWNER = 'container:cccccccc-1111-2222-3333-444444444444';
const ruleLine = { property: 'color', value: 'navy', layer: ancestorLayerId(OWNER, '> pb-text'), variant: null, lost: false, winner: null };
const wornLine = { property: 'color', value: 'red', layer: ancestorWearLayerId(OWNER, 'pb-text .text-block-link', 'card_left'), variant: 'card_left', lost: false, winner: null };

test('an ancestor rule\'s own line is delivered in the floor hue and names the rule and its owner in its title', () => {
  const [mark] = lineMarks([ruleLine], { library, sheet: false });
  assert.deepEqual(mark.classes, [DELIVERED]);
  assert.deepEqual(mark.attributes, {
    style: `--daedalus-stylish-line-hue: ${SKIN_COLOR}`,
    title: `Inherited from the rule "> pb-text" on ${OWNER}.`,
  });
  // The run heads in the floor hue; the tier has no word of its own.
  assert.equal(mark.run.layer, ruleLine.layer);
  assert.equal(mark.run.color, SKIN_COLOR);
});

test('a variant an ancestor\'s rule wears is a VARIANT line: the variant\'s hue, the title naming the variant and the rule position, the run its variant\'s', () => {
  const [mark] = lineMarks([wornLine], { library, sheet: false });
  assert.deepEqual(mark.classes, [DELIVERED]);
  assert.deepEqual(mark.attributes, {
    style: `--daedalus-stylish-line-hue: ${layerColor(variantLayerId('card_left'))}`,
    title: `Supplied by the variant "Card left" through the rule "pb-text .text-block-link" on ${OWNER}.`,
  });
  assert.notEqual(mark.attributes.style, `--daedalus-stylish-line-hue: ${SKIN_COLOR}`);
  assert.deepEqual(mark.run, { label: 'Card left', layer: wornLine.layer, color: layerColor(variantLayerId('card_left')), first: true });
  // The library lacking the variant falls back to the reference.
  const unknown = { ...wornLine, layer: ancestorWearLayerId(OWNER, '> pb-text', 'ghost'), variant: 'ghost' };
  assert.equal(lineMarks([unknown], { library })[0].attributes.title, `Supplied by the variant "ghost" through the rule "> pb-text" on ${OWNER}.`);
});

test('a line lost to an ancestor\'s worn variant names the variant through the rule in the variant\'s hue; lost to the rule\'s own line names the rule in the floor hue', () => {
  const toWorn = { ...skinLine, lost: true, winner: { layer: ancestorWearLayerId(OWNER, '> pb-text', 'card_left'), variant: 'card_left' } };
  assert.deepEqual(lineMarks([toWorn], { library })[0].winner, {
    label: '"Card left" through an ancestor\'s rule',
    color: layerColor(variantLayerId('card_left')),
  });
  const toOwn = { ...skinLine, lost: true, winner: { layer: ancestorLayerId(OWNER, '> pb-text'), variant: null } };
  assert.deepEqual(lineMarks([toOwn], { library })[0].winner, { label: 'an ancestor\'s "> pb-text" rule', color: SKIN_COLOR });
  // Own beats worn inside the group: the worn line lost to the rule's
  // own keeps its delivered mark beside the strike.
  const wornLost = { ...wornLine, lost: true, winner: { layer: ancestorLayerId(OWNER, 'pb-text .text-block-link'), variant: null } };
  const [mark] = lineMarks([wornLost], { library });
  assert.deepEqual(mark.classes, [LOST, DELIVERED]);
  assert.deepEqual(mark.winner, { label: 'an ancestor\'s "pb-text .text-block-link" rule', color: SKIN_COLOR });
});

test('a document island never marks a floor line delivered', () => {
  assert.deepEqual(lineMarks([declaredLine], { library, sheet: true })[0], { classes: [], attributes: null, winner: null, pending: null, run: null });
});

// --- pending: the dismiss mark, and the restore after it --------------------

test('a staged document line wears the pending treatment and the dismiss mark', () => {
  const staged = { ...ownLine, pending: true, pendingId: 'p1' };
  const [mark] = lineMarks([staged], { library, sheet: true });
  assert.deepEqual(mark.classes, ['daedalus-stylish-island__line--pending']);
  assert.deepEqual(mark.pending, { kind: 'dismiss', id: 'p1' });
});

test('a dismissed line restores plain: the reverted sheet wears no pending mark', () => {
  // Dismissal re-prints the sheet without the staged line; the restored
  // line shape carries no pending flag and must wear nothing again.
  const restored = { ...ownLine };
  const [mark] = lineMarks([restored], { library, sheet: true });
  assert.deepEqual(mark, { classes: [], attributes: null, winner: null, pending: null, run: null });
});

// --- the mode gates: sheet vs element ---------------------------------------

test('element mode never marks pending (staged edits are a document-island lane)', () => {
  const staged = { ...ownLine, pending: true, pendingId: 'p1' };
  const [mark] = lineMarks([staged], { library, sheet: false });
  assert.equal(mark.pending, null);
  assert.deepEqual(mark.classes, []);
});

test('sheet mode never reads provenance (a document island prints one sheet)', () => {
  const [mark] = lineMarks([variantLine], { library, sheet: true });
  assert.deepEqual(mark, { classes: [], attributes: null, winner: null, pending: null, run: null });
});
