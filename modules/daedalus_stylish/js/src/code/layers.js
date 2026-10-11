import { entryVariants } from '../variant-scope.js';

/**
 * The layer model (pure; the identity surfaces render it).
 *
 * The code view stays ONE plain document (rules in place under their real
 * structure) and layer membership is a lens over it, never a
 * reorganization: a hue per line, a run label naming each layer run once
 * above its lines (line-marks.js; the lines keep their cascade order).
 * This module owns the layer vocabulary: layer identities
 * (the defaults floor's layers, one per declaring scope of each tier, are
 * the bottom layers, each worn variant is a layer above them, the
 * element's own styles are the top layer; style-stack.js builds the
 * stack from this grammar), the
 * stable per-layer hue the IDENTITY surfaces share (wearing-row
 * dots, run labels and winner chips, library dots: "where does this come
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
 * The tier names the id grammar prefixes with: `declared:{scope}`,
 * `skin:{scope}`, `variant:{reference}`, and the bare `element`.
 */
export const TIER_DECLARED = 'declared';
export const TIER_SKIN = 'skin';
export const TIER_ANCESTOR = 'ancestor';
export const TIER_VARIANT = 'variant';

/**
 * A worn variant's layer id, namespaced so no machine name collides with
 * the element layer.
 */
export const variantLayerId = (name) => `${TIER_VARIANT}:${name}`;

/**
 * A defaults-floor layer id: the declaration's own defaults at one
 * declaring scope (`declared:kind:block`, `declared:block_type:text`).
 *
 * The floor's layers are display layers outside a skin session, never
 * write targets: none lives in the map (style-stack.js reads them from
 * the guide payload), so no island op ever addresses one; a floor line
 * edits as an own override and vanishes as a no-op. They sit at the
 * bottom of every stack, one layer per declaring scope in delivery rank
 * (the family kind's block beneath the refinement's), so a floor line
 * never wins over a map line: a declared layer loses to every skin
 * layer, a skin layer to every worn variant and to the element's own
 * layer. They print on the SELECTED element's islands only, in every
 * code scope (page-view.js; the tree's other rows print the map alone),
 * in the floor hue, with the lift and, for a skin line, the Edit skin…
 * doorway in the line menu, never Unset (line-menu.js). A skin layer
 * becomes the editable layer inside the skin session on its scope (the
 * doorway's session: style-stack.js overlays the staged document on it,
 * and Push commits update_default). Canvas coverage has no footprint
 * for them (paintCoverage reads map refs and own styles; the floor's
 * coverage story is still an open design call).
 */
export const declaredLayerId = (scope) => `${TIER_DECLARED}:${scope}`;

/**
 * An active-skin defaults layer id at one declaring scope
 * (`skin:kind:block`, `skin:block_type:text`, and `skin:base:p` for the
 * skin's base:{tag} block, present only when the rendered tag is known).
 */
export const skinLayerId = (scope) => `${TIER_SKIN}:${scope}`;

/**
 * A skin document's reference: the active skin's defaults at one
 * declaring scope, as an editing session edits them.
 *
 * The session machinery speaks documents by reference (pending.js
 * sessions, the synced `editing` field, the doorways' openDocument): a
 * variant's reference is its record id, and the skin's document at a
 * scope is spelled as that scope's skin LAYER id (`skin:base:h2`,
 * `skin:block_type:text`, `skin:kind:container`), one string that both
 * names the document and is the layer the stack overlays it on. No
 * variant reference carries a colon, so the grammar tells the two apart
 * (isSkinDocument), and every identity surface (the dot, the rail hue)
 * reads the document's layer through documentLayerId.
 */
export const skinDocument = (scope) => skinLayerId(scope);

/**
 * Whether a document reference names the skin's document at a scope.
 */
export const isSkinDocument = (reference) =>
  typeof reference === 'string' &&
  layerTier(reference) === TIER_SKIN &&
  (layerScope(reference) ?? '') !== '';

/**
 * The scope a skin document reference names, null for a variant's.
 */
export const skinDocumentScope = (reference) =>
  isSkinDocument(reference) ? layerScope(reference) : null;

/**
 * A document's layer id: a skin document's own spelling, a variant
 * reference's variant layer.
 */
export const documentLayerId = (reference) =>
  isSkinDocument(reference) ? reference : variantLayerId(reference);

