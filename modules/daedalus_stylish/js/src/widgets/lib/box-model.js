/**
 * Pure value logic for the box-model diagram (the spacing widget's
 * per-side half). Preact-free on purpose, like widget-values.js and
 * dimension-value.js: everything here is testable under plain node.
 *
 * The diagram edits PADDING LONGHANDS while the row's shorthand field
 * (the padding row's DimensionField, the stroke row's width field) keeps
 * writing the shorthand, so this module owns the three questions that
 * interplay raises:
 * - what each side's effective value IS (last-declaration-wins over a
 *   condition object, shorthand expanded by the CSS 1/2/3/4 rule);
 * - where a side's value comes FROM (the per-side read of the style
 *   stack: authored on the element / supplied by a worn variant, with
 *   the supplying reference / defaulted by the active skin / defaulted
 *   by the declaration / none of them: the computed-placeholder state);
 * - what one per-side edit COMMITS (a plain longhand write, or the
 *   one-time shorthand→longhand expansion: one multi-property op
 *   unsetting `padding` and writing all four longhands, so shorthand and
 *   authored longhands never coexist and declaration order never
 *   decides).
 * Plus the diagram's gesture math: the Figma modifier split (Shift =
 * coarse, Alt = mirror, Alt+Shift = all four; the full DevTools grain
 * ladder lives only inside the field editor), dispatched over each side
 * longhand's VALUE SPACE (value-space.js): closed cells scrub detent to
 * detent, detented cells ride sticky magnets, free cells keep the plain
 * diagram grain, and every settle that lands on a stop commits the
 * stop's reference (settleSpelling).
 */

import { normalizeValue } from '../../widget-values.js';
import { entryAtPose, posedBlock } from '../../condition-cascade.js';
import {
  ELEMENT_LAYER,
  TIER_ANCESTOR,
  TIER_DECLARED,
  TIER_SKIN,
  TIER_VARIANT,
  ancestorVariant,
  layerColor,
} from '../../code/layers.js';
import {
  applyStep,
  contextUnit,
  fieldState,
  formatDimension,
  scrubAdvance,
  scrubRate,
  seedAnchor,
  stepSize,
  unitPrecision,
} from './dimension-value.js';
import {
  MAGNET_RADIUS,
  SCRUB_PER_PIXEL,
  detentsFor,
  discreteScrubMove,
  discreteScrubStart,
  feelFactor,
  magnetAdvance,
  magneticCapture,
  referenceVariable,
  stepAcross,
  stepStop,
  valueOrient,
  valuesEqual,
} from '../../value-space.js';

/**
 * The four physical sides, in CSS clock order (top right bottom left).
 * Physical on purpose: every surveyed builder ships physical sides, and
 * every cell and per-side write is one of these. The logical longhands
 * are READ onto them (resolveSides through LOGICAL_SIDES), never
 * offered.
 */
export const SIDES = ['top', 'right', 'bottom', 'left'];

/**
 * Each side's mirror across the box (the Alt spread partner).
 */
export const OPPOSITE_SIDE = {
  top: 'bottom',
  bottom: 'top',
  left: 'right',
  right: 'left',
};

/**
 * The sides a box family's LOGICAL longhand addresses, in the horizontal
 * writing mode (the only one the canvas poses in). `padding-inline` is
 * the width story's spelling (the one-box container's inset on the band
 * itself) and reads as the left and right sides it moves.
 */
export const LOGICAL_SIDES = {
  inline: ['right', 'left'],
  block: ['top', 'bottom'],
};

/**
 * The box shorthand families whose declarations share sides, keyed by
 * the family's shorthand name. Each descriptor is the family's naming
 * rule: `shorthand` is the four-side property, `longhand(side)` spells
 * the declaration that addresses one side (a physical side from SIDES,
 * or a logical axis from LOGICAL_SIDES). Two spellings live here: the
 * spacing families suffix the side (`padding-top`, `margin-inline`),
 * the border families infix it between the family and its part
 * (`border-top-width`, `border-inline-color`), and every consumer that
 * needs a side's property name reads it through familyLonghand rather
 * than composing a string, so no surface can spell a border side the
 * way it spells a padding side.
 */
export const BOX_FAMILIES = {
  padding: {
    shorthand: 'padding',
    longhand: (side) => `padding-${side}`,
    length: true,
  },
  margin: {
    shorthand: 'margin',
    longhand: (side) => `margin-${side}`,
    length: true,
  },
  'border-width': {
    shorthand: 'border-width',
    longhand: (side) => `border-${side}-width`,
    length: true,
  },
  'border-style': {
    shorthand: 'border-style',
    longhand: (side) => `border-${side}-style`,
    length: false,
  },
  'border-color': {
    shorthand: 'border-color',
    longhand: (side) => `border-${side}-color`,
    length: false,
  },
};

/**
 * The one naming rule: the longhand a box family spells for one side.
 *
 * @param {string} family
 *   A BOX_FAMILIES key ('padding', 'border-width', ...).
 * @param {string} side
 *   A physical side (SIDES) or a logical axis (LOGICAL_SIDES key).
 *
 * @return {string}
 *   The longhand property name ('border-width', 'top' →
 *   'border-top-width').
 */
const familyDescriptor = (family) => {
  const descriptor = BOX_FAMILIES[family];
  if (!descriptor) {
    throw new Error(`Unknown box family: ${family}`);
  }
  return descriptor;
};

export const familyLonghand = (family, side) =>
  familyDescriptor(family).longhand(side);

/**
 * The sides a box property addresses: the shorthand addresses all four,
 * a physical longhand exactly its own side, a logical longhand its pair
 * (LOGICAL_SIDES), anything else none. The one side grammar every
 * consumer reads: the painter registry maps a highlighted property back
 * to the bands it tints through this, and the shadow read (longhandGrain)
 * compares an own declaration with a worn one on it. The diagram's side
 * vocabulary is NOT derived here: grantedSides reads the granted
 * longhand NAMES, so a grant of padding-inline still offers no side cell
 * (the inline inset is the width story, not a spacing edit).
 *
 * @param {string} property
 *   The box shorthand family (a BOX_FAMILIES key).
 * @param {string} name
 *   The property name to classify.
 *
 * @return {?string[]}
 *   The addressed sides in SIDES order, or null when name is not this
 *   box property (the -start/-end logical longhands are outside the
 *   site's spacing vocabulary and return null).
 */
export const propertySides = (property, name) => {
  if (name === familyDescriptor(property).shorthand) {
    return [...SIDES];
  }
  const side = SIDES.find(
    (candidate) => familyLonghand(property, candidate) === name,
  );
  if (side) {
    return [side];
  }
  const axis = Object.keys(LOGICAL_SIDES).find(
    (candidate) => familyLonghand(property, candidate) === name,
  );
  return axis
    ? SIDES.filter((candidate) => LOGICAL_SIDES[axis].includes(candidate))
    : null;
};

