/**
 * @file
 * AJAX command to show Drupal messages via
 * Drupal.Daedalus.ModeManager.getPlugin('edit').message(message, status).
 */

/**
 * AJAX command for showing a message through the Edit mode plugin.
 *
 * @param {Drupal.Ajax} ajax
 *   The Drupal.Ajax object.
 * @param {object} response
 *   The server response: message (plain text), type, and optionally
 *   duration, elementId and html (the message's display markup, filtered
 *   by the server).
 */
Drupal.AjaxCommands.prototype.editModeMessage = function editModeMessage(
  ajax,
  response,
) {
  if (Drupal.Daedalus?.ModeManager?.getPlugin) {
    try {
      const editMode = Drupal.Daedalus.ModeManager.getPlugin('edit');
      if (editMode && editMode.message) {
        // Only forward a duration when the server set one; otherwise the
        // plugin applies its per-type default.
        const options = {};
        if (response.duration !== undefined) {
          options.duration = response.duration;
        }
        if (response.html !== undefined) {
          options.html = response.html;
        }
        editMode.message(
          response.message,
          response.type,
          options,
          response.elementId,
        );
      }
    } catch (error) {
      // The message still lands through core's messenger; the failure
      // surfaces outside the call stack.
      Drupal.throwError(error);
      const messageWrapper = document.querySelector('[data-drupal-messages]');
      if (messageWrapper) {
        const drupalMessage = new Drupal.Message(messageWrapper);
        // The message is text; core's toast writes HTML.
        drupalMessage.add(Drupal.checkPlain(response.message), {
          type: response.type,
        });
      }
    }
  }
};
