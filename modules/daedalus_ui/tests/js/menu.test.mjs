/**
 * Self-asserting tests for the menu primitive's state machine
 * (js/menu/menu-state.js), the shared spine piece every popup menu adapts
 * into.
 *
 * The state machine is deliberately pure (no DOM, no Drupal globals), so
 * these tests cover the whole shared contract directly: the item grammar
 * ({label, icon?, disabled?+reason?, title?, hint?, checked?, danger?,
 * confirm?, run, preview?}, the submenu row {label, items} with its
 * one-level flyout, {separator}, {caption} and the read-only
 * {label, meter, hint?} row), open/replace/close and onClose
 * exactly-once, the two placements (pointer coordinates or an anchor)
 * with the anchor's explicit `align` intent and an optional header,
 * wrapping keyboard navigation with disabled items focusable and
 * separators stepped over, the in-menu two-step confirm (arm,
 * disarm-by-rearming-elsewhere, run), and the preview lifecycle (the
 * active item, pointer or keyboard, is told it is active and told when
 * it stops being so, close before run), and the flyout (opened by
 * activation or openSub with its first focusable item active, or by a
 * pointer with none; the keyboard moving and wrapping inside it; closed
 * alone by closeSub and by the active top row moving elsewhere; a run
 * inside it closing the whole menu; confirm and preview inside it).
 * Where a placement lands, the flyout's included, is the floating
 * surface's math (tests/js/placement.test.mjs); the plain-DOM host
 * (js/menu/menu.js) renders this machine as the surface's content, and
 * focus, Escape and the surface's dismissal are DOM concerns covered by
 * the FJS tier.
 *
 * Run with `node --test tests/js/*.test.mjs` from the module root.
 */
import { strict as assert } from 'node:assert';
import { test, beforeEach } from 'node:test';

const {
  subscribe,
  getState,
  isOpen,
  isFocusable,
  isSubmenu,
  open,
  close,
  closeSub,
  openSub,
  setActive,
  setSubActive,
  moveActive,
  moveActiveToFirst,
  moveActiveToLast,
  activate,
  activateSub,
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
    meter: null,
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
    preview: null,
    items: null,
  });
  assert.equal(getState().activeIndex, 0);
  assert.equal(getState().armedIndex, null);
  assert.equal(getState().sub, null);
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

// The preview lifecycle: `preview(active)` follows the active item.
test('the first item previews on open, and a keyboard move or a pointer enter hands the preview over, off before on', () => {
  const heard = [];
  open({ x: 0, y: 0, items: [
    item({ label: 'A', preview: (active) => heard.push(`A:${active}`) }),
    item({ label: 'B', preview: (active) => heard.push(`B:${active}`) }),
    item({ label: 'C' }),
  ] });
  assert.deepEqual(heard, ['A:true'], 'focus starts on the first item, so it previews');
  moveActive(1);
  assert.deepEqual(heard, ['A:true', 'A:false', 'B:true']);
  setActive(1);
  assert.deepEqual(heard, ['A:true', 'A:false', 'B:true'], 'the same item again is not a change');
  setActive(2);
  assert.deepEqual(heard, ['A:true', 'A:false', 'B:true', 'B:false'], 'an item with no preview is active silently');
  moveActiveToFirst();
  assert.deepEqual(heard, ['A:true', 'A:false', 'B:true', 'B:false', 'A:true']);
});

test('close ends the preview before onClose, and a pick reads preview(false) then run()', () => {
  const order = [];
  open({
    x: 0,
    y: 0,
    items: [
      item({ label: 'A', preview: (active) => order.push(`A:${active}`), run: () => order.push('A:run') }),
      item({ label: 'B', preview: (active) => order.push(`B:${active}`), run: () => order.push('B:run') }),
    ],
    onClose: () => order.push('closed'),
  });
  // A synthetic click on B (no pointer enter first) moves the preview
  // there, then the menu closes, then B runs.
  assert.equal(activate(1), 'run');
  assert.deepEqual(order, ['A:true', 'A:false', 'B:true', 'B:false', 'closed', 'B:run']);
  order.length = 0;
  open({ x: 0, y: 0, items: [item({ label: 'A', preview: (active) => order.push(`A:${active}`) })], onClose: () => order.push('closed') });
  close();
  close();
  assert.deepEqual(order, ['A:true', 'A:false', 'closed'], 'an outside dismissal ends the preview once');
});

