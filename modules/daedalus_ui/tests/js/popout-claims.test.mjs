/**
 * @file
 * The pop-out's claim set (popout/claims.js): the combos the leader
 * published, matched synchronously against a sensed descriptor so the
 * pop-out swallows a browser default only for a combo the leader would act
 * on. Locked here: matching on the effective (remapped) combos, the strict
 * modifier match, the typing partition, replacement on update, and that a
 * cleared set (no leader) claims nothing.
 *
 * Run: node --test modules/daedalus_ui/tests/js/popout-claims.test.mjs
 */

import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { createClaims } from '../../js/popout/claims.js';
import { parseBinding } from '../../js/edit_mode/hotkeys/binding.js';

/** The platform's primary modifier, as 'mod' resolves on this machine. */
const MOD = parseBinding('mod+z').modifiers.meta ? 'meta' : 'ctrl';

const desc = (key, ...modifiers) => ({
  key,
  modifiers: {
    meta: modifiers.includes('meta'),
    ctrl: modifiers.includes('ctrl'),
    alt: modifiers.includes('alt'),
    shift: modifiers.includes('shift'),
  },
});

test('a fresh set claims nothing', () => {
  const claims = createClaims();
  assert.equal(claims.claims(desc('z', MOD), false), false);
  assert.equal(claims.claims(desc('z', MOD), true), false);
});

test('a free combo is claimed while not typing, strictly on its modifiers', () => {
  const claims = createClaims();
  claims.update({ free: ['mod+z', 'v'], typing: [] });
  assert.equal(claims.claims(desc('z', MOD), false), true);
  assert.equal(claims.claims(desc('v'), false), true);
  assert.equal(claims.claims(desc('z'), false), false, 'bare z is not mod+z');
  assert.equal(
    claims.claims(desc('z', MOD, 'shift'), false),
    false,
    'mod+shift+z is not mod+z',
  );
  assert.equal(claims.claims(desc('d', MOD), false), false, 'unclaimed combo');
});

test('the typing partition: free combos yield inside a text field, typing combos fire only there', () => {
  const claims = createClaims();
  claims.update({ free: ['mod+z'], typing: ['tab', 'escape'] });
  assert.equal(claims.claims(desc('z', MOD), true), false, 'native text undo survives');
  assert.equal(claims.claims(desc('tab'), true), true);
  assert.equal(claims.claims(desc('escape'), true), true);
  assert.equal(claims.claims(desc('tab'), false), false);
});

test('a combo in both partitions is claimed in both states', () => {
  const claims = createClaims();
  claims.update({ free: ['tab'], typing: ['tab'] });
  assert.equal(claims.claims(desc('tab'), false), true);
  assert.equal(claims.claims(desc('tab'), true), true);
});

test('a remapped combo is what the set carries: the default is not claimed', () => {
  const claims = createClaims();
  claims.update({ free: ['alt+u'], typing: [] });
  assert.equal(claims.claims(desc('u', 'alt'), false), true);
  assert.equal(claims.claims(desc('z', MOD), false), false);
});

test('a bare-modifier hold combo matches its own keydown', () => {
  const claims = createClaims();
  claims.update({ free: ['alt'], typing: [] });
  assert.equal(claims.claims(desc('alt', 'alt'), false), true);
  assert.equal(claims.claims(desc('alt', 'alt', 'shift'), false), false);
});

test('an update replaces the set rather than merging, and unbound strings are ignored', () => {
  const claims = createClaims();
  claims.update({ free: ['mod+z', 'v'], typing: [] });
  claims.update({ free: ['v', ''], typing: [] });
  assert.equal(claims.claims(desc('z', MOD), false), false);
  assert.equal(claims.claims(desc('v'), false), true);
  claims.update({ free: [], typing: [] });
  assert.equal(claims.claims(desc('v'), false), false, 'the gate off: nothing claimed');
});

test('a cleared set (the leader gone) claims nothing until the next update', () => {
  const claims = createClaims();
  claims.update({ free: ['mod+z'], typing: ['tab'] });
  claims.clear();
  assert.equal(claims.claims(desc('z', MOD), false), false);
  assert.equal(claims.claims(desc('tab'), true), false);
  claims.update({ free: ['mod+z'], typing: [] });
  assert.equal(claims.claims(desc('z', MOD), false), true);
});
