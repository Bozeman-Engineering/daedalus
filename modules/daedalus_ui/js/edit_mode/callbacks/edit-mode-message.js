/**
 * @file
 * AJAX command to show Drupal messages via
 * Drupal.Daedalus.ModeManager.getPlugin('edit').message(message, status).
 */
Drupal.AjaxCommands.prototype.editModeMessage = function (
  ajax,
  response,
  status,
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
        editMode.message(
          response.message,
          response.type,
          options,
          response.elementId,
        );
      }
    } catch (error) {
      console.error('Error displaying edit mode message:', error);
      const messageWrapper = document.querySelector('[data-drupal-messages]');
      if (messageWrapper) {
        const drupalMessage = new Drupal.Message(messageWrapper);
        drupalMessage.add(response.message, {
          type: response.type,
        });
      }
    }
  }
};
