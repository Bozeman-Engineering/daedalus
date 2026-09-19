/**
 * @file
 * Persists the per-user "Editing chrome" density from the Edit Mode settings
 * panel and mirrors it onto the html element live.
 *
 * The html attribute (data-daedalus-ui-chrome-density) is the one thing CSS and the
 * reveal engine read, so mirroring it on change applies the new density
 * immediately, no reload; the SSR stamp in daedalus_ui_preprocess_html
 * makes the next page load agree.
 */
((Drupal, once) => {
  'use strict';

  Drupal.behaviors.daedalusUiChromeDensity = {
    attach(context) {
      once('daedalus-ui-chrome-density', '#daedalus-ui-chrome-density', context).forEach(
        (select) => {
          select.addEventListener('change', (event) => {
            const value = event.target.value;
            document.documentElement.setAttribute(
              'data-daedalus-ui-chrome-density',
              value,
            );
            Drupal.Daedalus.saveSetting(
              'daedalus_ui',
              'chrome_density',
              value,
            );
          });
        },
      );
    },
  };
})(Drupal, once);
