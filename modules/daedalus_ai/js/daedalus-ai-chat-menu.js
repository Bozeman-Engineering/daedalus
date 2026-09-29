/**
 * @file
 * The AI chat sidebar's options kebab: a rider of the shared menu.
 *
 * ChatSidebar::build() renders only the kebab button; on click this opens
 * Drupal.Daedalus.menu anchored to it, end-aligned so the list hangs
 * inward over the panel (the kebab sits at the inline end of the header).
 * The menu primitive owns the surface, its placement, dismissal, keyboard,
 * and focus return. Two items, in two groups:
 *
 * - "On brand styling", a checked item: the user's design posture
 *   declaration. Checked is on brand, unchecked is workshopping (the
 *   default). Activating it flips the posture through the
 *   Drupal.Daedalus.Ai.designPosture facade (daedalus-ai-design-posture.js owns the
 *   state and its design_posture context provider; the menu never touches
 *   storage). The primitive closes the menu before any item runs, and the
 *   items are built at open time, so the next open shows the new state
 *   with no re-render seam.
 * - "Clear History", a destructive two-step confirm (danger + confirm):
 *   the first activation arms it, the second runs.
 *
 * The clear itself lives in ai_chatbot's deepchat-init.js, which exposes
 * Drupal.clearDeepchatMessages() and arrives through the ai_chatbot/deepchat
 * dependency. Its absence is a broken dependency, so it throws rather than
 * warning and doing nothing. Before the reset, the thread's attachments are
 * released through daedalus_ai's release route: ai_chatbot's reset hands
 * the same thread id straight back to the next conversation, so a file
 * left held would bleed into it (the cron sweep only catches threads whose
 * session is gone, and the next conversation writes it again).
 */

((Drupal, drupalSettings, once) => {
  /**
   * Releases the thread's attachments (daedalus_ai.attachments_release).
   *
   * The token rides the X-CSRF-Token header the way every page-builder post
   * does; a failed release is an error, since the reset that follows would
   * hand the held files to the next conversation.
   */
  const releaseAttachments = async (threadId) => {
    const tokenResponse = await fetch(Drupal.url('session/token'));
    const token = await tokenResponse.text();
    const response = await fetch(Drupal.url('daedalus-ai/attachments/release'), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': token,
      },
      body: JSON.stringify({ thread_id: threadId }),
    });
    if (!response.ok) {
      throw new Error(
        `Daedalus AI: releasing the attachments of thread ${threadId} failed (${response.status}).`,
      );
    }
  };

  /**
   * Flips the design posture: on brand <-> workshopping.
   */
  const flipDesignPosture = () => {
    const posture = Drupal.Daedalus.Ai.designPosture.get();
    Drupal.Daedalus.Ai.designPosture.set(
      posture === 'on_brand' ? 'workshopping' : 'on_brand',
    );
  };

  /**
   * Clears the conversation through ai_chatbot's chat reset.
   *
   * clearDeepchatMessages() takes the click event that ai_chatbot's own
   * buttons hand it, only to stop its propagation; the menu runs items
   * without an event, so a detached one satisfies the signature.
   */
  const clearHistory = async () => {
    if (typeof Drupal.clearDeepchatMessages !== 'function') {
      throw new Error(
        'Daedalus AI: Drupal.clearDeepchatMessages is missing; ai_chatbot/deepchat did not initialize the chat.',
      );
    }
    await releaseAttachments(drupalSettings.ai_deepchat.thread_id);
    Drupal.clearDeepchatMessages(new Event('click'));
  };

  Drupal.behaviors.daedalusAiChatMenu = {
    attach(context) {
      once('daedalus-ai-chat-menu', '.daedalus-ai-chat-menu-button', context).forEach(
        (button) => {
          // Whether OUR menu is the open one (aria-expanded); the primitive's
          // onClose clears it however the menu closes.
          let expanded = false;
          const setExpanded = (open) => {
            expanded = open;
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
          };

          // The kebab toggles: the anchor counts as inside the menu's
          // dismissal boundary, so a click on it while open closes rather
          // than reopens.
          button.addEventListener('click', () => {
            if (expanded) {
              Drupal.Daedalus.menu.close();
              return;
            }
            setExpanded(
              Drupal.Daedalus.menu.open({
                anchor: button,
                align: 'end',
                items: [
                  {
                    label: Drupal.t('On brand styling'),
                    checked: Drupal.Daedalus.Ai.designPosture.get() === 'on_brand',
                    run: flipDesignPosture,
                  },
                  { separator: true },
                  {
                    label: Drupal.t('Clear History'),
                    danger: true,
                    confirm: true,
                    run: clearHistory,
                  },
                ],
                onClose: () => setExpanded(false),
              }),
            );
          });
        },
      );
    },
  };
})(Drupal, drupalSettings, once);
