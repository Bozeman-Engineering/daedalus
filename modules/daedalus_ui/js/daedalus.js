/**
 * @file
 * The Daedalus JS root: Drupal.Daedalus.
 *
 * The one namespace every module's browser code registers with. daedalus_ui's
 * own libraries build it out (logger, messenger, modes, the edit-mode app);
 * daedalus_edit attaches Drupal.Daedalus.Edit, daedalus_ai attaches
 * Drupal.Daedalus.AiContext and its siblings, and daedalus_stylish attaches
 * Drupal.Daedalus.Stylish. A library that assigns into the
 * root declares daedalus_ui/daedalus as a dependency, so the root exists
 * before its script runs.
 */

((Drupal) => {
  Drupal.Daedalus = Drupal.Daedalus || {};
})(Drupal);
