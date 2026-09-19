/**
 * @file
 * Workaround for a loading-animation leak in ai_chatbot's deepchat-init.js.
 *
 * PROBLEM
 * -------
 * When an AI agent makes multiple tool calls, the DeepChat polling loop
 * (getAllMessages in deepchat-init.js) accumulates step descriptions in
 * Drupal.behaviors.deepChatToggle.stepMessages. These are joined into an
 * <ol> inside a <details> element in the final rendered message.
 *
 * The bug is a double-push: the responseInterceptor (deepchat-init.js ~L114)
 * pushes the CLEAN tool-call HTML to stepMessages and then wraps
 * response.html with a loading-wrapper div containing a loading-span that
 * has an infinite CSS animation (pulse-color 2s infinite). Immediately
 * after, getAllMessages (~L313) pushes data.html to stepMessages again —
 * but by this point the interceptor has already mutated it into the
 * wrapped version. The result: the final <details> section contains <li>
 * elements with loading-wrapper markup whose animation never stops.
 *
 * WHY WE WORK AROUND IT INSTEAD OF PATCHING
 * ------------------------------------------
 * The fix in contrib would be to remove the redundant push at ~L313 of
 * deepchat-init.js — the responseInterceptor already handles it. We chose
 * not to carry a local patch of deepchat-init.js because:
 *
 *   1. ai_chatbot is an actively-developed contrib module with frequent
 *      releases. A patch on deepchat-init.js would need to be re-applied
 *      and conflict-checked on every update.
 *
 *   2. The bug is latent in contrib and only surfaces when the agent makes
 *      2+ tool calls through the verbose-mode polling loop, which our
 *      architecture (persistent single-agent with direct tool access)
 *      introduced. Filing an upstream issue is the right long-term path.
 *
 *   3. This workaround is isolated, defensive, and degrades gracefully:
 *      if contrib changes the class name or fixes the bug, the filter
 *      simply becomes a no-op.
 *
 * HOW IT WORKS
 * ------------
 * Listens for the 'daedalusAiDeepChatResponse' event dispatched by
 * daedalus-ai-command-processor.js after every DeepChat response. On each
 * event, filters stepMessages to remove any entry containing
 * loading-wrapper markup. This runs before getAllMessages reads
 * stepMessages for the final message assembly, so the permanent <details>
 * section only contains clean step descriptions.
 *
 * The intermediate loading display (pulsing "Contacting agents.." text
 * shown during the loop) is unaffected — that is built directly from
 * data.html by getAllMessages, not from stepMessages.
 *
 * @see https://www.drupal.org/project/ai/issues/XXXXXXX
 */

((Drupal, once) => {
  /**
   * Drops loading-bubble step messages once a response has arrived.
   */
  const stripLoadingSteps = () => {
    const steps = Drupal.behaviors.deepChatToggle?.stepMessages;
    if (steps) {
      Drupal.behaviors.deepChatToggle.stepMessages = steps.filter(
        (msg) => !msg.includes('loading-wrapper'),
      );
    }
  };

  Drupal.behaviors.daedalusAiStepMessageSanitizer = {
    attach(context) {
      once('daedalus-ai-step-sanitizer', 'deep-chat', context).forEach(
        (deepchat) => {
          deepchat.addEventListener(
            'daedalusAiDeepChatResponse',
            stripLoadingSteps,
          );
        },
      );
    },
  };
})(Drupal, once);
