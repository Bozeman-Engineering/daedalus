import { entryVariants } from '../variant-scope.js';

/**
 * The layer model (pure; the identity surfaces render it).
 *
 * The code view stays ONE plain document (rules in place under their real
 * structure) and layer membership is a color dimension over it, never a
 * reorganization. This module owns the layer vocabulary: layer identities
 * (the defaults floor's two tiers are the bottom layers, each worn variant
 * is a layer above them, the element's own styles are the top layer), the
 * stable per-layer hue the IDENTITY surfaces share (wearing-row
 * dots, line tints and winner chips, library dots: "where does this come
 * from"; focus-duty chrome like the Outline Variant paint wears the
 * --daedalus-focus treatment instead), and the coverage projection
 * (paintCoverage) behind the canvas outline and the page elements panel's
 * row marks. Everything is data-in/data-out for node-test backpressure.
 */

/**
 * The element's own layer id: the top of every stack.
 */
export const ELEMENT_LAYER = 'element';

/**
 * A worn variant's layer id, namespaced so no machine name collides with
 * the element layer.
 */
export const variantLayerId = (name) => `variant:${name}`;

/**
 * The defaults floor's two layers: the active skin's defaults and, beneath
 * them, the declaration's own defaults.
 *
 * Both are display layers, never write targets: neither lives in the map
 * (skinDefaultEntry / declaredDefaultEntry read them from the guide and
 * the rendered tag), so no island op ever addresses them; a floor line
 * edits as an own override and vanishes as a no-op. They sit at the bottom
 * of every stack, so a floor line never wins over a map line (declared
 * loses to skin, skin to every worn variant and to the element's own
 * layer). They print on the SELECTED element's islands only, in every
 * code scope (page-view.js; the tree's other rows print the map alone),
 * in the floor hue, with the lift and, for a skin line, the Edit skin…
 * doorway in the line menu, never Unset (line-menu.js). Canvas coverage
 * has no footprint for them (paintCoverage reads
 * map refs and own styles; the floor's coverage story is still an open
 * design call).
 */
export const SKIN_LAYER = 'skin';
export const DECLARED_LAYER = 'declared';

/**
 * The defaults floor's stable color, both tiers.
 *
 * The one hue every identity surface uses for a value the floor supplies:
 * the widget rows' layer dots (rowOrigin's skin and declared layers), the
 * box-model diagram, and the code view's floor lines and winner chips
 * (layerColor for SKIN_LAYER and DECLARED_LAYER). A fixed teal, kept well
 * away from the element blue's band.
 */
export const SKIN_COLOR = 'hsl(172 66% 50%)';

/**
 * The layer a line belongs to (islandLines() line shapes carry it).
 */
export const lineLayerId = (line) => line.layer;

/**
 * The layer a lost line's winner names ({layer, variant}).
 */
export const winnerLayerId = (winner) => winner.layer;

/**
 * A layer's stable hue.
 *
 * Stable per layer instance across renders, sessions, and surfaces: the
 * element layer is a fixed blue, the floor's two layers share the fixed
 * SKIN_COLOR teal, and a variant layer's hue derives from its id (djb2
 * hash → hue), so the same variant is the same color in every scope, chip,
 * line, and dot without any registry to keep. Hues landing on the element
 * blue's band shift deterministically away so the top layer stays visually
 * reserved. Two variants may still collide (accepted; the chip labels
 * disambiguate).
 *
 * @param {string} id
 *   A layer id (ELEMENT_LAYER, SKIN_LAYER, DECLARED_LAYER, or
 *   variantLayerId()).
 *
 * @return {string}
 *   A CSS color.
 */
export const layerColor = (id) => {
  if (id === ELEMENT_LAYER) {
    return 'hsl(213 90% 66%)';
  }
  if (id === SKIN_LAYER || id === DECLARED_LAYER) {
    return SKIN_COLOR;
  }
  let hash = 5381;
  const name = String(id);
  for (let i = 0; i < name.length; i += 1) {
    // djb2: the shift and the unsigned coercion are the hash.
    // eslint-disable-next-line no-bitwise
    hash = ((hash << 5) + hash + name.charCodeAt(i)) >>> 0;
  }
  let hue = hash % 360;
  if (Math.abs(hue - 213) < 24) {
    hue = (hue + 48) % 360;
  }
  return `hsl(${hue} 70% 60%)`;
};

/**
 * Whether a style entry carries declarations of its own (any condition
 * block with at least one property — the reserved `variants` list is not
 * a declaration). The element layer's coverage read: a pure-reference
 * entry wears layers but adds nothing of its own.
 */
const entryHasOwnStyles = (entry) =>
  Object.entries(entry ?? {}).some(
    ([name, block]) =>
      name !== 'variants' &&
      block !== null &&
      typeof block === 'object' &&
      !Array.isArray(block) &&
      Object.keys(block).length > 0,
  );

/**
 * The paint-canvas projection: which style targets each layer covers.
 *
 * The layer stack projected onto the page, driven entirely from client-side
 * map refs, nothing round-trips. One walk over the WHOLE style map (coverage
 * is a page question even when the inspector's selection is one element): a
 * variant layer covers every map entry wearing a reference to it (root or
 * distributed fragment — wearing is wearing; a reference is the variant's
 * record id, matched verbatim), and the element layer covers every entry
 * carrying declarations of its own. A part entry (`base::.cls`) is its own target
 * — its canvas element differs from its base's — so the map key splits
 * into the resolveTargets() pair.
 *
 * @param {?object} map
 *   The root entity's style map (selectable key → style entry).
 * @param {Array<{id: string, variant: ?string}>} layers
 *   The layers to paint, in stacking order ({id, variant} at minimum;
 *   the element layer by its id, a variant layer by its reference).
 *
 * @return {Array<{key: string, sub: ?string, layers: string[]}>}
 *   One item per covered style target in map order, with the covering
 *   layer ids in the given layer order. Uncovered targets are absent.
 */
export const paintCoverage = (map, layers) => {
  const list = (Array.isArray(layers) ? layers : []).filter(
    (layer) => typeof layer?.id === 'string',
  );
  if (!list.length) {
    return [];
  }
  const targets = [];
  Object.entries(map ?? {}).forEach(([mapKey, entry]) => {
    const worn = new Set(entryVariants(entry));
    const own = entryHasOwnStyles(entry);
    const covering = list
      .filter((layer) =>
        layer.id === ELEMENT_LAYER ? own : worn.has(layer.variant),
      )
      .map((layer) => layer.id);
    if (!covering.length) {
      return;
    }
    const split = mapKey.indexOf('::');
    targets.push({
      key: split === -1 ? mapKey : mapKey.slice(0, split),
      sub: split === -1 ? null : mapKey.slice(split + 2),
      layers: covering,
    });
  });
  return targets;
};
