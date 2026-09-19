(($, Drupal) => {
  Drupal.Daedalus = Drupal.Daedalus || {};

  Drupal.Daedalus.highlightElement = function (el) {
    el.classList.add('call-attention-to');
    el.addEventListener(
      'animationend',
      () => {
        el.classList.remove('call-attention-to');
      },
      { once: true },
    );
  };

  /**
   * AJAX command to highlight elements on the page.
   */
  Drupal.AjaxCommands.prototype.highlight = function (ajax, response) {
    if (!response.selectors?.length) {
      return;
    }

    response.selectors.forEach((selector) => {
      const el = document.querySelector(selector);
      if (el) {
        Drupal.Daedalus.highlightElement(el);
      }
    });
  };
})(jQuery, Drupal);
