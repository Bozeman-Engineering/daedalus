/**
 * @file
 * Processes initial page load messages in Edit Mode.
 */

((Drupal, once) => {
  'use strict';

  /**
   * Shows each initial message as an Edit Mode message.
   *
   * @param {object} editMode
   *   The Edit mode plugin.
   * @param {Object<string, Array<{text: string, html: ?string}>>} initialMessages
   *   The messages keyed by type: each its plain text and, when the server
   *   kept any, its filtered display markup.
   */
  const showMessages = (editMode, initialMessages) => {
    Object.keys(initialMessages).forEach((type) => {
      initialMessages[type].forEach((message) => {
        editMode.message(
          String(message.text),
          type,
          message.html ? { html: message.html } : {},
        );
      });
    });
  };

  Drupal.behaviors.daedalusUiInitialMessages = {
    attach(context, settings) {
      once('daedalusUiInitialMessages', 'html', context).forEach(() => {
        const mode = Drupal.Daedalus?.getCookieValue?.('navigationMode');
        if (mode !== 'edit') {
          return;
        }

        const initialMessages = settings.daedalusUi?.initialMessages;
        if (!initialMessages) {
          return;
        }

        const editMode = Drupal.Daedalus?.ModeManager?.getPlugin?.('edit');
        if (!editMode || !editMode.message) {
          Drupal.throwError(
            new Error('Edit Mode plugin not available for initial messages'),
          );
          return;
        }

        showMessages(editMode, initialMessages);

        // Clear from settings so they don't reprocess.
        delete settings.daedalusUi.initialMessages;
      });
    },
  };
})(Drupal, once);
