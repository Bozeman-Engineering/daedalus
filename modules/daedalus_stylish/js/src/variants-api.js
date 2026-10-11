/**
 * The variant lifecycle HTTP client: usage, copy, revert, rename,
 * revisions/restore, delete, release/transfer.
 *
 * A thin naming layer over the shared gesture transport
 * (gesture-transport.js) for the gestures that stay routes: variants are
 * site-wide content, not per-user tempstore state, so these calls
 * ride their own authenticated session, never the leader's op queue. The
 * WRITES that mint or push a variant are not here: the push bar's save
 * and update sessions submit `save_material` / `update_material` (kind
 * variant) through the state source (material-gestures.js), the same ops
 * the AI submits, carrying the client's own capture (`key` + `styles`,
 * the walk that drives the live preview); only the re-attach after a save
 * (apply_variant) follows as its own batch. There is no list call: the
 * published variants are materials, on the store from the page load, and
 * every write here answers with the `materials` delta the caller hands to
 * `source.applyResponse()` so both windows learn the outcome; the written
 * record is read from that delta (respondedMaterial), never from an echo
 * payload. Failures reject with an Error carrying `payload` (the blueprint
 * {errors: [{path, code, message, hint}]} envelope), which failureText()
 * phrases.
 */

import { get, post, send } from './gesture-transport.js';

/**
 * Fetches a variant's management facts: the live wearer count (the push
 * and delete gestures' loud number), the shipped state against the
 * current disk copy (the revert affordance's gate) and the shipping
 * module. Per open, never a list fact: a roster record says where a
 * variant came from, not what a module's disk copy says today.
 *
 * @return {Promise<{machine_name: string, label: string, usages: number,
 *   provider: ?string, shipped_state: ?string}>}
 */
export const fetchUsage = (reference) =>
  get(`daedalus_stylish/variants/${encodeURIComponent(reference)}/usage`);

/**
 * Copies a variant as the user's own site variant: the distro lock's fork
 * gesture (shipped variants are read-only; the copy is editable).
 *
 * @param {string} reference
 *   The source variant's machine name.
 * @param {?string} label
 *   The copy's label; null derives "<source label> (copy)" server-side.
 *
 * @return {Promise<{materials: object}>}
 */
export const copyVariant = (reference, label = null) =>
  post(`daedalus_stylish/variants/${encodeURIComponent(reference)}/copy`, { label });

/**
 * Reverts a shipped variant to its current disk defaults.
 *
 * No body: the provider's variants file is the payload. Revisioned like
 * a push, workspace-staged on workspace sites.
 *
 * @return {Promise<{usages: number, materials: object}>}
 */
export const revertVariant = (reference) =>
  post(`daedalus_stylish/variants/${encodeURIComponent(reference)}/revert`, {});

/**
 * Updates a variant's details (the lifecycle card's face gesture): the
 * label, the description and/or the AI opt-out (offered), in one
 * revisioned save.
 *
 * Identity (machine name, uuid) never changes, so every style map
 * reference survives a relabel. An empty description clears it.
 *
 * @param {string} reference
 *   The variant's machine name or uuid.
 * @param {{label?: string, description?: string, offered?: boolean}} details
 *   The fields to change; at least one.
 *
 * @return {Promise<{materials: object}>}
 */
export const updateVariantDetails = (reference, details) =>
  post(`daedalus_stylish/variants/${encodeURIComponent(reference)}/details`, details);

/**
 * Fetches a variant's revision history (the restore menu).
 *
 * @return {Promise<{machine_name: string, label: string, revisions:
 *   Array<{revision_id: number, label: string, created: number,
 *   user: ?string, log_message: string, current: boolean}>}>}
 */
export const fetchRevisions = (reference) =>
  get(`daedalus_stylish/variants/${encodeURIComponent(reference)}/revisions`);

/**
 * Restores an old revision: a NEW default revision carrying its content,
 * never a rollback, so a restore is itself one restore away from undo.
 *
 * @return {Promise<{usages: number, materials: object}>}
 */
export const restoreRevision = (reference, revisionId) =>
  post(
    `daedalus_stylish/variants/${encodeURIComponent(reference)}/revisions/${revisionId}/restore`,
    {},
  );

/**
 * Deletes a variant, always safely.
 *
 * An unworn variant deletes in this request ({deleted: true}). A WORN one
 * responds 202 with {deleted: false, wearers, batch_url}: the server
 * staged the detach-then-delete Batch API job and the CLIENT drives the
 * returned core batch URL (driveBatch) — every wearer keeps the variant's
 * delivered look as its own styles, then the entity goes. A worn variant
 * without the in-use permission grade rejects 403 STYLE_VARIANT_IN_USE.
 *
 * @return {Promise<{deleted: boolean, wearers: number,
 *   batch_url?: string}>}
 */
export const deleteVariant = (reference) =>
  send('DELETE', `daedalus_stylish/variants/${encodeURIComponent(reference)}`);

/**
 * Releases a blueprint-owned variant back to general purpose.
 *
 * This and transferVariant ride daedalus_blueprint_library's ownership
 * routes, not daedalus_stylish's own: ownership is the blueprint side's vocabulary
 * (the fields it provides on daedalus_variant), enforced by the
 * library's own ownership permission. The calls are never blind: the library card
 * offers the gestures only under the variant gates' `canOwn` (the grant,
 * present only with the blueprint module and its routes). Shipped variants
 * refuse server-side (the disk lane re-adopts at the next import); unowned
 * variants refuse (nothing to release).
 *
 * @return {Promise<{variant: {machine_name: string, uuid: string,
 *   blueprint: ?string}}>}
 */
export const releaseVariant = (reference) =>
  post(
    `daedalus-blueprint/blueprint-variant/${encodeURIComponent(reference)}/release`,
    {},
  );

/**
 * Transfers a variant's ownership to a content blueprint: one atomic
 * gesture, release + adopt with no intermediate unowned state. On an
 * UNOWNED variant this is adopt-later: the blueprint takes the variant
 * into its curated switchable looks.
 *
 * @param {string} reference
 *   The variant's machine name.
 * @param {string} blueprint
 *   The content blueprint taking ownership (its id); file-shipped targets
 *   refuse server-side.
 *
 * @return {Promise<{variant: {machine_name: string, uuid: string,
 *   blueprint: ?string}}>}
 */
export const transferVariant = (reference, blueprint) =>
  post(
    `daedalus-blueprint/blueprint-variant/${encodeURIComponent(reference)}/transfer`,
    { blueprint },
  );
