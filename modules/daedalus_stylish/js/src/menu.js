import { useEffect, useRef } from 'preact/hooks';

/**
 * The inspector's adapter over the shared menu primitive
 * (Drupal.Daedalus.menu, library daedalus_ui/menu).
 *
 * Every inspector popup (the wearing rows' right-click menu, the chip
 * row's dropdowns, the code views' line and row menus, the property
 * rows' provenance menu, the library card's transfer targets) opens
 * the one primitive imperatively, in its event handler; the primitive
 * owns position, keyboard, and dismissal, and closes BEFORE it runs an
 * item. The inspector renders in the satellite window too: that window
 * declares the library and so runs its own copy, and `Drupal` there is
 * the satellite's, so the facade is read at call time, never at load.
 *
 * What this hook adds is ownership: the primitive is one singleton per
 * window, so a consumer that wants to close the menu on its own terms
 * (a selection move, a map refresh, its own unmount) must close only the
 * menu IT opened, never one another surface has up.
 *
 * @return {{open: Function, close: Function}}
 *   `open(spec)` forwards to the primitive's open() (spec: {items, x, y}
 *   or {items, anchor}, plus header and onClose) and returns whether a
 *   menu opened (false on no items, so the caller can leave the native
 *   menu alone); `close()` closes the menu only while this consumer's is
 *   the open one.
 */
export const useMenu = () => {
  const owned = useRef(false);
  const open = (spec) => {
    const opened = Drupal.Daedalus.menu.open({
      ...spec,
      onClose: () => {
        owned.current = false;
        spec.onClose?.();
      },
    });
    owned.current = opened;
    return opened;
  };
  const close = () => {
    if (owned.current) {
      Drupal.Daedalus.menu.close();
    }
  };
  // A consumer leaving the tree takes its menu with it: the items' closures
  // would otherwise outlive the field or island they act on.
  useEffect(() => close, []);
  return { open, close };
};
