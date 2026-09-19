/**
 * @file
 * Processes initial page load messages in Edit Mode.
 */

(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.daedalusUiInitialMessages = {
    attach(context, settings) {
      once('daedalusUiInitialMessages', 'html', context).forEach(
        function () {
          const mode =
            Drupal.Daedalus?.getCookieValue?.('navigationMode');
          if (mode !== 'edit') {
            return;
          }

          const initialMessages = settings.daedalusUi?.initialMessages;
          if (!initialMessages) {
            return;
          }

          const editMode =
            Drupal.Daedalus?.ModeManager?.getPlugin?.('edit');
          if (!editMode || !editMode.message) {
            console.warn('Edit Mode plugin not available for initial messages');
            return;
          }

          Object.keys(initialMessages).forEach(function (type) {
            initialMessages[type].forEach(function (message) {
              // Display each message as an Edit Mode message.
              editMode.message(String(message), type);
            });
          });

          // Clear from settings so they don't reprocess.
          delete settings.daedalusUi.initialMessages;
        },
      );
    },
  };
})(Drupal, once);
