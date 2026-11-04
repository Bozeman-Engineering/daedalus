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
 * The item grammar:
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
 * - preview: optional function(active). Called with true when the item
 *   becomes the active item and false when it stops being it: another
 *   item takes over (pointer enter or a keyboard move, one fact), or the
 *   menu closes, which happens BEFORE the picked item runs, so a pick
 *   reads preview(false) then run(). The first item previews on open,
 *   the keyboard's focus already being there. Opaque like run: an
 *   opener paints what the item would do (the stylish address chip
 *   paints the hovered rule's reach on the canvas) and takes it down.
 * - items: optional array; a SUBMENU row. The row holds a nested list in
 *   this same grammar (one level: a flyout never holds a submenu) and
 *   nothing to do itself: no run, never checked, never confirm (each
 *   throws beside items). Its flyout opens beside the row (ArrowRight,
 *   Enter, Space, a click, or the host's pointer intent) with its first
 *   focusable item active, and the row is the flyout's parent while it
 *   stands: the keyboard moves inside the flyout, ArrowLeft and Escape
 *   close the flyout alone, and the active top row moving anywhere else
 *   closes it. A flyout item activates like a top item (a confirm arms
 *   inside the flyout, preview follows its active item) and a run closes
 *   the WHOLE menu. A disabled submenu row (reason and all) opens
 *   nothing. The row's own preview, if any, follows it as the active top
 *   row, flyout open or not.
 * - {separator: true}: a rule between groups. Never focusable; never
 *   leads, trails, or doubles (open() trims them).
 * - {caption: string}: a group's caption inside the list (the "Model" and
 *   "Usage left" headings of the chat kebab), rendered like the menu's
 *   header. Never focusable; carries nothing else.
 * - {label, meter: 0..1, hint?}: a read-only meter row (the chat kebab's
 *   usage windows): the label, a track filled to the fraction, and the
 *   hint on a second line. Never focusable and never runs (no run; a
 *   separator's treatment with a face), so a keyboard user steps over it
 *   as over a rule; the host renders it role="menuitem" aria-disabled so
 *   a screen reader still reads the label and hint in sequence. Under
 *   0.1 the host marks it low, at 0 out.
 *
 * open() places the menu one of two ways: at viewport coordinates
 * {x, y} (a pointer), or under an `anchor` Element (a trigger button),
 * with `align` ('start', the default, or 'end') naming which of the
 * anchor's edges the menu aligns to: an explicit intent from the caller,
 * never inferred from layout (a kebab at the end of a panel header
 * passes 'end' so its menu hangs inward). Where that lands is the
 * surface's placement math (js/popover/placement.js). An optional
 * `header` captions the list.
 *
 * The state carries `sub`: null while no flyout stands, else
 * {index, activeIndex, armedIndex} for the one open flyout (the submenu
 * row's top index, the flyout's own active and armed rows; -1 active
 * when a pointer opened it and nothing inside is pointed at yet). The
 * top `activeIndex` stays on the submenu row the whole time, so a close
 * lands the keyboard back on it. The keyboard entries (moveActive,
 * moveActiveToFirst/Last) address the flyout while one stands; the
 * pointer entries name the list they are in (setActive/activate for the
 * top list, setSubActive/activateSub for the flyout).
 */

/**
 * @type {?{x: ?number, y: ?number, anchor: ?Element, align: string,
 *   header: ?string, items: object[], onClose: ?Function,
 *   activeIndex: number, armedIndex: ?number,
 *   sub: ?{index: number, activeIndex: number, armedIndex: ?number}}}
 */
let state = null;

/** @type {Set<Function>} */
const listeners = new Set();

const notify = () => listeners.forEach((listener) => listener());

/**
 * Subscribes to state changes (the host re-renders on any).
 *
 * @param {Function} listener
 *   Called, with no arguments, after every change.
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
 * Drops separators that would lead, trail, or double: an adapter builds
 * its groups conditionally and must not have to trim the rules itself.
 *
 * @param {object[]} items
 *   Normalized items, separators among them.
 *
 * @return {object[]}
 *   The same items without the leading, trailing and doubled separators.
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
 * Validates and normalizes one item against the grammar. Invalid items
 * throw: a malformed adapter is a bug to surface, not to skip quietly.
 * `nested` is true inside a submenu's items, where a second level is
 * refused.
 *
 * @param {object} item
 *   The adapter's item, in the grammar of the file docblock.
 * @param {number} index
 *   The item's position in its list, for the error messages.
 * @param {boolean} [nested]
 *   Whether the item sits inside a submenu's items; false by default.
 *
 * @return {object}
 *   The normalized item, every grammar key present.
 */
const normalizeItem = (item, index, nested = false) => {
  if (item && item.separator === true) {
    return { separator: true };
  }
  if (item && item.caption !== undefined) {
    if (typeof item.caption !== 'string' || item.caption === '') {
      throw new Error(`Menu item ${index} requires a non-empty caption.`);
    }
    return { caption: item.caption };
  }
  if (!item || typeof item.label !== 'string' || item.label === '') {
    throw new Error(`Menu item ${index} requires a label.`);
  }
  if (item.meter !== undefined) {
    if (
      typeof item.meter !== 'number' ||
      Number.isNaN(item.meter) ||
      item.meter < 0 ||
      item.meter > 1
    ) {
      throw new Error(
        `Menu item "${item.label}" requires a meter between 0 and 1.`,
      );
    }
    return {
      separator: false,
      meter: item.meter,
      label: item.label,
      hint: item.hint ?? null,
    };
  }
  const submenu = item.items !== undefined;
  if (submenu) {
    if (nested) {
      throw new Error(
        `Menu item "${item.label}" nests a submenu inside a flyout; one level only.`,
      );
    }
    if (!Array.isArray(item.items)) {
      throw new Error(`Menu item "${item.label}" requires items as an array.`);
    }
    if (item.run !== undefined) {
      throw new Error(
        `Menu item "${item.label}" has items, so it carries no run().`,
      );
    }
    if (item.checked !== undefined) {
      throw new Error(
        `Menu item "${item.label}" has items, so it is never checked.`,
      );
    }
    if (item.confirm !== undefined) {
      throw new Error(
        `Menu item "${item.label}" has items, so it never confirms.`,
      );
    }
  } else if (typeof item.run !== 'function' && !item.disabled) {
    throw new Error(
      `Menu item "${item.label}" requires run() unless disabled.`,
    );
  }
  const items = submenu
    ? trimSeparators(
        item.items.map((entry, position) =>
          normalizeItem(entry, position, true),
        ),
      )
    : null;
  if (submenu && items.length === 0) {
    throw new Error(`Menu item "${item.label}" requires at least one item.`);
  }
  return {
    separator: false,
    meter: null,
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
    preview: typeof item.preview === 'function' ? item.preview : null,
    items,
  };
};

/**
 * Whether an item can be the active (focused) item: separators, captions
 * and meter rows are inert, stepped over by the keyboard and never run.
 *
 * @param {object} item
 *   A normalized item.
 *
 * @return {boolean}
 *   True for a menuitem row, checkable or not, disabled or not.
 */
export const isFocusable = (item) =>
  !item.separator && item.caption === undefined && item.meter === null;

/**
 * Whether an item is a submenu row: it opens a flyout instead of running.
 *
 * @param {object} item
 *   A normalized item.
 *
 * @return {boolean}
 *   True when the item carries a nested item list.
 */
export const isSubmenu = (item) => isFocusable(item) && item.items !== null;

const firstFocusable = (items) => items.findIndex(isFocusable);

const lastFocusable = (items) => items.findLastIndex(isFocusable);

/**
 * Tells one item it became (or stopped being) the active item. The
 * state is already settled and notified when this runs, so a preview
 * that reads getState() sees the item it is told about.
 *
 * @param {object[]} items
 *   The list the item is in (the top list or a flyout).
 * @param {number} index
 *   The item's index in that list; -1 or out of range tells nobody.
 * @param {boolean} active
 *   Whether the item became active (true) or stopped being (false).
 */
const previewAt = (items, index, active) => {
  items[index]?.preview?.(active);
};

/** The open flyout's items, or null. */
const subItems = () => (state?.sub ? state.items[state.sub.index].items : null);

/**
 * Opens the menu with a fresh item list. An open menu is replaced (its
 * onClose fires first): the second-contextmenu dismissal rule. No items
 * means NO menu (the caller leaves the native menu alone): returns false
 * so adapters can decide not to preventDefault. The first focusable item
 * is the active one; a list of inert rows alone (captions and meters) has
 * none active, and the keyboard has nothing to move.
 *
 * @param {object} spec
 *   Where the menu lands and what it lists: either an anchor or both
 *   viewport coordinates.
 * @param {?number} [spec.x]
 *   The pointer's viewport x, when there is no anchor.
 * @param {?number} [spec.y]
 *   The pointer's viewport y, when there is no anchor.
 * @param {?Element} [spec.anchor]
 *   The element the menu hangs under.
 * @param {string} [spec.align]
 *   Which edges of anchor and menu align, 'start' (the default) or 'end'.
 * @param {?string} [spec.header]
 *   A caption over the list; empty or absent captions nothing.
 * @param {object[]} spec.items
 *   The adapter's items, in the grammar of the file docblock.
 * @param {?Function} [spec.onClose]
 *   Called once when the menu closes.
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
  const normalized = trimSeparators(
    (items ?? []).map((item, index) => normalizeItem(item, index)),
  );
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
    activeIndex: firstFocusable(normalized),
    armedIndex: null,
    sub: null,
  };
  notify();
  previewAt(normalized, state.activeIndex, true);
  return true;
};

/**
 * Closes the menu, flyout and all. Idempotent; fires onClose exactly
 * once per open. The flyout's active item hears preview(false) before
 * the top row does.
 */
export const close = () => {
  if (!state) {
    return;
  }
  const { onClose, items, activeIndex, sub } = state;
  const flyout = subItems();
  state = null;
  notify();
  if (sub) {
    previewAt(flyout, sub.activeIndex, false);
  }
  previewAt(items, activeIndex, false);
  onClose?.();
};

/**
 * Closes the open flyout alone (ArrowLeft, Escape inside it, the active
 * top row moving elsewhere). The submenu row stays the active top row,
 * so the keyboard lands back on it. Nothing open: nothing happens.
 */
export const closeSub = () => {
  if (!state?.sub) {
    return;
  }
  const { sub } = state;
  const flyout = subItems();
  state = { ...state, sub: null };
  notify();
  previewAt(flyout, sub.activeIndex, false);
};

/**
 * Opens the flyout of the submenu row at `index` (ArrowRight, Enter,
 * Space, a click, or the host's pointer intent). The row becomes the
 * active top row and any armed top item disarms. Already open there:
 * nothing changes. Not a submenu row, or disabled: nothing opens.
 *
 * @param {number} index
 *   The submenu row's top index.
 * @param {object} [options]
 *   How the flyout opens.
 * @param {boolean} [options.focusFirst]
 *   Whether the flyout's first focusable item becomes active (the
 *   keyboard's open, true by default) or nothing inside is active yet (a
 *   pointer's open: the pointer says what it points at).
 *
 * @return {boolean}
 *   Whether a flyout stands open at that row afterwards.
 */
export const openSub = (index, { focusFirst = true } = {}) => {
  if (!state) {
    return false;
  }
  const item = state.items[index];
  if (!item || !isSubmenu(item) || item.disabled) {
    return false;
  }
  if (state.sub?.index === index) {
    return true;
  }
  setActive(index);
  const activeIndex = focusFirst ? firstFocusable(item.items) : -1;
  state = {
    ...state,
    armedIndex: null,
    sub: { index, activeIndex, armedIndex: null },
  };
  notify();
  previewAt(item.items, activeIndex, true);
  return true;
};

/**
 * Sets the active (focused) top item; pointer hover and roving focus
 * share this. Out-of-range indexes and inert rows are ignored. Moving
 * off the submenu row whose flyout stands closes the flyout.
 *
 * @param {number} index
 *   The top item's index.
 */
export const setActive = (index) => {
  if (
    !state ||
    index < 0 ||
    index >= state.items.length ||
    index === state.activeIndex ||
    !isFocusable(state.items[index])
  ) {
    return;
  }
  closeSub();
  const previous = state.activeIndex;
  state = { ...state, activeIndex: index };
  notify();
  previewAt(state.items, previous, false);
  previewAt(state.items, index, true);
};

/**
 * Sets the active item inside the open flyout (a pointer enter on a
 * flyout row). No flyout, an out-of-range index, or an inert row:
 * ignored.
 *
 * @param {number} index
 *   The item's index in the flyout.
 */
export const setSubActive = (index) => {
  const flyout = subItems();
  if (
    !flyout ||
    index < 0 ||
    index >= flyout.length ||
    index === state.sub.activeIndex ||
    !isFocusable(flyout[index])
  ) {
    return;
  }
  const previous = state.sub.activeIndex;
  state = { ...state, sub: { ...state.sub, activeIndex: index } };
  notify();
  previewAt(flyout, previous, false);
  previewAt(flyout, index, true);
};

/**
 * Steps an index by delta through a list, wrapping and stepping over
 * inert rows; from -1 (nothing active) a step lands on the first or the
 * last focusable row by its direction.
 *
 * @param {object[]} items
 *   The list to step through.
 * @param {number} from
 *   The index stepped from; -1 for nothing active.
 * @param {number} delta
 *   The signed step, usually 1 or -1.
 *
 * @return {number}
 *   The focusable index landed on; `from` again when the list has no
 *   other focusable row.
 */
const stepped = (items, from, delta) => {
  if (from === -1) {
    return delta > 0 ? firstFocusable(items) : lastFocusable(items);
  }
  const count = items.length;
  let index = from;
  do {
    index = (((index + delta) % count) + count) % count;
  } while (!isFocusable(items[index]) && index !== from);
  return index;
};

/**
 * Moves the active item by delta, wrapping and stepping over inert rows
 * (separators, captions, meters): the ArrowDown/ArrowUp contract.
 * Disabled items are included (focusable, per the grammar). While a
 * flyout stands the move is inside it: the keyboard's one list is the
 * one it is in.
 *
 * @param {number} delta
 *   The signed step, usually 1 or -1.
 */
export const moveActive = (delta) => {
  if (!state) {
    return;
  }
  const flyout = subItems();
  if (flyout) {
    setSubActive(stepped(flyout, state.sub.activeIndex, delta));
    return;
  }
  if (firstFocusable(state.items) === -1) {
    return;
  }
  setActive(stepped(state.items, state.activeIndex, delta));
};

/** Home: the first focusable row of the list the keyboard is in. */
export const moveActiveToFirst = () => {
  const flyout = subItems();
  if (flyout) {
    setSubActive(firstFocusable(flyout));
  } else if (state) {
    setActive(firstFocusable(state.items));
  }
};

/** End: the last focusable row of the list the keyboard is in. */
export const moveActiveToLast = () => {
  const flyout = subItems();
  if (flyout) {
    setSubActive(lastFocusable(flyout));
  } else if (state) {
    setActive(lastFocusable(state.items));
  }
};

/**
 * Activates a top item: Enter/Space on the active item, or a click.
 *
 * - Disabled, or an inert row (a separator, a caption, a meter): nothing
 *   ('noop').
 * - A submenu row: opens its flyout with the first focusable item active
 *   ('opened'); the menu stays.
 * - Confirm item, not armed: arms it ('armed'); the label swap is the
 *   host's rendering of armedIndex. Arming a different item disarms the
 *   first; only one item is ever armed.
 * - Otherwise: closes the menu, THEN runs (the menu is gone before any
 *   dialog or mutation the action raises), returns 'run'.
 *
 * @param {number} index
 *   The top item's index.
 *
 * @return {string}
 *   'noop' | 'opened' | 'armed' | 'run'.
 */
export const activate = (index) => {
  if (!state) {
    return 'noop';
  }
  const item = state.items[index];
  if (!item || !isFocusable(item) || item.disabled) {
    return 'noop';
  }
  if (isSubmenu(item)) {
    openSub(index);
    return 'opened';
  }
  // A click lands on the item it names whether or not a pointer enter
  // made it active first (a synthetic click never did): the active item
  // moves there, previews and all, before anything else happens.
  setActive(index);
  if (item.confirm && state.armedIndex !== index) {
    state = { ...state, armedIndex: index };
    notify();
    return 'armed';
  }
  const { run } = item;
  close();
  run();
  return 'run';
};

/**
 * Activates an item inside the open flyout: Enter/Space on its active
 * item, or a click on a flyout row. The same contract as activate()
 * (noop, arm, run), with the flyout's own armed row and a run that
 * closes the whole menu. No flyout: 'noop'.
 *
 * @param {number} index
 *   The item's index in the flyout.
 *
 * @return {string}
 *   'noop' | 'armed' | 'run'.
 */
export const activateSub = (index) => {
  const flyout = subItems();
  if (!flyout) {
    return 'noop';
  }
  const item = flyout[index];
  if (!item || !isFocusable(item) || item.disabled) {
    return 'noop';
  }
  setSubActive(index);
  if (item.confirm && state.sub.armedIndex !== index) {
    state = { ...state, sub: { ...state.sub, armedIndex: index } };
    notify();
    return 'armed';
  }
  const { run } = item;
  close();
  run();
  return 'run';
};