/**
 * A law document's reference: the declaration at one declaring scope,
 * as the Materials place's Law tab shows it, optionally at one property.
 *
 * The declared tier's doorway rides the one openDocument road the skin
 * and variant doorways ride (provenance-model.js declaredDoorway,
 * use-row-menu.js, the code view's run menu), so its target is spelled
 * the way theirs are: the declared LAYER id (`declared:block_type:text`,
 * `declared:kind:container`), with a `#property` fragment naming the
 * rows the tab reveals (`declared:block_type:text#display`). A law
 * document is never a session: the Inspector's openDocument routes it
 * to the tab before any session logic (lawDocumentFocus), so no stack
 * or session ever sees the fragment. No scope or property carries `#`.
 */
export const lawDocument = (scope, property = null) =>
  `${declaredLayerId(scope)}${property ? `#${property}` : ''}`;

/**
 * Whether a document reference names the law at a scope.
 */
export const isLawDocument = (reference) =>
  typeof reference === 'string' &&
  layerTier(reference) === TIER_DECLARED &&
  (layerScope(reference) ?? '') !== '';

/**
 * The focus a law document reference names, null for any other document.
 *
 * @return {?{scope: string, property: ?string}}
 */
export const lawDocumentFocus = (reference) => {
  if (!isLawDocument(reference)) {
    return null;
  }
  const scope = layerScope(reference);
  const hash = scope.indexOf('#');
  return hash === -1
    ? { scope, property: null }
    : { scope: scope.slice(0, hash), property: scope.slice(hash + 1) };
};

/**
 * An ancestor-rule layer id: another element's selector entry reaching
 * this key (`ancestor:container:{uuid}::> pb-text`), the rule's own key
 * after the tier prefix (ancestorRule() reads it back).
 *
 * Like the floor's, an ancestor layer is a display layer in the
 * element's stack, never a write target there: its values live at the
 * ancestor's key, which the widget row's doorway and the code view's
 * rule header seat as the styling target (the write address). It sits
 * above the floor and beneath the worn references (style-stack.js:
 * delivery's own order). The widget rows read it as a layer dot; the
 * code view prints the rule it names as a RULE ISLAND of the rule's
 * own key in the selected element's body (element-model.js
 * ancestorIslands, DevTools' inherited rules), where the rule's entry
 * is the editable layer, and drops the tier's lines from the element's
 * own islands (ownIslandLines); it keeps naming winners there (a floor
 * line a rule beats). It has no canvas coverage of its own (the rule's
 * coverage is painted at its owner).
 *
 * A rule position is a GROUP of ancestor layers (style-stack.js
 * ancestorLayers): the variants the rule wears, one layer each in
 * delivery order, then the rule's own entry. This id names the own
 * layer, which reads in the floor hue; ancestorWearLayerId() names a
 * worn one, which reads in its variant's hue.
 */
export const ancestorLayerId = (owner, selector) =>
  `${TIER_ANCESTOR}:${owner}::${selector}`;

/**
 * A worn ancestor layer id: a variant a rule wears, read at the position
 * its fragment reaches this key through
 * (`ancestor:container:{uuid}::> pb-text|button`). The own id with the
 * variant after a `|`, a character neither the selector grammar
 * (relative-selector.js) nor a machine name admits, so the tail after
 * the LAST `|` is the variant and ancestorRule() reads the position back
 * from either spelling.
 */
export const ancestorWearLayerId = (owner, position, variant) =>
  `${ancestorLayerId(owner, position)}|${variant}`;

/**
 * The variant a worn ancestor layer id carries: the reference after the
 * `|`, or null for an own ancestor layer and for any other id.
 *
 * The one predicate the identity surfaces read "an ancestor layer
 * carrying a variant" by: its hue, its sentences and its doorways are
 * the variant's, while its tier keeps every "this element wears" reader
 * (the Advanced roster, the candidate resolver, the AI door) honest by
 * construction.
 */
export const ancestorVariant = (id) => {
  if (layerTier(id) !== TIER_ANCESTOR) {
    return null;
  }
  const scope = layerScope(id);
  const bar = scope.lastIndexOf('|');
  return bar === -1 ? null : scope.slice(bar + 1);
};

/**
 * The rule an ancestor layer id names: {key, selector} for the own and
 * the worn spelling alike (a worn layer's rule is the POSITION its value
 * reaches this key through; the carrier that wears the variant is the
 * layer's own `carrier` field), or null for any other id.
 */
