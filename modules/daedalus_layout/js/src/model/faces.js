/**
 * @file
 * The faces: one grammar for everything the Add panel can place.
 *
 * The panel draws one list of placeables from two feeds. Blocks arrive
 * once with the page, as the faces AddPanelFaces answers
 * (`{id, kind, label, description, icon, groups, favorite}`, the
 * Container first, in the restrictions' order). Blueprints arrive live
 * through the materials store, whose records carry the same facts under
 * the delivery's names (`label`, `description`, a resolved `icon`,
 * `groups`, `favorite`, plus `origin` and `updatable`, which the row's
 * menu reads). This file projects both onto one face so one renderer
 * draws every row and the sets never ask which feed a face came from.
 *
 * `id` is the panel's item grammar as it stands: a block plugin id for a
 * block, `blueprint:ID` for a blueprint. It is the key the editor's marks
 * use too, so a mark on a blueprint and a mark on a block are read by one
 * rule (isFavorite).
 *
 * `kind` is where a face files under All: KIND_BASIC for the Container
 * and the block types (the server's word), KIND_BLUEPRINT for a
 * blueprint (the panel's word, since a record has no category), core's
 * block category for every other plugin.
 *
 * The effective favorite is the face's default (the site's fact on the
 * thing) with the editor's own mark over it: a mark of TRUE adds, a mark
 * of FALSE removes a default, and an unmarked face shows its default.
 * The marks never rewrite a face's `favorite`; the server states that
 * rule (AddPanelFaces) and this is where it is computed.
 *
 * `editable` is whether the actor may write the face's default for
 * everyone (the row menu's "Favorite for everyone"), learned and never
 * tried: a block's from the server's answer (the block type's update
 * access; the Container and a plain plugin have no face to write), a
 * blueprint's from its record's `updatable` (a content blueprint, never a
 * file-shipped one), which the component still reads under the "manage
 * blueprints" grant, as the blueprint's other gestures do.
 *
 * A row is keyed by set and id, never by a DOM id, because a face shows
 * in every set it belongs to and the same id is in the DOM as many times
 * as it has sets (rowKey).
 *
 * Pure: no DOM, no Preact, nothing read from the window.
 */

/**
 * The Container's block plugin id (SectionBlock::PLUGIN_ID).
 */
export const CONTAINER_ID = 'daedalus_layout_section';

/**
 * The kind of the site's own pieces: the Container and the block types
 * (AddPanelFaces::KIND_BASIC).
 */
export const KIND_BASIC = 'basic';

/**
 * The kind of a saved blueprint's face.
 */
export const KIND_BLUEPRINT = 'blueprint';

/**
 * The prefix of a blueprint's face id, the record's id after it.
 */
export const BLUEPRINT_PREFIX = 'blueprint:';

/**
 * A blueprint record's face id.
 *
 * @param {string} recordId
 *   The materials record's id.
 *
 * @return {string}
 */
export const blueprintFaceId = (recordId) => `${BLUEPRINT_PREFIX}${recordId}`;

/**
 * Whether a face id names a blueprint.
 */
export const isBlueprintId = (id) => String(id).startsWith(BLUEPRINT_PREFIX);

/**
 * The record id a blueprint face id names, or null for a block's id.
 */
export const blueprintRecordId = (id) =>
  isBlueprintId(id) ? String(id).slice(BLUEPRINT_PREFIX.length) : null;

/**
 * A block's face as the server answers it, normalized to the face shape.
 *
 * The server's shape is trusted; only the list-valued and nullable keys
 * are made safe to read without a guard.
 */
export const blockFace = (face) => ({
  id: String(face.id),
  kind: String(face.kind ?? ''),
  label: String(face.label ?? ''),
  description: String(face.description ?? ''),
  icon: face.icon ?? null,
  groups: Array.isArray(face.groups) ? face.groups.map(String) : [],
  favorite: Boolean(face.favorite),
  editable: Boolean(face.editable),
  blueprint: null,
});

/**
 * A blueprint record's face.
 *
 * The record rides along as `blueprint` for what only a blueprint row
 * needs (its menu reads `origin` and `updatable`, the drop reads the
 * record's own id).
 *
 * @param {object} record
 *   A materials record of kind `blueprint`, in the delivery's shape.
 *
 * @return {object}
 */
