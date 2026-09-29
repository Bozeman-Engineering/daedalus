import { BOX_FAMILIES, SIDES, familyLonghand } from '../../js/src/widgets/lib/box-model.js';

/**
 * A style stack built by hand for the per-side reads (box-model.js
 * sideProvenance / uniformState, stroke-model.js strokeSides): the
 * shape buildStack() returns (style-stack.js), one layer per tier the
 * caller names, bottom to top, the element's own layer always last and
 * editable. Each layer's styles is a condition-keyed entry; a bare
 * block (property → value) reads as its `default` block.
 */

const isBlock = (entry) =>
  Object.values(entry).every((value) => value === null || typeof value !== 'object');

const styles = (entry) =>
  entry === null || entry === undefined
    ? {}
    : isBlock(entry)
      ? { default: entry }
      : entry;

export const layer = (id, tier, entry, extra = {}) => ({
  id,
  tier,
  scope: null,
  variant: null,
  fragment: null,
  via: null,
  viaLabel: null,
  label: null,
  description: null,
  axis: null,
  resolved: true,
  editable: tier === 'element',
  carrier: null,
  styles: styles(entry),
  ...extra,
});

export const SCOPE = 'block_type:text';

/**
 * @param {object} [tiers]
 * @param {?object} [tiers.declared]
 *   The declaration's block at the key (one `declared:` layer).
 * @param {?object} [tiers.skin]
 *   The skin's block at the key (one `skin:` layer).
 * @param {Array<{id: string, styles: object, label?: string}>} [tiers.variants]
 *   Worn references in delivery order.
 * @param {?object} [tiers.own]
 *   The element's own entry.
 */
export const stackOf = ({
  declared = null,
  skin = null,
  variants = [],
  own = null,
} = {}) => ({
  key: 'block:x',
  rootKey: 'block:x',
  relative: '',
  scope: SCOPE,
  layers: [
    ...(declared
      ? [layer(`declared:${SCOPE}`, 'declared', declared, { scope: SCOPE })]
      : []),
    ...(skin ? [layer(`skin:${SCOPE}`, 'skin', skin, { scope: SCOPE })] : []),
    ...variants.map(({ id, label = null, styles: entry }) =>
      layer(`variant:${id}`, 'variant', entry, { variant: id, label }),
    ),
    layer('element', 'element', own),
  ],
  baseTags: [],
  skin: skin !== null,
});

/** A side no layer resolves (sideProvenance's computed state). */
export const COMPUTED = {
  state: 'computed',
  layer: null,
  id: null,
  reference: null,
  condition: null,
  atPose: false,
  value: null,
  property: null,
};

/**
 * The per-property stacks map the per-side readers take (box-model.js
 * sideProvenance / uniformState, stroke-model.js strokeSides read a
 * control model's `stacks`, property → stack): one hand-built stack
 * (stackOf) served for every box family's shorthand and longhands, the
 * single-box binding's shape.
 */
export const stacksOf = (tiers = {}) => {
  const stack = stackOf(tiers);
  return Object.fromEntries(
    Object.keys(BOX_FAMILIES)
      .flatMap((family) => [family, ...SIDES.map((side) => familyLonghand(family, side))])
      .map((name) => [name, stack]),
  );
};