test('arming a confirm item moves the preview to it and keeps it (the menu stays open)', () => {
  const order = [];
  open({ x: 0, y: 0, items: [
    item({ label: 'A', preview: (active) => order.push(`A:${active}`) }),
    item({ label: 'B', confirm: true, preview: (active) => order.push(`B:${active}`), run: () => order.push('B:run') }),
  ] });
  assert.equal(activate(1), 'armed');
  assert.deepEqual(order, ['A:true', 'A:false', 'B:true']);
  assert.equal(activate(1), 'run');
  assert.deepEqual(order, ['A:true', 'A:false', 'B:true', 'B:false', 'B:run']);
});

test('a separator never previews and a non-function preview is dropped', () => {
  const order = [];
  open({ x: 0, y: 0, items: [
    item({ label: 'A', preview: 'not a function' }),
    { separator: true },
    item({ label: 'B', preview: (active) => order.push(`B:${active}`) }),
  ] });
  assert.equal(getState().items[0].preview, null);
  moveActive(1);
  assert.deepEqual(order, ['B:true']);
});

// The inert rows: a group caption and the read-only meter.
test('a caption is a non-empty string and normalizes to nothing else', () => {
  assert.throws(() => open({ x: 0, y: 0, items: [{ caption: '' }, item()] }), /requires a non-empty caption/);
  assert.throws(() => open({ x: 0, y: 0, items: [{ caption: 3 }, item()] }), /requires a non-empty caption/);
  open({ x: 0, y: 0, items: [{ caption: 'Model' }, item()] });
  assert.deepEqual(getState().items[0], { caption: 'Model' });
  assert.equal(isFocusable(getState().items[0]), false);
});

test('a meter row needs a label and a fraction between 0 and 1, and no run()', () => {
  assert.throws(() => open({ x: 0, y: 0, items: [{ meter: 0.5 }] }), /requires a label/);
  assert.throws(() => open({ x: 0, y: 0, items: [{ label: '5 hours', meter: 1.2 }] }), /meter between 0 and 1/);
  assert.throws(() => open({ x: 0, y: 0, items: [{ label: '5 hours', meter: -0.1 }] }), /meter between 0 and 1/);
  assert.throws(() => open({ x: 0, y: 0, items: [{ label: '5 hours', meter: '0.5' }] }), /meter between 0 and 1/);
  assert.throws(() => open({ x: 0, y: 0, items: [{ label: '5 hours', meter: Number.NaN }] }), /meter between 0 and 1/);
  open({ x: 0, y: 0, items: [{ label: '5 hours', meter: 0.82, hint: '82% · resets in 3 h' }, { label: '1 month', meter: 0 }] });
  assert.deepEqual(getState().items[0], { separator: false, meter: 0.82, label: '5 hours', hint: '82% · resets in 3 h' });
  assert.deepEqual(getState().items[1], { separator: false, meter: 0, label: '1 month', hint: null });
  assert.equal(isFocusable(getState().items[0]), false);
});

test('a plain item is focusable, disabled or not; the inert kinds are not', () => {
  open({ x: 0, y: 0, items: [item({ disabled: true, run: undefined }), { separator: true }, { caption: 'C' }, { label: 'M', meter: 0.3 }] });
  assert.deepEqual(getState().items.map(isFocusable), [true, false, false, false]);
});

test('the first focusable item is active on open, so a leading caption or meter never takes focus', () => {
  const heard = [];
  open({ x: 0, y: 0, items: [
    { caption: 'Usage left' },
    { label: '5 hours', meter: 0.5 },
    { separator: true },
    item({ label: 'A', preview: (active) => heard.push(`A:${active}`) }),
    item({ label: 'B' }),
  ] });
  assert.equal(getState().activeIndex, 3);
  assert.deepEqual(heard, ['A:true'], 'the first focusable item previews on open');
});

test('navigation steps over captions and meters both ways, and Home/End land on focusable rows', () => {
  open({ x: 0, y: 0, items: [
    { caption: 'Model' },
    item({ label: 'A' }),
    { separator: true },
    { caption: 'Usage left' },
    { label: '5 hours', meter: 0.5 },
    { label: '7 days', meter: 0.9 },
    { separator: true },
    item({ label: 'B' }),
    { label: 'Trailing', meter: 0.1 },
  ] });
  assert.equal(getState().activeIndex, 1);
  moveActive(1);
  assert.equal(getState().activeIndex, 7, 'ArrowDown skipped the rule, the caption and both meters');
  moveActive(1);
  assert.equal(getState().activeIndex, 1, 'wrapping skipped the trailing meter and the leading caption');
  moveActive(-1);
  assert.equal(getState().activeIndex, 7);
  moveActiveToFirst();
  assert.equal(getState().activeIndex, 1, 'Home is the first focusable row, not the caption');
  moveActiveToLast();
  assert.equal(getState().activeIndex, 7, 'End is the last focusable row, not the trailing meter');
  setActive(4);
  assert.equal(getState().activeIndex, 7, 'a pointer over a meter does not make it active');
  setActive(0);
  assert.equal(getState().activeIndex, 7, 'nor over a caption');
});

