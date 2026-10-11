/**
 * @file
 * Favorite for everyone: a face's site default, written where it lives.
 *
 * A favorite is a fact on the thing (a blueprint's `favorite` field, a
 * block type's `third_party_settings.daedalus_layout.favorite`), with
 * each editor's own marks over it (favorites.js). The row menu offers
 * "Favorite for everyone" only where there is a face and the actor may
 * write it, which the face says (`editable`, model/faces.js) and nothing
 * here tries: a blueprint's row writes through the library's details
 * route, the door Edit details… already commits through
 * (blueprint-gestures.js); a block type's row writes through
 * daedalus_layout's own route, gated by the bundle's admin permission
 * (Controller\BlockTypeFavorite); a plain plugin's row never shows the
 * item.
 *
 * Each write answers the face's new default under the panel's item
 * grammar, `{id, favorite}`. A blueprint's repaints through the materials
 * store (the response's delta); a block's is the component's to restate
 * in the faces it holds, since blocks arrive once with the page.
 */
import { KIND_BLUEPRINT } from './model/faces.js';
import {
  canSaveBlueprints,
  postJson,
  setBlueprintSiteFavorite,
} from './blueprint-gestures.js';

/**
 * The prefix of an inline block plugin id, the block type after it.
 */
const INLINE_BLOCK_PREFIX = 'inline_block:';

/**
 * The block type route's path (daedalus_layout.block_type_favorite).
 *
 * @param {string} bundle
 *   The block type's id.
 *
 * @return {string}
 */
export const blockTypeFavoritePath = (bundle) =>
  `daedalus-layout/block-type/${encodeURIComponent(bundle)}/favorite`;

/**
 * Whether the actor may write a face's favorite for everyone.
 *
 * The face's own flag, under the blueprint grant for a blueprint
 * (canSaveBlueprints, the Add panel's any-type chrome; the details route
 * enforces by the blueprint's type for real).
 *
 * @param {object} face
 *   A face (model/faces.js).
 *
 * @return {boolean}
 */
export const canEditFace = (face) =>
  face.kind === KIND_BLUEPRINT
    ? canSaveBlueprints() && face.editable
    : face.editable;

/**
 * Writes a block type's site default favorite.
 *
 * @param {string} bundle
 *   The block type's id.
 * @param {boolean} favorite
 *   The new site default.
 *
 * @return {Promise<{id: string, favorite: boolean}>}
 *   The face's new default.
 */
export const setBlockTypeSiteFavorite = (bundle, favorite) =>
  postJson(Drupal.url(blockTypeFavoritePath(bundle)), { favorite });

/**
 * Writes a face's site default favorite where it lives.
 *
 * @param {object} face
 *   A face the actor may edit (canEditFace).
 * @param {boolean} favorite
 *   The new site default.
 *
 * @return {Promise<{id: string, favorite: boolean}>}
 *   The face's new default; rejects with an Error carrying the server's
 *   refusal (blueprint-gestures.js failureMessage reads it).
 */
export const setSiteFavorite = (face, favorite) => {
  if (face.kind === KIND_BLUEPRINT) {
    return setBlueprintSiteFavorite(face.blueprint.id, favorite).then(() => ({
      id: face.id,
      favorite,
    }));
  }
  if (face.id.startsWith(INLINE_BLOCK_PREFIX)) {
    return setBlockTypeSiteFavorite(
      face.id.slice(INLINE_BLOCK_PREFIX.length),
      favorite,
    );
  }
  // Unreachable through the menu, which gates on the face's flag; said
  // loudly rather than answered with a silent no-op.
  return Promise.reject(
    new Error(Drupal.t('This block has no face to mark for everyone.')),
  );
};
