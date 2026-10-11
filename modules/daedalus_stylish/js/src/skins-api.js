/**
 * The skin HTTP client: the skin card's lifecycle and the session's read.
 *
 * A thin naming layer over the shared gesture transport
 * (gesture-transport.js) for the skin's route lane: the card's read
 * (which skins the active design system may wear), choose, copy as my
 * skin, the revision history and restore, and the site layer's revert,
 * each an administer gesture with no page batch to ride. Every write
 * answers with the materials delta and no echo payload: the caller hands
 * the whole response to the source (source.applyResponse) so both
 * windows' stores learn a swapped or restored token set, then refreshes
 * the skin's delivery (source.refreshDefaults) since the worn defaults
 * changed with it.
 *
 * The WRITES that change the active skin's tokens and defaults are not
 * here: the token card submits `save_material` / `update_material` (kind
 * token) and the skin session's Push submits `update_default` through
 * the state source, the ops the AI submits. What is here beside the
 * lifecycle is the delivery read that follows a push: the active skin's
 * defaults as the panel reads them (the guide payload's skinDefaults
 * shape) beside the CSS the page should be showing for them
 * (DefaultsStylesheet::css(): the compiled file's text, or the
 * workspace's staged print inside one), so a push lands on the panel and
 * the canvas without a reload (leader-source.js refreshDefaults).
 */

import { get, post } from './gesture-transport.js';

/**
 * Fetches the active skin's delivered defaults.
 *
 * @return {Promise<{skin: ?string, defaults: object, css: string}>}
 *   The active published skin's machine name (null when none delivers),
 *   its defaults {scope: {relative key: entry}} as stored (the merge of
 *   both layers), and the defaults stylesheet's current CSS text.
 */
export const fetchDefaults = () => get('daedalus_stylish/defaults');

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
 * No body; the delta carries the token swap (the skin worn before to
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
 * wears it at once (`worn` true: the delta carries the copy's tokens);
 * otherwise nothing is worn by copying (an empty delta) and choosing is
 * the next gesture. 422 with no active system.
 *
 * @return {Promise<{copied: string, label: string, worn: boolean,
 *   materials: object}>}
 *   The copy's machine name and label, and whether the site wears it.
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
