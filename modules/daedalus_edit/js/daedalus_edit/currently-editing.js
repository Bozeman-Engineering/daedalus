/**
 * The ONE writer of Drupal.Daedalus.Edit.CurrentlyEditingElement.
 *
 * Every edit open and close flows through setCurrentlyEditing so the
 * change is REPORTED through the registration seam
 * (app.editSession.editChanged): the app derives its store's editingKey
 * from the report, and selection-anchored chrome (the frame's dash, the
 * chip's verb face) re-renders from the edit's real lifecycle. A bare
 * write kept the global truthful but left the app blind on any path the
 * session did not itself initiate (Escape's cancel inside the widget, a
 * markup swap ending an edit) — the Escape name-only-chip bug.
 *
 * The app reference arrives with engine registration
 * (edit-session-engine.js); before it, the setter still maintains the
 * global (nothing can be edited before the engine registers anyway).
 * Editors delivered outside this module's ES-module graph (the
 * formatted-text submodule's plain script) reach the setter as
 * Drupal.Daedalus.Edit.SetCurrentlyEditingElement (exposed in daedalus-edit.js).
 */

let appRef = null;

/**
 * Binds the registration-seam app so edit changes can be reported.
 *
 * @param app
 *   The Daedalus registration app API.
 */
export const bindEditChangeReporting = (app) => {
  appRef = app;
};

/**
 * Sets the current edit and reports the change to the app.
 *
 * @param {EditableElement} EditableElement
 *   The element now being edited, or null when the edit ended.
 */
export const setCurrentlyEditing = (EditableElement) => {
  Drupal.Daedalus.Edit.CurrentlyEditingElement = EditableElement;
  appRef?.editSession.editChanged(EditableElement?.getPageElement() ?? null);
};
