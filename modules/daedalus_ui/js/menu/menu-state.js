/**
 * The menu primitive's state machine: the shared spine piece every popup
 * menu adapts into, whichever gesture opens it (right-click, a
 * chevron, a kebab, a keyboard opener).
 *
 * The MENU is what daedalus_ui shares: one singleton per window, one
 * item grammar, one keyboard/dismissal contract. Item SOURCES stay per
 * noun-space: the page-elements tree adapts the verb registry
 * (verbsForSet) into items; the daedalus_layout palette supplies items directly;
 * the Show kebab lists its visibility toggles as checked items. This
 * module is deliberately pure (no DOM, no Drupal globals) so the grammar,
 * navigation, and confirm semantics are node-testable; the plain-DOM host
 * in menu.js renders it as a list riding the shared floating surface
 * (Drupal.Daedalus.popover), which owns placement, dismissal, and
 * Escape; the host keeps the list's roving focus and keyboard.
 *
 * The item grammar (submenus cut; nothing needs one):
 * - label: string, required.
 * - icon: optional inline SVG markup (fill="currentColor", the verb-menu
 *   convention).
 * - disabled: optional bool. Disabled items stay focusable (the ARIA
 *   practice: a keyboard user can reach them and hear WHY via reason).
 * - reason: optional string; why the item is disabled; surfaces as the
 *   item's title.
 * - title: optional string; the tooltip on an ENABLED item (reason owns
 *   the disabled one).
 * - hint: optional string; muted text after the label (a shortcut, a
 *   provenance note).
 * - checked: optional bool. Any boolean makes the item checkable: it
 *   renders the check gutter and carries aria-checked. Absent means a
 *   plain item.
 * - danger: optional bool; destructive styling.
 * - confirm: optional bool|string; the in-menu two-step: the first
 *   activation arms the item (its label swaps to the confirm label), the
 *   second runs. A string is the armed label ("Confirm delete?"); true
 *   lets the host derive one from the label. No dialog dependency.
 * - run: function, required unless disabled; perform the action. Opaque
 *   to the menu, exactly as verb run() is opaque to the verb registry.
 * - {separator: true}: a rule between groups. Never focusable; never
 *   leads, trails, or doubles (open() trims them).
 *
 * open() places the menu one of two ways: at viewport coordinates
 * {x, y} (a pointer), or under an `anchor` Element (a trigger button),
 * with `align` ('start', the default, or 'end') naming which of the
 * anchor's edges the menu aligns to: an explicit intent from the caller,
 * never inferred from layout (a kebab at the end of a panel header
 * passes 'end' so its menu hangs inward). Where that lands is the
 * surface's placement math (js/popover/placement.js). An optional
 * `header` captions the list.
 */

/**
 * @type {?{x: ?number, y: ?number, anchor: ?Element, align: string,
 *   header: ?string, items: object[], onClose: ?Function,
 *   activeIndex: number, armedIndex: ?number}}
 */
let state = null;

/** @type {Set<Function>} */
const listeners = new Set();

const notify = () => listeners.forEach((listener) => listener());

/**
 * Subscribes to state changes (the host re-renders on any).
 *
 * @return {Function}
 *   Unsubscribe.
 */
export const subscribe = (listener) => {
  listeners.add(listener);
  return () => listeners.delete(listener);
};

/**
 * The current menu state, or null while closed. Read-only snapshots:
 * consumers project, never write.
 */
export const getState = () => state;

export const isOpen = () => state !== null;

/**
 * Validates and normalizes one item against the grammar. Invalid items
 * throw: a malformed adapter is a bug to surface, not to skip quietly.
 */
const normalizeItem = (item, index) => {
  if (item && item.separator === true) {
    return { separator: true };
  }
  if (!item || typeof item.label !== 'string' || item.label === '') {
    throw new Error(`Menu item ${index} requires a label.`);
  }
  if (typeof item.run !== 'function' && !item.disabled) {
    throw new Error(
      `Menu item "${item.label}" requires run() unless disabled.`,
    );
  }
  return {
    separator: false,
    label: item.label,
    icon: item.icon ?? null,
    disabled: item.disabled === true,
    reason: item.reason ?? null,
    title: item.title ?? null,
    hint: item.hint ?? null,
    checked: typeof item.checked === 'boolean' ? item.checked : null,
    danger: item.danger === true,
    confirm: !!item.confirm,
    // A string confirm IS the armed label; with `true` the host derives
    // one from the label (Drupal.t lives host-side, keeping this pure).
    confirmLabel: typeof item.confirm === 'string' ? item.confirm : null,
    run: item.run ?? null,
  };
};

