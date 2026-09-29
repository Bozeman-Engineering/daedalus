/**
 * @file
 * Resolution parity (hotkeys/resolve.js): the pure loop the leader's
 * dispatcher runs over its registry and the claim set it publishes to
 * pop-outs, locked to the dispatcher's pre-split rules: the typing
 * partition (while typing only 'typing' contexts fire, and never
 * otherwise), the context ladder's priority, specificity, and later-wins
 * on a full tie.
 *
 * Bindings are built the way hotkeys.js register() stores them (`when`
 * normalized to an array, `parsed` from binding.js), and the store reads
 * arrive through the `isActive(context, binding)` callback, so the loop
 * is exercised without a store.
 *
 * Run: node --test modules/daedalus_ui/tests/js/resolve.test.mjs
 */

import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { registerHooks } from 'node:module';
import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

registerHooks({
  resolve(specifier, context, nextResolve) {
    try {
      return nextResolve(specifier, context);
    } catch (error) {
      if (
        (specifier.startsWith('.') || specifier.startsWith('/')) &&
        existsSync(fileURLToPath(new URL(`${specifier}.js`, context.parentURL)))
      ) {
        return nextResolve(`${specifier}.js`, context);
      }
      throw error;
    }
  },
});

const { parseBinding } = await import('../../js/edit_mode/hotkeys/binding.js');
const { resolve, claimSet, CONTEXT_PRIORITY } = await import(
  '../../js/edit_mode/hotkeys/resolve.js'
);

const MOD = /mac/i.test(navigator.platform) ? 'meta' : 'ctrl';

const binding = (id, key, when = 'global', extra = {}) => ({
  id,
  key,
  when: Array.isArray(when) ? when : [when],
  parsed: parseBinding(key),
  ...extra,
});

const desc = (key, modifiers = {}) => ({
  key,
  modifiers: { meta: false, ctrl: false, alt: false, shift: false, ...modifiers },
});

/**
 * A context vector: the contexts live now, global always.
 */
const active = (live) => (context) =>
  context === 'global' || live.includes(context);

test('the ladder is the dispatcher\'s: typing above lift, editing, selection, global', () => {
  assert.deepEqual(
    Object.entries(CONTEXT_PRIORITY).sort((a, b) => b[1] - a[1]).map(([name]) => name),
    ['typing', 'lift', 'editing', 'selection', 'global'],
  );
});

test('typing partition: while typing only typing contexts fire, and never otherwise', () => {
  const undo = binding('history.undo', 'mod+z');
  const escape = binding('edit.cancel', 'escape', ['editing', 'typing']);
  const bindings = [undo, escape];

  assert.equal(resolve(bindings, desc('z', { [MOD]: true }), true, active([])), null);
  assert.equal(resolve(bindings, desc('z', { [MOD]: true }), false, active([])), undo);
  // The typing twin fires inside a widget whether or not the store knows
  // an edit is open.
  assert.equal(resolve(bindings, desc('escape'), true, active([])), escape);
  // Not typing: the same binding needs its editing context live.
  assert.equal(resolve(bindings, desc('escape'), false, active([])), null);
  assert.equal(resolve(bindings, desc('escape'), false, active(['editing'])), escape);
});

test('the open edit outranks the selection and the global ladder on the same key', () => {
  const cancel = binding('edit.cancel', 'escape', ['editing', 'typing']);
  const clear = binding('selection.escape', 'escape');
  const enter = binding('edit.enter', 'enter', 'selection');
  // No edit open: Escape is the global ladder's.
  assert.equal(resolve([cancel, clear], desc('escape'), false, active(['selection'])), clear);
  // An edit open: its cancel wins; the ladder is the next press's.
  assert.equal(resolve([cancel, clear], desc('escape'), false, active(['selection', 'editing'])), cancel);
  // Enter is the selection's, never typing's: an open editor keeps its
  // newline.
  assert.equal(resolve([enter], desc('enter'), false, active(['selection'])), enter);
  assert.equal(resolve([enter], desc('enter'), true, active(['selection', 'editing'])), null);
  assert.equal(resolve([enter], desc('enter'), false, active([])), null);
});

