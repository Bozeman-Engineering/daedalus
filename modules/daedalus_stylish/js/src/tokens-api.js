/**
 * The token lifecycle HTTP client: the swatch API's twin for the material
 * that lives on the skin.
 *
 * A thin naming layer over the shared gesture transport
 * (gesture-transport.js) for the token gestures that stay routes: the
 * palette card's rename, revert and delete, and the per-token management
 * read (usage). Tokens are site-wide content on the active skin, not
 * per-user tempstore state, so these calls ride their own authenticated
 * session in either host window. Failures reject with an Error carrying
 * `payload` (the blueprint {errors: [{path, code, message, hint}]}
 * envelope), which failureText() phrases.
 *
 * The WRITES that mint or rewrite a token's value, description and
 * offered flag are not here: "+ New token" and the card's editors submit
 * `save_material` / `update_material` (kind token) through the state
 * source (material-gestures.js), the same ops the AI submits. There is
 * no list call either: the tokens the palette's skin section and every
 * stop row read are the materials store's (booted with the page, followed
 * through every response's `materials` delta). Every write here answers
 * with that delta and no echo payload: the caller hands the whole
 * response to the source (source.applyResponse) and reads the written
 * record from the delta (respondedMaterial) when it continues with it.
 *
 * A token's reference is its roster id, "{scale}/{name}"; the routes
 * address the active skin's token, so the reference is never
 * skin-qualified. On the wire the id is two path segments,
 * tokens/{scale}/{name}: Drupal's router matches by outline, one
 * wildcard per parameter, so a slash inside one parameter never reaches
 * a route with a segment after it.
 */

import { get, post, send } from './gesture-transport.js';

/**
 * The route path of a token: its id's scale and name as two segments.
 */
const tokenPath = (reference) => {
  const slash = reference.indexOf('/');
  const scale = slash === -1 ? reference : reference.slice(0, slash);
  const name = slash === -1 ? '' : reference.slice(slash + 1);
  return `daedalus_stylish/tokens/${encodeURIComponent(scale)}/${encodeURIComponent(name)}`;
};

/**
 * Fetches a token's management facts: the live reference count (the
 * prompts' loud number, the card's use line), the layer(s) holding it
 * (package, site or both), whether the package layer is locked on this
 * site (the identity gestures' gate) and the shipped state against the
 * current disk copy (the revert affordance's gate) with its provider.
 *
 * @return {Promise<{id: string, scale: string, name: string,
 *   variable: string, usages: number, layer: string, locked: boolean,
 *   provider: ?string, shipped_state: ?string}>}
 */
export const fetchTokenUsage = (reference) =>
  get(`${tokenPath(reference)}/usage`);

/**
 * Renames a token (the palette card's name gesture).
 *
 * The name IS the custom property's tail, so an unreferenced token moves
 * in this request (the delta removes the old id and upserts the new) and
 * a referenced one answers 202 with a batch URL the caller drives
 * (driveBatch): every var() of it is respelled, then the record moves.
 * The 202 response states the outcome the batch is committed to (the old
 * id's removal, the renamed record), applied once the batch has run.
 *
 * @return {Promise<{renamed: boolean, id: string, references: number,
 *   batch_url?: string, materials: object}>}
 */
export const renameToken = (reference, label) =>
  post(`${tokenPath(reference)}/rename`, {
    label,
  });

/**
 * Reverts a token to what the skin's provider ships right now.
 *
 * No body: the provider's skins file is the payload. On a consuming site
 * this drops the site's record over the token (the package's shows
 * through: the unset gesture); under the developer gate it rewrites the
 * package record from the file too.
 *
 * @return {Promise<{materials: object}>}
 */
export const revertToken = (reference) =>
  post(`${tokenPath(reference)}/revert`, {});

/**
 * Deletes a token, always safely.
 *
 * Immediate (200, `deleted` true) when nothing references it; otherwise
 * 202 with the rewrite-then-delete batch URL the caller drives
 * (driveBatch), every reference rewritten to the token's literal value
 * before the record goes. The response's materials delta states the
 * removal either way.
 *
 * @return {Promise<{deleted: boolean, references: number,
 *   batch_url?: string, materials: object}>}
 */
export const deleteToken = (reference) =>
  send('DELETE', tokenPath(reference));
