/**
 * @file
 * The shared popup menu primitive (Drupal.Daedalus.menu).
 *
 * The one popup menu every chrome surface shares: right-click
 * menus, chevron and kebab dropdowns, the stop picker. Any chrome library
 * declares daedalus_ui/menu as a dependency and opens it imperatively:
 * @code
 *   Drupal.Daedalus.menu.open({
 *     anchor: button,                  // or x, y from a pointer
 *     align: 'end',                    // 'start' (default) or 'end'
 *     header: Drupal.t('Show'),
 *     items: [
 *       { caption: Drupal.t('Model') },
 *       { label, icon, checked, hint, run, preview },
 *       { label: Drupal.t('Panels'), items: [{ label, checked, run }] },
 *       { separator: true },
 *       { label, meter: 0.82, hint },
 *       { separator: true },
 *       { label, danger: true, confirm: Drupal.t('Confirm delete?'), run },
 *     ],
 *     onClose,
 *   });
 * @endcode
 * open() returns false when items is empty (no items, NO menu) so an
 * adapter can leave the native context menu alone (skip preventDefault).
 *
 * A plain-DOM host over the pure state machine in menu-state.js (grammar,
 * navigation, confirm semantics; see its docblock for the item grammar),
 * and the first rider of the shared floating surface
 * (Drupal.Daedalus.popover, js/popover/popover.js): the menu is a
 * list (`.daedalus-ui-menu`) mounted as the surface's content. The surface owns
 * where the list lands (anchor or pointer, `align`, viewport clamp) and
 * how it leaves (outside pointerdown, outside scroll, resize, blur,
 * Escape at document capture with focus returned to the opener, a second
 * open replacing the first). The surface is reached through the Drupal
 * facade at call time, never a relative import: Drupal's cache-busting
 * query string would make a relative import a second module instance
 * with its own singleton. The flyout's placement math is imported
 * relatively from the surface's placement.js: pure functions, so a
 * second instance of that module is no second anything.
 *
 * The host keeps the list's own concerns:
 * - Focus: roving focus on the items (disabled ones included, so their
 *   reason is reachable); the opener's focus is restored on keyboard
 *   dismissal and after an item runs, but NOT on outside-pointer
 *   dismissal (the pointer said where focus goes next).
 * - Keyboard: ArrowUp/ArrowDown wrap, Home/End, Enter/Space activates,
 *   Tab closes and proceeds from the restored opener. Every keydown inside
 *   the list stops propagating so the document-level hotkey dispatcher
 *   stays out of an open menu (Escape never reaches the list: the surface
 *   takes it at document capture, except while a flyout stands, below).
 * - Rows: the header caption, group captions, the check gutter, icons,
 *   labels, hints, separators, the read-only meter row (the gutter for
 *   alignment, the label, the track filled to the fraction, the hint on
 *   a second line; `is-low` under a tenth, `is-out` at nothing), and the
 *   armed label swap of the two-step confirm.
 * - Submenus: a row with `items` carries aria-haspopup="menu",
 *   aria-expanded and a chevron glyph. Its flyout is a second
 *   `.daedalus-ui-menu` list (`--flyout`) inside the same surface root,
 *   so the surface's one-pop-up rule and its inside/outside dismissal
 *   hold; the flyout is position: fixed (the root scrolls its overflow,
 *   which would clip an absolute child) and placed beside the row by
 *   flyoutPosition (right of the row, flipped left when the right would
 *   leave the viewport, clamped vertically). Opening: ArrowRight, Enter,
 *   Space or a click on the row (focus moves to the flyout's first
 *   item), or the pointer resting on the row for a short intent delay
 *   (the flyout opens without taking focus; the pointer then says which
 *   flyout row is active). Pointer enter on another top row closes it.
 *   Closing: ArrowLeft, or Escape, which the host takes at document
 *   capture ahead of the surface (registered before the surface's
 *   listener, so it runs first and stops the event there) and which
 *   closes the flyout alone, focus back on the row. ArrowUp/Down and
 *   Home/End move inside the flyout while it stands. A flyout item runs
 *   like a top item and takes the whole menu down.
 * - Hover ownership: the list is a selection hover-scope owner
 *   (`data-selection-hover-scope`, the attribute the page-elements tree
 *   and code mode's page view carry). An item's preview drives the
 *   spine's hover-selector facet, and the canvas resolver
 *   (edit_mode/selection/selector-events.js) steps aside inside an owner
 *   instead of clearing that facet on every pointer move over the list,
 *   which it would otherwise do before the first frame painted.
 *
 * Like every surface rider this is an unbundled module script, so a
 * separate window (daedalus_stylish's satellite) gets its own copy by declaring
 * the dependency. Preact cannot cross separately built bundles.
 */