test('activating a caption or a meter is a noop and the menu stays', () => {
  open({ x: 0, y: 0, items: [{ caption: 'Usage left' }, { label: '5 hours', meter: 0.5 }, item()] });
  assert.equal(activate(0), 'noop');
  assert.equal(activate(1), 'noop');
  assert.equal(isOpen(), true);
});

test('a list of inert rows alone opens with nothing active and the keyboard has nothing to move', () => {
  assert.equal(open({ x: 0, y: 0, items: [{ caption: 'Usage left' }, { label: '5 hours', meter: 0.5 }] }), true);
  assert.equal(getState().activeIndex, -1);
  moveActive(1);
  assert.equal(getState().activeIndex, -1);
  moveActiveToFirst();
  moveActiveToLast();
  assert.equal(getState().activeIndex, -1);
  assert.equal(activate(-1), 'noop');
});

// The submenu row and its flyout.
const submenu = (overrides = {}) => ({
  label: 'Panels',
  items: [item({ label: 'Blocks', checked: true }), item({ label: 'Styles', checked: false })],
  ...overrides,
});

test('a submenu row holds items and nothing to do: run, checked and confirm each throw beside items', () => {
  assert.throws(() => open({ x: 0, y: 0, items: [submenu({ run: () => {} })] }), /carries no run\(\)/);
  assert.throws(() => open({ x: 0, y: 0, items: [submenu({ checked: true })] }), /never checked/);
  assert.throws(() => open({ x: 0, y: 0, items: [submenu({ confirm: true })] }), /never confirms/);
  assert.throws(() => open({ x: 0, y: 0, items: [submenu({ items: 'Blocks' })] }), /items as an array/);
  assert.throws(() => open({ x: 0, y: 0, items: [submenu({ items: [] })] }), /at least one item/);
  assert.throws(() => open({ x: 0, y: 0, items: [submenu({ items: [{ separator: true }] })] }), /at least one item/);
  assert.throws(() => open({ x: 0, y: 0, items: [submenu({ items: [{ label: 'Broken' }] })] }), /requires run\(\)/);
  assert.throws(
    () => open({ x: 0, y: 0, items: [submenu({ items: [submenu({ label: 'Deeper' })] })] }),
    /one level only/,
  );
  assert.equal(isOpen(), false);
});

test('a submenu row normalizes with its items in the grammar, separators trimmed, and is a focusable submenu', () => {
  open({ x: 0, y: 0, items: [submenu({ items: [{ separator: true }, item({ label: 'Blocks' }), { separator: true }] })] });
  const row = getState().items[0];
  assert.equal(isSubmenu(row), true);
  assert.equal(isFocusable(row), true);
  assert.equal(row.run, null);
  assert.equal(row.checked, null);
  assert.deepEqual(row.items.map((entry) => entry.label), ['Blocks']);
  assert.equal(row.items[0].items, null);
  assert.equal(isSubmenu(row.items[0]), false);
  assert.equal(isSubmenu(getState().items[0].items[0]), false);
  assert.equal(getState().sub, null, 'a submenu row opens nothing by itself');
});

test('activating a submenu row opens its flyout with the first focusable item active and the menu stays', () => {
  open({ x: 0, y: 0, items: [item({ label: 'Save' }), submenu({ items: [{ caption: 'Open' }, item({ label: 'Blocks' }), item({ label: 'Styles' })] })] });
  moveActive(1);
  assert.equal(activate(1), 'opened');
  assert.deepEqual(getState().sub, { index: 1, activeIndex: 1, armedIndex: null }, 'the caption is stepped over');
  assert.equal(getState().activeIndex, 1, 'the row stays the active top row');
  assert.equal(isOpen(), true);
  assert.equal(activate(1), 'opened', 'again: still open, unchanged');
  assert.deepEqual(getState().sub, { index: 1, activeIndex: 1, armedIndex: null });
});

