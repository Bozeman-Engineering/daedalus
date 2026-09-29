/**
 * @file
 * Wording a binding string for a person (hotkeys/binding.js
 * describeBinding): the platform's modifier glyphs joined bare on a Mac,
 * spelled names joined with '+' elsewhere, the key capitalized, a bare
 * 'mod' as the modifier alone, unbound as empty.
 *
 * The platform is whatever node reports (navigator.platform), so the
 * expectations are computed the way binding.js decides, never hardcoded
 * to one platform.
 *
 * Run: node --test modules/daedalus_ui/tests/js/binding.test.mjs
 */

import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { describeBinding } from '../../js/edit_mode/hotkeys/binding.js';

const isMac = /mac/i.test(navigator.platform);

test('a modifier combo reads as the platform glyphs or names with the key capitalized', () => {
  assert.equal(describeBinding('mod+shift+c'), isMac ? '⌘⇧C' : 'Ctrl+Shift+C');
  assert.equal(describeBinding('alt+d'), isMac ? '⌥D' : 'Alt+D');
  assert.equal(describeBinding('ctrl+shift+escape'), isMac ? '⌃⇧Escape' : 'Ctrl+Shift+Escape');
});

test('a bare mod reads as the platform primary modifier alone', () => {
  assert.equal(describeBinding('mod'), isMac ? '⌘' : 'Ctrl');
});

test('a bare key and an unbound binding', () => {
  assert.equal(describeBinding('v'), 'V');
  assert.equal(describeBinding('escape'), 'Escape');
  assert.equal(describeBinding(''), '');
  assert.equal(describeBinding(undefined), '');
});
