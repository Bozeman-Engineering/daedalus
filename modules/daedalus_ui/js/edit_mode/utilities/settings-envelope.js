/**
 * @file
 * Idempotent boot of a client store from a drupalSettings JSON envelope.
 *
 * The delivery payloads that boot the client stores (the blueprint mirror,
 * the materials roster) ride drupalSettings as JSON STRINGS, not objects:
 * core's AJAX settings merge is a jQuery deep $.extend, which merges arrays
 * element-wise, so a fresh object merged over a stale one would keep
 * removed entries. A string is replaced atomically and change detection is
 * string equality: the same envelope delivered twice (every behavior
 * attach re-checks) boots nothing, a different one re-boots wholesale
 * (the server owns the model).
 *
 * A malformed envelope is a server bug, never a client condition to absorb:
 * JSON.parse's error propagates.
 *
 * @param {Function} read
 *   Returns the current envelope string from drupalSettings, or undefined
 *   when the payload is not on this page.
 * @param {Function} boot
 *   Receives the parsed envelope when it changed.
 * @return {Function}
 *   sync(): re-checks the settings and boots on change.
 */
export const createEnvelopeSync = (read, boot) => {
  let booted = null;
  return () => {
    const encoded = read();
    if (!encoded || encoded === booted) {
      return;
    }
    const envelope = JSON.parse(encoded);
    booted = encoded;
    boot(envelope);
  };
};
