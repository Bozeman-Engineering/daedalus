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
 *       { label, icon, checked, hint, run },
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
 * with its own singleton.
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
 *   takes it at document capture).
 * - Rows: the header caption, the check gutter, icons, labels, hints,
 *   separators, and the armed label swap of the two-step confirm.
 *
 * Like every surface rider this is an unbundled module script, so a
 * separate window (daedalus_stylish's satellite) gets its own copy by declaring
 * the dependency. Preact cannot cross separately built bundles.
 */
import {
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
} from './menu-state.js';

Drupal.Daedalus = Drupal.Daedalus || {};

// Phosphor "check" (currentColor), the check gutter's mark.
const CHECK =
  '<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 256 256"><path d="M229.66,77.66l-128,128a8,8,0,0,1-11.32,0l-56-56a8,8,0,0,1,11.32-11.32L96,188.69,218.34,66.34a8,8,0,0,1,11.32,11.32Z"></path></svg>';

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
 * carries it out when the list unmounts.
 *
 * @type {?{items: object[], buttons: (Element|null)[],
 *   restoreFocus: boolean}}
 */
let mounted = null;

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
 */
const activateItem = (index) => {
  if (mounted) {
    mounted.restoreFocus = true;
  }
  if (activate(index) !== 'run' && mounted) {
    // Armed or ignored: the menu stays, and a later pointer leave must
    // not inherit the flag.
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
      activateItem(menu.activeIndex);
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
 * Renders one item row (a separator or a menuitem button).
 */
const buildItem = (item, index, gutter) => {
  if (item.separator) {
    const rule = build('div', 'daedalus-ui-menu__separator');
    rule.setAttribute('role', 'separator');
    return rule;
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
  if (item.disabled) {
    button.setAttribute('aria-disabled', 'true');
    if (item.reason) {
      button.title = item.reason;
    }
  } else if (item.title) {
    button.title = item.title;
  }
  if (gutter) {
    const check = build('span', 'daedalus-ui-menu__check');
    check.setAttribute('aria-hidden', 'true');
    if (item.checked) {
      check.innerHTML = CHECK;
    }
    button.appendChild(check);
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
  button.addEventListener('mouseenter', () => setActive(index));
  button.addEventListener('click', (event) => {
    event.preventDefault();
    event.stopPropagation();
    activateItem(index);
  });
  return button;
};

/**
 * Builds the list for a fresh open and opens the surface with it as
 * content: the surface measures, places, and reveals it, and its onClose
 * (any dismissal the surface performs, or a replacement by another
 * surface) closes the menu's state.
 */
const mount = (menu) => {
  const list = build('div', 'daedalus-ui-menu');
  list.setAttribute('role', 'menu');
  list.setAttribute('aria-label', menu.header ?? Drupal.t('Menu'));
  list.addEventListener('keydown', onKeyDown);
  list.addEventListener('contextmenu', (event) => {
    // No native menu on the menu itself.
    event.preventDefault();
    event.stopPropagation();
  });

  if (menu.header) {
    const header = build('div', 'daedalus-ui-menu__header', menu.header);
    header.setAttribute('aria-hidden', 'true');
    list.appendChild(header);
  }
  // Any checkable item gives every row the gutter, so labels align.
  const gutter = menu.items.some((item) => item.checked !== null);
  const buttons = menu.items.map((item, index) => {
    const row = buildItem(item, index, gutter);
    list.appendChild(row);
    return item.separator ? null : row;
  });

  mounted = { items: menu.items, buttons, restoreFocus: false };
  Drupal.Daedalus.popover.open({
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
  const { restoreFocus } = mounted;
  mounted = null;
  Drupal.Daedalus.popover.close({ restoreFocus });
};

/**
 * Projects the state machine into the DOM: mount on open, unmount on
 * close, and in between move focus with the active item and swap the
 * armed item's label.
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
  menu.items.forEach((item, index) => {
    const button = mounted.buttons[index];
    if (!button) {
      return;
    }
    const armed = menu.armedIndex === index;
    button.classList.toggle('daedalus-ui-menu__item--armed', armed);
    button.querySelector('.daedalus-ui-menu__label').textContent = armed
      ? armedLabel(item)
      : item.label;
  });
  mounted.buttons[menu.activeIndex]?.focus();
};

subscribe(sync);

Drupal.Daedalus.menu = { open, close, isOpen };