import {
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
} from './menu-state.js';
import { flyoutPosition } from '../popover/placement.js';

Drupal.Daedalus = Drupal.Daedalus || {};

// Phosphor "check" (currentColor), the check gutter's mark.
const CHECK =
  '<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 256 256"><path d="M229.66,77.66l-128,128a8,8,0,0,1-11.32,0l-56-56a8,8,0,0,1,11.32-11.32L96,188.69,218.34,66.34a8,8,0,0,1,11.32,11.32Z"></path></svg>';

// Phosphor "caret-right" (currentColor), the submenu row's chevron.
const CHEVRON =
  '<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 256 256"><path d="M181.66,133.66l-80,80a8,8,0,0,1-11.32-11.32L164.69,128,90.34,53.66a8,8,0,0,1,11.32-11.32l80,80A8,8,0,0,1,181.66,133.66Z"></path></svg>';

/**
 * How long the pointer rests on a submenu row before its flyout opens:
 * long enough that a pointer crossing the row on its way elsewhere opens
 * nothing, short enough to read as hover.
 */
const INTENT_DELAY = 150;

const armedLabel = (item) =>
  item.confirmLabel ?? Drupal.t('Confirm: @label', { '@label': item.label });

/**
 * Builds one element with a class and optional text.
 */
const build = (tag, className, text) => {
  const el = document.createElement(tag);
  el.className = className;
  if (text !== undefined) {
    el.textContent = text;
  }
  return el;
};

/**
 * The mounted list: null while closed. `restoreFocus` is the leave kind
 * the host has decided on (a keyboard exit or an item that ran hand
 * focus back to the opener; a pointer leave does not); the surface
 * carries it out when the list unmounts. `root` is the surface's root
 * the flyout mounts into; `sub` is the mounted flyout, null while none
 * stands; `intent` the pending pointer-open timer.
 *
 * @type {?{items: object[], buttons: (Element|null)[], root: Element,
 *   restoreFocus: boolean,
 *   sub: ?{index: number, list: Element, buttons: (Element|null)[]},
 *   intent: ?number}}
 */
let mounted = null;

const clearIntent = () => {
  if (mounted?.intent) {
    clearTimeout(mounted.intent);
    mounted.intent = null;
  }
};

/**
 * Closes the menu on a keyboard exit: the opener gets its focus back.
 */
const leaveByKeyboard = () => {
  if (mounted) {
    mounted.restoreFocus = true;
  }
  close();
};

/**
 * Activates an item; after a run the opener gets its focus back (a
 * click or Enter on an item is a deliberate leave, whichever pointed).
 * `inFlyout` names the list the index is in.
 */
const activateItem = (index, inFlyout = false) => {
  if (mounted) {
    mounted.restoreFocus = true;
  }
  const result = inFlyout ? activateSub(index) : activate(index);
  if (result !== 'run' && mounted) {
    // Armed, opened or ignored: the menu stays, and a later pointer
    // leave must not inherit the flag.
    mounted.restoreFocus = false;
  }
};

