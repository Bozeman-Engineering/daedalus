/**
 * Self-asserting tests for the Show menu's pure item composer
 * (js/edit_mode/components/page-elements/show-menu-entries.js): the view
 * toggles first, the registered Show-menu items behind one separator,
 * no separator when either side is empty, a registered item's checked()
 * read at composition time, and a re-registration by id replacing the
 * earlier item in place (withShowMenuItem, the registry's one write).
 *
 * The kebab (show-menu.js) hands the result to the menu primitive; the
 * primitive's own grammar is covered by tests/js/menu.test.mjs.
 *
 * Run with `node --test tests/js/show-menu-entries.test.mjs` from the
 * module root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import {
  showMenuEntries,
  withShowMenuItem,
} from '../../js/edit_mode/components/page-elements/show-menu-entries.js';

const MARKUP = { id: 'markup', label: 'Markup' };
const item = (overrides = {}) => ({
  id: 'explode',
  label: 'Explode',
  checked: () => false,
  run: () => {},
  ...overrides,
});

test('toggles only: one checked item per toggle, no separator', () => {
  const entries = showMenuEntries([MARKUP], { markup: true }, () => {}, []);
  assert.deepEqual(
    entries.map(({ label, checked }) => ({ label, checked })),
    [{ label: 'Markup', checked: true }],
  );
  assert.equal(entries.some((entry) => entry.separator), false);
});

test('a toggle runs the visibility flip for its own id', () => {
  const writes = [];
  const [on] = showMenuEntries([MARKUP], { markup: true }, (change) => writes.push(change), []);
  const [off] = showMenuEntries([MARKUP], {}, (change) => writes.push(change), []);
  on.run();
  off.run();
  assert.deepEqual(writes, [
    { id: 'markup', visible: false },
    { id: 'markup', visible: true },
  ]);
});

test('items only: the registered items, no separator', () => {
  const run = () => {};
  const entries = showMenuEntries([], {}, () => {}, [
    item({ icon: '<svg></svg>', run }),
  ]);
  assert.deepEqual(entries, [
    { label: 'Explode', icon: '<svg></svg>', checked: false, run },
  ]);
});

test('both: the toggles, one separator, then the items', () => {
  const entries = showMenuEntries([MARKUP], {}, () => {}, [item()]);
  assert.deepEqual(
    entries.map((entry) => (entry.separator ? 'separator' : entry.label)),
    ['Markup', 'separator', 'Explode'],
  );
  assert.equal(entries.filter((entry) => entry.separator).length, 1);
});

test('an item without an icon carries none', () => {
  const [entry] = showMenuEntries([], {}, () => {}, [item()]);
  assert.equal('icon' in entry, false);
});

test('a registered item\'s checked() is read at composition time', () => {
  let open = false;
  const items = [item({ checked: () => open })];
  assert.equal(showMenuEntries([], {}, () => {}, items)[0].checked, false);
  open = true;
  assert.equal(showMenuEntries([], {}, () => {}, items)[0].checked, true);
});

test('a non-boolean checked() answer reads as unchecked', () => {
  const [entry] = showMenuEntries([], {}, () => {}, [item({ checked: () => 'yes' })]);
  assert.equal(entry.checked, false);
});

test('re-registering an id replaces the earlier item in place', () => {
  const first = item({ label: 'First' });
  const other = item({ id: 'other', label: 'Other' });
  const replacement = item({ label: 'Second' });
  const before = withShowMenuItem(withShowMenuItem([], first), other);
  const after = withShowMenuItem(before, replacement);
  assert.deepEqual(
    after.map(({ id, label }) => ({ id, label })),
    [
      { id: 'explode', label: 'Second' },
      { id: 'other', label: 'Other' },
    ],
  );
  assert.equal(after[0], replacement);
  // The input list is not mutated.
  assert.equal(before[0], first);
  // The composed menu shows the replacement, once.
  const entries = showMenuEntries([], {}, () => {}, after);
  assert.deepEqual(
    entries.map((entry) => entry.label),
    ['Second', 'Other'],
  );
});

test('a new id appends after the registered items', () => {
  const items = withShowMenuItem([item()], item({ id: 'other', label: 'Other' }));
  assert.deepEqual(
    items.map((entry) => entry.id),
    ['explode', 'other'],
  );
});