/**
 * Drops separators that would lead, trail, or double: an adapter builds
 * its groups conditionally and must not have to trim the rules itself.
 */
const trimSeparators = (items) =>
  items
    .reduce((kept, item) => {
      if (
        item.separator &&
        (kept.length === 0 || kept[kept.length - 1].separator)
      ) {
        return kept;
      }
      kept.push(item);
      return kept;
    }, [])
    .filter(
      (item, index, all) => !(item.separator && index === all.length - 1),
    );

/**
 * Opens the menu with a fresh item list. An open menu is replaced (its
 * onClose fires first): the second-contextmenu dismissal rule. No items
 * means NO menu (the caller leaves the native menu alone): returns false
 * so adapters can decide not to preventDefault.
 *
 * @param {object} spec
 *   {items, x?, y?, anchor?, align?, header?, onClose?}: either an anchor
 *   Element or both viewport coordinates; align is 'start' (default) or
 *   'end'.
 *
 * @return {boolean}
 *   Whether a menu opened.
 */
export const open = ({
  x = null,
  y = null,
  anchor = null,
  align = 'start',
  header = null,
  items,
  onClose = null,
}) => {
  if (!anchor && (typeof x !== 'number' || typeof y !== 'number')) {
    throw new Error('A menu opens at {x, y} or under an anchor.');
  }
  if (align !== 'start' && align !== 'end') {
    throw new Error(`A menu aligns 'start' or 'end', not "${align}".`);
  }
  const normalized = trimSeparators((items ?? []).map(normalizeItem));
  if (normalized.length === 0) {
    return false;
  }
  if (state) {
    close();
  }
  state = {
    x,
    y,
    anchor,
    align,
    header: typeof header === 'string' && header !== '' ? header : null,
    items: normalized,
    onClose,
    activeIndex: 0,
    armedIndex: null,
  };
  notify();
  return true;
};

/**
 * Closes the menu. Idempotent; fires onClose exactly once per open.
 */
export const close = () => {
  if (!state) {
    return;
  }
  const { onClose } = state;
  state = null;
  notify();
  onClose?.();
};

/**
 * Sets the active (focused) item; pointer hover and roving focus share
 * this. Out-of-range indexes and separators are ignored.
 */
export const setActive = (index) => {
  if (
    !state ||
    index < 0 ||
    index >= state.items.length ||
    index === state.activeIndex ||
    state.items[index].separator
  ) {
    return;
  }
  state = { ...state, activeIndex: index };
  notify();
};

/**
 * Moves the active item by delta, wrapping and stepping over separators:
 * the ArrowDown/ArrowUp contract. Disabled items are included (focusable,
 * per the grammar).
 */
export const moveActive = (delta) => {
  if (!state) {
    return;
  }
  const count = state.items.length;
  let index = state.activeIndex;
  do {
    index = (((index + delta) % count) + count) % count;
  } while (state.items[index].separator && index !== state.activeIndex);
  setActive(index);
};

/** Home. */
export const moveActiveToFirst = () => setActive(0);

/** End. */
export const moveActiveToLast = () => {
  if (state) {
    setActive(state.items.length - 1);
  }
};

/**
 * Activates an item: Enter/Space on the active item, or a click.
 *
 * - Disabled, or a separator: nothing ('noop').
 * - Confirm item, not armed: arms it ('armed'); the label swap is the
 *   host's rendering of armedIndex. Arming a different item disarms the
 *   first; only one item is ever armed.
 * - Otherwise: closes the menu, THEN runs (the menu is gone before any
 *   dialog or mutation the action raises), returns 'run'.
 *
 * @return {string}
 *   'noop' | 'armed' | 'run'.
 */
export const activate = (index) => {
  if (!state) {
    return 'noop';
  }
  const item = state.items[index];
  if (!item || item.separator || item.disabled) {
    return 'noop';
  }
  if (item.confirm && state.armedIndex !== index) {
    state = { ...state, activeIndex: index, armedIndex: index };
    notify();
    return 'armed';
  }
  const { run } = item;
  close();
  run();
  return 'run';
};
