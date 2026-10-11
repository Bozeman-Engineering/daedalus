/**
 * The one notification call: this window's messenger.
 *
 * Every gesture outcome (a write succeeded, failed, landed with warnings)
 * rides Drupal.Daedalus.messenger, the document-level notification
 * spine daedalus_ui/messenger attaches to whichever window hosts it.
 * The editing page and the satellite window each show their own toasts
 * and write the one shared history, so both state sources' notify() are
 * this call and the satellite never relays an outcome to the leader page
 * to be shown over there: the hand is here.
 *
 * The one HTML boundary, escaped once. The messenger renders its text as
 * HTML by contract (daedalus_ui js/messenger.js: "HTML is rendered, as
 * core's Drupal.Message does", and it attaches behaviors to the toast),
 * while every string daedalus_stylish holds is text: a Drupal.t constant
 * whose placeholders are `!` (the module's sigil policy; nothing is
 * escaped at the sigil), a formatPlural count, or the server's failure
 * text, which must never be able to put markup in a toast. So the text is
 * Drupal.checkPlain'd here, at the boundary, and no caller escapes: one
 * sink, one escape. A notification that wants markup (a use-ajax link,
 * say) does not exist yet; the first one revisits this.
 *
 * Never load-bearing: a document without the messenger (a unit context, a
 * page without the library) silently drops the message. The messenger is
 * a deferred module, read at call time and never at parse time.
 *
 * @param {string} type
 *   The messenger type: 'status', 'error' or 'warning'.
 * @param {string} text
 *   The message text, plain.
 */
export const notify = (type, text) => {
  const messenger = globalThis.Drupal?.Daedalus?.messenger;
  if (!messenger?.message) {
    return;
  }
  messenger.message(globalThis.Drupal.checkPlain(text), type);
};