/**
 * The longhand property names for a set of sides — the other half of
 * the shared grammar: a consumer that thinks in sides (the diagram's
 * hover/scrub state) maps them to the properties the canvas-highlight
 * spec speaks ('padding', ['top', 'left'] → ['padding-top',
 * 'padding-left']; 'border-width', ['top'] → ['border-top-width']).
 *
 * @param {string} property
 *   The box shorthand family (a BOX_FAMILIES key).
 * @param {string[]} sides
 *
 * @return {string[]}
 */
export const sideLonghands = (property, sides) =>
  sides.map((side) => familyLonghand(property, side));

/**
 * The sides a granted-property subset lets a box surface offer.
 *
 * The guide's allowlist grammar can narrow a group's grant to a subset of
 * its longhands (a container's vertical-rhythm spacing); the padding row
 * derives its side vocabulary from the GRANTED longhands. The shorthand
 * is not read here: the served grant carries it exactly when every side
 * is granted (the server's family rule), and the row reads that name.
 *
 * @param {string[]} granted
 *   The granted property names (model.granted).
 * @param {string} property
 *   The box shorthand family (a BOX_FAMILIES key).
 *
 * @return {string[]}
 *   The offered sides in SIDES order.
 */
export const grantedSides = (granted, property = 'padding') =>
  SIDES.filter((side) => granted.includes(familyLonghand(property, side)));

/**
 * A declaration's longhand grain: the physical side longhands a box
 * property addresses (`padding` all four, `padding-inline` left and
 * right, `padding-top` itself), or the property alone outside the box
 * families.
 *
 * The read two declarations are compared on before one is said to
 * shadow the other (wear-bar-model.js resetOverrideOps): an own
 * `padding` masks a worn `padding-inline`
 * because their grains overlap, an own `padding-top` leaves it showing,
 * exactly as the cascade delivers. One grammar for the diagram, the
 * highlight painter and the shadow read, so the wear bar's honesty and
 * the canvas's bands can never disagree about which sides a spelling
 * moves.
 *
 * @param {string} name
 *   A property name.
 *
 * @return {string[]}
 */
export const longhandGrain = (name) => {
  for (const family of Object.keys(BOX_FAMILIES)) {
    const sides = propertySides(family, name);
    if (sides) {
      return sideLonghands(family, sides);
    }
  }
  return [name];
};

/**
 * Whether two declarations meet on any longhand of their grain: a
 * SHADOW when one is the element's own and the other a worn variant's.
 *
 * @param {string} own
 * @param {string} worn
 *
 * @return {boolean}
 */
export const sharesGrain = (own, worn) => {
  if (own === worn) {
    return true;
  }
  const grain = longhandGrain(own);
  return longhandGrain(worn).some((longhand) => grain.includes(longhand));
};

/**
 * A shorthand's components: the runs between top-level whitespace, each
 * function consumed to its balanced close so its inner spaces never
 * split it.
 */
const splitComponents = (text) => {
  const parts = [];
  let current = '';
  let depth = 0;
  for (const char of text) {
    if (char === '(') {
      depth++;
    } else if (char === ')') {
      depth--;
    }
    if (depth === 0 && /\s/.test(char)) {
      if (current !== '') {
        parts.push(current);
        current = '';
      }
      continue;
    }
    current += char;
  }
  if (current !== '') {
    parts.push(current);
  }
  return parts;
};

/**
 * Expands a box shorthand into its four sides (the CSS 1/2/3/4 rule).
 *
 * Mirrors the server's shorthand grammar (PropertyGroupBase
 * validateDimension: whitespace-separated components, `repeat: 4`), so
 * what splits here is exactly what the server validated per-component.
 * Components split on TOP-LEVEL whitespace only: a function's own
 * spaces (`rgb(0 0 0 / 50%)` on a border-color shorthand) stay inside
 * their component. A shorthand that IS exactly one token reference
 * (`var(--space-sm)`, value-space.js referenceVariable) splits into
 * four copies of itself: one reference addresses every side
 * identically, so a scale pick or a skin color reads and writes per
 * side like a literal. This is the ONE splitter, shared by the read
 * (resolveSides) and the write (sideCommit), so the two can never
 * answer a lone reference differently. Anything else the server passes
 * STRUCTURALLY as one opaque whole — a `calc(` head, a var() beside
 * other components, a reference with a fallback tail — cannot be split
 * honestly (a var() may expand to any number of components), so it
 * returns null and the caller renders the honest set-but-unsplittable
 * state rather than a guess.
 *
 * @param {*} value
 *   A committed shorthand value ('1rem', '1rem 2rem', '1px 2px 3px 4px',
 *   'var(--space-sm)').
 *
 * @return {?{top: string, right: string, bottom: string, left: string}}
 *   Per-side components in the value's own spelling, or null for unset
 *   values, structural pass-throughs, and anything outside 1–4
 *   components.
 */
export const expandShorthand = (value) => {
  if (normalizeValue(value) === null) {
    return null;
  }
  const text = String(value).trim();
  if (referenceVariable(text) !== null) {
    return { top: text, right: text, bottom: text, left: text };
  }
  if (text.includes('var(') || text.startsWith('calc(')) {
    return null;
  }
  const parts = splitComponents(text);
  if (parts.length < 1 || parts.length > 4) {
    return null;
  }
  const [top, right = top, bottom = top, left = right] = parts;
  return { top, right, bottom, left };
};

/**
 * Resolves each side's effective value within ONE style layer.
 *
 * A condition object prints as declarations in insertion order
 * (CssPrinter), so within a layer the CSS answer is
 * last-declaration-wins over the sides each declaration addresses
 * (propertySides): a shorthand overwrites all four sides (a null draft
 * value clears them — the expansion commit's `padding: null`
 * mid-gesture), a physical longhand overwrites its own, a LOGICAL
 * longhand (`padding-inline`, `margin-block`) overwrites its physical
 * pair (LOGICAL_SIDES, the horizontal writing mode the canvas poses
 * in) with its whole value, and a later physical longhand still wins
 * its side over it. So the Site width variant's `padding-inline` reads
 * as the right and left sides it moves, in the variant's hue, rather
 * than as "computed from the theme". The logical spellings are READ
 * here only: no surface offers a `padding-inline` cell (grantedSides
 * reads the granted longhand names), and a per-side edit writes the
 * physical longhand, which beats the worn logical one by order. Pass
 * an ORDER-PRESERVING condition object (a map entry's `.default`, a
 * stack layer's posed block, a draft-layered copy of one) — a values
 * map rebuilt in owned-property order (controls.js litValues) loses
 * the layer's order, though under the widget's own commit discipline
 * (expansion always unsets the shorthand) shorthand and own longhands
 * never coexist and order is moot.
 *
 * @param {?Object<string, *>} values
 *   One layer's property → value object.
 * @param {string} property
 *   The box shorthand family (a BOX_FAMILIES key; margin reuses the logic in
 *   advanced mode later).
 *
 * @return {Object<string, ?{value: ?string, property: string}>}
 *   side → resolution. null: the layer says nothing about this side.
 *   {value, property}: the side's effective value and the declaration
 *   that supplied it (a lone reference shorthand is the reference on
 *   every side, expandShorthand's rule). A set-but-unsplittable
 *   shorthand (calc(), a multi-component var()) resolves every side to
 *   {value: null, property} — the side IS styled here, but no per-side
 *   value can honestly be shown.
 */
