/**
 * @file
 * The editor's marks on the server: the whole map, posted as one body.
 *
 * The marks are `favorites` under this module's namespace in daedalus_ui's
 * EditorSettings (AddPanelFaces::FAVORITES_SETTING), a map of face id to
 * TRUE (added) or FALSE (a default removed), written through the one
 * editor-preference lane (Drupal.Daedalus.saveSetting, the library
 * daedalus_ui/save_setting). The whole map rides every write, last
 * write wins; the model keeps the map small by dropping a mark that
 * restates the face's default (faces.js toggledMarks).
 *
 * The component applies a toggle optimistically and repaints at once;
 * a failed save rejects here with a sentence the component reports
 * through the one message display and rolls the mark back.
 */

/**
 * The setting's namespace and key in EditorSettings.
 */
export const FAVORITES_SETTING = ['daedalus_layout', 'favorites'];

/**
 * Saves the marks.
 *
 * @param {object} marks
 *   The whole map, face id to boolean.
 *
 * @return {Promise<void>}
 *   Resolves when the server took the map; rejects with an Error carrying
 *   a sentence for the editor.
 */
export const saveFavorites = (marks) =>
  Drupal.Daedalus.saveSetting(...FAVORITES_SETTING, marks ?? {});
