/**
 * @file
 * AJAX command to clear every showing toast through the notification spine.
 */

((Drupal) => {
  /**
   * AJAX command for clearing all messages.
   *
   * @param {Drupal.Ajax} [ajax]
   *   The Drupal.Ajax object.
   * @param {object} response
   *   Object holding the server response.
   */
  Drupal.AjaxCommands.prototype.clearMessages = function (ajax, response) {
    Drupal.Daedalus.messenger.clear();
  };
})(Drupal);