export const resolveSides = (values, property = 'padding') => {
  const sides = { top: null, right: null, bottom: null, left: null };
  Object.entries(values ?? {}).forEach(([name, raw]) => {
    const addressed = propertySides(property, name);
    if (addressed === null) {
      return;
    }
    const set = normalizeValue(raw) !== null;
    // The shorthand's components split per side; a longhand, physical
    // or logical, carries its whole value onto every side it addresses.
    const expanded = set && name === property ? expandShorthand(raw) : null;
    addressed.forEach((side) => {
      sides[side] = set
        ? {
            value:
              name === property
                ? (expanded?.[side] ?? null)
                : String(raw).trim(),
            property: name,
          }
        : null;
    });
  });
  return sides;
};

/**
 * The diagram's state a stack layer earns: the element's own layer
 * authors, a worn reference supplies, the two floor tiers default. An
 * ancestor rule's own entry reads as the floor does (a value this
 * element neither authored nor wears, in the floor hue); a variant the
 * rule wears, reaching this element (a worn ancestor layer, layers.js
 * ancestorVariant), reads as a variant: the identity is the variant's
 * even though the tier keeps "this element wears" honest.
 */
const sideState = (layer) => {
  switch (layer.tier) {
    case ELEMENT_LAYER:
      return 'authored';
    case TIER_VARIANT:
      return 'variant';
    case TIER_SKIN:
      return 'skin';
    case TIER_DECLARED:
      return 'declared';
    case TIER_ANCESTOR:
      return ancestorVariant(layer.id) === null ? 'skin' : 'variant';
    default:
      return 'variant';
  }
};

/**
 * The variant a layer supplies a side from: an own wearing's reference,
 * or the variant an ancestor's rule wears; null for every other layer.
 */
const suppliedReference = (layer) =>
  layer.tier === TIER_VARIANT ? layer.variant : ancestorVariant(layer.id);

/**
 * A side no layer resolves: nothing styles it, and the diagram shows
 * the canvas's computed value as a muted placeholder (the caller owns
 * that read; value here is null).
 */
const computedSide = () => ({
  state: 'computed',
  layer: null,
  id: null,
  reference: null,
  condition: null,
  atPose: false,
  value: null,
  property: null,
});

/**
 * The stack a side reads: its longhand's entry in a per-property stacks
 * map (a control model's `stacks`), null when the map carries none.
 *
 * @param {?Object<string, object>} stacks
 * @param {string} property
 *   The box shorthand family (a BOX_FAMILIES key).
 * @param {string} side
 *   A physical side (SIDES).
 *
 * @return {?object}
 */
const sideStack = (stacks, property, side) =>
  stacks?.[familyLonghand(property, side)] ?? null;

/**
 * Classifies each side's provenance across the style stack's layers at
 * a pose, the diagram's five states: the PER-SIDE read of the stack.
 *
 * stackAt() answers one property; a box family is spelled across a
 * shorthand and four longhands whose grains overlap, so a side's
 * delivering layer is found the way the printed document decides it:
 * each layer's block at the pose (condition-cascade.js posedBlock, the
 * same cascade the printer emits, so a layer's `padding` at Base still
 * supplies the sides a Medium `padding-top` leaves alone when posed at
 * Medium, and a later block's shorthand resets an earlier longhand) is
 * resolved per side (resolveSides: last-declaration-wins within the
 * layer, the shorthand expanded), and the TOPMOST layer resolving a
 * side wins it, whatever its spelling: an own `padding` beats a worn
 * `padding-top` on every side, exactly as CSS would, while an own
 * `padding-top` leaves the other three to the variant. The state is the
 * winning layer's tier (authored on this element, supplied by a worn
 * variant with its reference, defaulted by the active skin, defaulted
 * by the declaration), `layer` and `id` name it for the hue and the
 * label, and `condition` is the delivering block's (the layer's block
 * that reaches the pose for the resolving declaration). A side no
 * layer resolves is 'computed'.
 *
 * Every tier is read the same way, so a skin-defaulted side is a lit
 * value with its origin like any other (the display rule: the shown
 * value is the winner's), never an unlit resting value; the Unset
 * gestures read the delivering condition against the pose, not the
 * state.
 *
 * The read is PER PROPERTY: `stacks` is a control model's `stacks` map
 * (property → the layer stack of the box that property resolved to,
 * controls.js resolveCandidates), and each side walks its own
 * longhand's stack (sideStack: `padding-top` reads
 * stacks['padding-top']), so a binding over two boxes reads each
 * side from the box that paints it. A single-box binding maps every
 * property to the same stack, and the read is the one it always was.
 *
 * @param {?Object<string, object>} stacks
 *   The per-property layer stacks (style-stack.js buildStack per
 *   candidate, floor and rendered tag included), keyed by property.
 * @param {string} property
 *   The box shorthand family (a BOX_FAMILIES key).
 * @param {string} [condition]
 *   The editing compound ('default').
 * @param {object} [conditions]
 *   The condition table (guide.conditions), for the cascade order.
 *
 * @return {Object<string, {state: string, layer: ?number, id: ?string,
 *   reference: ?string, condition: ?string, atPose: boolean,
 *   value: ?string, property: ?string}>}
 *   side → {state: 'authored'|'variant'|'skin'|'declared'|'computed',
 *   layer, id, reference, condition, atPose, value, property}. layer
 *   indexes the stack's delivering layer and id is its layer id (null
 *   under 'computed'); reference names the supplying variant under
 *   'variant' (an own wearing's, or the one an ancestor's rule wears;
 *   null elsewhere); condition is the delivering block's (null for
 *   computed) and atPose says it is the posed compound itself (the
 *   fill every per-part mark reads: a cell or a corner dot fills at
 *   the pose and is hollow when the value cascades in from another
 *   condition; false for computed); value/property follow resolveSides
 *   (value null under 'computed', and for a set-but-unsplittable
 *   shorthand).
 */