const onKeyDown = (event) => {
  const menu = getState();
  if (!menu) {
    return;
  }
  // An open menu owns the keyboard: nothing reaches the hotkey
  // dispatcher's document listener.
  event.stopPropagation();
  switch (event.key) {
    case 'ArrowDown':
      event.preventDefault();
      moveActive(1);
      break;
    case 'ArrowUp':
      event.preventDefault();
      moveActive(-1);
      break;
    case 'ArrowRight':
      event.preventDefault();
      if (!menu.sub) {
        openSub(menu.activeIndex);
      }
      break;
    case 'ArrowLeft':
      event.preventDefault();
      closeSub();
      break;
    case 'Home':
      event.preventDefault();
      moveActiveToFirst();
      break;
    case 'End':
      event.preventDefault();
      moveActiveToLast();
      break;
    case 'Enter':
    case ' ':
      event.preventDefault();
      if (menu.sub) {
        activateItem(menu.sub.activeIndex, true);
      } else {
        activateItem(menu.activeIndex);
      }
      break;
    case 'Tab':
      // The menu closes and Tab proceeds from the restored opener.
      leaveByKeyboard();
      break;
    default:
      break;
  }
};

/**
 * Escape while a flyout stands closes the flyout alone. At document
 * capture, registered before the surface's own Escape listener so it
 * runs first and the surface never sees the press; with no flyout the
 * press goes on to the surface, which closes the menu.
 */
const onEscapeCapture = (event) => {
  if (event.key !== 'Escape' || !getState()?.sub) {
    return;
  }
  event.preventDefault();
  event.stopImmediatePropagation();
  closeSub();
};

/**
 * The check gutter: the mark on a checked row, empty space on the rest.
 */
const buildCheck = (checked) => {
  const check = build('span', 'daedalus-ui-menu__check');
  check.setAttribute('aria-hidden', 'true');
  if (checked) {
    check.innerHTML = CHECK;
  }
  return check;
};

/**
 * Renders a read-only meter row: not a button, nothing to activate.
 *
 * The fill is the fraction as a width; the hint carries the number in
 * words, so the bar is decoration to a screen reader.
 */
const buildMeter = (item) => {
  const row = build(
    'div',
    'daedalus-ui-menu__item daedalus-ui-menu__item--meter',
  );
  row.setAttribute('role', 'menuitem');
  row.setAttribute('aria-disabled', 'true');
  if (item.meter === 0) {
    row.classList.add('is-out');
  } else if (item.meter < 0.1) {
    row.classList.add('is-low');
  }
  row.appendChild(buildCheck(false));
  row.appendChild(build('span', 'daedalus-ui-menu__label', item.label));
  const track = build('span', 'daedalus-ui-menu__meter');
  track.setAttribute('aria-hidden', 'true');
  const fill = build('span', 'daedalus-ui-menu__meter-fill');
  fill.style.width = `${Math.round(item.meter * 1000) / 10}%`;
  track.appendChild(fill);
  row.appendChild(track);
  if (item.hint) {
    row.appendChild(build('span', 'daedalus-ui-menu__hint', item.hint));
  }
  return row;
};

/**
 * The pointer resting on a submenu row opens its flyout after the intent
 * delay, if the row is still the active one by then; a plain row's enter
 * cancels a pending intent (and, through setActive, closes a flyout
 * that stands at another row).
 */
const onTopRowEnter = (item, index) => {
  clearIntent();
  setActive(index);
  if (isSubmenu(item) && !item.disabled && mounted) {
    mounted.intent = setTimeout(() => {
      if (mounted) {
        mounted.intent = null;
      }
      if (getState()?.activeIndex === index) {
        openSub(index, { focusFirst: false });
      }
    }, INTENT_DELAY);
  }
};

/**
 * Renders one item row: a separator, a group caption, a meter row, or a
 * menuitem button. `inFlyout` names the list the row is in, which is
 * where its pointer and click land.
 */
