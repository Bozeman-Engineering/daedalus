// cspell:ignore Variante
/**
 * @file
 * Locks the wearing row's naming (wear-bar-model.js): the row label rule
 * (an axis's wearing reads its axis, a stencil-owned wearing reads its
 * owner), the origin sub-line's name (a distributed wearing names the
 * composition that put it there) and the right-click menu's headline
 * (identity facts, the owner said after the axis). The label and the
 * header read the owner through library-model.js stencilLabel over the
 * materials roster, both ownership lanes alike. The row roster, the
 * reach read and the menu roster are wear-bar.test.mjs's. Run with:
 * node --test tests/js/*.test.mjs
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { wearOrigin, wearRowHeader, wearRowLabel } from '../../js/src/wear-bar-model.js';
import { EMPTY_MATERIALS, roster, stencilRecord, variantRecord } from './materials-fixture.mjs';

const GUIDE = { axisLabels: { container_layout: 'Layout' } };
const VOCABULARY = ['style', 'container_layout', 'animation'];
// The owners: a content stencil and a file-shipped one, both named by
// their machine name in the roster (the two ownership lanes).
const MATERIALS = roster(
  stencilRecord('card', { label: 'Card' }),
  stencilRecord('hero', { label: 'Hero banner', updatable: false }),
);

const quiet = variantRecord('quiet', { label: 'Quiet' });
const columns = variantRecord('columns', { label: 'Columns', group: 'container_layout', weight: 10, description: 'Children side by side.' });
const cardLook = variantRecord('card_soft', { label: 'Soft', owned_by: 'card', description: 'Padded surface with a soft shadow.' });
const heroLook = variantRecord('hero_dark', { label: 'Dark', owned_by: 'hero' });
const cardStack = variantRecord('card_stack', { label: 'Stacked', group: 'container_layout', owned_by: 'card' });
const orphanLook = variantRecord('lost_look', { label: 'Lost', owned_by: 'gone' });

const label = (axis, worn, extra = {}) => wearRowLabel(axis, worn, {
  guide: GUIDE,
  vocabulary: VOCABULARY,
  materials: MATERIALS,
  singleAxisLabel: 'Variant',
  ...extra,
});

test('wearRowLabel: an unowned wearing reads its axis', () => {
  // The guide's declared label wins, the derived one stands in.
  assert.equal(label('style', quiet), 'Style');
  assert.equal(label('container_layout', columns), 'Layout');
  assert.equal(label('container_layout', columns, { guide: null }), 'Container layout');
});

test('wearRowLabel: the one-axis scope says the view\'s word', () => {
  // A scope offering only style names its single row "Variant" (the
  // translated word is the view's; the rule only decides when).
  assert.equal(label('style', quiet, { vocabulary: ['style'] }), 'Variant');
  assert.equal(label('style', quiet, { vocabulary: ['style'], singleAxisLabel: 'Variante' }), 'Variante');
  // An axis-gone row on a one-axis scope is not that axis: it keeps its
  // own name.
  assert.equal(label('container_layout', columns, { vocabulary: ['style'] }), 'Layout');
});

test('wearRowLabel: an owned wearing reads its owner, both lanes', () => {
  // The content stencil and the file-shipped one name their looks alike;
  // the axis word is gone from the row (the menu header still says it).
  assert.equal(label('style', cardLook), 'Card');
  assert.equal(label('style', heroLook), 'Hero banner');
  // Ownership names the row on any axis, and beats the one-axis word.
  assert.equal(label('container_layout', cardStack), 'Card');
  assert.equal(label('style', cardLook, { vocabulary: ['style'] }), 'Card');
});

test('wearRowLabel: an owner the roster lacks names by id; no record falls back to the axis', () => {
  // The offer gate drops an orphaned look before it is worn, so this is
  // the honesty fallback, never a rendered case.
  assert.equal(label('style', orphanLook), 'gone');
  // A stale row never reaches the rule, but a null record is not an
  // exception: the axis names it.
  assert.equal(label('style', null), 'Style');
  assert.equal(label('style', null, { materials: EMPTY_MATERIALS }), 'Style');
});

test('wearOrigin: a distributed wearing names the composition that put it there', () => {
  // The card scenario: Card Left's apply nested `row` onto the wrapper
  // and stamped `via`; the row's sub-line reads the origin's label.
  const cardLeft = variantRecord('card_left', { label: 'Card Left', owned_by: 'card' });
  const library = [columns, cardLeft];
  assert.deepEqual(wearOrigin({ variant: 'columns', fragment: '', via: 'card_left' }, library), { via: 'card_left', label: 'Card Left' });
  // An origin the records cannot resolve (the composition since deleted;
  // inert, nothing reaps by it) is still said, by its machine name.
  assert.deepEqual(wearOrigin({ variant: 'columns', fragment: '', via: 'card_left' }, [columns]), { via: 'card_left', label: 'card_left' });
  assert.deepEqual(wearOrigin({ variant: 'columns', fragment: '', via: 'card_left' }, null), { via: 'card_left', label: 'card_left' });
});

test('wearOrigin: a wearing with no origin has none to name', () => {
  // A chosen wearing (entryRefs() reads `via: null`), a reference read
  // before the seam carried the key, an empty origin (the server's gate
  // reads it as none), and no reference at all: nothing to say.
  assert.equal(wearOrigin({ variant: 'columns', fragment: '', via: null }, [columns]), null);
  assert.equal(wearOrigin({ variant: 'columns', fragment: '' }, [columns]), null);
  assert.equal(wearOrigin({ variant: 'columns', fragment: '', via: '' }, [columns]), null);
  assert.equal(wearOrigin(null, [columns]), null);
});

test('wearRowHeader: the menu headline carries the record\'s identity facts', () => {
  // Label, axis label, weight (when set) and the description, from the
  // same record the AI reads.
  assert.equal(wearRowHeader(columns, GUIDE, MATERIALS), 'Columns · Layout · weight 10: Children side by side.');
  // Declared axis labels win; a zero weight and an empty description say
  // nothing.
  assert.equal(wearRowHeader(quiet, { axisLabels: { style: 'Look' } }, MATERIALS), 'Quiet · Look');
  assert.equal(wearRowHeader(quiet, null, EMPTY_MATERIALS), 'Quiet · Style');
  // A stale row has no record to speak for.
  assert.equal(wearRowHeader(null, GUIDE, MATERIALS), null);
});

test('wearRowHeader: an owned wearing says its owner after the axis', () => {
  // The row reads "Card"; the header is where the axis and the owner are
  // both said, the owner by its stencil label (either lane).
  assert.equal(wearRowHeader(cardLook, GUIDE, MATERIALS), 'Soft · Style · owned by Card: Padded surface with a soft shadow.');
  assert.equal(wearRowHeader(heroLook, GUIDE, MATERIALS), 'Dark · Style · owned by Hero banner');
  assert.equal(wearRowHeader(cardStack, GUIDE, MATERIALS), 'Stacked · Layout · owned by Card');
  // The owner fact sits between the axis and the weight.
  const weighted = variantRecord('card_bold', { label: 'Bold', owned_by: 'card', weight: 5 });
  assert.equal(wearRowHeader(weighted, GUIDE, MATERIALS), 'Bold · Style · owned by Card · weight 5');
  // An owner the roster lacks is named by id (the honesty fallback).
  assert.equal(wearRowHeader(orphanLook, GUIDE, MATERIALS), 'Lost · Style · owned by gone');
});
