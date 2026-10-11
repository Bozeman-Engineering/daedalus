/**
 * The font lifecycle HTTP client: the animations API's twin for the type
 * material.
 *
 * A thin naming layer over the shared gesture transport
 * (gesture-transport.js) for what stays a route: the bytes door (a face's
 * file upload, which no page-language op can carry), the per-font
 * management read (usage), the delete, and the delivery read the leader
 * refreshes the canvas's @font-face from after a write. Fonts are
 * site-wide content, so these calls ride their own authenticated session
 * in either host window. Failures reject with an Error carrying
 * `payload` (the blueprint {errors: [{path, code, message, hint}]}
 * envelope), which failureText() phrases.
 *
 * The WRITES that mint or edit a font are not here: "+ New font" and the
 * card's edits submit `save_material` / `update_material` (kind font)
 * through the state source (material-gestures.js), the same ops the AI
 * submits, naming the uploaded files by the uuids the upload answered.
 * There is no list call: the fonts the Fonts face, the Font row's stack
 * editor and the completions read are the materials store's (booted with
 * the page, followed through every response's `materials` delta).
 */

import { get, postForm, send } from './gesture-transport.js';

/**
 * Uploads one font file (woff2 or woff) to the font store.
 *
 * @param {File} file
 *   The browser file.
 *
 * @return {Promise<{file: string, name: string, format: ?string,
 *   url: string}>}
 *   The managed file's uuid (what a face's source names), its stored
 *   name, its format and its URL.
 */
export const uploadFontFile = (file) => {
  const form = new FormData();
  form.append('file', file, file.name);
  return postForm('daedalus_stylish/fonts/files', form);
};

/**
 * Fetches a font's management facts: the live reference count (the
 * prompts' loud number, the card's use line) and the shipped facts.
 *
 * @return {Promise<{machine_name: string, label: string, family: string,
 *   spelling: string, usages: number, provider: ?string,
 *   shipped_state: ?string}>}
 */
export const fetchFontUsage = (reference) =>
  get(`daedalus_stylish/fonts/${encodeURIComponent(reference)}/usage`);

/**
 * Fetches the delivered @font-face text: the compiled fonts stylesheet's
 * current print, for the canvas to stamp after a write.
 *
 * @return {Promise<{css: string}>}
 */
export const fetchFontStylesheet = () => get('daedalus_stylish/fonts/stylesheet');

/**
 * Deletes a font, always safely.
 *
 * An unnamed font deletes in this request ({deleted: true}). A NAMED one
 * responds 202 with {deleted: false, references, batch_url}: the server
 * staged the rewrite-then-delete Batch API job and the CLIENT drives the
 * returned core batch URL (driveBatch); the family leaves every
 * font-family stack in place, then the entity goes. A named font without
 * the in-use permission grade rejects 403 STYLE_FONT_IN_USE.
 *
 * Both answers carry the materials delta: the immediate delete's flushed
 * removal, or, for the batched path, the removal the staged batch is
 * committed to (applied by the caller AFTER driving the batch).
 *
 * @return {Promise<{deleted: boolean, references: number,
 *   batch_url?: string, materials: object}>}
 */
export const deleteFont = (reference) =>
  send('DELETE', `daedalus_stylish/fonts/${encodeURIComponent(reference)}`);
