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
 * Never load-bearing: a document without the messenger (a unit context, a
 * page without the library) silently drops the message. The messenger is
 * a deferred module, read at call time and never at parse time.
 *
 * @param {string} type
 *   The messenger type: 'status', 'error' or 'warning'.
 * @param {string} text
 *   The message text.
 */
export const notify = (type, text) => {
  globalThis.Drupal?.Daedalus?.messenger?.message?.(text, type);
};
