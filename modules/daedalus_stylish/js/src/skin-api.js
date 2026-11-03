/**
 * The skin HTTP client: the skin's lifecycle, its materials' identity
 * gestures, the font bytes door and the two delivery reads.
 *
 * A thin naming layer over the shared gesture transport
 * (gesture-transport.js) for everything on the skin that stays a route:
 * the skin card's read (which skins the active design system may wear),
 * choose, copy as my skin, the revision history and restore, and the
 * site layer's revert (each an administer gesture with no page batch to
 * ride); a material's management read (usage), rename, revert and delete
 * on the skin's three sections (/daedalus_stylish/skin/{section}/…, one
 * controller, SkinMaterialApiController); the font file upload (a
 * face's bytes, which no page-language op can carry); and the delivery
 * reads the leader refreshes the canvas from after a write (the active
 * skin's defaults with the compiled CSS, the @font-face print). A skin
 * material is site-wide content on the active skin, not per-user
 * tempstore state, so these calls ride their own authenticated session
 * in either host window. Failures reject with an Error carrying
 * `payload` (the blueprint {errors: [{path, code, message, hint}]}
 * envelope), which failureText() phrases.
 *
 * The WRITES that mint or rewrite a material's value, steps, faces,
 * description and offered flag are not here: "+ New" and the card's
 * editors submit `save_material` / `update_material` through the state
 * source (material-gestures.js), the same ops the AI submits, and the
 * skin session's Push submits `update_default`. There is no list call
 * either: the materials the Skin tab and every row read are the
 * materials store's (booted with the page, followed through every
 * response's `materials` delta). Every write here answers with that
 * delta and no echo payload: the caller hands the whole response to the
 * source (source.applyResponse) and reads the written record from the
 * delta (respondedMaterial) when it continues with it.
 */

import { get, post, postForm, send } from './gesture-transport.js';
import { materialRoute } from './skin-model.js';

// --- The skin's materials: usage, rename, revert, delete, by kind. ---

/**
 * Fetches a material's management facts: the live reference count (the
 * prompts' loud number, the card's use line), the layer(s) holding it
 * (package, site or both), whether the package layer is locked on this
 * site (the identity gestures' gate) and the shipped state against the
 * current disk copy (the revert affordance's gate) with its provider.
 * A token's read also carries its `variable`, an animation's its
 * `keyframes`, a font's its `family` and `spelling`.
 *
 * @param {string} kind
 *   'token', 'animation' or 'font'.
 * @param {string} reference
 *   The roster id.
 *
 * @return {Promise<{id: string, name: string, usages: number,
 *   layer: string, locked: boolean, provider: ?string,
 *   shipped_state: ?string}>}
 */
export const fetchMaterialUsage = (kind, reference) =>
  get(`${materialRoute(kind, reference)}/usage`);

/**
 * Renames a material (the card's name gesture).
 *
 * A token's name IS its custom property's tail and an animation's its
 * keyframes ident tail, so an unreferenced one moves in this request
 * ({renamed: true, id}: the delta removes the old id and upserts the
 * new) and a REFERENCED one answers 202 with {renamed: false, id,
 * references, batch_url}: the server staged the rewrite-then-rename
 * batch and the CLIENT drives the returned core batch URL (driveBatch);
 * every reference is respelled, then the record moves. A font's name is
 * its roster id alone (stacks name the FAMILY), so its rename is always
 * immediate. A shipped material refuses on a consuming site.
 *
 * @return {Promise<{renamed: boolean, id: string, references: number,
 *   batch_url?: string, materials: object}>}
 */
export const renameMaterial = (kind, reference, label) =>
  post(`${materialRoute(kind, reference)}/rename`, { label });

/**
 * Reverts a material to what the skin's provider ships right now.
 *
 * No body: the provider's skins file is the payload. On a consuming site
 * this drops the site's record over the shipped one (the package's shows
 * through: the unset gesture); under the developer gate it rewrites the
 * package record from the file too.
 *
 * @return {Promise<{materials: object}>}
 */
export const revertMaterial = (kind, reference) =>
  post(`${materialRoute(kind, reference)}/revert`, {});

/**
 * Deletes a material, always safely.
 *
 * Immediate (200, `deleted` true) when nothing references it; otherwise
 * 202 with the rewrite-then-delete batch URL the caller drives
 * (driveBatch): a token's references become its literal value, an
 * animation's `none`, a font's family leaves every stack naming it, then
 * the record goes. A referenced material without the in-use grade
 * rejects 403. The response's materials delta states the removal either
 * way (for the batched path, the removal the staged batch is committed
 * to, applied by the caller AFTER driving the batch).
 *
 * @return {Promise<{deleted: boolean, references: number,
 *   batch_url?: string, materials: object}>}
 */