test('openSub names the row (ArrowRight): a plain row, a disabled row, or no menu opens nothing', () => {
  assert.equal(openSub(0), false, 'no menu');
  open({ x: 0, y: 0, items: [item({ label: 'Save' }), submenu(), submenu({ label: 'Locked', disabled: true, reason: 'Nothing to list' })] });
  assert.equal(openSub(0), false, 'a plain row has no flyout');
  assert.equal(getState().sub, null);
  assert.equal(openSub(2), false, 'a disabled submenu row opens nothing');
  assert.equal(getState().sub, null);
  assert.equal(activate(2), 'noop');
  assert.equal(openSub(1), true);
  assert.deepEqual(getState().sub, { index: 1, activeIndex: 0, armedIndex: null });
  assert.equal(getState().activeIndex, 1, 'opening by index makes the row the active top row');
});

test('a pointer open leaves nothing active inside until the pointer says; the keyboard then steps in from either end', () => {
  open({ x: 0, y: 0, items: [submenu()] });
  assert.equal(openSub(0, { focusFirst: false }), true);
  assert.equal(getState().sub.activeIndex, -1);
  moveActive(1);
  assert.equal(getState().sub.activeIndex, 0, 'ArrowDown from nothing lands on the first row');
  closeSub();
  openSub(0, { focusFirst: false });
  moveActive(-1);
  assert.equal(getState().sub.activeIndex, 1, 'ArrowUp from nothing lands on the last row');
  closeSub();
  openSub(0, { focusFirst: false });
  setSubActive(1);
  assert.equal(getState().sub.activeIndex, 1, 'a pointer enter on a flyout row makes it active');
});

test('the keyboard wraps inside the flyout and Home/End stay inside it; the top row does not move', () => {
  open({ x: 0, y: 0, items: [item({ label: 'Save' }), submenu({ items: [item({ label: 'A' }), { separator: true }, item({ label: 'B' }), item({ label: 'C' })] }), item({ label: 'Done' })] });
  setActive(1);
  openSub(1);
  moveActive(1);
  assert.equal(getState().sub.activeIndex, 2, 'ArrowDown skipped the rule');
  moveActive(1);
  moveActive(1);
  assert.equal(getState().sub.activeIndex, 0, 'and wrapped to the top of the flyout');
  moveActive(-1);
  assert.equal(getState().sub.activeIndex, 3, 'ArrowUp wrapped to the bottom of the flyout');
  moveActiveToFirst();
  assert.equal(getState().sub.activeIndex, 0);
  moveActiveToLast();
  assert.equal(getState().sub.activeIndex, 3);
  assert.equal(getState().activeIndex, 1, 'the top list never moved');
  assert.equal(getState().sub.index, 1);
});

test('closeSub closes the flyout alone (ArrowLeft, Escape): the row stays active and the menu stands', () => {
  let closes = 0;
  open({ x: 0, y: 0, items: [item({ label: 'Save' }), submenu()], onClose: () => { closes += 1; } });
  setActive(1);
  openSub(1);
  moveActive(1);
  closeSub();
  assert.equal(getState().sub, null);
  assert.equal(getState().activeIndex, 1);
  assert.equal(isOpen(), true);
  assert.equal(closes, 0);
  closeSub();
  assert.equal(getState().sub, null, 'idempotent');
  moveActive(1);
  assert.equal(getState().activeIndex, 0, 'with the flyout gone the keyboard moves the top list again (wrapping)');
});

test('the active top row moving elsewhere closes the flyout; back onto the row it stays', () => {
  open({ x: 0, y: 0, items: [item({ label: 'Save' }), submenu(), item({ label: 'Done' })] });
  setActive(1);
  openSub(1);
  setActive(1);
  assert.notEqual(getState().sub, null, 'the pointer back on the row changes nothing');
  setActive(2);
  assert.equal(getState().sub, null, 'another top row: the flyout is gone');
  assert.equal(getState().activeIndex, 2);
  openSub(1);
  assert.equal(getState().activeIndex, 1);
  activate(0);
  assert.equal(isOpen(), false, 'a plain top item run closes everything');
});

test('a flyout item runs AFTER the whole menu closes, and onClose fires once', () => {
  const order = [];
  open({
    x: 0,
    y: 0,
    items: [submenu({ items: [item({ label: 'Blocks', run: () => order.push(`run (menu open: ${isOpen()})`) })] })],
    onClose: () => order.push('closed'),
  });
  openSub(0);
  assert.equal(activateSub(0), 'run');
  assert.deepEqual(order, ['closed', 'run (menu open: false)']);
  assert.equal(getState(), null);
  assert.equal(activateSub(0), 'noop', 'nothing open: nothing to activate');
});

