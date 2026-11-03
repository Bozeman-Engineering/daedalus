/**
 * @file
 * The responsive preview window's one opener
 * (Drupal.Daedalus.openPreviewWindow).
 *
 * The preview window is the pop-out document the responsive_preview
 * library shells (a device selector over a device-sized iframe of the
 * edited page). Opening it is one call from any surface (the top bar
 * menu, daedalus_stylish's chip row), never a window.open of its own:
 * the window's name, its features and the pop-out route contract (the
 * session scope forwarded as ?scope, the edited page as ?url) live here
 * once.
 *
 * Reads the two settings the Edit mode attaches together
 * (Edit::buildTopBar): daedalusUi.responsivePreview.url, the preview
 * route, and daedalusUi.editSession.scope, the session identity the
 * preview's reload pings pair on. The edited page's path is read at call
 * time: the same page is reachable at several URLs, and the click knows
 * the real one.
 *
 * Standalone like dialog and menu: no edit-mode dependency, so a surface
 * attaches this library and calls the door. A page the Edit mode
 * attached no preview to (no route host, so no session to pair with)
 * has no preview to open; a caller offers the opener only where
 * daedalusUi.responsivePreview is attached, and the door throws when
 * called without it.
 */
((Drupal, drupalSettings) => {
  'use strict';

  Drupal.Daedalus = Drupal.Daedalus || {};

  /**
   * The window name: one preview window per browser, reused on re-open.
   */
  const WINDOW_NAME = 'daedalus_ui_responsive_preview';

  /**
   * The popup's features: a phone-tall window beside the editing page.
   */
  const WINDOW_FEATURES = 'popup=yes,width=520,height=920';

  /**
   * Opens (or refocuses) the responsive preview window for this page.
   *
   * @return {?Window}
   *   The window handle window.open answered (null when the browser
   *   blocked the popup).
   */
  Drupal.Daedalus.openPreviewWindow = () => {
    const url = drupalSettings.daedalusUi?.responsivePreview?.url;
    const scope = drupalSettings.daedalusUi?.editSession?.scope;
    if (!url || !scope) {
      throw new Error(
        'No responsive preview is attached to this page ' +
          '(daedalusUi.responsivePreview.url and ' +
          'daedalusUi.editSession.scope ride the Edit mode on a route host).',
      );
    }
    const page = window.location.pathname + window.location.search;
    const query =
      `scope=${encodeURIComponent(scope)}&url=${encodeURIComponent(page)}`;
    return window.open(`${url}?${query}`, WINDOW_NAME, WINDOW_FEATURES);
  };
})(Drupal, drupalSettings);