export const deleteMaterial = (kind, reference) =>
  send('DELETE', materialRoute(kind, reference));

// --- The font bytes door and the delivery reads. ---

/**
 * Uploads one font file (woff2 or woff) to the skin's font store.
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
  return postForm('daedalus_stylish/skin/fonts/files', form);
};

/**
 * Fetches the delivered @font-face text: the active skin's current faces
 * print (the head of the compiled skin file), for the canvas to stamp
 * after a write.
 *
 * @return {Promise<{css: string}>}
 */
export const fetchFontStylesheet = () =>
  get('daedalus_stylish/skin/fonts/stylesheet');

/**
 * Fetches the active skin's delivered defaults.
 *
 * @return {Promise<{skin: ?string, defaults: object, css: string}>}
 *   The active published skin's machine name (null when none delivers),
 *   its defaults {scope: {relative key: entry}} as stored (the merge of
 *   both layers), and the skin stylesheet's current CSS text.
 */
export const fetchDefaults = () => get('daedalus_stylish/defaults');

// --- The skin's lifecycle: the card's read, choose, copy, revisions, revert. ---

/**
 * Fetches the skin card's read: what the active design system may wear.
 *
 * @return {Promise<{design_system: ?string, worn: ?string,
 *   choice: ?string, default: ?string, skins: Array<{machine_name: string,
 *   label: string, published: boolean, provider: ?string,
 *   site_layer: boolean, locked: boolean, refusal: ?object}>,
 *   sources: Array<{machine_name: string, label: string,
 *   design_system: ?string, design_system_label: ?string,
 *   provider: ?string}>}>}
 *   The active system, the skin it wears, the site's stored choice, the
 *   unit's default, every member skin with the facts the card's
 *   gestures key on (skin-card-model.js), and every skin on the site as
 *   what a copy may copy (served with no active system too).
 */
export const fetchSkins = () => get('daedalus_stylish/skins');

/**
 * Chooses the skin the active design system wears on this site.
 *
 * No body; the delta carries the material swap (the skin worn before to
 * removes, the skin worn now to upserts).
 *
 * @return {Promise<{chosen: string, design_system: string,
 *   materials: object}>}
 */
export const chooseSkin = (reference) =>
  post(`daedalus_stylish/skins/${encodeURIComponent(reference)}/choose`, {});

/**
 * Copies a skin as the site's own: a new skin with no provider carrying
 * the source's merged look, published and a member of the ACTIVE design
 * system whatever the source's membership. A site's own unit (no
 * package, no default_skin) takes its first copy as its default and
 * wears it at once (`worn` true: the delta carries the copy's materials);
 * otherwise nothing is worn by copying (an empty delta) and choosing is
 * the next gesture. 422 with no active system.
 *
 * @return {Promise<{copied: string, label: string, worn: boolean,
 *   materials: object}>}
 */
export const copySkin = (reference, label = null) =>
  post(
    `daedalus_stylish/skins/${encodeURIComponent(reference)}/copy`,
    label === null ? {} : { label },
  );

/**
 * Fetches a skin's revision history (the restore menu).
 *
 * @return {Promise<{machine_name: string, label: string, locked: boolean,
 *   revisions: Array<{revision_id: number, label: string, created: number,
 *   user: ?string, log_message: string, current: boolean}>}>}
 *   `locked` says what a restore restores: the site layer alone while
 *   the package layer is the disk's, else the revision whole.
 */
export const fetchSkinRevisions = (reference) =>
  get(`daedalus_stylish/skins/${encodeURIComponent(reference)}/revisions`);

/**
 * Restores an old revision: a NEW default revision carrying its content,
 * never a rollback, so a restore is itself one restore away from undo.
 *
 * @return {Promise<{restored: number, materials: object}>}
 */
export const restoreSkinRevision = (reference, revisionId) =>
  post(
    `daedalus_stylish/skins/${encodeURIComponent(reference)}/revisions/${revisionId}/restore`,
    {},
  );

/**
 * Reverts a skin's site layer: the skin wears what its module ships,
 * whole. No body. `reverted` is false when the site layer was already
 * empty (nothing saved).
 *
 * @return {Promise<{reverted: boolean, materials: object}>}
 */
export const revertSkin = (reference) =>
  post(`daedalus_stylish/skins/${encodeURIComponent(reference)}/revert`, {});