export const sideProvenance = (
  stacks,
  property = 'padding',
  condition = 'default',
  conditions = {},
) => {
  const states = {};
  SIDES.forEach((side) => {
    states[side] = computedSide();
    // The side reads its own longhand's box: the layers walked are that
    // candidate's, bottom to top, the shorthand and the longhand lines
    // of every layer resolved together (a later layer's shorthand resets
    // an earlier layer's longhand, as the printed document does).
    const stack = sideStack(stacks, property, side);
    (stack?.layers ?? []).forEach((layer, index) => {
      const block = posedBlock(layer.styles, condition, conditions);
      if (block === null) {
        return;
      }
      const resolved = resolveSides(block, property);
      if (!resolved[side]) {
        return;
      }
      // The delivering condition for the declaration that resolved the
      // side: the same cascade posedBlock merged, read per property.
      const from =
        entryAtPose(layer.styles, resolved[side].property, condition, conditions)
          ?.condition ?? condition;
      states[side] = {
        state: sideState(layer),
        layer: index,
        id: layer.id,
        reference: suppliedReference(layer),
        condition: from,
        // The one derivation of "at the pose" for a side; every per-part
        // mark (the cell, the row's derived origin) reads this field.
        atPose: from === condition,
        ...resolved[side],
      };
    });
  });
  return states;
};

/**
 * A box family row's ONE origin over its offered sides: the facts the
 * row's mark reads (widgets/lib/mark.js) off the per-side provenance.
 *
 * A family row (the padding row) shows one value for up to four sides
 * that may come from four places, so its mark cannot be one stackAt()
 * projection. The rule: the set sides are grouped by (delivering layer,
 * at the pose); ONE group is the row's origin as any property row's
 * would be (that layer's hue, filled when its block is the posed
 * compound); several groups read as the topmost group's hue, hollow
 * (`atPose` false), and `groups` names the split for the sentence
 * (provenance-model.js sidesSentence). With no set side, the topmost
 * layer authoring the family at ANY condition lends its hue (hollow,
 * `named` its conditions), else nothing (`color` null: the resting
 * ring). Words are not built here: the sentence is the provenance
 * model's, over these facts.
 *
 * @param {?Object<string, object>} stacks
 *   The per-property layer stacks (see sideProvenance).
 * @param {string} property
 *   The box shorthand family (a BOX_FAMILIES key).
 * @param {string[]} sides
 *   The sides the row OFFERS (grantedSides()).
 * @param {string} [condition]
 *   The editing compound (the pose).
 * @param {object} [conditions]
 *   The condition table (guide.conditions).
 *
 * @return {{delivers: boolean, atPose: boolean, layer: ?string, tier: ?string, reference: ?string, color: ?string, condition: ?string, supplier: ?object, groups: Array<{sides: string[], id: string, index: number, state: string, reference: ?string, condition: string, atPose: boolean, supplier: ?object}>, named: string[]}}
 *   delivers: any offered side is set. atPose: every set side's block
 *   is the posed compound AND one layer delivers them all. layer, tier,
 *   reference, color, condition, supplier (the stack layer object):
 *   the one group's, else the topmost group's, else the topmost
 *   author's (delivers false), else null. groups: the split, topmost
 *   layer first. named: with no set side, the author's conditions.
 */
export const sidesOrigin = (
  stacks,
  property = 'padding',
  sides = SIDES,
  condition = 'default',
  conditions = {},
) => {
  const states = sideProvenance(stacks, property, condition, conditions);
  const groups = [];
  sides.forEach((side) => {
    const prov = states[side];
    if (prov.state === 'computed') {
      return;
    }
    // The side's own at-pose fact (sideProvenance), never rederived.
    const { atPose } = prov;
    const group = groups.find(
      (candidate) => candidate.id === prov.id && candidate.atPose === atPose,
    );
    if (group) {
      group.sides.push(side);
      return;
    }
    groups.push({
      sides: [side],
      id: prov.id,
      index: prov.layer,
      state: prov.state,
      reference: prov.reference,
      condition: prov.condition,
      atPose,
      supplier: sideStack(stacks, property, side)?.layers?.[prov.layer] ?? null,
    });
  });
  groups.sort((a, b) => b.index - a.index);
  const identity = (group) => ({
    layer: group.id,
    tier: group.supplier?.tier ?? null,
    reference: group.reference,
    color: layerColor(group.id),
    condition: group.condition,
    supplier: group.supplier,
  });
  if (groups.length) {
    const lead = groups[0];
    return {
      delivers: true,
      atPose: groups.length === 1 && lead.atPose,
      ...identity(lead),
      groups,
      named: [],
    };
  }
  const author = familyAuthor(stacks, property, sides, conditions);
  return {
    delivers: false,
    atPose: false,
    layer: author?.layer.id ?? null,
    tier: author?.layer.tier ?? null,
    reference: author ? suppliedReference(author.layer) : null,
    color: author ? layerColor(author.layer.id) : null,
    condition: null,
    supplier: author?.layer ?? null,
    groups: [],
    named: author?.named ?? [],
  };
};

/**
 * The topmost layer authoring the family on any offered side at ANY
 * condition (the hover-only padding while posed at Base), with the
 * conditions it names, Base first then the guide's order; null when no
 * layer authors the family at all.
 */
const familyAuthor = (stacks, property, sides, conditions) => {
  const order = ['default', ...Object.keys(conditions ?? {})];
  const rank = (name) => {
    const index = order.indexOf(name);
    return index === -1 ? order.length : index;
  };
  let found = null;
  sides.forEach((side) => {
    const layers = sideStack(stacks, property, side)?.layers ?? [];
    for (let index = layers.length - 1; index >= 0; index -= 1) {
      if (found !== null && found.index > index) {
        break;
      }
      const layer = layers[index];
      const named = Object.entries(layer.styles ?? {})
        .filter(
          ([name, block]) =>
            name !== 'variants' &&
            block !== null &&
            typeof block === 'object' &&
            !Array.isArray(block) &&
            Object.entries(block).some(
              ([key, value]) =>
                value !== null &&
                value !== undefined &&
                (propertySides(property, key) ?? []).includes(side),
            ),
        )
        .map(([name]) => name);
      if (named.length) {
        const merged =
          found !== null && found.index === index
            ? [...new Set([...found.named, ...named])]
            : named;
        found = { layer, index, named: merged };
        break;
      }
    }
  });
  return found === null
    ? null
    : {
        ...found,
        named: [...found.named].sort((a, b) => rank(a) - rank(b)),
      };
};