const buildItem = (item, index, gutter, inFlyout) => {
  if (item.separator) {
    const rule = build('div', 'daedalus-ui-menu__separator');
    rule.setAttribute('role', 'separator');
    return rule;
  }
  if (item.caption !== undefined) {
    const caption = build('div', 'daedalus-ui-menu__header', item.caption);
    caption.setAttribute('role', 'presentation');
    return caption;
  }
  if (item.meter !== null) {
    return buildMeter(item);
  }
  const button = build('button', 'daedalus-ui-menu__item');
  button.type = 'button';
  button.tabIndex = -1;
  if (item.danger) {
    button.classList.add('daedalus-ui-menu__item--danger');
  }
  if (item.checked === null) {
    button.setAttribute('role', 'menuitem');
  } else {
    button.setAttribute('role', 'menuitemcheckbox');
    button.setAttribute('aria-checked', item.checked ? 'true' : 'false');
  }
  if (isSubmenu(item)) {
    button.classList.add('daedalus-ui-menu__item--submenu');
    button.setAttribute('aria-haspopup', 'menu');
    button.setAttribute('aria-expanded', 'false');
  }
  if (item.disabled) {
    button.setAttribute('aria-disabled', 'true');
    if (item.reason) {
      button.title = item.reason;
    }
  } else if (item.title) {
    button.title = item.title;
  }
  if (gutter) {
    button.appendChild(buildCheck(item.checked));
  }
  if (item.icon) {
    const icon = build('span', 'daedalus-ui-menu__icon');
    icon.setAttribute('aria-hidden', 'true');
    icon.innerHTML = item.icon;
    button.appendChild(icon);
  }
  button.appendChild(build('span', 'daedalus-ui-menu__label', item.label));
  if (item.hint) {
    button.appendChild(build('span', 'daedalus-ui-menu__hint', item.hint));
  }
  if (isSubmenu(item)) {
    const chevron = build('span', 'daedalus-ui-menu__chevron');
    chevron.setAttribute('aria-hidden', 'true');
    chevron.innerHTML = CHEVRON;
    button.appendChild(chevron);
  }
  button.addEventListener('mouseenter', () => {
    if (inFlyout) {
      setSubActive(index);
    } else {
      onTopRowEnter(item, index);
    }
  });
  button.addEventListener('click', (event) => {
    event.preventDefault();
    event.stopPropagation();
    clearIntent();
    activateItem(index, inFlyout);
  });
  return button;
};

/**
 * Builds one list (the top list or a flyout) from its items: the rows,
 * the keyboard, and the hover-scope ownership. Any checkable item gives
 * every row the gutter, so labels align; a meter row always carries one,
 * so its presence aligns the rest too.
 *
 * @return {{list: Element, buttons: (Element|null)[]}}
 */
const buildList = (items, label, inFlyout) => {
  const list = build('div', 'daedalus-ui-menu');
  list.setAttribute('role', 'menu');
  list.setAttribute('data-selection-hover-scope', '');
  list.setAttribute('aria-label', label);
  list.addEventListener('keydown', onKeyDown);
  list.addEventListener('contextmenu', (event) => {
    // No native menu on the menu itself.
    event.preventDefault();
    event.stopPropagation();
  });
  const gutter = items.some(
    (item) => item.checked !== null || typeof item.meter === 'number',
  );
  const buttons = items.map((item, index) => {
    const row = buildItem(item, index, gutter, inFlyout);
    list.appendChild(row);
    return isFocusable(item) ? row : null;
  });
  return { list, buttons };
};

/**
 * Mounts the flyout of the submenu row at `index` into the surface
 * root beside the row: measured hidden, placed by flyoutPosition,
 * revealed. The row reads expanded.
 */
const mountSub = (menu, index) => {
  const row = mounted.buttons[index];
  const item = menu.items[index];
  const { list, buttons } = buildList(item.items, item.label, true);
  list.classList.add('daedalus-ui-menu--flyout');
  list.style.visibility = 'hidden';
  mounted.root.appendChild(list);
  const { left, top, side } = flyoutPosition({
    rect: row.getBoundingClientRect(),
    width: list.offsetWidth,
    height: list.offsetHeight,
    viewportWidth: window.innerWidth,
    viewportHeight: window.innerHeight,
    // The flyout's border and list padding above its first row
    // (menu.css), so that row sits level with the submenu row.
    inset: 5,
  });
  list.style.left = `${left}px`;
  list.style.top = `${top}px`;
  list.setAttribute('data-side', side);
  list.style.visibility = 'visible';
  row.setAttribute('aria-expanded', 'true');
  mounted.sub = { index, list, buttons };
};

