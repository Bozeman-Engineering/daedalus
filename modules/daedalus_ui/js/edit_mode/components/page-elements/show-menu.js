import { h } from 'preact';
import { useSelector, useDispatch } from 'react-redux';
import { useEffect, useRef, useState } from 'preact/hooks';
import { setViewVisibility } from '../../store/slices/view-slice';
import {
  showMenuItems,
  subscribeTreeViews,
  viewToggles,
} from './view-registry';
import { showMenuEntries } from './show-menu-entries';

/**
 * The Show menu: what the page-elements tree displays.
 *
 * A kebab in the panel header's action cluster (the IntelliJ
 * Appearance-menu pattern), a kit icon button the tree portals into the
 * slot the header lends it (#page-elements-show-menu, beside the close
 * control), opening the shared menu primitive (daedalus_ui/menu)
 * anchored to the button, one checked item per tree view provider (the
 * built-in markup view, plus any contrib views), default off; on, the
 * provider's view replaces the tree's projection. After the toggles,
 * behind a separator, the registered Show-menu items (view-registry.js
 * registerShowMenuItem): another module's entry that belongs in this
 * menu without being a view of the tree.
 *
 * The kebab renders only when it has entries: no providers and no
 * registered items means no menu at all.
 */

export const ShowMenu = () => {
  const dispatch = useDispatch();
  const viewVisibility = useSelector((state) => state.view.views);
  // Whether OUR menu is the open one (aria-expanded); the primitive's
  // onClose clears it however the menu closes.
  const [expanded, setExpanded] = useState(false);
  // Providers and items register at any point after mount; re-read them.
  const [, bump] = useState(0);
  useEffect(() => subscribeTreeViews(() => bump((count) => count + 1)), []);
  const menuRef = useRef(null);

  const toggles = viewToggles();
  const registered = showMenuItems();
  // An empty menu is no menu.
  const rendered = toggles.length > 0 || registered.length > 0;

  // Secondary chrome: hidden from minimal density up until the pointer
  // nears.
  // React-rendered, so the [data-daedalus-ui-conceal] auto-register behavior never
  // sees it; register with the reveal engine whenever the kebab exists
  // (providers can make it appear at any point after mount).
  useEffect(() => {
    const el = menuRef.current;
    if (!rendered || !el) {
      return undefined;
    }
    Drupal.Daedalus.reveal.register(el, { near: 100 });
    return () => Drupal.Daedalus.reveal.unregister(el);
  }, [rendered]);

  if (!rendered) {
    return null;
  }

  // The kebab toggles: the anchor counts as inside the menu's dismissal
  // boundary, so a click on it while open closes rather than reopens.
  // The kebab sits at the inline end of the panel header, so the menu
  // aligns its end edge to the kebab's and hangs inward over the panel,
  // never outward over the canvas.
  const toggle = () => {
    if (expanded) {
      Drupal.Daedalus.menu.close();
      return;
    }
    // The entries in the shared menu grammar (show-menu-entries.js),
    // composed as the menu opens: the toggles as checked items whose
    // activation flips their axis (the menu closes first, as any
    // activation does), then the registered items, whose checked state is
    // read right now (a registered item answers from its owner's own
    // state, so the menu shows the truth at open time).
    const items = showMenuEntries(
      toggles,
      viewVisibility,
      (change) => dispatch(setViewVisibility(change)),
      registered,
    );
    setExpanded(
      Drupal.Daedalus.menu.open({
        anchor: menuRef.current.querySelector('.daedalus-ui-show-menu__button'),
        align: 'end',
        header: Drupal.t('Show'),
        items,
        onClose: () => setExpanded(false),
      }),
    );
  };

  return (
    <div className="daedalus-ui-show-menu" data-daedalus-ui-conceal="minimal" ref={menuRef}>
      <button
        type="button"
        className="daedalus-ui-show-menu__button daedalus-ui-icon-button daedalus-ui-icon-button--muted"
        aria-haspopup="menu"
        aria-expanded={expanded}
        aria-label={Drupal.t('Show options')}
        title={Drupal.t('Show options')}
        onClick={toggle}
      >
        ⋮
      </button>
    </div>
  );
};
