/**
 * @file
 * The Selector's depth policy (selection/depth-policy.js): the truth table.
 *
 * Two sources feed the one policy, the held platform modifier on the
 * pointer event (metaKey or ctrlKey, both read so a Mac Ctrl-click deeps
 * too) and the armed element picker (the selection slice's pickerArmed).
 * Either makes the gesture deep; neither makes it BOUNDARY. isDeep is the
 * boolean, policyFor the resolver policy it maps to, and both tolerate a
 * bare object or a missing flag (a synthetic event carries only what a
 * test sets).
 *
 * The resolver module the policy constants come from imports the app
 * store (webpack-style extensionless imports; the window global), so the
 * suite registers the extension-adding resolve hook and stubs window
 * before dynamically importing.
 *
 * Run: node --test modules/daedalus_ui/tests/js/depth-policy.test.mjs
 */

import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { registerHooks } from 'node:module';

registerHooks({
  resolve(specifier, context, nextResolve) {
    try {
      return nextResolve(specifier, context);
    } catch (error) {
      if (specifier.startsWith('.') || specifier.startsWith('/')) {
        return nextResolve(`${specifier}.js`, context);
      }
      throw error;
    }
  },
});
globalThis.window = globalThis;

const { isDeep, policyFor } = await import(
  '../../js/edit_mode/selection/depth-policy.js'
);
const { POLICY } = await import('../../js/edit_mode/selection/resolver.js');

const TABLE = [
  // [metaKey, ctrlKey, pickerArmed, deep]
  [false, false, false, false],
  [true, false, false, true],
  [false, true, false, true],
  [true, true, false, true],
  [false, false, true, true],
  [true, false, true, true],
  [false, true, true, true],
  [true, true, true, true],
];

test('isDeep: the modifier or the armed picker makes the gesture deep; nothing else does', () => {
  for (const [metaKey, ctrlKey, pickerArmed, deep] of TABLE) {
    assert.equal(
      isDeep({ metaKey, ctrlKey }, pickerArmed),
      deep,
      `meta=${metaKey} ctrl=${ctrlKey} armed=${pickerArmed}`,
    );
  }
});

test('policyFor maps deep to LEAF and shallow to BOUNDARY, row for row', () => {
  for (const [metaKey, ctrlKey, pickerArmed, deep] of TABLE) {
    assert.equal(
      policyFor({ metaKey, ctrlKey }, pickerArmed),
      deep ? POLICY.LEAF : POLICY.BOUNDARY,
      `meta=${metaKey} ctrl=${ctrlKey} armed=${pickerArmed}`,
    );
  }
});

test('a bare event object and an omitted flag read as shallow, never as an error', () => {
  assert.equal(isDeep({}), false);
  assert.equal(isDeep({}, undefined), false);
  assert.equal(policyFor({}), POLICY.BOUNDARY);
  assert.equal(isDeep({}, true), true);
  assert.equal(policyFor({ metaKey: true }), POLICY.LEAF);
});