export const blueprintFace = (record) => ({
  id: blueprintFaceId(record.id),
  kind: KIND_BLUEPRINT,
  label: String(record.label ?? ''),
  description: String(record.description ?? ''),
  icon: record.icon ?? null,
  groups: Array.isArray(record.groups) ? record.groups.map(String) : [],
  favorite: Boolean(record.favorite),
  editable: Boolean(record.updatable),
  blueprint: record,
});

/**
 * Every face the panel holds: the blocks, then the blueprints.
 *
 * The order here is the feeds' own and means nothing to a set: every set
 * sorts its rows (order.js). A face id appears once; should the two
 * feeds ever name the same id, the first one wins, so a row is never in
 * the list twice.
 *
 * @param {Array<object>} blockFaces
 *   The server's faces.
 * @param {Array<object>} blueprintRecords
 *   The materials store's blueprint records.
 *
 * @return {Array<object>}
 */
export const faceList = (blockFaces, blueprintRecords) => {
  const seen = new Set();
  const faces = [];
  [
    ...(blockFaces ?? []).map(blockFace),
    ...(blueprintRecords ?? []).map(blueprintFace),
  ].forEach((face) => {
    if (!seen.has(face.id)) {
      seen.add(face.id);
      faces.push(face);
    }
  });
  return faces;
};

/**
 * The effective favorite: the editor's mark over the face's default.
 *
 * @param {object} face
 *   A face.
 * @param {?object} marks
 *   The editor's marks, id to boolean (AddPanelAnswer's `favorites`).
 *
 * @return {boolean}
 */
export const isFavorite = (face, marks) =>
  marks !== null &&
  marks !== undefined &&
  Object.prototype.hasOwnProperty.call(marks, face.id)
    ? Boolean(marks[face.id])
    : Boolean(face.favorite);

/**
 * The marks with one face's effective favorite flipped: the star's
 * gesture, and the row menu's "Add to" and "Remove from my favorites".
 *
 * The whole map is what the client posts, so the answer is the whole
 * map. A mark that merely restates the face's default is dropped rather
 * than written: an editor who stars a default and unstars it again is
 * back where the site put them, with no mark to carry.
 *
 * @param {object} face
 *   A face.
 * @param {?object} marks
 *   The editor's marks.
 *
 * @return {object}
 *   The marks to store and post.
 */
export const toggledMarks = (face, marks) => {
  const next = { ...(marks ?? {}) };
  const wanted = !isFavorite(face, marks);
  if (wanted === Boolean(face.favorite)) {
    delete next[face.id];
  } else {
    next[face.id] = wanted;
  }
  return next;
};

/**
 * The face ids of the blueprint records in `records` that `previousRecords`
 * did not hold: the creator's favorite rule.
 *
 * The materials change set is per request, so a blueprint record that is
 * new to this session's store was minted by this editor's own gesture (the
 * Save as verb or an AI turn in the editor's request alike land the same
 * delta), and the panel marks it as the creator's own favorite through
 * the one marks path (toggledMarks, saveFavorites). An update upserts an
 * id the store already held, and a page-scope save arrives as a remove,
 * so neither answers anything here. Only records of kind blueprint count:
 * a block face is a block, whatever list it rides in.
 *
 * Against an empty previous list every record is new, so the caller seeds
 * its baseline from the records it held at mount and never asks this of a
 * boot (a whole-set replacement from the page's envelope, which may carry
 * another editor's records): the component's guard, not this rule's.
 *
 * @param {Array<object>} previousRecords
 *   The blueprint records the caller held before the delivery.
 * @param {Array<object>} records
 *   The blueprint records the delivery carries.
 *
 * @return {Array<string>}
 *   The new records' face ids (blueprint:ID), in the delivery's order.
 */
export const newBlueprintIds = (previousRecords, records) => {
  const held = new Set(
    (previousRecords ?? [])
      .filter((record) => record.kind === KIND_BLUEPRINT)
      .map((record) => blueprintFaceId(record.id)),
  );
  return (records ?? [])
    .filter((record) => record.kind === KIND_BLUEPRINT)
    .map((record) => blueprintFaceId(record.id))
    .filter((id) => !held.has(id));
};

/**
 * A row's key: the set it sits in and the face it shows.
 *
 * @param {string} setId
 *   The set's id (sets.js).
 * @param {string} faceId
 *   The face's id.
 *
 * @return {string}
 */
export const rowKey = (setId, faceId) => `${setId}|${faceId}`;
