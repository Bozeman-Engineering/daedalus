import { h } from 'preact';
import { useSelector, useDispatch } from 'react-redux';
import { useEffect, useRef, useState } from 'preact/hooks';
import { setPartVisibility } from '../../store/slices/view-slice';
import { subscribeTreeViews, viewToggles } from './view-registry';

/**
 * The Show menu: what the page-elements tree displays.
 *
 * A kebab in the panel header's action cluster (the IntelliJ
 * Appearance-menu pattern), a kit icon button the tree portals into the
 * slot the header lends it (#page-elements-show-menu, beside the close
 * control), opening the shared menu primitive (daedalus_ui/menu)
 * anchored to the button, one checked item per tree view provider (the
 * built-in markup view, plus any contrib views), default off; on, the
 * provider's view replaces the tree's projection.
 *
 * The kebab renders only when it has entries: no providers means no menu
 * at all.
 */

export const ShowMenu = () => {
  const dispatch = useDispatch();
  const partVisibility = useSelector((state) => state.view.parts);
  // Whether OUR menu is the open one (aria-expanded); the primitive's
  // onClose clears it however the menu closes.
  const [expanded, setExpanded] = useState(false);
  // Providers register at any point after mount; re-read their toggles.
  const [, bump] = useState(0);
  useEffect(() => subscribeTreeViews(() => bump((count) => count + 1)), []);
  const menuRef = useRef(null);

  const toggles = viewToggles();
  // An empty menu is no menu.
  const rendered = toggles.length > 0;

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

  // The entries as checked items in the shared menu grammar: activating
  // one flips its axis (the menu closes first, as any activation does).
  const items = toggles.map((toggle) => ({
    label: toggle.label,
    checked: partVisibility[toggle.id] === true,
    run: () =>
      dispatch(
        setPartVisibility({
          id: toggle.id,
          visible: partVisibility[toggle.id] !== true,
        }),
      ),
  }));

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
