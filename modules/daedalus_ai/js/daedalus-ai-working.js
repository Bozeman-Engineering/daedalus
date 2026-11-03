/**
 * @file
 * The "AI is working" signal: the chat's busy state mirrored onto <html>.
 *
 * Every lane that puts the AI to work already leaves one of two public
 * marks, and reading them is the whole signal; no lane is wired by hand,
 * and a new lane is covered the day it sets either mark:
 *
 * - deep-chat's own typed turn swaps its submit button to the
 *   `loading-button` class for the request's life (the state behind its
 *   public submitButtonStyles.loading). This is the only mark the first
 *   leg of a turn leaves.
 * - ai_chatbot's Drupal.behaviors.deepChatToggle.processing is the flag
 *   its continuation loop raises (deepchat-init.js getAllMessages) and the
 *   image follow-up and deferred lanes raise themselves, the one "the chat
 *   is still processing" fact this codebase already keeps (ai_chatbot's
 *   own beforeunload guard reads it).
 *
 * A live submit button (`submit-button`) is the user's turn, never the
 * AI's, so it vetoes the flag: ai_chatbot's continuation catch re-enables
 * submit without clearing the flag, and the veto is what ends the glow
 * there when the input holds text (with an empty input the button stays
 * disabled and the flag's staleness shows; an ai_chatbot gap).
 *
 * Neither mark announces itself, so the shadow root is observed and the
 * state re-read on the macrotask after any mutation: deep-chat's class
 * swaps are mutations, and every lane adds or updates a message in the
 * same tick it flips the flag (a lane re-enables submit right after its
 * last updateMessage), so the deferred read sees the settled state and
 * the glow never flickers between a turn's legs.
 *
 * The result is one class on <html>, daedalus-ai-working, which
 * daedalus-ai-working.css paints as the pulsing edge glow.
 */

((Drupal, once) => {
  const CLASS = 'daedalus-ai-working';

  /**
   * Whether the AI holds the chat right now.
   *
   * @param {HTMLElement} chat
   *   The <deep-chat> element.
   *
   * @return {boolean}
   *   TRUE while a request is in flight or a lane holds the chat.
   */
  const isWorking = (chat) => {
    const root = chat.shadowRoot;
    if (root.querySelector('.loading-button')) {
      return true;
    }
    if (root.querySelector('.submit-button')) {
      return false;
    }
    return Drupal.behaviors.deepChatToggle?.processing === true;
  };

  Drupal.behaviors.daedalusAiWorking = {
    attach(context) {
      once('daedalus-ai-working', 'deep-chat', context).forEach((chat) => {
        let pending = 0;
        const sync = () => {
          pending = 0;
          document.documentElement.classList.toggle(CLASS, isWorking(chat));
        };
        const schedule = () => {
          if (!pending) {
            pending = setTimeout(sync, 0);
          }
        };
        // The shadow root exists once the element has upgraded; the
        // observer then sees the render itself and everything after.
        customElements.whenDefined('deep-chat').then(() => {
          new MutationObserver(schedule).observe(chat.shadowRoot, {
            subtree: true,
            childList: true,
            attributes: true,
            attributeFilter: ['class'],
          });
          schedule();
        });
      });
    },
  };
})(Drupal, once);
