/**
 * The OWN layer a widget's patch expands and collapses against, read per
 * WRITE.
 *
 * A box family (padding, the three border families) is spelled one way
 * on one own layer (shorthand or longhands, never a mix), so the patch
 * that expands or collapses it (box-model.js sidesPatch / uniformCommit,
 * stroke-model.js strokeUniformPatch / strokeSidesPatch) must read the
 * layer it lands beside, and that layer follows the write: a write's SET
 * members land as a group where the model's address rule says
 * (model.landing: a width alone where the width reads, a whole stroke
 * where any of its parts is authored, another box than the family's
 * shorthand key on a two-box binding), a write of removals alone lands
 * each null on the box the retired value lives on (model.keys, where the
 * pipeline lands it). Asked per write, never once per render, for that
 * reason: a layer read at a render-time key is right only while every
 * write of the family lands where that key reads, which a two-box
 * binding breaks.
 *
 * The empty write is a caller's contract violation, never a layer: a
 * ring's blocked probe (the empty gesture) names its family's longhands
 * as the set and asks ownLayerAt at their landing, so a layer whose
 * shorthand the expansion cannot split still blocks the ring.
 */

const isPlainObject = (value) =>
  value !== null && typeof value === 'object' && !Array.isArray(value);

/**
 * The own values at the editing compound of one box, ORDER-PRESERVING.
 *
 * @param {?Object<string, object>} styleMap
 *   The style map (key → condition-keyed entry).
 * @param {?string} key
 *   The box's style-map key; null or an unknown key reads no layer.
 * @param {string} condition
 *   The editing compound (model.condition, where the writes land).
 *
 * @return {?Object<string, *>}
 *   The block at the compound, or null when the box carries none there.
 */
export const ownLayerAt = (styleMap, key, condition) => {
  const entry = key === null || key === undefined ? null : styleMap?.[key];
  return isPlainObject(entry) && isPlainObject(entry[condition])
    ? entry[condition]
    : null;
};

/**
 * The own layer ONE write expands and collapses against.
 *
 * @param {?Object<string, object>} styleMap
 *   The style map.
 * @param {{landing: Function, keys: Object<string, string>,
 *   condition: string}} model
 *   The control model (controls.js controlModels): its address rule for
 *   the write's set members, its per-property keys for a removal.
 * @param {Object<string, ?string>} write
 *   The write, property → value (null a removal); never empty.
 *
 * @return {?Object<string, *>}
 *   The block at the editing compound of the box the write lands on, or
 *   null when that box carries none there.
 */
export const writeLayer = (styleMap, model, write) => {
  const names = Object.keys(write ?? {});
  if (names.length === 0) {
    throw new Error(
      'writeLayer: an empty write has no layer; a probe names its set.',
    );
  }
  const set = names.filter((name) => write[name] !== null);
  return ownLayerAt(
    styleMap,
    set.length ? model.landing(set) : model.keys[names[0]],
    model.condition,
  );
};