export const ancestorRule = (id) => {
  if (layerTier(id) !== TIER_ANCESTOR) {
    return null;
  }
  const scope = layerScope(id);
  const bar = scope.lastIndexOf('|');
  const rule = bar === -1 ? scope : scope.slice(0, bar);
  const pos = rule.indexOf('::');
  return pos === -1
    ? null
    : { key: rule.slice(0, pos), selector: rule.slice(pos + 2) };
};

/**
 * Whether a layer id names an ancestor-rule layer.
 */
export const isAncestorLayer = (id) => layerTier(id) === TIER_ANCESTOR;

/**
 * A layer id's tier: the prefix before the first colon, or the whole id
 * for the unprefixed element layer.
 *
 * @param {string} id
 *   A layer id.
 *
 * @return {string}
 *   'declared', 'skin', 'variant' or 'element' (an unknown id answers
 *   its own prefix).
 */
export const layerTier = (id) => {
  const name = String(id);
  const pos = name.indexOf(':');
  return pos === -1 ? name : name.slice(0, pos);
};

/**
 * A layer id's address after its tier prefix: a floor layer's declaring
 * scope (`kind:block`, `base:p`), a variant layer's reference; null for
 * the element layer.
 *
 * @param {string} id
 *   A layer id.
 *
 * @return {?string}
 */
export const layerScope = (id) => {
  const name = String(id);
  const pos = name.indexOf(':');
  return pos === -1 ? null : name.slice(pos + 1);
};

/**
 * Whether a layer id names a defaults-floor layer (either tier).
 */
export const isFloorLayer = (id) => {
  const tier = layerTier(id);
  return tier === TIER_SKIN || tier === TIER_DECLARED;
};

/**
 * The defaults floor's stable color, both tiers.
 *
 * The one hue every identity surface uses for a value this element did
 * not author and no variant supplies: the floor's two tiers and an
 * ancestor rule's own entry reaching it (the widget rows' layer dots,
 * the box-model diagram, the code view's floor and ancestor lines and
 * winner chips: layerColor for every `skin:*`, `declared:*` and own
 * `ancestor:*` id; a worn ancestor layer wears its variant's hue
 * instead). A fixed teal, kept well away from the element blue's band.
 */
export const SKIN_COLOR = 'hsl(172 66% 50%)';

/**
 * The layer a line belongs to (the code view's line shapes carry it).
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
 * element layer is a fixed blue, every floor layer (`skin:*` and
 * `declared:*`, read by tier prefix) and every own ancestor layer
 * (`ancestor:{owner}::{position}`) shares the fixed SKIN_COLOR teal, and
 * a variant layer's hue derives from its id (djb2 hash → hue), so the
 * same variant is the same color in every scope, chip, line, and dot
 * without any registry to keep. A worn ancestor layer (a variant a rule
 * wears, reaching this key: ancestorWearLayerId) is that variant's hue,
 * the same hash an own wearing of it gets, so one variant is one color
 * whether the element or a rule above it wears it. Hues landing on the
 * element blue's band shift deterministically away so the top layer
 * stays visually reserved. Two variants may still collide (accepted;
 * the chip labels disambiguate).
 *
 * @param {string} id
 *   A layer id (ELEMENT_LAYER, declaredLayerId(), skinLayerId(),
 *   ancestorLayerId(), ancestorWearLayerId() or variantLayerId()).
 *
 * @return {string}
 *   A CSS color.
 */
export const layerColor = (id) => {
  if (id === ELEMENT_LAYER) {
    return 'hsl(213 90% 66%)';
  }
  const worn = ancestorVariant(id);
  if (worn !== null) {
    return layerColor(variantLayerId(worn));
  }
  if (isFloorLayer(id) || isAncestorLayer(id)) {
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
 * carrying declarations of its own. A selector entry (`base::.cls`,
 * `base::> *`) is its own target — its canvas elements differ from its
 * base's — so the map key splits into the resolveTargets() pair.
 *
 * @param {?object} map
 *   The root entity's style map (selectable key → style entry).
 * @param {Array<{id: string, variant: ?string}>} layers
 *   The layers to paint, in stacking order ({id, variant} at minimum;
 *   the element layer by its id, a variant layer by its reference).
 *
 * @return {Array<{key: string, selector: ?string, layers: string[]}>}
 *   One item per covered style target in map order (the map's canonical
 *   order is the document's, styleMapOf: node-less keys first, then the
 *   nodes in document order), with the covering layer ids in the given
 *   layer order. Uncovered targets are absent.
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
      selector: split === -1 ? null : mapKey.slice(split + 2),
      layers: covering,
    });
  });
  return targets;
};
