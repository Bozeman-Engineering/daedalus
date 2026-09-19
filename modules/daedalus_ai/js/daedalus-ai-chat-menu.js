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
 * warning and doing nothing.
 */

((Drupal, once) => {
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
  const clearHistory = () => {
    if (typeof Drupal.clearDeepchatMessages !== 'function') {
      throw new Error(
        'Daedalus AI: Drupal.clearDeepchatMessages is missing; ai_chatbot/deepchat did not initialize the chat.',
      );
    }
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
})(Drupal, once);
