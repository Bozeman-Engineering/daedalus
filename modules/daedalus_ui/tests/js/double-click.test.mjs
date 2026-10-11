/**
 * @file
 * The double-click predicate (selection/double-click.js): the truth table
 * over the OS click count a `click` event carries as `detail`.
 *
 * Browsers fire `dblclick` only at count 2, and macOS keeps counting fast
 * stationary clicks past it, so Edit Mode reads the gesture off the click
 * itself: an even count of 2 or more closes a pair (2, 4, 6...), an odd
 * count is a single click at whatever depth the last pair left (a
 * triple-click is not a second drill), and 0 or a missing `detail` (a
 * synthetic or keyboard-generated click) is a single click too. The
 * module is pure: no store, no globals.
 *
 * Run: node --test modules/daedalus_ui/tests/js/double-click.test.mjs
 */

import { strict as assert } from 'node:assert';
import { test } from 'node:test';

import { isDoubleClick } from '../../js/edit_mode/selection/double-click.js';

test('count 2 closes the first pair', () => {
  assert.equal(isDoubleClick({ detail: 2 }), true);
});

test('count 4 closes the second pair: the fast stationary double-click macOS reports after the first', () => {
  assert.equal(isDoubleClick({ detail: 4 }), true);
});

test('count 1 is a single click', () => {
  assert.equal(isDoubleClick({ detail: 1 }), false);
});

test('count 3 is a single click at the new depth, not a second drill', () => {
  assert.equal(isDoubleClick({ detail: 3 }), false);
});

test('count 0, a synthetic or keyboard-generated click, is a single click', () => {
  assert.equal(isDoubleClick({ detail: 0 }), false);
});

test('a missing detail is a single click', () => {
  assert.equal(isDoubleClick({}), false);
});
