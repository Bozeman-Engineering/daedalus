(($, Drupal, once) => {
  /**
   * Notifications clear button handler.
   *
   * Empties the messenger's history; the notifications sidebar re-renders
   * from the storage event the write fires.
   */
  Drupal.behaviors.DaedalusUiNotificationsClear = {
    attach: (context, settings) => {
      // Handle clear notifications button.
      once('ClearNotifications', '#clear-notifications', context).forEach(
        (clearButton) => {
          clearButton.addEventListener('click', (e) => {
            e.preventDefault();
            Drupal.Daedalus.messenger.clearHistory();
          });
        },
      );
    },
  };
})(jQuery, Drupal, once);
