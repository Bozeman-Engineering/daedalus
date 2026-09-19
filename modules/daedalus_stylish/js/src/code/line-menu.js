/**
 * The right-click line menu model (pure; Island renders and executes it).
 *
 * The code view's line gestures, offered where clicking beats typing:
 * - "Move to this element" on a variant-supplied or floor line, the lift:
 *   the value becomes an own override (references stay worn, the floor
 *   stays where it is), the same landing typing in that line produces. One
 *   rule, two gestures.
 * - "Unset" on an element-own line — the override lifts and whatever the
 *   layers deliver beneath resurfaces (deleting the line, as a click).
 * - "Edit skin…" on a skin line: the doorway the row menu already offers
 *   (provenance-model.js skinDoorway); the Island opens guide.skinEditUrl.
 *   A floor line never offers Unset: nothing in the map holds it, and the
 *   declared tier has no doorway (a declaration is code).
 *
 * Variant content changes ONLY through the pending lane: edit the
 * variant's document in the editing session, or pre-stage the element's
 * diff with "Update from this element" — both commit from the
 * Push/Discard bar. The
 * old "Add to <variant>" targeted push retired with the rest of the
 * bespoke push doorways: it was an instant commit beside the pending
 * cadence, and dismissing the pre-staged diff down to one line covers its
 * job. There is still no "remove from variant" here, and a variant line
 * offers no unset (deleting it honestly no-ops; only detach, remove, or a
 * pushed unset removes a variant's value).
 */

import { ELEMENT_LAYER, SKIN_LAYER } from './layers.js';

/**
 * The menu items for one baseline line.
 *
 * Element-own lines offer Unset; every other layer's line (a variant's,
 * the floor's) offers the lift, lost or winning alike (lifting a shadowed
 * value re-points the own override at it); a skin line adds the skin
 * doorway.
 *
 * @param {?{property: string, value: string, layer: string,
 *   lost: boolean}} line
 *   The islandLines() line under the click, or null (an unmatched,
 *   mid-edit declaration: no menu).
 *
 * @return {Array<{type: string}>}
 *   Menu items in render order: [{type: 'unset'}], [{type: 'lift'}], or
 *   [{type: 'lift'}, {type: 'edit-skin'}]; empty for no line.
 */
export const lineMenuItems = (line) => {
  if (!line || typeof line.property !== 'string') {
    return [];
  }
  if (line.layer === ELEMENT_LAYER) {
    return [{ type: 'unset' }];
  }
  if (line.layer === SKIN_LAYER) {
    return [{ type: 'lift' }, { type: 'edit-skin' }];
  }
  return [{ type: 'lift' }];
};
