/**
 * Self-asserting tests for the menu primitive's state machine
 * (js/menu/menu-state.js), the shared spine piece every popup menu adapts
 * into.
 *
 * The state machine is deliberately pure (no DOM, no Drupal globals), so
 * these tests cover the whole shared contract directly: the item grammar
 * ({label, icon?, disabled?+reason?, title?, hint?, checked?, danger?,
 * confirm?, run} and {separator}), open/replace/close and onClose
 * exactly-once, the two placements (pointer coordinates or an anchor)
 * with the anchor's explicit `align` intent and an optional header,
 * wrapping keyboard navigation with disabled items focusable and
 * separators stepped over, the in-menu two-step confirm (arm,
 * disarm-by-rearming-elsewhere, run). Where a placement lands is the
 * floating surface's math (tests/js/placement.test.mjs); the plain-DOM
 * host (js/menu/menu.js) renders this machine as the surface's content,
 * and focus and the surface's dismissal are DOM concerns covered by the
 * FJS tier.
 *
 * Run with `node --test tests/js/*.test.mjs` from the module root.
 */
import { strict as assert } from 'node:assert';
import { test, beforeEach } from 'node:test';

const {
  subscribe,
  getState,
  isOpen,
  open,
  close,
  setActive,
  moveActive,
  moveActiveToFirst,
  moveActiveToLast,
  activate,
} = await import('../../js/menu/menu-state.js');

const item = (overrides = {}) => ({ label: 'Duplicate', run: () => {}, ...overrides });

// The machine is a singleton; every test starts closed.
beforeEach(() => close());

test('the grammar rejects an item without a label', () => {
  assert.throws(() => open({ x: 0, y: 0, items: [{ run: () => {} }] }), /requires a label/);
  assert.equal(isOpen(), false);
});

test('the grammar rejects an enabled item without run()', () => {
  assert.throws(() => open({ x: 0, y: 0, items: [{ label: 'Broken' }] }), /requires run\(\)/);
});

test('a disabled item may omit run() — it only exists to explain itself', () => {
  assert.equal(open({ x: 0, y: 0, items: [item({ disabled: true, run: undefined, reason: 'File-shipped' })] }), true);
  assert.equal(getState().items[0].reason, 'File-shipped');
});

test('no items means NO menu: open() refuses and reports it', () => {
  assert.equal(open({ x: 10, y: 10, items: [] }), false);
  assert.equal(isOpen(), false);
});

test('a menu needs a placement: coordinates or an anchor', () => {
  assert.throws(() => open({ items: [item()] }), /opens at \{x, y\} or under an anchor/);
  assert.throws(() => open({ x: 4, items: [item()] }), /opens at \{x, y\} or under an anchor/);
  const anchor = { tag: 'button' };
  assert.equal(open({ anchor, items: [item()] }), true);
  assert.equal(getState().anchor, anchor);
  assert.equal(getState().x, null);
});

test('align is an explicit intent: start by default, end when the caller says so, nothing else', () => {
  const anchor = { tag: 'button' };
  assert.equal(open({ anchor, items: [item()] }), true);
  assert.equal(getState().align, 'start');
  assert.equal(open({ anchor, align: 'end', items: [item()] }), true);
  assert.equal(getState().align, 'end');
  assert.throws(() => open({ anchor, align: 'center', items: [item()] }), /aligns 'start' or 'end'/);
  // A pointer placement carries the default too; the surface ignores it.
  assert.equal(open({ x: 4, y: 4, items: [item()] }), true);
  assert.equal(getState().align, 'start');
});

test('normalization fills the full grammar with defaults', () => {
  open({ x: 5, y: 6, items: [item()] });
  assert.deepEqual(getState().items[0], {
    separator: false,
    label: 'Duplicate',
    icon: null,
    disabled: false,
    reason: null,
    title: null,
    hint: null,
    checked: null,
    danger: false,
    confirm: false,
    confirmLabel: null,
    run: getState().items[0].run,
  });
  assert.equal(getState().activeIndex, 0);
  assert.equal(getState().armedIndex, null);
  assert.equal(getState().header, null);
});

test('checked, hint, title, and header carry through; checked is a boolean or absent', () => {
  open({
    x: 0,
    y: 0,
    header: 'Show',
    items: [
      item({ label: 'Full structure', checked: true, hint: 'Alt+F', title: 'Everything the blueprint knows' }),
      item({ label: 'Markup', checked: false }),
      item({ label: 'Plain', checked: 'yes' }),
    ],
  });
  const { header, items } = getState();
  assert.equal(header, 'Show');
  assert.equal(items[0].checked, true);
  assert.equal(items[0].hint, 'Alt+F');
  assert.equal(items[0].title, 'Everything the blueprint knows');
  assert.equal(items[1].checked, false);
  assert.equal(items[2].checked, null, 'a non-boolean is not a checkable item');
  close();
  open({ x: 0, y: 0, header: '', items: [item()] });
  assert.equal(getState().header, null, 'an empty header is no header');
});

test('a string confirm is the armed label; true leaves it to the host', () => {
  open({
    x: 0,
    y: 0,
    items: [item({ confirm: 'Confirm delete?' }), item({ label: 'Trash', confirm: true })],
  });
  assert.equal(getState().items[0].confirmLabel, 'Confirm delete?');
  assert.equal(getState().items[1].confirm, true);
  assert.equal(getState().items[1].confirmLabel, null);
});

