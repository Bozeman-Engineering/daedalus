/**
 * @file
 * The chrome-density door (Drupal.Daedalus.setChromeDensity) and the
 * Edit Mode settings panel's "Editing chrome" select, one of its callers.
 *
 * The html attribute (data-daedalus-ui-chrome-density) is the one thing CSS
 * and the reveal engine read, so stamping it on change applies the new
 * density immediately, no reload; the SSR stamp in
 * daedalus_ui_preprocess_html makes the next page load agree. Every writer
 * of the density (the Settings select here, the top bar menu's Editing
 * chrome rows) goes through the one door, which stamps the attribute,
 * persists the preference, and announces the change on the document as a
 * `daedalus-ui:chrome-density` event (detail: {value}); any surface that
 * shows the density follows that event instead of the writer it does not
 * know about, which is how the select here reads a change made from the
 * menu.
 */
((Drupal, once) => {
  'use strict';

  Drupal.Daedalus = Drupal.Daedalus || {};

  /**
   * The html attribute the stylesheets and the reveal engine read.
   */
  const ATTRIBUTE = 'data-daedalus-ui-chrome-density';

  /**
   * The document event a density change announces (detail: {value}).
   */
  const EVENT = 'daedalus-ui:chrome-density';

  /**
   * The ladder, as EditorSettings::getChromeDensity() accepts it.
   */
  const DENSITIES = ['full', 'minimal', 'clean'];

  /**
   * The Settings panel's select, when the panel is in the document.
   */
  const SELECT = '#daedalus-ui-chrome-density';

  /**
   * Sets the per-user editing chrome density.
   *
   * Stamps the html attribute (the live effect), saves the preference
   * through the one settings lane (Drupal.Daedalus.saveSetting, so the
   * next page load agrees), and dispatches `daedalus-ui:chrome-density`
   * on the document so every surface showing the density follows.
   *
   * @param {string} value
   *   'full' (show everything), 'minimal', or 'clean'.
   */
  Drupal.Daedalus.setChromeDensity = (value) => {
    if (!DENSITIES.includes(value)) {
      throw new Error(`Unknown chrome density "${value}".`);
    }
    document.documentElement.setAttribute(ATTRIBUTE, value);
    Drupal.Daedalus.saveSetting('daedalus_ui', 'chrome_density', value);
    document.dispatchEvent(new CustomEvent(EVENT, { detail: { value } }));
  };

  // The select follows every change, whoever made it. One document
  // listener looking the select up at event time, so a panel re-rendered
  // later is found and no listener outlives a detached select.
  document.addEventListener(EVENT, (event) => {
    const select = document.querySelector(SELECT);
    if (select) {
      select.value = event.detail.value;
    }
  });

  Drupal.behaviors.daedalusUiChromeDensity = {
    attach(context) {
      once('daedalus-ui-chrome-density', SELECT, context).forEach((select) => {
        select.addEventListener('change', (event) => {
          Drupal.Daedalus.setChromeDensity(event.target.value);
        });
      });
    },
  };
})(Drupal, once);
