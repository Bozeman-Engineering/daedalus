/**
 * @file
 * The AI chat sidebar's options kebab: a rider of the shared menu.
 *
 * ChatSidebar::build() renders only the kebab button; on click this opens
 * Drupal.Daedalus.menu anchored to it, end-aligned so the list hangs
 * inward over the panel (the kebab sits at the inline end of the header).
 * The menu primitive owns the surface, its placement, dismissal, keyboard,
 * and focus return. Up to four groups, the first three only when they
 * have content (the items are built at open time, so each open reads the
 * current state):
 *
 * - "Model": one checked item per model the administrator curated, for a
 *   user holding "choose ai model" (ChatSidebar::build() hands the list
 *   over as drupalSettings.daedalus_ai.modelChoices, the current one
 *   marked: the user's stored choice, else the site default when it is in
 *   the list). The row's label is the model id and its hint the admin's
 *   label. Activating one saves the choice through daedalus_ui's
 *   editor-preference lane (Drupal.Daedalus.saveSetting, namespace
 *   daedalus_ai, key model, the provider__model key) and remembers the
 *   key here so the next open shows it checked; the next conversation
 *   turn runs on it (the memory runner reads the lane at a turn's first
 *   leg).
 * - "Usage left": one read-only meter row per usage window, from the
 *   usage script's last fetch (Drupal.Daedalus.Ai.usage, attached only
 *   when the user has a meter to read): the window's period as the
 *   label (no scope word: "1 month", whether the site's or the user's),
 *   the fraction left as the bar, and the percent with the reset moment
 *   as the hint.
 * - "Design system size": one action row, no caption, no meter, for a
 *   user holding "view ai context usage" (ChatSidebar::build() hands the
 *   dialog route over as drupalSettings.daedalus_ai.contextDialogUrl
 *   only then, with the daedalus popup's opener library,
 *   daedalus_ui/modal). It opens the usage display (what the site shares
 *   with the AI as materials against the budget, the bar by kind, and
 *   the form under it) in core's modal through the popup's one opener,
 *   Drupal.Daedalus.openModal, under the door class every usage door
 *   wears (daedalus-ai-context-dialog). The figure lives in the dialog
 *   alone: a meter here read as the conversation's own context usage,
 *   which it is not.
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
   * The model key chosen in this page's life, so a reopen shows it checked
   * before the server's next render; null until a row is activated.
   */
  let chosenModel = null;

  /**
   * Saves the user's model choice through the editor-preference lane.
   *
   * saveSetting arrives with daedalus_ui/save_setting, a dependency of the
   * chat library; its absence is a broken dependency, so it throws rather
   * than losing the choice.
   *
   * @param {string} key
   *   The chosen model's key, as the row carries it.
   */
  const chooseModel = (key) => {
    if (typeof Drupal.Daedalus.saveSetting !== 'function') {
      throw new Error(
        'Daedalus AI: Drupal.Daedalus.saveSetting is missing; daedalus_ui/save_setting did not load with the chat.',
      );
    }
    Drupal.Daedalus.saveSetting('daedalus_ai', 'model', key);
    chosenModel = key;
  };

  /**
   * The "Model" group: a caption and one checked row per curated model.
   *
   * @return {object[]}
   *   The items, or none when the user has no models to choose from.
   */
  const modelItems = () => {
    const choices = drupalSettings.daedalus_ai?.modelChoices ?? [];
    if (choices.length === 0) {
      return [];
    }
    const current =
      chosenModel ?? choices.find((choice) => choice.current)?.key ?? null;
    return [
      { caption: Drupal.t('Model') },
      ...choices.map((choice) => ({
        label: choice.model,
        hint: choice.label || null,
        checked: choice.key === current,
        run: () => chooseModel(choice.key),
      })),
      { separator: true },
    ];
  };

  /**
   * The "Usage left" group: a caption and one meter row per window.
   *
   * @return {object[]}
   *   The items, or none without a usage script or before its first fetch.
   */
  const usageItems = () => {
    const usage = Drupal.Daedalus.Ai?.usage;
    const windows = usage ? usage.windows() : [];
    if (windows.length === 0) {
      return [];
    }
    return [
      { caption: Drupal.t('Usage left') },
      ...windows.map((window) => ({
        label: window.label,
        meter: window.remaining,
        hint: usage.hint(window),
      })),
      { separator: true },
    ];
  };

  /**
   * The "Design system size" group: the one row opening the usage display.
   *
   * @return {object[]}
   *   The items, or none when the user may not read the usage (no dialog
   *   URL was handed over).
   */
  const contextItems = () => {
    const url = drupalSettings.daedalus_ai?.contextDialogUrl;
    if (!url) {
      return [];
    }
    return [
      {
        label: Drupal.t('Design system size'),
        run: () =>
          Drupal.Daedalus.openModal(url, {
            dialogClass: 'daedalus-ai-context-dialog',
          }),
      },
      { separator: true },
    ];
  };

  /**
   * Releases the thread's attachments (daedalus_ai.attachments_release).
   *
   * The token rides the X-CSRF-Token header the way every page-builder post
   * does; a failed release is an error, since the reset that follows would
   * hand the held files to the next conversation.
   *
   * @param {string} threadId
   *   The conversation's thread id.
   */
  const releaseAttachments = async (threadId) => {
    const tokenResponse = await fetch(Drupal.url('session/token'));
    const token = await tokenResponse.text();
    const response = await fetch(
      Drupal.url('daedalus-ai/attachments/release'),
      {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': token,
        },
        body: JSON.stringify({ thread_id: threadId }),
      },
    );
    if (!response.ok) {
      throw new Error(
        `Daedalus AI: releasing the attachments of thread ${threadId} failed (${response.status}).`,
      );
    }
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
      once(
        'daedalus-ai-chat-menu',
        '.daedalus-ai-chat-menu-button',
        context,
      ).forEach((button) => {
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
                ...modelItems(),
                ...usageItems(),
                ...contextItems(),
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
      });
    },
  };
})(Drupal, drupalSettings, once);