test('activateSub refuses inert, disabled and out-of-range flyout rows, and a closed flyout', () => {
  let ran = false;
  open({ x: 0, y: 0, items: [submenu({ items: [{ caption: 'Open' }, item({ label: 'Blocks', disabled: true, run: () => { ran = true; } }), item({ label: 'Styles' })] })] });
  assert.equal(activateSub(0), 'noop', 'no flyout yet');
  openSub(0);
  assert.equal(activateSub(0), 'noop', 'a caption');
  assert.equal(activateSub(1), 'noop', 'a disabled row');
  assert.equal(activateSub(5), 'noop');
  assert.equal(ran, false);
  assert.equal(isOpen(), true);
  assert.equal(getState().sub.index, 0);
});

test('confirm works inside a flyout: arm, re-arm elsewhere, run; opening the flyout disarms the top list', () => {
  let ran = 0;
  open({ x: 0, y: 0, items: [
    item({ label: 'Trash', confirm: true }),
    submenu({ items: [item({ label: 'A', confirm: 'Confirm A?', run: () => { ran += 1; } }), item({ label: 'B', confirm: true })] }),
  ] });
  assert.equal(activate(0), 'armed');
  assert.equal(getState().armedIndex, 0);
  openSub(1);
  assert.equal(getState().armedIndex, null, 'one armed item ever: the flyout opening disarmed the top row');
  assert.equal(activateSub(0), 'armed');
  assert.equal(getState().sub.armedIndex, 0);
  assert.equal(getState().sub.activeIndex, 0);
  assert.equal(isOpen(), true);
  assert.equal(activateSub(1), 'armed');
  assert.equal(getState().sub.armedIndex, 1, 'the arm moved, not stacked');
  assert.equal(activateSub(1), 'run');
  assert.equal(isOpen(), false);
  assert.equal(ran, 0, 'B ran, not A');
  open({ x: 0, y: 0, items: [submenu({ items: [item({ label: 'A', confirm: true })] })] });
  openSub(0);
  activateSub(0);
  closeSub();
  openSub(0);
  assert.equal(getState().sub.armedIndex, null, 'an armed flyout row disarms with its flyout');
});

test('preview follows the active flyout item; the flyout item hears false before the row on close, and before a run', () => {
  const heard = [];
  open({ x: 0, y: 0, items: [
    submenu({
      preview: (active) => heard.push(`Panels:${active}`),
      items: [
        item({ label: 'A', preview: (active) => heard.push(`A:${active}`), run: () => heard.push('A:run') }),
        item({ label: 'B', preview: (active) => heard.push(`B:${active}`) }),
      ],
    }),
  ], onClose: () => heard.push('closed') });
  assert.deepEqual(heard, ['Panels:true']);
  openSub(0);
  assert.deepEqual(heard, ['Panels:true', 'A:true'], 'the row stays active; the first flyout item previews');
  moveActive(1);
  assert.deepEqual(heard, ['Panels:true', 'A:true', 'A:false', 'B:true']);
  closeSub();
  assert.deepEqual(heard, ['Panels:true', 'A:true', 'A:false', 'B:true', 'B:false'], 'closing the flyout ends its preview, not the row\'s');
  heard.length = 0;
  openSub(0);
  activateSub(0);
  assert.deepEqual(heard, ['A:true', 'A:false', 'Panels:false', 'closed', 'A:run']);
  heard.length = 0;
  open({ x: 0, y: 0, items: [submenu({ items: [item({ label: 'A', preview: (active) => heard.push(`A:${active}`) })] })] });
  openSub(0);
  setActive(0);
  assert.deepEqual(heard, ['A:true'], 'the pointer back on the row is not a change');
  close();
  assert.deepEqual(heard, ['A:true', 'A:false'], 'an outside dismissal ends the flyout preview once');
});

test('a second open while a flyout stands replaces everything', () => {
  open({ x: 0, y: 0, items: [submenu()] });
  openSub(0);
  assert.equal(open({ x: 1, y: 1, items: [item({ label: 'Second' })] }), true);
  assert.equal(getState().sub, null);
  assert.equal(getState().items[0].label, 'Second');
});

test('subscribers hear the flyout open, move and close', () => {
  open({ x: 0, y: 0, items: [submenu()] });
  let notified = 0;
  const unsubscribe = subscribe(() => { notified += 1; });
  openSub(0);
  moveActive(1);
  closeSub();
  assert.equal(notified, 3);
  unsubscribe();
});
