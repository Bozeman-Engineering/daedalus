/**
 * @file
 * AJAX command handler for opening entity links.
 */

((Drupal) => {
  const { log } = Drupal.Daedalus.logger('daedalus_ai');

  Drupal.AjaxCommands.prototype.daedalusAiOpenLink = function daedalusAiOpenLink(
    ajax,
    response,
  ) {
    if (response.url) {
      log('open-link', 'Navigating', { url: response.url });
      if (Drupal.behaviors.deepChatToggle) {
        Drupal.behaviors.deepChatToggle.processing = false;
      }
      window.location.href = response.url;
    }
  };
})(Drupal);
