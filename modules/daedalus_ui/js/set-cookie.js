/**
 * @file
 * AJAX command to set a browser cookie.
 */
(function (Drupal) {
  'use strict';

  Drupal.AjaxCommands.prototype.daedalusUiSetCookie = function (
    ajax,
    response,
  ) {
    if (response.name && response.value) {
      const options = response.path ? { path: response.path } : {};
      Drupal.Daedalus.setCookieValue(
        response.name,
        response.value,
        options,
      );
    }
  };
})(Drupal);
