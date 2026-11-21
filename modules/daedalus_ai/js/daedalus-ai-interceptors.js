/**
 * @file
 * The one road for wrapping a deep-chat interceptor ai_chatbot assigns.
 *
 * ai_chatbot's deepchat-init.js (since ai 1.5.1; the floor is 1.5.3)
 * assigns the <deep-chat> element's requestInterceptor and
 * responseInterceptor inside
 * its own Drupal.aiDeepchat.whenNeeded(element).then(), which runs after
 * every behavior has returned. A wrapper installed at behavior attach
 * therefore wraps nothing and is then overwritten by that assignment: the
 * request interceptor lost the attachment lane that way until 2026-10-21,
 * and the response interceptor lost blueprint_responses and
 * drupal_commands until this helper. Two scripts with the same timing need
 * drifted (one fixed, one not), so the timing and its precondition live
 * here once and each wrapping script is one install() call.
 *
 * install() chains on the same whenNeeded() promise: deepchat-init.js's
 * behavior is registered and run first (its script precedes daedalus_ai's
 * in the deepchat library these files are appended to), so its
 * configuration has assigned ai's interceptor by the time the chained
 * callback runs. A chat attached after the bundle has loaded resolves at
 * once. If the property is still absent when the callback runs, the
 * wrapper would be the one overwritten next, so install() throws rather
 * than succeed silently; the rejection reaches the console (core's test
 * log records window errors only, so the suite pins the install order by
 * asserting on the element instead).
 *
 * ai_chatbot's DrupalDeepchatInitialized event is no seam for this: it is
 * dispatched from a render listener the same configuration adds, and the
 * element has already rendered by then (the bundle is a module script, the
 * render a timer behind it), so on the sidebar the event never fires.
 */

((Drupal) => {
  /**
   * Daedalus AI interceptor installer.
   *
   * @namespace Drupal.Daedalus.AiInterceptors
   */
  Drupal.Daedalus.AiInterceptors = {
    /**
     * Wraps an interceptor property once ai_chatbot has assigned it.
     *
     * @param {HTMLElement} element
     *   The <deep-chat> element.
     * @param {string} property
     *   'requestInterceptor' or 'responseInterceptor'.
     * @param {Function} build
     *   Receives the interceptor ai_chatbot assigned and returns the
     *   wrapper to install in its place.
     *
     * @return {Promise<void>}
     *   Resolves once the wrapper is on the element; rejects with an Error
     *   naming the property when there was nothing to wrap.
     */
    install(element, property, build) {
      return Drupal.aiDeepchat.whenNeeded(element).then(() => {
        const existing = element[property];
        if (existing === undefined) {
          throw new Error(
            `deep-chat has no ${property} to wrap: ai_chatbot has not assigned its own yet, so a wrapper installed now would be overwritten.`,
          );
        }
        element[property] = build(existing);
      });
    },
  };
})(Drupal);