test('separators never lead, trail, or double; a separators-only list is no menu', () => {
  assert.equal(open({ x: 0, y: 0, items: [{ separator: true }, { separator: true }] }), false);
  open({
    x: 0,
    y: 0,
    items: [
      { separator: true },
      item({ label: 'A' }),
      { separator: true },
      { separator: true },
      item({ label: 'B' }),
      { separator: true },
    ],
  });
  assert.deepEqual(
    getState().items.map((entry) => (entry.separator ? '---' : entry.label)),
    ['A', '---', 'B'],
  );
});

test('arrow navigation wraps and includes disabled items (focusable, so their reason is reachable)', () => {
  open({ x: 0, y: 0, items: [item(), item({ label: 'B', disabled: true, run: undefined }), item({ label: 'C' })] });
  moveActive(1);
  assert.equal(getState().activeIndex, 1);
  moveActive(1);
  assert.equal(getState().activeIndex, 2);
  moveActive(1);
  assert.equal(getState().activeIndex, 0, 'ArrowDown wraps to the top');
  moveActive(-1);
  assert.equal(getState().activeIndex, 2, 'ArrowUp wraps to the bottom');
  moveActiveToFirst();
  assert.equal(getState().activeIndex, 0);
  moveActiveToLast();
  assert.equal(getState().activeIndex, 2);
});

test('navigation steps over separators; they are never active', () => {
  open({ x: 0, y: 0, items: [item({ label: 'A' }), { separator: true }, item({ label: 'B' })] });
  moveActive(1);
  assert.equal(getState().activeIndex, 2, 'ArrowDown skips the rule');
  moveActive(1);
  assert.equal(getState().activeIndex, 0, 'and wraps');
  moveActive(-1);
  assert.equal(getState().activeIndex, 2, 'ArrowUp skips the rule on the way back');
  setActive(1);
  assert.equal(getState().activeIndex, 2, 'hovering a rule activates nothing');
  assert.equal(activate(1), 'noop');
  assert.equal(isOpen(), true);
});

test('setActive ignores out-of-range indexes', () => {
  open({ x: 0, y: 0, items: [item()] });
  setActive(5);
  setActive(-1);
  assert.equal(getState().activeIndex, 0);
});

test('activating a disabled item does nothing', () => {
  let ran = false;
  open({ x: 0, y: 0, items: [item({ disabled: true, run: () => { ran = true; } })] });
  assert.equal(activate(0), 'noop');
  assert.equal(isOpen(), true);
  assert.equal(ran, false);
});

test('a plain item runs AFTER the menu closes, and onClose fires', () => {
  const order = [];
  open({
    x: 0,
    y: 0,
    items: [item({ run: () => order.push(`run (menu open: ${isOpen()})`) })],
    onClose: () => order.push('closed'),
  });
  assert.equal(activate(0), 'run');
  assert.deepEqual(order, ['closed', 'run (menu open: false)']);
});

test('a checked item activates like any other: the menu closes, then run() flips the axis', () => {
  let visible = true;
  open({ x: 0, y: 0, items: [item({ label: 'Markup', checked: visible, run: () => { visible = !visible; } })] });
  assert.equal(activate(0), 'run');
  assert.equal(visible, false);
  assert.equal(isOpen(), false);
});

test('confirm is a two-step: first activation arms, second runs', () => {
  let ran = 0;
  open({ x: 0, y: 0, items: [item({ confirm: 'Confirm delete?', run: () => { ran += 1; } })] });
  assert.equal(activate(0), 'armed');
  assert.equal(getState().armedIndex, 0);
  assert.equal(isOpen(), true, 'arming keeps the menu open');
  assert.equal(ran, 0);
  assert.equal(activate(0), 'run');
  assert.equal(ran, 1);
  assert.equal(isOpen(), false);
});

test('arming a different confirm item disarms the first — only one armed item ever', () => {
  open({ x: 0, y: 0, items: [item({ confirm: true }), item({ label: 'B', confirm: true })] });
  activate(0);
  assert.equal(getState().armedIndex, 0);
  activate(1);
  assert.equal(getState().armedIndex, 1, 'the second arm moved, not stacked');
  assert.equal(getState().activeIndex, 1);
});

test('close is idempotent and onClose fires exactly once', () => {
  let closes = 0;
  open({ x: 0, y: 0, items: [item()], onClose: () => { closes += 1; } });
  close();
  close();
  assert.equal(closes, 1);
});

test('a second open replaces the first: its onClose fires, the new menu stands', () => {
  let firstClosed = false;
  open({ x: 0, y: 0, items: [item()], onClose: () => { firstClosed = true; } });
  assert.equal(open({ x: 50, y: 60, items: [item({ label: 'Second' })] }), true);
  assert.equal(firstClosed, true);
  assert.equal(getState().x, 50);
  assert.equal(getState().items[0].label, 'Second');
});

test('subscribers hear opens, navigation, and closes', () => {
  let notified = 0;
  const unsubscribe = subscribe(() => { notified += 1; });
  open({ x: 0, y: 0, items: [item(), item({ label: 'B' })] });
  moveActive(1);
  close();
  assert.equal(notified, 3);
  unsubscribe();
  open({ x: 0, y: 0, items: [item()] });
  assert.equal(notified, 3, 'unsubscribed listeners stay silent');
});
