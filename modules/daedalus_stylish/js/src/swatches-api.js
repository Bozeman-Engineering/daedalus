/**
 * The swatch lifecycle HTTP client: the variant API's twin at value scope.
 *
 * A thin naming layer over the shared gesture transport
 * (gesture-transport.js) for the gestures that stay routes: the palette
 * card's rename, revisions/restore, revert and delete, and the per-swatch
 * management read (usage). Swatches are site-wide content, not per-user
 * tempstore state, so these calls ride their own authenticated session in
 * either host window. Failures reject with an Error carrying `payload`
 * (the blueprint {errors: [{path, code, message, hint}]} envelope), which
 * failureText() phrases.
 *
 * The WRITES that mint or rewrite a swatch's value are not here: "+ New
 * swatch", the builder's and the color field's "Save as swatch" and the
 * card's value editor submit `save_material` / `update_material` (kind
 * swatch) through the state source (material-gestures.js), the same ops
 * the AI submits. There is no list call either: the swatches every picker
 * and the palette face read are the materials store's (booted with the
 * page, followed through every response's `materials` delta). Every write
 * here answers with that delta and no echo payload: the caller hands the
 * whole response to the source (source.applyResponse) and reads the
 * written record from the delta (respondedMaterial) when it continues
 * with it. The reads (usage, revisions) are management facts, fetched per
 * gesture.
 */

import { get, post, send } from './gesture-transport.js';

/**
 * Fetches a swatch's management facts: the live reference count (the
 * prompts' loud number, the card's use line) and the shipped state against
 * the current disk copy (the revert affordance's gate) with its provider.
 *
 * @return {Promise<{machine_name: string, label: string, usages: number,
 *   provider: ?string, shipped_state: ?string}>}
 */
export const fetchSwatchUsage = (reference) =>
  get(`daedalus_stylish/swatches/${encodeURIComponent(reference)}/usage`);

/**
 * Renames a swatch (the palette card's label gesture).
 *
 * Identity (machine name, uuid) never changes, so every var(--swatch-*)
 * reference survives a rename.
 *
 * @return {Promise<{materials: object}>}
 */
export const renameSwatch = (reference, label) =>
  post(`daedalus_stylish/swatches/${encodeURIComponent(reference)}/rename`, { label });

/**
 * Fetches a swatch's revision history (the restore menu).
 *
 * @return {Promise<{machine_name: string, label: string, revisions:
 *   Array<{revision_id: number, label: string, created: number,
 *   user: ?string, log_message: string, current: boolean}>}>}
 */
export const fetchSwatchRevisions = (reference) =>
  get(`daedalus_stylish/swatches/${encodeURIComponent(reference)}/revisions`);

/**
 * Restores an old revision: a NEW default revision carrying its content,
 * never a rollback, so a restore is itself one restore away from undo.
 *
 * @return {Promise<{materials: object}>}
 */
export const restoreSwatchRevision = (reference, revisionId) =>
  post(
    `daedalus_stylish/swatches/${encodeURIComponent(reference)}/revisions/${revisionId}/restore`,
    {},
  );

/**
 * Reverts a shipped swatch to its current disk defaults.
 *
 * No body: the provider's swatches file is the payload.
 *
 * @return {Promise<{materials: object}>}
 */
export const revertSwatch = (reference) =>
  post(`daedalus_stylish/swatches/${encodeURIComponent(reference)}/revert`, {});

/**
 * Deletes a swatch — always safely.
 *
 * An unreferenced swatch deletes in this request ({deleted: true}). A
 * REFERENCED one responds 202 with {deleted: false, references,
 * batch_url}: the server staged the rewrite-then-delete Batch API job and
 * the CLIENT drives the returned core batch URL (driveBatch) — every
 * var(--swatch-*) becomes the swatch's literal value, then the entity
 * goes. A referenced swatch without the in-use permission grade rejects
 * 403 STYLE_SWATCH_IN_USE.
 *
 * Both answers carry the materials delta: the immediate delete's flushed
 * removal, or, for the batched path, the removal the staged batch is
 * committed to (applied by the caller AFTER driving the batch).
 *
 * @return {Promise<{deleted: boolean, references: number,
 *   batch_url?: string, materials: object}>}
 */
export const deleteSwatch = (reference) =>
  send('DELETE', `daedalus_stylish/swatches/${encodeURIComponent(reference)}`);
