/**
 * The material gesture family on the client: save_material and
 * update_material over the state source's batch commit.
 *
 * Every UI verb that writes a token, an animation or a variant (the
 * palette's mint card and value editors, the gradient builder's and the
 * color field's "Save as token", the push bar's save and update
 * sessions) submits the SAME page-language op the AI submits
 * (daedalus_blueprint's MaterialGestureOperationHandler), through
 * `source.commitBatch()`: the
 * leader runs it on the op queue, the satellite runs it through the leader
 * (popout-leader.js relays the batch and settles the promise with the
 * leader's result). The op queue applies the response's `materials` delta
 * to the store before the promise resolves, and the leader relays that
 * delta on the materials wire, so both windows already read the written
 * record when the caller continues with it.
 *
 * The record comes back in the response's `materials.upsert`; there is no
 * per-gesture echo payload. A mint's machine name is derived server-side
 * from its label (a token's label IS its name, its id "{scale}/{name}",
 * and its scale may be the server's inference), so a save resolves the
 * record as the one of its kind the roster did not hold before the commit,
 * and an update by id. By novelty, not "the one record of the kind in the
 * delta": a skin write's delta carries EVERY material the active skin
 * holds (daedalus_stylish's skin hook touches each id the skin carried
 * before and carries now, upsert vs remove resolved against the fresh
 * roster), so a token, animation or font mint arrives beside its skin's
 * standing records.
 */

/**
 * The record a material write's response carries.
 *
 * @param {?object} payload
 *   A mutation response (or a gesture route's response body).
 * @param {string} kind
 *   The material kind ('token' | 'variant' | 'animation' | 'font' |
 *   'blueprint').
 * @param {?string} [id]
 *   The record id when the gesture named one (an update, a route on a
 *   known material); null for a mint, whose id the server derived.
 *
 * @return {?object}
 *   The upserted record, or null when the response carries none.
 */
export const respondedMaterial = (payload, kind, id = null) =>
  (payload?.materials?.upsert ?? []).find(
    (record) => record.kind === kind && (id === null || record.id === id),
  ) ?? null;

/**
 * The record a mint's response carries: the upserted record of the kind
 * that the roster did not hold before the commit.
 *
 * @param {?object} payload
 *   A mutation response.
 * @param {string} kind
 *   The material kind.
 * @param {{has: function(string, string): boolean}} before
 *   The materials roster as it stood before the commit (the source's
 *   `getMaterials()` answer, a snapshot whose identity changes with the
 *   records).
 *
 * @return {?object}
 *   The minted record, or null when the delta carries no record of the
 *   kind the roster lacked.
 */
export const mintedMaterial = (payload, kind, before) =>
  (payload?.materials?.upsert ?? []).find(
    (record) => record.kind === kind && !before.has(kind, record.id),
  ) ?? null;

const submit = (source, operation, label) => {
  const before =
    operation.op === 'save_material' ? source.getMaterials() : null;
  return source
    .commitBatch({ operations: [operation], label })
    .then((payload) => {
      const record =
        operation.op === 'save_material'
          ? mintedMaterial(payload, operation.kind, before)
          : respondedMaterial(payload, operation.kind, operation.id);
      if (!record) {
        // A settled write always answers its record: the change set is fed by
        // the entity hooks every material save runs. Loud, like assertDelta.
        throw new Error(
          `${operation.op} (${operation.kind}) settled without its record in the materials delta.`,
        );
      }
      return record;
    });
};

/**
 * Mints a material: `save_material {kind, label, ...}`.
 *
 * @param {object} source
 *   The state source (leader or satellite).
 * @param {object} gesture
 *   {kind, label} plus the kind's own keys (a token's value, with its
 *   group, the scale, description and offered; a variant's key, styles,
 *   group and owned_by). Keys whose value is
 *   undefined are dropped.
 * @param {string} label
 *   The history-entry label.
 *
 * @return {Promise<object>}
 *   The minted record; rejects with the op queue's Error (failureText
 *   reads it).
 */
export const saveMaterial = (source, gesture, label) =>
  submit(source, operation('save_material', gesture), label);

/**
 * Rewrites a material: `update_material {kind, id, ...}`.
 *
 * @param {object} source
 *   The state source (leader or satellite).
 * @param {object} gesture
 *   {kind, id} plus the keys to change.
 * @param {string} label
 *   The history-entry label.
 *
 * @return {Promise<object>}
 *   The written record; rejects with the op queue's Error.
 */
export const updateMaterial = (source, gesture, label) =>
  submit(source, operation('update_material', gesture), label);

const operation = (op, gesture) =>
  Object.fromEntries(
    Object.entries({ op, ...gesture }).filter(
      ([, value]) => value !== undefined,
    ),
  );