/**
 * Takes the flyout down; the row reads collapsed again.
 */
const unmountSub = () => {
  if (!mounted?.sub) {
    return;
  }
  const { index, list } = mounted.sub;
  mounted.sub = null;
  list.remove();
  mounted.buttons[index]?.setAttribute('aria-expanded', 'false');
};

/**
 * Builds the list for a fresh open and opens the surface with it as
 * content: the surface measures, places, and reveals it, and its onClose
 * (any dismissal the surface performs, or a replacement by another
 * surface) closes the menu's state. The host's Escape listener goes on
 * the document BEFORE the surface registers its own, so a flyout's
 * Escape is taken here first.
 */
const mount = (menu) => {
  const { list, buttons } = buildList(
    menu.items,
    menu.header ?? Drupal.t('Menu'),
    false,
  );
  if (menu.header) {
    const header = build('div', 'daedalus-ui-menu__header', menu.header);
    header.setAttribute('aria-hidden', 'true');
    list.prepend(header);
  }

  document.addEventListener('keydown', onEscapeCapture, true);
  mounted = {
    items: menu.items,
    buttons,
    root: null,
    restoreFocus: false,
    sub: null,
    intent: null,
  };
  mounted.root = Drupal.Daedalus.popover.open({
    anchor: menu.anchor,
    x: menu.x,
    y: menu.y,
    align: menu.align,
    content: list,
    onClose: close,
  });
  buttons[menu.activeIndex]?.focus();
};

/**
 * Takes the list down with the surface. Closing the surface fires its
 * onClose, which finds the menu state already closed (idempotent).
 */
const unmount = () => {
  if (!mounted) {
    return;
  }
  clearIntent();
  document.removeEventListener('keydown', onEscapeCapture, true);
  const { restoreFocus } = mounted;
  mounted = null;
  Drupal.Daedalus.popover.close({ restoreFocus });
};

/**
 * Swaps the armed row's label in one list and restores the others'.
 */
const syncArmed = (items, buttons, armedIndex) => {
  items.forEach((item, index) => {
    const button = buttons[index];
    if (!button) {
      return;
    }
    const armed = armedIndex === index;
    button.classList.toggle('daedalus-ui-menu__item--armed', armed);
    button.querySelector('.daedalus-ui-menu__label').textContent = armed
      ? armedLabel(item)
      : item.label;
  });
};

/**
 * Projects the state machine into the DOM: mount on open, unmount on
 * close, and in between mount or drop the flyout as `sub` says, move
 * focus with the active item of the list the keyboard is in, and swap
 * the armed item's label.
 */
const sync = () => {
  const menu = getState();
  if (!menu) {
    unmount();
    return;
  }
  if (!mounted || mounted.items !== menu.items) {
    unmount();
    mount(menu);
    return;
  }
  if (mounted.sub && mounted.sub.index !== menu.sub?.index) {
    unmountSub();
  }
  if (menu.sub && !mounted.sub) {
    mountSub(menu, menu.sub.index);
  }
  syncArmed(menu.items, mounted.buttons, menu.armedIndex);
  if (menu.sub) {
    const item = menu.items[menu.sub.index];
    syncArmed(item.items, mounted.sub.buttons, menu.sub.armedIndex);
  }
  // The keyboard's list: the flyout while it has an active row, else
  // the top list (a pointer-opened flyout with nothing active yet leaves
  // focus on its row).
  const target =
    menu.sub && menu.sub.activeIndex >= 0
      ? mounted.sub.buttons[menu.sub.activeIndex]
      : mounted.buttons[menu.activeIndex];
  target?.focus();
};

subscribe(sync);

Drupal.Daedalus.menu = { open, close, isOpen };
