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
 * - "Edit skin…" on a skin line (any `skin:{scope}` layer, the base:{tag}
 *   block included): the doorway the row menu already offers
 *   (provenance-model.js skinDoorway); the item carries the line's
 *   layer scope and the Island opens the editing session on the skin's
 *   document there (code/layers.js skinDocument through the Inspector's
 *   openDocument). A floor line never offers Unset: nothing in the map
 *   holds it, and the declared tier has no doorway (a declaration is
 *   code).
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
 *
 * The run menu (runMenuItems) is the run label's: the label naming a
 * layer run above its lines (line-marks.js) is a handle, and its
 * right-click opens the layer's doorways, the same doorways the widget
 * rows' provenance menu carries (provenance-model.js): a variant's
 * editing session, the skin's session at the run's scope. The own run
 * has none, and the
 * ancestor tier's own run has none either: the tier never prints on an
 * island (element-model.js ownIslandLines drops it because every rule
 * reaching the element prints as a RULE ISLAND of its own, whose header
 * is the seat), so a doorway to the rule here would be a second seat
 * for a run no island renders.
 */

import {
  ELEMENT_LAYER,
  TIER_DECLARED,
  TIER_SKIN,
  TIER_VARIANT,
  ancestorVariant,
  layerScope,
  layerTier,
} from './layers.js';
import { addressKey } from '../write-address.js';

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
 *   The stackLines() line under the click, or null (an unmatched,
 *   mid-edit declaration: no menu).
 *
 * @return {Array<{type: string}>}
 *   Menu items in render order: [{type: 'unset'}], [{type: 'lift'}], or
 *   [{type: 'lift'}, {type: 'edit-skin', scope}] (scope: the skin
 *   layer's declaring scope, the document the doorway opens); empty for
 *   no line.
 */
export const lineMenuItems = (line) => {
  if (!line || typeof line.property !== 'string') {
    return [];
  }
  if (line.layer === ELEMENT_LAYER) {
    return [{ type: 'unset' }];
  }
  if (layerTier(line.layer) === TIER_SKIN) {
    return [{ type: 'lift' }, { type: 'edit-skin', scope: layerScope(line.layer) }];
  }
  return [{ type: 'lift' }];
};

/**
 * A layer's carrier, off the island's stack layers.
 *
 * Every variant-tier layer record (style-stack.js referenceLayer, built
 * by mapLayers and the worn-ancestor layers alike) carries the address
 * whose entry wears the reference: the root with a null selector, or a
 * rule under it.
 *
 * @return {?{key: string, selector: ?string}}
 *   The carrier, or null when the layers hold no record for the run.
 */
const carrierOf = (layer, layers) =>
  layers.find((candidate) => candidate.id === layer)?.carrier ?? null;

/**
 * The rule a carrier names: a non-empty selector under its key.
 *
 * @return {?{key: string, selector: string}}
 *   The carrier rule, or null for a root carrier or no carrier.
 */
const carrierRule = (carrier) =>
  carrier && typeof carrier.selector === 'string' && carrier.selector !== ''
    ? { key: carrier.key, selector: carrier.selector }
    : null;

/**
 * The menu items for one layer run's label.
 *
 * A variant run opens the variant's document (the editing session
 * anchored at the wearer: the island's own key, or the carrier rule the
 * stack's layer record names when a rule wears it, because a value a
 * rule's wear delivers at a continued position is worn by the rule, and
 * a session anchored at the position itself would find no wearing to
 * mount over); a skin run opens the skin's session at its scope; a worn
 * ancestor run
 * opens the variant's session anchored at the carrier (a value a worn
 * variant delivers is edited at the carrier, never at the position it
 * lands at, the provenance row's reading); a declared run opens the
 * Materials place's Law tab at its declaring scope (the row menu's
 * declared doorway, a law document through the same openDocument); the
 * own run has no doorway, and neither has an ancestor rule's own run
 * (the rule's seat is its rule island's header; see the file docblock).
 *
 * @param {string} layer
 *   The run's layer id (layers.js grammar).
 * @param {Array<object>} [layers]
 *   The island's stack layers (style-stack.js), read for a variant or
 *   worn ancestor layer's carrier.
 *
 * @return {Array<{type: string}>}
 *   Menu items in render order: [{type: 'edit-variant', reference,
 *   anchor}] (anchor: the carrier rule's key, or null for the island's
 *   own wearer), [{type: 'edit-skin', scope}], [{type: 'declared',
 *   scope}] (scope: the declaring scope the Law tab opens at); empty
 *   for the own run and an ancestor rule's own run.
 */
export const runMenuItems = (layer, layers = []) => {
  if (typeof layer !== 'string' || layer === ELEMENT_LAYER) {
    return [];
  }
  const tier = layerTier(layer);
  if (tier === TIER_SKIN) {
    return [{ type: 'edit-skin', scope: layerScope(layer) }];
  }
  if (tier === TIER_DECLARED) {
    return [{ type: 'declared', scope: layerScope(layer) }];
  }
  if (tier === TIER_VARIANT) {
    // A root carrier is the island's own wearer, the wear key the
    // Inspector seats untold; only a rule carrier is an anchor to name.
    const rule = carrierRule(carrierOf(layer, layers));
    return [
      {
        type: 'edit-variant',
        reference: layerScope(layer),
        anchor: rule ? addressKey(rule) : null,
      },
    ];
  }
  // An ancestor rule's own run (and any id outside the grammar): no
  // doorway; a worn ancestor run is its variant's, at the carrier.
  const worn = ancestorVariant(layer);
  if (worn === null) {
    return [];
  }
  const carrier = carrierOf(layer, layers);
  return [
    {
      type: 'edit-variant',
      reference: worn,
      anchor: carrier ? addressKey(carrier) : null,
    },
  ];
};
