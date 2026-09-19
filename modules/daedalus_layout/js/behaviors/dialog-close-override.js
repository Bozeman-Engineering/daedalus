(($, Drupal, once, drupalSettings) => {
  // Override close so we don't detach behaviors without reattaching
  drupalSettings.dialog.close = (e) => {
    Drupal.dialog(e.target).close();
  };
})(jQuery, Drupal, once, drupalSettings);