test('a higher context wins over a lower one whatever the registration order', () => {
  const global = binding('panel.toggle', 'delete');
  const remove = binding('daedalus_layout.remove', 'delete', 'selection');
  assert.equal(resolve([remove, global], desc('delete'), false, active(['selection'])), remove);
  assert.equal(resolve([global, remove], desc('delete'), false, active(['selection'])), remove);
  // Nothing selected: the selection binding is not live, the global one is.
  assert.equal(resolve([remove, global], desc('delete'), false, active([])), global);
});

test('the lift context outranks the selection and traversal grammars on the same key', () => {
  const liftKey = binding('selector.lift', 'space', 'selection');
  const drop = binding('lift.drop', 'space', 'lift');
  const traverse = binding('selection.traverse_next', 'arrowdown');
  const walk = binding('lift.next', 'arrowdown', 'lift');
  assert.equal(resolve([liftKey, drop], desc('space'), false, active(['selection'])), liftKey);
  assert.equal(resolve([liftKey, drop], desc('space'), false, active(['selection', 'lift'])), drop);
  assert.equal(resolve([walk, traverse], desc('arrowdown'), false, active([])), traverse);
  assert.equal(resolve([walk, traverse], desc('arrowdown'), false, active(['lift'])), walk);
});

test('on a full tie the later registration wins', () => {
  const first = binding('first', 'enter', 'selection');
  const second = binding('second', 'enter', 'selection');
  assert.equal(resolve([first, second], desc('enter'), false, active(['selection'])), second);
  assert.equal(resolve([second, first], desc('enter'), false, active(['selection'])), first);
});

test('a multi-context binding fires at the highest of its live contexts', () => {
  const both = binding('both', 'escape', ['global', 'editing']);
  const selection = binding('sel', 'escape', 'selection');
  // Only the global context live: global (10) loses to selection (30).
  assert.equal(resolve([both, selection], desc('escape'), false, active(['selection'])), selection);
  // Editing too: editing (40) beats selection.
  assert.equal(resolve([both, selection], desc('escape'), false, active(['selection', 'editing'])), both);
});

test('matching is strict on modifiers and an unbound binding never resolves', () => {
  const undo = binding('history.undo', 'mod+z');
  const redo = binding('history.redo', 'mod+shift+z');
  const unbound = binding('daedalus_stylish.toggle', '');
  assert.equal(resolve([undo, redo], desc('z', { [MOD]: true }), false, active([])), undo);
  assert.equal(resolve([undo, redo], desc('z', { [MOD]: true, shift: true }), false, active([])), redo);
  assert.equal(resolve([undo, redo], desc('z'), false, active([])), null);
  assert.equal(unbound.parsed, null);
  assert.equal(resolve([unbound], desc('t'), false, active([])), null);
});

test('isActive receives the context and the binding, never for typing', () => {
  const seen = [];
  const b = binding('b', 'x', ['selection', 'typing']);
  resolve([b], desc('x'), false, (context, bound) => {
    seen.push([context, bound.id]);
    return true;
  });
  assert.deepEqual(seen, [['selection', 'b']]);
  seen.length = 0;
  resolve([b], desc('x'), true, (context, bound) => {
    seen.push([context, bound.id]);
    return true;
  });
  assert.deepEqual(seen, []);
});

test('the claim set partitions the live combos by typing state', () => {
  const bindings = [
    binding('history.undo', 'mod+z'),
    binding('daedalus_layout.remove', 'delete', 'selection'),
    binding('edit.cancel', 'escape', ['editing', 'typing']),
    binding('selection.traverse_next', 'arrowdown'),
    binding('daedalus_stylish.toggle', ''),
  ];
  assert.deepEqual(claimSet(bindings, active([])), {
    free: ['mod+z', 'arrowdown'],
    typing: ['escape'],
  });
  assert.deepEqual(claimSet(bindings, active(['selection'])), {
    free: ['mod+z', 'delete', 'arrowdown'],
    typing: ['escape'],
  });
  assert.deepEqual(claimSet(bindings, active(['editing'])), {
    free: ['mod+z', 'escape', 'arrowdown'],
    typing: ['escape'],
  });
});

test('the claim set carries the effective (remapped) combo once per key', () => {
  const bindings = [
    binding('a', 'mod+k'),
    binding('b', 'mod+k', 'selection'),
    binding('c', 'alt+u'),
  ];
  assert.deepEqual(claimSet(bindings, active(['selection'])), {
    free: ['mod+k', 'alt+u'],
    typing: [],
  });
});
