(($, Drupal) => {
  Drupal.Daedalus = Drupal.Daedalus || {};

  /**
   * Calls attention to one element with the chrome's highlight animation.
   *
   * @param {Element} el
   *   The element to highlight; the class leaves when the animation ends.
   */
  Drupal.Daedalus.highlightElement = function highlightElement(el) {
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
   *
   * @param {Drupal.Ajax} ajax
   *   The Drupal.Ajax object.
   * @param {object} response
   *   The command's response: `selectors`, the elements to highlight.
   */
  Drupal.AjaxCommands.prototype.highlight = function highlight(ajax, response) {
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