/**
 * The properties object one per-side edit commits (one update_style op).
 *
 * Two shapes, decided by whether the OWN layer carries a set shorthand:
 * - No own shorthand: a plain longhand write per spread side (value
 *   null clears — the Alt-click unset). A VARIANT-supplied shorthand
 *   needs no expansion: the own longhand shadows the variant per side
 *   while the variant keeps supplying the others, and stays detachable.
 * - Own shorthand set: the one-time expansion — unset `padding`, write
 *   all four longhands in one atomic multi-property commit; edited
 *   sides at the new value, the rest at their current own-layer
 *   effective values, so the look cannot change out from under the
 *   unedited sides. After this commit the own layer is longhands-only,
 *   which is why declaration order never matters in practice.
 *
 * The sides are read through resolveSides, so the split is the one
 * splitter's (expandShorthand): an own shorthand that IS exactly one
 * token reference (`border-width: var(--stroke-thin)`, `border-color:
 * var(--color-x)`) lands the reference on every side it still supplies
 * and never blocks the ring. An own shorthand the splitter cannot
 * honestly split (calc(), a multi-component var()) returns null:
 * committing guessed per-side values would silently change the
 * element, so the caller degrades (the diagram shows the shorthand as
 * one unsplittable value; clearing or retyping it goes through the
 * shorthand's own editor).
 *
 * @param {?Object<string, *>} own
 *   The element's OWN editing-compound values (order-preserving).
 * @param {string[]} sides
 *   The sides this gesture writes (spreadSides()).
 * @param {?string} value
 *   The value every spread side receives; null unsets.
 * @param {string} property
 *   The box shorthand family (a BOX_FAMILIES key).
 *
 * @return {?Object<string, ?string>}
 *   The update_style properties patch, or null when an unsplittable own
 *   shorthand blocks per-side editing.
 */
export const sideCommit = (own, sides, value, property = 'padding') => {
  const shorthand = normalizeValue(own?.[property]) !== null;
  if (!shorthand) {
    const patch = {};
    sides.forEach((side) => {
      patch[familyLonghand(property, side)] = value;
    });
    return patch;
  }
  const current = resolveSides(own, property);
  // What each side carries on the own layer today: a split component
  // (the reference itself under a lone reference shorthand); null for a
  // side the layer no longer styles. An opaque shorthand leaves a styled
  // side with nothing honest to write, and that blocks the whole patch.
  if (
    SIDES.some((side) => current[side] !== null && current[side].value === null)
  ) {
    return null;
  }
  const patch = { [property]: null };
  SIDES.forEach((side) => {
    patch[familyLonghand(property, side)] = sides.includes(side)
      ? value
      : (current[side]?.value ?? null);
  });
  return patch;
};

/**
 * The properties object one gesture with PER-SIDE values commits.
 *
 * A diagram gesture can leave different sides at different values (a
 * mirror scrub whose Alt was released mid-drag keeps the mirrored side
 * at its last spread value), which sideCommit's one-value contract
 * cannot express. This folds sideCommit side by side: the first step
 * performs any one-time shorthand expansion, later steps see the
 * already-expanded layer and write plain longhands, so the whole result
 * is still ONE atomic update_style patch with the shorthand unset at
 * most once.
 *
 * @param {?Object<string, *>} own
 *   The element's OWN editing-compound values (order-preserving).
 * @param {?Object<string, ?string>} valuesBySide
 *   side → value; null unsets that side. Sides absent from the object
 *   are untouched (beyond what an expansion must write).
 * @param {string} property
 *   The box shorthand family (a BOX_FAMILIES key).
 *
 * @return {?Object<string, ?string>}
 *   The update_style properties patch ({} for an empty gesture), or
 *   null when an unsplittable own shorthand (calc(), a multi-component
 *   var()) blocks per-side editing (sideCommit's degradation signal).
 *   The block is judged before the gesture is read, so an EMPTY gesture
 *   over a blocked layer answers null too: the diagram's blocked probe
 *   is this call with no sides, the one seam a ring over any family
 *   shares.
 */
export const sidesPatch = (own, valuesBySide, property = 'padding') => {
  if (sideCommit(own, [], null, property) === null) {
    return null;
  }
  let layer = { ...(own ?? {}) };
  const patch = {};
  for (const [side, value] of Object.entries(valuesBySide ?? {})) {
    const step = sideCommit(layer, [side], value, property);
    if (step === null) {
      return null;
    }
    Object.assign(patch, step);
    layer = { ...layer, ...step };
  }
  return patch;
};

/**
 * The minimal CSS shorthand spelling for four per-side values (the
 * 1/2/3/4 rule run backwards): equal left/right drop left, then equal
 * top/bottom drop bottom, then an all-equal set drops right. Comparison
 * is normalized; the kept components keep their own spelling.
 */
const shorthandText = (values) => {
  const parts = [values.top, values.right, values.bottom, values.left];
  if (normalizeValue(values.left) === normalizeValue(values.right)) {
    parts.pop();
    if (normalizeValue(values.bottom) === normalizeValue(values.top)) {
      parts.pop();
      if (normalizeValue(values.right) === normalizeValue(values.top)) {
        parts.pop();
      }
    }
  }
  return parts.join(' ');
};

/**
 * The shorthand field's longhand-aware view of a box property: whether
 * the style map styles it at all, and whether ONE value honestly
 * represents all four sides.
 *
 * The field keeps its one-value vocabulary (a settle writes the
 * shorthand) while the diagram edits longhands, so the field must read
 * per-side state honestly: four equal sides — however spelled, shorthand
 * or longhands, whichever layer delivers them (the element's own, a
 * worn variant's, the skin's or the declaration's: the display rule
 * lights the winner) — show that one value exactly as a plain shorthand
 * would (a closed vocabulary's select lights its stop), and anything
 * per-side is the EXPLICIT custom state (uniformFieldText's degraded
 * text), never "nothing set" and never a lie that one value covers it.
 *
 * @param {?Object<string, object>} stacks
 *   The per-property layer stacks (a control model's `stacks`; see
 *   sideProvenance).
 * @param {string} property
 *   The box shorthand family (a BOX_FAMILIES key).
 * @param {string[]} sides
 *   The sides the surface OFFERS (grantedSides()); uniformity, set-ness,
 *   and the custom listing are judged over exactly these, so a
 *   vertical-only grant lights its chip when top and bottom agree no
 *   matter what the ungoverned horizontal sides carry. Defaults to all
 *   four (the ungoverned surface).
 * @param {string} [condition]
 *   The editing compound ('default').
 * @param {object} [conditions]
 *   The condition table (guide.conditions), for the cascade order.
 *
 * @return {{set: boolean, uniform: ?string, text: ?string}}
 *   set false: no layer styles any side the field can spell (the field
 *   is empty); a supplied side outside the dimension grammar (the Site
 *   width variant's `padding-inline: max(...)`) is a measurement, the
 *   ring's cell rule (sideMeasured), not a value.
 *   uniform: the single value representing all four sides (feed
 *   segmentState), or null when the sides differ, are only partly set,
 *   or ride an unsplittable calc()/multi-component var() shorthand. A
 *   shorthand that IS exactly one token reference (var(--name)) needs
 *   no exception: the per-side read already yields the one spelling on
 *   every side (expandShorthand), so it surfaces as the uniform value
 *   and a scale token pick lights its stop (the lit-stop rule is
 *   reference-spelling matching). text: display copy for the custom
 *   pill — the minimal shorthand when all four sides are set, a
 *   per-side listing when only some are, null for the opaque
 *   unsplittable case (the caller says calc/var).
 */
