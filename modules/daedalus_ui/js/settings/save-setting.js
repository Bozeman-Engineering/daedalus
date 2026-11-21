/**
 * @file
 * Generic helper to persist a per-user editor preference.
 *
 * Its own library (daedalus_ui/save_setting) so chrome outside Edit Mode
 * (the always-on chat's model choice, the responsive preview's window)
 * can save through the one lane without the edit-mode bundle.
 */
((Drupal) => {
  Drupal.Daedalus = Drupal.Daedalus || {};

  /**
   * Persists a namespaced editor preference for the current user.
   *
   * Server-side counterpart: daedalus_ui.settings.save_setting route ->
   * Settings::saveSetting -> EditorSettings::set. Use for any preference the
   * server needs to read back (not for purely visual client-only toggles).
   * The value is the POST's JSON body, a scalar ('1' / '0', a model id) or
   * a structure (a device list, a map of marks) alike, sent with the
   * session token through Drupal.Daedalus.post. The server answers a
   * sentence for a scalar it stored; it is shown where the notification
   * spine (Drupal.Daedalus.messenger) is loaded.
   *
   * @param {string} namespace
   *   The owning module's machine name.
   * @param {string} key
   *   The preference key within the namespace.
   * @param {*} value
   *   The value to store, anything JSON carries.
   *
   * @return {Promise<void>}
   *   Resolves once the server took the value; rejects with an Error
   *   carrying a sentence for the editor when it refused.
   */
  Drupal.Daedalus.saveSetting = async (namespace, key, value) => {
    const response = await Drupal.Daedalus.post(
      Drupal.url(`daedalus-ui/setting/${namespace}/${key}`),
      value,
    );
    if (!response.ok) {
      throw new Error(
        Drupal.t('Saving !key failed (HTTP !status).', {
          '!key': key,
          '!status': response.status,
        }),
      );
    }
    const { message } = await response.json();
    if (message && Drupal.Daedalus.messenger) {
      Drupal.Daedalus.messenger.message(message, 'status', { duration: 1250 });
    }
  };
})(Drupal);
