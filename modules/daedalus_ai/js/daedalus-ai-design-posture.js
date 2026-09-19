/**
 * @file
 * The design posture: the user's declaration, made from the chat UI.
 *
 * The design skill carries two postures, on brand (the design system's
 * materials are the boundary) and workshopping (the materials are
 * reference), and its doctrine is that the posture is the user's call,
 * declared by their intent in the conversation, never read from site
 * config. This file is the UI's way to make that declaration: the chat
 * kebab's "On brand styling" item flips the posture through the setter
 * here, and the posture then rides every chat turn as context, in both
 * states, so a flip mid-conversation lands as a signal rather than
 * silence. From the UI the default is workshopping: the item starts
 * unchecked and the editor turns on brand on. The server reads an ABSENT
 * declaration as on brand (the skill's own default for conversations that
 * carry none), but the UI never sends an absent one, so the client default
 * is the one an editor meets.
 *
 * The spine is the existing chat context spine, no new road: the
 * `design_posture` provider registered here -> Drupal.Daedalus.AiContext
 * collects it into request.body.contexts -> ai_chatbot's DeepChatApi
 * setContext() -> AiAssistantPassContextToAgentEvent -> daedalus_ai_anthropic's
 * DesignPostureContextSubscriber, which prepends the posture as a
 * <system-reminder> block on the last user message (the selection and
 * page-print blocks travel exactly this way). The wire vocabulary is the
 * skill's own: `on_brand` and `workshopping`.
 *
 * State is browser-local: one localStorage key, `daedalus_ai.design_posture`,
 * present with `on_brand` or absent (workshopping, the default). The server
 * never reads the posture as a preference, only as per-turn context like
 * the selection, which is why it stays client-side rather than in the
 * per-user EditorSettings. This file is the state's one owner; the menu
 * consumes it through get() and set() and never touches storage.
 *
 * The context manager arrives with ai_chatbot/deepchat (a dependency of
 * the `chat` library this file ships in), a script the lazy-built panel can
 * stream in under BigPipe, so the provider registers on behavior attach
 * rather than at load. A manager still missing at attach is a broken
 * dependency, so it throws rather than warning and sending no posture.
 */

((Drupal) => {
  const STORAGE_KEY = 'daedalus_ai.design_posture';
  const ON_BRAND = 'on_brand';
  const WORKSHOPPING = 'workshopping';

  Drupal.Daedalus.Ai = Drupal.Daedalus.Ai || {};

  /**
   * The design posture facade.
   *
   * @namespace Drupal.Daedalus.Ai.designPosture
   */
  Drupal.Daedalus.Ai.designPosture = {
    /**
     * Reads the current posture.
     *
     * @return {string}
     *   'on_brand' when the key holds it, otherwise 'workshopping'; an
     *   absent or unrecognized key is the default posture.
     */
    get() {
      return localStorage.getItem(STORAGE_KEY) === ON_BRAND
        ? ON_BRAND
        : WORKSHOPPING;
    },

    /**
     * Declares the posture.
     *
     * @param {string} posture
     *   'on_brand' writes the key; 'workshopping' removes it (absent is the
     *   default). Anything else is a caller bug and throws.
     */
    set(posture) {
      if (posture === ON_BRAND) {
        localStorage.setItem(STORAGE_KEY, ON_BRAND);
        return;
      }
      if (posture === WORKSHOPPING) {
        localStorage.removeItem(STORAGE_KEY);
        return;
      }
      throw new Error(
        `Daedalus AI: unknown design posture "${posture}"; expected "${ON_BRAND}" or "${WORKSHOPPING}".`,
      );
    },
  };

  /**
   * Registers the design_posture context provider.
   *
   * Registration is idempotent (the manager keys providers by name), so
   * riding every attach is harmless and guarantees the provider is in
   * place before anything sends a chat request.
   */
  Drupal.behaviors.daedalusAiDesignPosture = {
    attach() {
      if (!Drupal.Daedalus.AiContext) {
        throw new Error(
          'Daedalus AI: Drupal.Daedalus.AiContext is missing; ai_chatbot/deepchat did not load the context manager.',
        );
      }
      Drupal.Daedalus.AiContext.register('design_posture', () =>
        Drupal.Daedalus.Ai.designPosture.get(),
      );
    },
  };
})(Drupal);
