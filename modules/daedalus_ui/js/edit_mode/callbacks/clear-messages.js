/**
 * @file
 * AJAX command to clear every showing toast through the notification spine.
 */

((Drupal) => {
  /**
   * AJAX command for clearing all messages.
   */
  Drupal.AjaxCommands.prototype.clearMessages = function clearMessages() {
    Drupal.Daedalus.messenger.clear();
  };
})(Drupal);
