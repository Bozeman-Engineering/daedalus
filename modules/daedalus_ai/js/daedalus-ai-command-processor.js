/**
 * @file
 * Processes Drupal AJAX commands and mutation payloads from DeepChat responses.
 *
 * Wraps DeepChat's responseInterceptor to extract two payload kinds from
 * every response (initial and agent loop continuations):
 * - drupal_commands: AJAX side effects (open-link, workspace toolbar,
 *   trigger-deferred), executed via Drupal's AJAX command system.
 * - blueprint_responses: mutation-response payloads for whatever the AI
 *   tools mutated during the turn, applied through the app's ONE
 *   applyResponse pipeline (blueprint mirror replacement, subtree markup
 *   swaps, asset attachments, piggybacked commands). That is the same path
 *   every other mutation rides, so AI edits join the mirror and highlights
 *   by construction.
 *
 * After processing, dispatches an 'daedalusAiDeepChatResponse' CustomEvent
 * on the deep-chat element so other modules can react to responses
 * without further wrapping the interceptor chain.
 */

(($, Drupal, once) => {
  const { log } = Drupal.Daedalus.logger('daedalus_ai');

  Drupal.Daedalus.AiCommands = {
    installed: false,

    /**
     * Installs the response interceptor on DeepChat.
     */
    install() {
      const deepchat = document.querySelector('deep-chat');
      if (!deepchat || this.installed) {
        return;
      }

      const existingInterceptor = deepchat.responseInterceptor;
      deepchat.responseInterceptor = (response) => {
        if (response.drupal_commands) {
          log('commands', 'Processing drupal_commands from response', {
            count: response.drupal_commands.length,
          });
          const ajax = Drupal.ajax({ url: '' });
          ajax.success(response.drupal_commands, 'success');
          delete response.drupal_commands;
        }
        if (response.blueprint_responses) {
          const payloads = response.blueprint_responses;
          delete response.blueprint_responses;
          // The applyResponse facade only exists where the edit-mode app
          // booted. Without it there is nothing to swap (and playing the
          // payload's attachments outside the app would be all risk, no
          // reward), so the payloads are dropped: the change is staged in
          // tempstore and appears on the next edit-mode load.
          const applyResponse = Drupal.Daedalus?.Blueprint?.applyResponse;
          if (applyResponse) {
            log('commands', 'Applying blueprint_responses from response', {
              count: payloads.length,
            });
            payloads
              .reduce(
                (chain, payload) => chain.then(() => applyResponse(payload)),
                Promise.resolve(),
              )
              .catch((error) => {
                log('commands', 'Applying a blueprint response failed', error);
              });
          }
        }
        const result = existingInterceptor
          ? existingInterceptor(response)
          : response;

        deepchat.dispatchEvent(
          new CustomEvent('daedalusAiDeepChatResponse', {
            detail: { response: result },
          }),
        );

        return result;
      };
      this.installed = true;
    },
  };

  Drupal.behaviors.daedalusAiCommandProcessor = {
    attach(context) {
      once('daedalus-ai-commands', 'deep-chat', context).forEach(() => {
        Drupal.Daedalus.AiCommands.install();
      });
    },
  };
})(jQuery, Drupal, once);