export const uniformState = (
  stacks,
  property = 'padding',
  sides = SIDES,
  condition = 'default',
  conditions = {},
) => {
  const provenance = sideProvenance(stacks, property, condition, conditions);
  // A side the field can spell, or one it only measures: the ring's
  // rule (sideMeasured) read over the raw spelling, since the field has
  // no materials to resolve a reference into its face (the ring does)
  // and a lone reference IS the field's value (its stop lights by
  // spelling). So the Site width variant's `padding-inline: max(...)`
  // reads as the ring's cells read it, a measurement over an empty
  // field, never a per-side listing of the expression. Only a length
  // family measures (BOX_FAMILIES `length`: the rings' families); a
  // stroke's style keyword or color is a value however it is spelled,
  // and only its absence reads computed.
  const measures = familyDescriptor(property).length;
  const resolutions = sides.map((side) => ({
    ...provenance[side],
    measured: measures
      ? referenceVariable(provenance[side].value ?? '') === null &&
        sideMeasured(provenance[side].state, provenance[side].value)
      : provenance[side].state === 'computed',
  }));
  if (resolutions.every((resolution) => resolution.measured)) {
    return { set: false, uniform: null, text: null };
  }
  if (
    resolutions.some(
      (resolution) => !resolution.measured && resolution.value === null,
    )
  ) {
    // Set-but-unsplittable: an own calc()/multi-component var()
    // shorthand styles the sides but no per-side value can honestly be
    // shown.
    return { set: true, uniform: null, text: null };
  }
  if (resolutions.some((resolution) => resolution.measured)) {
    const text = sides
      .filter((side, index) => !resolutions[index].measured)
      .map((side) => `${side} ${provenance[side].value}`)
      .join(', ');
    return { set: true, uniform: null, text };
  }
  const spellings = new Set(
    resolutions.map((resolution) => normalizeValue(resolution.value)),
  );
  if (spellings.size === 1) {
    return {
      set: true,
      uniform: resolutions[0].value,
      text: resolutions[0].value,
    };
  }
  if (sides.length < SIDES.length) {
    // A side subset has no shorthand spelling — the honest custom text is
    // the per-side listing, same shape as the partially-set case.
    const text = sides
      .map((side) => `${side} ${provenance[side].value}`)
      .join(', ');
    return { set: true, uniform: null, text };
  }
  const bySide = {};
  SIDES.forEach((side) => {
    bySide[side] = provenance[side].value;
  });
  return { set: true, uniform: null, text: shorthandText(bySide) };
};

/**
 * The properties object one CHIP pick commits: the shorthand write, with
 * any own longhands collapsed back into it.
 *
 * The inverse of sideCommit's expansion, keeping the same invariant —
 * shorthand and authored longhands never coexist on the own layer, so
 * declaration order never decides. A settle writes `padding: value` and
 * unsets whichever longhands the OWN layer carries in the same atomic
 * patch (leaving them would let map order pick a winner); with no own
 * longhands it stays the plain one-property write the row always made.
 * Variant-supplied longhands are untouched: the own shorthand lands
 * after them in the cascade and resets all four sides, exactly as CSS
 * would, and stays detachable. A null value (the emptied field, or a
 * closed select's unset) unsets the shorthand AND the own longhands —
 * the gesture said "no padding choice", and leaving longhands would
 * keep sides the field claimed to clear.
 *
 * @param {?Object<string, *>} own
 *   The element's OWN editing-compound values (order-preserving).
 * @param {?string} value
 *   The field's commit spelling; null unsets.
 * @param {string} property
 *   The box shorthand family (a BOX_FAMILIES key).
 *
 * @return {Object<string, ?string>}
 *   The update_style properties patch.
 */
export const uniformCommit = (own, value, property = 'padding') => {
  const patch = { [property]: value };
  SIDES.forEach((side) => {
    const longhand = familyLonghand(property, side);
    if (normalizeValue(own?.[longhand]) !== null) {
      patch[longhand] = null;
    }
  });
  return patch;
};

/**
 * The properties object one FIELD settle commits under a SIDE-SUBSET
 * grant.
 *
 * The field's commit where the guide's allowlist withholds the shorthand
 * (a container's vertical-rhythm spacing): one value writes each offered
 * side's longhand — the shorthand cannot be spelled, so the subset dual
 * of uniformCommit writes longhands directly. An OWN shorthand (legacy
 * data from before the grant narrowed) is unset in the same atomic patch:
 * it is un-respellable here, and dropping it returns the withheld sides
 * to the variant/skin layer that owns them. Own longhands on withheld
 * sides are untouched — the settle said nothing about them ("never
 * reset what a gesture didn't say"). A null value unsets the offered
 * sides (and the own shorthand), the emptied field.
 *
 * @param {?Object<string, *>} own
 *   The element's OWN editing-compound values (order-preserving).
 * @param {string[]} sides
 *   The offered sides (grantedSides()).
 * @param {?string} value
 *   The field's commit spelling; null unsets.
 * @param {string} property
 *   The box shorthand family (a BOX_FAMILIES key).
 *
 * @return {Object<string, ?string>}
 *   The update_style properties patch.
 */
export const subsetCommit = (own, sides, value, property = 'padding') => {
  const patch = {};
  if (normalizeValue(own?.[property]) !== null) {
    patch[property] = null;
  }
  sides.forEach((side) => {
    patch[familyLonghand(property, side)] = value;
  });
  return patch;
};

/**
 * The sides one diagram gesture spreads to (the Figma modifier split).
 *
 * Alt mirrors to the opposite side, Alt+Shift spreads to all four,
 * unmodified edits stay on the grabbed side. Shift alone is NOT spread —
 * it is the coarse-grain scrub modifier (diagramModifiers); when Alt is
 * down, Shift is spent on spread and grain stays fine. This resolves
 * the documented collision between side-spread and scrub-grain
 * vocabularies; the full DevTools ladder (Alt 0.1 / Cmd 100) lives only
 * inside the field editor, where no spread gestures compete for the
 * keys.
 *
 * @param {string} side
 *   The grabbed side.
 * @param {{altKey?: boolean, shiftKey?: boolean}} modifiers
 *   The gesture's modifier state (a pointer event works as-is).
 *
 * @return {string[]}
 *   The sides to write, grabbed side first (SIDES order for all-four).
 */
export const spreadSides = (side, modifiers = {}) => {
  if (!modifiers.altKey) {
    return [side];
  }
  return modifiers.shiftKey ? [...SIDES] : [side, OPPOSITE_SIDE[side]];
};

