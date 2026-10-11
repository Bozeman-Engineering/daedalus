/**
 * Self-asserting tests for the row-facts seam
 * (js/edit_mode/components/page-elements/row-facts-registry.js): the
 * pure registry the tree's rows and the row menu read.
 *
 * Covered: a provider's facts and doorways read in registration order
 * across providers; a key no provider speaks about answers nothing, as
 * does a null address (a keyless row); doorways lead the row menu ahead
 * of the verbs behind one separator, no separator when either side is
 * empty (withDoorways); re-registering an id replaces the earlier
 * provider in place (its position kept, its answers gone); unregister
 * through the returned function and its no-op once replaced; the
 * registry's announcements (a registration, an unregistration, a
 * provider's own subscribe); a malformed provider refused at
 * registration; a throwing provider contained for the read.
 *
 * Run with `node --test tests/js/row-facts.test.mjs` from the module
 * root.
 */
import { strict as assert } from 'node:assert';
import { test, afterEach } from 'node:test';
import {
  registerRowFacts,
  rowFacts,
  rowDoorways,
  withDoorways,
  subscribeRowFacts,
} from '../../js/edit_mode/components/page-elements/row-facts-registry.js';

const BLOCK = 'block:aaaaaaaa-1111-2222-3333-444444444444';
const PART = `${BLOCK}::.quote`;

const dot = (hue, title) => ({ mark: 'dot', hue, title });
const doorway = (label) => ({ label, run: () => {} });

// The registry is a singleton; every test unregisters what it registered.
const cleanups = [];
const register = (provider) => {
  const unregister = registerRowFacts(provider);
  cleanups.push(unregister);
  return unregister;
};
afterEach(() => {
  cleanups.splice(0).forEach((unregister) => unregister());
});

test('facts and doorways read every provider in registration order', () => {
  register({
    id: 'second',
    facts: (address) => (address === BLOCK ? [dot('red', 'Wears Large')] : []),
    doorways: (address) => (address === BLOCK ? [doorway('Edit Large')] : []),
  });
  register({
    id: 'first',
    facts: () => [dot('blue', 'Styled on itself')],
    doorways: () => [doorway('Show its own styles in code')],
  });
  assert.deepEqual(rowFacts(BLOCK), [
    dot('red', 'Wears Large'),
    dot('blue', 'Styled on itself'),
  ]);
  assert.deepEqual(
    rowDoorways(BLOCK).map((item) => item.label),
    ['Edit Large', 'Show its own styles in code'],
  );
});

test('a part address is asked as itself, the key::selector pair', () => {
  const asked = [];
  register({ id: 'spy', facts: (address) => (asked.push(address), []) });
  rowFacts(PART);
  assert.deepEqual(asked, [PART]);
});

test('a key no provider speaks about, and a keyless row, answer nothing', () => {
  register({
    id: 'variants',
    facts: (address) => (address === BLOCK ? [dot('red', 'Wears Large')] : []),
    doorways: (address) => (address === BLOCK ? [doorway('Edit Large')] : []),
  });
  assert.deepEqual(rowFacts('block:other'), []);
  assert.deepEqual(rowDoorways('block:other'), []);
  assert.deepEqual(rowFacts(null), []);
  assert.deepEqual(rowDoorways(null), []);
  assert.deepEqual(rowFacts(undefined), []);
});

test('a provider may offer facts alone or doorways alone, and may answer nothing', () => {
  register({ id: 'facts-only', facts: () => [{ mark: 'text', text: '3', title: '3 rows' }] });
  register({ id: 'doorways-only', doorways: () => [doorway('Open')] });
  register({ id: 'silent', facts: () => undefined, doorways: () => null });
  assert.deepEqual(rowFacts(BLOCK), [{ mark: 'text', text: '3', title: '3 rows' }]);
  assert.deepEqual(rowDoorways(BLOCK).map((item) => item.label), ['Open']);
});

test('doorways lead the row menu ahead of the verbs, behind one separator', () => {
  const edit = doorway('Edit Large');
  const rename = { label: 'Rename', run: () => {} };
  const trash = { label: 'Trash', run: () => {} };
  assert.deepEqual(withDoorways([edit], [rename, trash]), [
    edit,
    { separator: true },
    rename,
    trash,
  ]);
});

test('no separator when either side of the row menu is empty', () => {
  const edit = doorway('Edit Large');
  const rename = { label: 'Rename', run: () => {} };
  assert.deepEqual(withDoorways([], [rename]), [rename]);
  assert.deepEqual(withDoorways([edit], []), [edit]);
  assert.deepEqual(withDoorways([], []), []);
});

test('re-registering an id replaces the provider in place: position kept, old answers gone', () => {
  register({ id: 'a', facts: () => [dot('a1', 'a1')] });
  register({ id: 'b', facts: () => [dot('b1', 'b1')] });
  register({ id: 'a', facts: () => [dot('a2', 'a2')] });
  assert.deepEqual(
    rowFacts(BLOCK).map((fact) => fact.hue),
    ['a2', 'b1'],
  );
});

test('unregister removes the provider; it is a no-op once another registration replaced it', () => {
  const first = register({ id: 'a', facts: () => [dot('a1', 'a1')] });
  register({ id: 'b', facts: () => [dot('b1', 'b1')] });
  const second = register({ id: 'a', facts: () => [dot('a2', 'a2')] });
  // The replaced registration's unregister does not take the replacement.
  first();
  assert.deepEqual(rowFacts(BLOCK).map((fact) => fact.hue), ['a2', 'b1']);
  second();
  assert.deepEqual(rowFacts(BLOCK).map((fact) => fact.hue), ['b1']);
});

test('the registry announces a registration, an unregistration and a provider\'s own change', () => {
  let announced = 0;
  const unsubscribe = subscribeRowFacts(() => {
    announced += 1;
  });
  let announce = null;
  const unregister = register({
    id: 'live',
    facts: () => [],
    subscribe: (callback) => {
      announce = callback;
    },
  });
  assert.equal(announced, 1);
  announce();
  assert.equal(announced, 2);
  unregister();
  assert.equal(announced, 3);
  // A spent unregister announces nothing.
  unregister();
  assert.equal(announced, 3);
  unsubscribe();
  register({ id: 'quiet' });
  assert.equal(announced, 3);
});

test('a malformed provider is refused at registration', () => {
  assert.throws(() => registerRowFacts(), /requires a string id/);
  assert.throws(() => registerRowFacts({ id: '' }), /requires a string id/);
  assert.throws(() => registerRowFacts({ id: 'x', facts: [] }), /names facts but it is not a function/);
  assert.throws(() => registerRowFacts({ id: 'x', doorways: 'Edit' }), /names doorways but it is not a function/);
  assert.throws(() => registerRowFacts({ id: 'x', subscribe: true }), /names subscribe but it is not a function/);
  assert.deepEqual(rowFacts(BLOCK), []);
});

test('a throwing provider is contained for the read; the others still answer', () => {
  const errors = [];
  const { error } = console;
  console.error = (...args) => errors.push(args);
  try {
    register({
      id: 'broken',
      facts: () => {
        throw new Error('boom');
      },
    });
    register({ id: 'fine', facts: () => [dot('blue', 'Styled on itself')] });
    assert.deepEqual(rowFacts(BLOCK), [dot('blue', 'Styled on itself')]);
    assert.equal(errors.length, 1);
    assert.match(errors[0][0], /"broken" failed answering facts/);
  } finally {
    console.error = error;
  }
});
