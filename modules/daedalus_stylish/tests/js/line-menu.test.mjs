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
import { lineMenuItems } from '../../js/src/code/line-menu.js';
import { DECLARED_LAYER, ELEMENT_LAYER, SKIN_LAYER, variantLayerId } from '../../js/src/code/layers.js';

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

test('a skin line offers the lift and the skin doorway, never Unset', () => {
  const skin = { property: 'gap', value: 'var(--space-gutter)', layer: SKIN_LAYER, variant: null, lost: false };
  assert.deepEqual(lineMenuItems(skin), [{ type: 'lift' }, { type: 'edit-skin' }]);
  const lost = { ...skin, lost: true, winner: { layer: ELEMENT_LAYER, variant: null } };
  assert.deepEqual(lineMenuItems(lost), [{ type: 'lift' }, { type: 'edit-skin' }]);
});

test('a declared line offers only the lift (a declaration is code: no doorway, no unset)', () => {
  const declared = { property: 'margin', value: '0', layer: DECLARED_LAYER, variant: null, lost: false };
  assert.deepEqual(lineMenuItems(declared), [{ type: 'lift' }]);
});

test('no line, no menu', () => {
  assert.deepEqual(lineMenuItems(null), []);
  assert.deepEqual(lineMenuItems({ value: '3rem' }), []);
});

process.stdout.write('line-menu suite passed\n');
