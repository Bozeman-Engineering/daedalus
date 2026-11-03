/**
 * @file
 * Generic helper to persist a per-user editor preference.
 *
 * Its own library (daedalus_ui/save_setting) so chrome outside Edit Mode
 * (the always-on chat's model choice) can save through the one lane
 * without the edit-mode bundle.
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
   * A path-safe scalar (letters, digits, `_`, `.`, `-`; '1' / '0', a model
   * id) rides the route's value segment through Drupal.ajax, so the
   * server's status message shows where the edit-mode command handler is
   * loaded (core skips a command it has no handler for). Any other string
   * (one carrying a slash or a colon, say) is POSTed as the route's
   * application/json body with the value segment omitted, the form
   * structured values take (see the responsive preview's device list);
   * the same lane, the form the value needs.
   *
   * @param {string} namespace
   *   The owning module's machine name.
   * @param {string} key
   *   The preference key within the namespace.
   * @param {string} value
   *   The value to store.
   *
   * @return {Promise<void>}
   *   Resolves once the save is answered (rejects on a refused JSON save;
   *   the Drupal.ajax form reports its failure through the console).
   */
  Drupal.Daedalus.saveSetting = (namespace, key, value) => {
    if (/^[A-Za-z0-9_.-]*$/.test(value)) {
      const ajaxConfig = {
        url: Drupal.url(`daedalus-ui/setting/${namespace}/${key}/${value}`),
        event: 'daedalus-ui-save-setting',
        error: (error) => {
          console.error(
            'Unable to save setting: ',
            error.responseText || error,
          );
        },
      };
      Drupal.ajax(ajaxConfig).execute();
      return Promise.resolve();
    }
    return fetch(Drupal.url(`daedalus-ui/setting/${namespace}/${key}`), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(value),
    }).then((response) => {
      if (!response.ok) {
        throw new Error(
          Drupal.t('Saving @key failed (HTTP @status).', {
            '@key': key,
            '@status': response.status,
          }),
        );
      }
    });
  };
})(jQuery, Drupal);