/**
 * A gesture's modifier state reduced to the diagram's grain vocabulary.
 *
 * Only Shift survives, and only when Alt is not claiming it for spread;
 * Alt and Cmd/Ctrl never reach the step math here (Alt is mirror, the
 * 0.1/100 grains and the hundredths precision are field-editor-only,
 * so `altKey` is spelled out false). Feed the result to
 * dimension-value's stepSize/scrubAdvance (free cells) or scrubRate
 * and unitPrecision (detented cells) so the diagram and the field
 * editor share one snapping implementation, one rate ladder and one
 * precision rule.
 *
 * @param {{altKey?: boolean, shiftKey?: boolean}} modifiers
 *
 * @return {{shiftKey: boolean, altKey: false}}
 */
export const diagramModifiers = (modifiers = {}) => ({
  shiftKey: Boolean(modifiers.shiftKey) && !modifiers.altKey,
  altKey: false,
});

/**
 * The diagram's step for a modifier state: the unit's grain (a pixel, a
 * tenth of a rem; dimension-value's unitGrain), or 10 under coarse
 * Shift.
 *
 * @param {{altKey?: boolean, shiftKey?: boolean}} modifiers
 * @param {string} unit
 *   The unit the gesture works in (px by default).
 *
 * @return {number}
 */
export const diagramStep = (modifiers = {}, unit = 'px') =>
  stepSize(diagramModifiers(modifiers), unit);

/**
 * One diagram scrub tick: dimension-value's scrubAdvance under the
 * diagram's grain policy (same accumulator/snap contract).
 *
 * @param {number} raw
 *   The running accumulator (starts at the grabbed side's value).
 * @param {number} movementX
 *   The tick's horizontal pointer travel.
 * @param {{altKey?: boolean, shiftKey?: boolean}} modifiers
 * @param {string} unit
 *   The unit the scrub works in (px by default).
 *
 * @return {{raw: number, number: number, step: number}}
 */
export const diagramScrub = (raw, movementX, modifiers = {}, unit = 'px') =>
  scrubAdvance(raw, movementX, diagramModifiers(modifiers), unit);

/**
 * Whether a side cell reads as its MEASUREMENT (the canvas's computed
 * value, converted into the gesture's unit, as a placeholder over an
 * empty editor) rather than as a value of the stack's.
 *
 * Two sides measure. A computed side (nothing in the stack styles it)
 * has nothing else to show. A SUPPLIED side (a worn variant's, the
 * skin's, the declaration's) whose value is outside the dimension
 * grammar (the Site width variant's `padding-inline: max(...)`, a
 * skin's `calc()` shorthand no splitter can open) has a value the cell
 * cannot scrub or the editor edit per side, so it shows what that value
 * measures as, in the supplier's hue (the origin still names itself on
 * hover), and a gesture writes the physical longhand over it: someone
 * dialing in a padding meant it, and the wear bar names the override.
 * An OWN side outside the grammar is the caller's opaque
 * set-but-unsplittable state (the shorthand's own editor still works),
 * never a measurement: measuring over it would invite a per-side write
 * the expansion cannot honestly make.
 *
 * @param {string} state
 *   The side's provenance state (sideProvenance: 'authored' | 'variant'
 *   | 'skin' | 'declared' | 'computed').
 * @param {*} face
 *   The side's display value (a worn reference's face at the pose, a
 *   literal as itself), null when the layer resolves no per-side value.
 *
 * @return {boolean}
 */
export const sideMeasured = (state, face) =>
  state === 'computed' ||
  (state !== 'authored' && fieldState(face ?? '').mode !== 'dimension');

/**
 * A side cell's gesture anchor: the unit its scrub, its arrows and the
 * ring label's unison scrub work in, and the number they start from.
 *
 * A side with a value of its own works in that value's unit from its
 * number. A measured side (sideMeasured: computed, or supplied outside
 * the grammar; the shown text is the canvas's placeholder, px-resolved
 * by computed-read.js, or nothing on the satellite) has no value to
 * take a unit from, so it takes contextUnit's unit (the vocabulary's,
 * then the first offered; no pick and no last parse, since a cell has
 * no select and remembers nothing) and starts from the placeholder
 * converted into it through the canvas's measured bases (seedAnchor:
 * 16px beneath a rem scale at a 16px root starts at 1rem, 17px at
 * 1.1rem, the ring's tenths; an incomparable or absent placeholder at
 * 0). A bare-number own value
 * (`0`) names no unit and takes the same context unit from its own
 * number. The side editor beneath the ring derives the same unit and
 * seed through DimensionField, so the cell, the label and the editor
 * agree by construction, and the placeholder's face converts into the
 * same unit (dimension-value.js lengthIn), so what the cell shows is
 * what a gesture starts from.
 *
 * @param {object} space
 *   The side longhand's value space (valueSpaceFor).
 * @param {*} shown
 *   The cell's shown text: the winner's display value, or the raw
 *   computed placeholder (null when there is none).
 * @param {boolean} measured
 *   Whether the shown text is a placeholder rather than a value.
 * @param {string[]} options
 *   The units the longhand offers (unitOptions).
 * @param {?{rootPx: ?number, fontPx: ?number}} bases
 *   The canvas's measured bases (readCanvas), null off canvas.
 *
 * @return {{mode: string, number: number, unit: string}}
 *   mode is fieldState's over the shown text ('text' makes an open cell
 *   click-to-edit only); number and unit are the gesture's start.
 */
export const sideAnchor = (space, shown, measured, options = [], bases = null) => {
  const state = fieldState(shown ?? '');
  const named =
    state.mode === 'dimension' && state.unit !== '' ? state.unit : null;
  if (!measured && named !== null) {
    return { mode: state.mode, number: state.number, unit: named };
  }
  const unit = contextUnit({ spaceUnit: space.unit, options });
  return {
    mode: state.mode,
    number: measured ? seedAnchor(shown, unit, bases) : (state.number ?? 0),
    unit,
  };
};

/**
 * A side cell's scrub gesture seed over its value space.
 *
 * Closed: the accumulator starts on the stop the shown face value-matches
 * (`index` keeps the match, -1 for unset; the travel seeds at the
 * value-low end so the first tick of an unset cell previews the lowest
 * legal value). Detented/free: the accumulator is the cell's numeric
 * anchor, continuous.
 *
 * @param {object} space
 *   The side longhand's value space (valueSpaceFor).
 * @param {*} shown
 *   The cell's display face (a stop VALUE, never a reference).
 * @param {?number} number
 *   The face's numeric anchor (fieldState; 0 for an empty cell).
 * @param {string} unit
 *   The gesture's unit context.
 *
 * @return {{raw: number, unit: string, index?: number}}
 */
export const sideScrubSeed = (space, shown, number, unit) => {
  if (space.kind === 'closed') {
    const index = space.stops.findIndex((stop) =>
      valuesEqual(stop.value, shown ?? ''),
    );
    const start =
      index === -1
        ? valueOrient(space.stops) === 1
          ? 0
          : space.stops.length - 1
        : index;
    return { raw: discreteScrubStart(start), index, unit };
  }
  return { raw: number ?? 0, unit };
};

