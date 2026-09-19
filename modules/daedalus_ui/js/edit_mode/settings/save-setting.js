/**
 * @file
 * Generic helper to persist a per-user editor preference.
 */
(($, Drupal) => {
  'use strict';

  Drupal.Daedalus = Drupal.Daedalus || {};

  /**
   * Persists a namespaced editor preference for the current user.
   *
   * Server-side counterpart: daedalus_ui.settings.save_setting route ->
   * Settings::saveSetting -> EditorSettings::set. Use for any preference the
   * server needs to read back (not for purely visual client-only toggles).
   * This helper covers path-safe scalars only; structured values POST an
   * application/json body to the same route with the value segment omitted
   * (see the responsive preview's device list for the pattern).
   *
   * @param {string} namespace
   *   The owning module's machine name.
   * @param {string} key
   *   The preference key within the namespace.
   * @param {string} value
   *   The value to store (path-safe scalar, e.g. '1' / '0').
   */
  Drupal.Daedalus.saveSetting = (namespace, key, value) => {
    const ajaxConfig = {
      url: `/daedalus-ui/setting/${namespace}/${key}/${value}`,
      event: 'daedalus-ui-save-setting',
      error: (error) => {
        console.error('Unable to save setting: ', error.responseText || error);
      },
    };
    Drupal.Daedalus.ModePluginBase.ajax(ajaxConfig).execute();
  };
})(jQuery, Drupal);
