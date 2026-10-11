/**
 * The clipboard door: the one file in the inspector that names
 * navigator.clipboard.
 *
 * Why a door. The Clipboard API's two interfaces, navigator.clipboard and
 * ClipboardItem, are both [SecureContext], so a page served over plain
 * http (a ddev site's http address in Safari, and the FunctionalJavascript
 * origin) has neither. A direct call there is a synchronous TypeError out
 * of the click handler, before any promise exists, so a caller's .finally
 * never runs: the header cluster's busy flag stayed true and every button
 * in it stayed disabled until the selection left elements. The door never
 * throws. An absent API rejects with the editor's sentence (never the
 * browser's), and a stub missing a method rejects too, so every caller's
 * chain settles and its .finally is reached.
 *
 * The probe is presence, read at call time here and at each render by the
 * callers, never window.isSecureContext: the FJS Copy CSS seam mounts a
 * fake navigator.clipboard by injected script after the page loads on the
 * http origin, and a secure-context probe would refuse the button the test
 * clicks.
 *
 * The write's two roads, moved here from variants.js: a promise-backed
 * ClipboardItem when the interface exists, so the write stays inside the
 * user's gesture while the text is produced (the Copy CSS print is fetched
 * from the server; Safari accepts a promise for the item's data, Chrome's
 * transient activation covers the fetch), else writeText after the
 * producer's promise resolves.
 */

/**
 * Whether this document has the system clipboard.
 *
 * @return {boolean}
 *   TRUE when navigator.clipboard exists (a secure context, or a mounted
 *   stub); FALSE on a plain-http page and in a node context.
 */
export const clipboardAvailable = () =>
  typeof navigator !== 'undefined' && !!navigator.clipboard;

/**
 * The one sentence for "no clipboard here", read at call time so a node
 * context without the Drupal global still imports this module.
 *
 * Worn by the header cluster's disabled tooltips before the click and by
 * the toast after it (the address chip's "Copy key" item, which has no
 * resting face to wear a reason), and carried by every rejection the door
 * makes for an absent API.
 *
 * @return {string}
 *   The translated sentence.
 */
export const clipboardUnavailableText = () =>
  Drupal.t(
    'Copying and pasting CSS needs a secure (https) page. Open this site over https, or use the Copy styles and Paste styles shortcuts within the page.',
  );

/**
 * Runs a road inside a promise executor, so a synchronous throw anywhere
 * on it (the refusal, a stub without the method, a producer that throws)
 * is a rejection and never escapes the caller's click handler. The road
 * itself runs synchronously, inside the user's gesture.
 */
const settle = (road) => new Promise((resolve) => resolve(road()));

/**
 * Reads the system clipboard's text.
 *
 * @return {Promise<string>}
 *   The text; rejects with an Error carrying clipboardUnavailableText()
 *   when the API is absent, or with the browser's refusal (the permission
 *   prompt declined) when it is present. Never throws.
 */
export const readClipboardText = () =>
  settle(() => {
    if (!clipboardAvailable()) {
      throw new Error(clipboardUnavailableText());
    }
    return navigator.clipboard.readText();
  });

/**
 * Writes produced text to the system clipboard.
 *
 * @param {function(): Promise<string>} produce
 *   Produces the text to write, called synchronously inside the gesture
 *   and only when the API is present: an absent API rejects without
 *   calling it, so a server print is never fetched for a clipboard that
 *   cannot take it.
 *
 * @return {Promise<void>}
 *   Resolves once the clipboard holds the text; rejects with an Error
 *   carrying clipboardUnavailableText() when the API is absent, or with
 *   the producer's or the browser's own refusal. Never throws.
 */
export const writeClipboardText = (produce) =>
  settle(() => {
    if (!clipboardAvailable()) {
      throw new Error(clipboardUnavailableText());
    }
    const text = Promise.resolve(produce());
    if (typeof ClipboardItem === 'undefined') {
      return text.then((value) => navigator.clipboard.writeText(value));
    }
    return navigator.clipboard.write([
      new ClipboardItem({
        'text/plain': text.then(
          (value) => new Blob([value], { type: 'text/plain' }),
        ),
      }),
    ]);
  });
