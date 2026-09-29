/**
 * Self-asserting tests for the right-click line menu model.
 *
 * Run directly (`node tests/js/line-menu.test.mjs`) or via
 * PrinterEquivalenceTest, which executes this file inside the suite so the
 * menu-item contract has PHPUnit backpressure. The old "Add to <variant>"
 * targeted push retired into the pending lane: variant
 * content changes only through the variant document and the Push/Discard
 * bar, so
 * the menu is down to the lift and the unset. Exits non-zero on the first
 * failure with a readable diff.
 */
import { strict as assert } from 'node:assert';
import { lineMenuItems, runMenuItems } from '../../js/src/code/line-menu.js';
import {
  ELEMENT_LAYER, ancestorLayerId, ancestorWearLayerId, declaredLayerId, skinLayerId, variantLayerId,
} from '../../js/src/code/layers.js';

let current = null;
const test = (name, run) => {
  current = name;
  run();
  process.stdout.write(`ok - ${name}\n`);
};

process.on('uncaughtException', (error) => {
  process.stderr.write(`FAIL - ${current}\n${error.stack ?? error.message}\n`);
  process.exit(1);
});

// --- lineMenuItems: which gestures a line offers ----------------------------

test('an own line offers only Unset (variant writes go through the pending lane)', () => {
  const items = lineMenuItems({ property: 'padding', value: '3rem', layer: ELEMENT_LAYER, variant: null, lost: false });
  assert.deepEqual(items, [{ type: 'unset' }]);
});

test('a variant-supplied line offers only the lift, winning or lost alike', () => {
  const winning = lineMenuItems({ property: 'padding', value: '2rem', layer: variantLayerId('card_left'), variant: 'card_left', lost: false });
  assert.deepEqual(winning, [{ type: 'lift' }]);
  const lost = lineMenuItems({ property: 'padding', value: '2rem', layer: variantLayerId('card_left'), variant: 'card_left', lost: true, winner: { layer: ELEMENT_LAYER, variant: null } });
  assert.deepEqual(lost, [{ type: 'lift' }]);
});

test('a skin line offers the lift and the skin doorway, never Unset, whichever skin scope supplies it', () => {
  const skin = { property: 'gap', value: 'var(--space-gutter)', layer: skinLayerId('kind:block'), variant: null, lost: false };
  assert.deepEqual(lineMenuItems(skin), [{ type: 'lift' }, { type: 'edit-skin' }]);
  const lost = { ...skin, lost: true, winner: { layer: ELEMENT_LAYER, variant: null } };
  assert.deepEqual(lineMenuItems(lost), [{ type: 'lift' }, { type: 'edit-skin' }]);
  const base = { ...skin, layer: skinLayerId('base:p') };
  assert.deepEqual(lineMenuItems(base), [{ type: 'lift' }, { type: 'edit-skin' }]);
});

test('a declared line offers only the lift (a declaration is code: no doorway, no unset)', () => {
  const declared = { property: 'margin', value: '0', layer: declaredLayerId('block_type:text'), variant: null, lost: false };
  assert.deepEqual(lineMenuItems(declared), [{ type: 'lift' }]);
});

test('no line, no menu', () => {
  assert.deepEqual(lineMenuItems(null), []);
  assert.deepEqual(lineMenuItems({ value: '3rem' }), []);
});

process.stdout.write('line-menu suite passed\n');

// --- runMenuItems: which doorways a run label offers ------------------------
// The run label names a layer run once above its lines; its right-click
// opens the layer's doorways, the provenance row's for the same layer.

const OWNER = 'container:cccccccc-1111-2222-3333-444444444444';

test('the own run offers nothing: the lines beneath it are the write target already', () => {
  assert.deepEqual(runMenuItems(ELEMENT_LAYER), []);
  assert.deepEqual(runMenuItems(null), []);
});

test('a variant run opens the variant\'s document, anchored at the island\'s own wearer when the root carries it', () => {
  const layer = variantLayerId('card_left');
  // No layer record (a document island, a caller without a stack): the
  // island's own wearer, the wear key the Inspector seats untold.
  assert.deepEqual(runMenuItems(layer), [{ type: 'edit-variant', reference: 'card_left', anchor: null }]);
  // A root carrier IS the island's own wearer: nothing to name.
  const root = runMenuItems(layer, [{ id: layer, carrier: { key: OWNER, selector: null } }]);
  assert.deepEqual(root, [{ type: 'edit-variant', reference: 'card_left', anchor: null }]);
});

test('a rule-carried variant run anchors its document at the carrier rule, the position it prints on never wearing it', () => {
  // Primary worn at the band's `pb-text` rule prints on the continued
  // position `pb-text .text-block-link`; the session must mount over the
  // rule's wearing, so the anchor is the rule's key (seatAnchor's rule
  // road: select the root, target the rule).
  const layer = variantLayerId('guide_button_primary');
  const carried = runMenuItems(layer, [
    { id: ELEMENT_LAYER, carrier: null },
    { id: layer, carrier: { key: 'container:band', selector: 'pb-text' } },
  ]);
  assert.deepEqual(carried, [{ type: 'edit-variant', reference: 'guide_button_primary', anchor: 'container:band::pb-text' }]);
});

test('a skin run opens the skin form; a declared run says it is code', () => {
  assert.deepEqual(runMenuItems(skinLayerId('kind:block')), [{ type: 'edit-skin' }]);
  assert.deepEqual(runMenuItems(skinLayerId('base:p')), [{ type: 'edit-skin' }]);
  assert.deepEqual(runMenuItems(declaredLayerId('block_type:text')), [{ type: 'declared' }]);
});

test('an ancestor rule\'s own run offers nothing: the rule prints as a rule island whose header is its seat', () => {
  // The ancestor tier never prints on an island (element-model.js
  // ownIslandLines drops it), so a doorway to the rule here would be a
  // second seat for a run no island renders.
  assert.deepEqual(runMenuItems(ancestorLayerId(OWNER, '> pb-text')), []);
});

test('a worn ancestor run opens the variant at its carrier, and only that', () => {
  const worn = ancestorWearLayerId(OWNER, 'pb-text .text-block-link', 'card_left');
  const carried = runMenuItems(worn, [{ id: worn, carrier: { key: OWNER, selector: 'pb-text' } }]);
  assert.deepEqual(carried, [
    { type: 'edit-variant', reference: 'card_left', anchor: `${OWNER}::pb-text` },
  ]);
  const root = runMenuItems(worn, [{ id: worn, carrier: { key: OWNER, selector: null } }]);
  assert.deepEqual(root, [{ type: 'edit-variant', reference: 'card_left', anchor: OWNER }]);
  // No layer record: the variant opens at the island's own wearer.
  assert.deepEqual(runMenuItems(worn), [{ type: 'edit-variant', reference: 'card_left', anchor: null }]);
});