/**
 * One side-cell scrub tick, dispatched on the space's kind — the cells
 * scrub the SAME space the fields render:
 * - closed: detent to detent across the stops (discreteScrubMove), in
 *   VALUE direction (valueOrient), pinned ends; the preview text is the
 *   landed stop's display value.
 * - detented: continuous travel with sticky magnets at the stops, the
 *   field's rem-tuned feel knobs scaled for px-magnitude units
 *   (feelFactor), the shown number between magnets rounded to the
 *   unit's precision (tenths of a rem, never the editor's hundredths:
 *   Alt is spread here, so the ring's fine flag is always off). The
 *   modifiers change the travel RATE, never
 *   whether the magnets capture (magnetAdvance's rate scales the
 *   radius with the travel), under the diagram's vocabulary
 *   (diagramModifiers): Shift is x10, Alt is spread and spends Shift
 *   (base rate), Cmd/Ctrl never reach the rate ladder. Read per tick, so
 *   the rate changes mid-drag like the grain does on a free cell.
 * - free: the diagram's own grain policy (diagramScrub: Shift coarse,
 *   Alt spent on spread).
 *
 * @param {object} space
 *   The side longhand's value space.
 * @param {{raw: number, unit: string, index?: number}} scrub
 *   The running gesture (sideScrubSeed, then each tick's result).
 * @param {number} movementX
 *   The tick's horizontal pointer travel.
 * @param {{altKey?: boolean, shiftKey?: boolean}} modifiers
 *
 * @return {{raw: number, unit: string, index?: number, number: ?number,
 *   text: string, stop: ?object}}
 *   text is the preview display value; stop is the stop the tick landed
 *   on (the settle commits its reference — settleSpelling), null between
 *   magnets and always for free. Callers clamp `number` themselves
 *   (bounds vary per spread side) and re-pin `raw` on a clamp.
 */
export const sideScrubMove = (space, scrub, movementX, modifiers = {}) => {
  if (space.kind === 'closed') {
    const advanced = discreteScrubMove(
      scrub.raw,
      movementX * valueOrient(space.stops),
      space.stops.length,
    );
    const stop = space.stops[advanced.index];
    return {
      ...scrub,
      raw: advanced.raw,
      index: advanced.index,
      number: stop.number,
      text: stop.value,
      stop,
    };
  }
  if (space.kind === 'detented') {
    const factor = feelFactor(scrub.unit);
    // The diagram's grain vocabulary keeps no Alt (spread), so the
    // ring's precision is the unit's own, never fine.
    const grain = diagramModifiers(modifiers);
    const advanced = magnetAdvance(
      scrub.raw,
      movementX,
      detentsFor(space.stops, scrub.unit),
      {
        perPixel: SCRUB_PER_PIXEL * factor,
        magnet: MAGNET_RADIUS * factor,
        precision: unitPrecision(scrub.unit, grain.altKey),
        rate: scrubRate(grain),
      },
    );
    return {
      ...scrub,
      raw: advanced.raw,
      number: advanced.number,
      text: formatDimension(advanced.number, scrub.unit),
      stop: advanced.stop,
    };
  }
  const advanced = diagramScrub(scrub.raw, movementX, modifiers, scrub.unit);
  return {
    ...scrub,
    raw: advanced.raw,
    number: advanced.number,
    text: formatDimension(advanced.number, scrub.unit),
    stop: null,
  };
};

/**
 * One side-cell arrow press, dispatched on the space's kind: closed
 * steps one stop in value direction (stepStop; entering from unset at
 * the low/high end), detented steps the diagram grain but lands ON a
 * crossed stop (stepAcross, so arrow-walking still finds the system),
 * free steps the plain diagram grain.
 *
 * @param {object} space
 *   The side longhand's value space.
 * @param {{number?: ?number, unit: string, index?: number}} anchor
 *   The gesture's running anchor: `index` for closed (-1 unset),
 *   `number` otherwise.
 * @param {number} direction
 *   +1 or -1, in value terms.
 * @param {{altKey?: boolean, shiftKey?: boolean}} modifiers
 *
 * @return {?{text: string, stop: ?object, number?: number,
 *   index?: number}}
 *   Null when the press moves nothing (a closed ramp pinned at its end).
 *   Callers clamp `number` for the open kinds.
 */
export const sideStepValue = (space, anchor, direction, modifiers = {}) => {
  if (space.kind === 'closed') {
    const index = stepStop(space.stops, anchor.index, direction);
    if (index === null) {
      return null;
    }
    const stop = space.stops[index];
    return { text: stop.value, stop, index };
  }
  if (space.kind === 'detented') {
    const across = stepAcross(
      anchor.number,
      direction,
      detentsFor(space.stops, anchor.unit),
      diagramStep(modifiers, anchor.unit),
    );
    return {
      text: formatDimension(across.number, anchor.unit),
      stop: across.stop,
      number: across.number,
    };
  }
  const number = applyStep(
    anchor.number,
    direction,
    diagramStep(modifiers, anchor.unit),
  );
  return { text: formatDimension(number, anchor.unit), stop: null, number };
};

/**
 * A settled side value's COMMIT spelling: a value that lands on a stop
 * commits the stop's reference (the magnetic-capture doctrine — closed
 * scrubs only ever land on stops, so closed commits are always
 * references), anything else commits the literal as shown.
 *
 * @param {object} space
 *   The side longhand's value space.
 * @param {string} text
 *   The settled display text.
 *
 * @return {string}
 */
export const settleSpelling = (space, text) =>
  magneticCapture(space.stops, text)?.ref ?? text;

/**
 * The shorthand FIELD's face for a box property's uniform state — what
 * the padding row's DimensionField shows:
 * - nothing set: empty (the field's unset state, its placeholder the
 *   computed hint);
 * - one value honestly covers the offered sides: that value in its
 *   committed spelling (a whole-reference shorthand keeps its var()
 *   spelling — the field maps ref→display and wears it blessed itself);
 * - differing or partly-set sides: uniformState's text (the minimal
 *   shorthand, or the per-side listing) — outside the dimension grammar,
 *   so the field degrades to honest text; typing over it collapses the
 *   sides back into one shorthand ("make all sides equal");
 * - the opaque unsplittable case (calc(), multi-component var()): the
 *   raw shorthand spelling — a set value always stays visible.
 *
 * @param {{set: boolean, uniform: ?string, text: ?string}} state
 *   The uniformState result.
 * @param {*} raw
 *   The raw shorthand value behind the opaque case (the shorthand's
 *   winner at the pose: style-stack.js stackAt).
 *
 * @return {string}
 */
export const uniformFieldText = (state, raw) => {
  if (!state.set) {
    return '';
  }
  if (state.uniform !== null) {
    return state.uniform;
  }
  if (state.text !== null) {
    return state.text;
  }
  return normalizeValue(raw) === null ? '' : String(raw).trim();
};
