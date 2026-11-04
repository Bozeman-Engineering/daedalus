/**
 * @file
 * AJAX command to set a browser cookie.
 */
((Drupal) => {
  'use strict';

  /**
   * AJAX command to set a browser cookie.
   *
   * @param {Drupal.Ajax} ajax
   *   The Drupal.Ajax object.
   * @param {object} response
   *   The command's response: `name`, `value` and an optional `path`.
   */
  Drupal.AjaxCommands.prototype.daedalusUiSetCookie = function setCookie(
    ajax,
    response,
  ) {
    if (response.name && response.value) {
      const options = response.path ? { path: response.path } : {};
      Drupal.Daedalus.setCookieValue(response.name, response.value, options);
    }
  };
})(Drupal);
