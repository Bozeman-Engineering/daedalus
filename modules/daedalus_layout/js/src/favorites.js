/**
 * @file
 * The editor's marks on the server: the whole map, posted as one body.
 *
 * The marks are `favorites` under this module's namespace in daedalus_ui's
 * EditorSettings (AddPanelFaces::FAVORITES_SETTING), a map of face id to
 * TRUE (added) or FALSE (a default removed), written through the generic
 * settings route (daedalus_ui.settings.save_setting) in its structured
 * shape: an application/json body with the value segment omitted, the
 * pattern the responsive preview's device list set (Drupal.Daedalus.saveSetting
 * covers path-safe scalars only). The whole map rides every write, last
 * write wins; the model keeps the map small by dropping a mark that
 * restates the face's default (faces.js toggledMarks).
 *
 * The component applies a toggle optimistically and repaints at once;
 * a failed save rejects here with a sentence the component reports
 * through the one message display and rolls the mark back.
 */

/**
 * The route's path: the namespace and the key, the value omitted.
 */
export const FAVORITES_PATH = 'daedalus-ui/setting/daedalus_layout/favorites';

/**
 * Posts the marks.
 *
 * @param {object} marks
 *   The whole map, face id to boolean.
 *
 * @return {Promise<void>}
 *   Resolves when the server took the map; rejects with an Error carrying
 *   a sentence for the editor.
 */
export const saveFavorites = (marks) =>
  fetch(Drupal.url(FAVORITES_PATH), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(marks ?? {}),
  }).then((response) => {
    if (!response.ok) {
      throw new Error(
        Drupal.t('Saving your favorites failed (HTTP @status).', {
          '@status': response.status,
        }),
      );
    }
  });
