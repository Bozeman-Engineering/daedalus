/**
 * Pure value logic for the box-model diagram (the spacing widget's
 * per-side half). Preact-free on purpose, like widget-values.js and
 * dimension-value.js: everything here is testable under plain node.
 *
 * The diagram edits PADDING LONGHANDS while the chips keep writing the
 * shorthand, so this module owns the three questions that interplay
 * raises:
 * - what each side's effective value IS (last-declaration-wins over a
 *   condition object, shorthand expanded by the CSS 1/2/3/4 rule);
 * - where a side's value comes FROM (authored on the element / supplied
 *   by a worn variant, with the supplying reference / defaulted by the
 *   active skin / none of them: the computed-placeholder state);
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
  applyStep,
  formatDimension,
  scrubAdvance,
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
 * Physical on purpose: every surveyed builder ships physical
 * sides; logical properties are a later community question.
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
  padding: { shorthand: 'padding', longhand: (side) => `padding-${side}` },
  margin: { shorthand: 'margin', longhand: (side) => `margin-${side}` },
  'border-width': {
    shorthand: 'border-width',
    longhand: (side) => `border-${side}-width`,
  },
  'border-style': {
    shorthand: 'border-style',
    longhand: (side) => `border-${side}-style`,
  },
  'border-color': {
    shorthand: 'border-color',
    longhand: (side) => `border-${side}-color`,
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
 * compares an own declaration with a worn one on it. The chips' side
 * vocabulary is NOT derived here: grantedSides reads the granted
 * longhand NAMES, so a grant of padding-inline still offers no side chip
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
 * shadow the other (variant-scope.js shadowedProperties, wear-bar-model.js
 * resetOverrideOps): an own `padding` masks a worn `padding-inline`
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
 * their component. Values the server passes STRUCTURALLY as one opaque
 * whole — anything containing `var(` or starting with `calc(`, the same
 * case-sensitive checks the server makes — cannot be split honestly (a
 * var() may expand to any number of components), so they return null
 * and the caller renders the honest set-but-unsplittable state rather
 * than a guess.
 *
 * @param {*} value
 *   A committed shorthand value ('1rem', '1rem 2rem', '1px 2px 3px 4px').
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
 * last-declaration-wins: a shorthand overwrites all four sides (a null
 * draft value clears them — the expansion commit's `padding: null`
 * mid-gesture), a longhand overwrites its own. Pass an ORDER-PRESERVING
 * condition object (a map entry's `.default`, an effectiveEntry
 * resolution, a draft-layered copy of one) — model.values is rebuilt in
 * owned-property order and loses the map's order, though under the
 * widget's own commit discipline (expansion always unsets the
 * shorthand) the two never coexist and order is moot.
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
 *   that supplied it. A set-but-unsplittable shorthand (calc()/var())
 *   resolves every side to {value: null, property} — the side IS
 *   styled here, but no per-side value can honestly be shown.
 */
export const resolveSides = (values, property = 'padding') => {
  const sides = { top: null, right: null, bottom: null, left: null };
  Object.entries(values ?? {}).forEach(([name, raw]) => {
    if (name === property) {
      const set = normalizeValue(raw) !== null;
      const expanded = set ? expandShorthand(raw) : null;
      SIDES.forEach((side) => {
        sides[side] = set
          ? { value: expanded ? expanded[side] : null, property }
          : null;
      });
      return;
    }
    const side = SIDES.find(
      (candidate) => name === familyLonghand(property, candidate),
    );
    if (side) {
      sides[side] =
        normalizeValue(raw) !== null
          ? { value: String(raw).trim(), property: name }
          : null;
    }
  });
  return sides;
};

/**
 * Classifies each side's provenance across the element's style layers,
 * the diagram's four states.
 *
 * The own entry's declarations land after every worn variant's in the
 * cascade (an element's own styles beat its references),
 * so a side the OWN layer resolves is 'authored' even when the spelling
 * differs (an own `padding` shorthand resets a variant's `padding-top`,
 * exactly as CSS would). A side only the EFFECTIVE layer resolves came
 * from a worn variant ('variant', carrying the supplying `reference`:
 * the delivery-winning variant the suppliers table names for the
 * declaration that resolved the side, at the editing condition; null
 * when no table attributes it). A side neither resolves but the
 * defaults tier does is the tier's: 'skin' when the active skin's site
 * defaults deliver it, else 'declared' when the element's declaration's
 * own defaults do (variant-scope.js skinDefaultEntry and
 * declaredDefaultEntry: read for provenance, never merged into the
 * effective entry, so they are classified here from their own
 * condition-keyed maps, each resolved on the block it DELIVERS at the
 * pose: condition-cascade.js posedBlock, the same cascade the printer
 * emits, so a skin `padding` at Base still supplies the sides a Medium
 * `padding-top` leaves alone when posed at Medium; the delivering
 * condition rides in `condition`). A side no layer resolves is
 * 'computed': nothing styles it, and the diagram shows the canvas's
 * computed value as a muted placeholder (the caller owns that read;
 * value here is null).
 *
 * The own and effective inputs are the editing compound's values by
 * construction (the diagram edits one compound), so the authored and
 * variant states sit at the pose.
 *
 * @param {?Object<string, *>} own
 *   The element's OWN editing-compound values
 *   (stripVariants(styleMap[key])[condition] — order-preserving).
 * @param {?Object<string, *>} effective
 *   The RESOLVED editing-compound values (effectiveEntry(...).entry
 *   [condition]: variant layers with the own entry merged on top).
 * @param {string} property
 *   The box shorthand family (a BOX_FAMILIES key).
 * @param {object} [tables]
 *   The provenance tables (provenance-model.js provenanceFor, the
 *   widget model's `provenance`):
 *   - suppliers: condition → {property: reference}
 *     (variant-scope.js propertySuppliers), read at `condition`, for
 *     the variant state's reference;
 *   - skinEntry: the skin's condition-keyed property map
 *     (skinDefaultEntry), for the skin state;
 *   - declaredEntry: the declaration's condition-keyed property map
 *     (declaredDefaultEntry), for the declared state beneath it;
 *   - condition: the editing compound ('default');
 *   - conditions: the condition table (guide.conditions), for the
 *     cascade order.
 *   Absent tables classify only authored / variant / computed.
 *
 * @return {Object<string, {state: string, reference: ?string,
 *   condition: ?string, value: ?string, property: ?string}>}
 *   side → {state: 'authored'|'variant'|'skin'|'declared'|'computed',
 *   reference, condition, value, property}. reference names the
 *   supplying variant under 'variant' (null elsewhere); condition is
 *   the delivering condition (the pose for authored and variant, the
 *   defaults tier's delivering block's for skin and declared, null for
 *   computed); value/property follow resolveSides (value null under
 *   'computed', and for a set-but-unsplittable shorthand).
 */
export const sideProvenance = (
  own,
  effective,
  property = 'padding',
  {
    suppliers = null,
    skinEntry = null,
    declaredEntry = null,
    condition = 'default',
    conditions = {},
  } = {},
) => {
  const authored = resolveSides(own, property);
  const resolved = resolveSides(effective, property);
  const skin = resolveSides(
    posedBlock(skinEntry, condition, conditions),
    property,
  );
  const declared = resolveSides(
    posedBlock(declaredEntry, condition, conditions),
    property,
  );
  // The tier's delivering condition for the declaration that resolved a
  // side: the same cascade posedBlock merged, read per property.
  const deliveredFrom = (entry, side) =>
    entryAtPose(entry, side.property, condition, conditions)?.condition ??
    condition;
  const states = {};
  SIDES.forEach((side) => {
    if (authored[side]) {
      states[side] = {
        state: 'authored',
        reference: null,
        condition,
        ...authored[side],
      };
    } else if (resolved[side]) {
      const reference =
        suppliers?.[condition]?.[resolved[side].property] ?? null;
      states[side] = {
        state: 'variant',
        reference,
        condition,
        ...resolved[side],
      };
    } else if (skin[side]) {
      states[side] = {
        state: 'skin',
        reference: null,
        condition: deliveredFrom(skinEntry, skin[side]),
        ...skin[side],
      };
    } else if (declared[side]) {
      states[side] = {
        state: 'declared',
        reference: null,
        condition: deliveredFrom(declaredEntry, declared[side]),
        ...declared[side],
      };
    } else {
      states[side] = {
        state: 'computed',
        reference: null,
        condition: null,
        value: null,
        property: null,
      };
    }
  });
  return states;
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
 * An own shorthand that IS exactly one token reference (var(--name))
 * splits honestly: one reference addresses all four sides identically
 * (uniformState's reading of the same spelling), so every side the
 * shorthand still supplies gets the reference itself, and a guide-scale
 * pick (`border-width: var(--stroke-thin)`) or a skin color
 * (`border-color: var(--color-x)`) never blocks the ring. An own
 * shorthand the expansion cannot honestly split (calc(), a
 * multi-component var()) returns null: committing guessed per-side
 * values would silently change the element, so the caller degrades (the
 * diagram shows the shorthand as one unsplittable value; clearing or
 * retyping it goes through the shorthand's own editor).
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
  // What each side carries on the own layer today: a split component, or
  // the whole reference when the shorthand is one; null for a side the
  // layer no longer styles. An opaque shorthand leaves a styled side with
  // nothing honest to write, and that blocks the whole patch.
  const reference =
    referenceVariable(own[property]) === null
      ? null
      : String(own[property]).trim();
  const supplied = {};
  for (const side of SIDES) {
    supplied[side] =
      current[side] === null ? null : (current[side].value ?? reference);
    if (current[side] !== null && supplied[side] === null) {
      return null;
    }
  }
  const patch = { [property]: null };
  SIDES.forEach((side) => {
    patch[familyLonghand(property, side)] = sides.includes(side)
      ? value
      : supplied[side];
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
 * The chips' longhand-aware view of a box property: whether the style
 * map styles it at all, and whether ONE value honestly represents all
 * four sides.
 *
 * The chips row keeps its one-value vocabulary (a chip writes the
 * shorthand) while the diagram edits longhands, so the chips must read
 * per-side state honestly: four equal sides — however spelled, shorthand
 * or longhands, own or variant-supplied — light the matching stop
 * exactly as a plain shorthand would, and anything per-side is the
 * EXPLICIT custom state, never "nothing set" and never a lie that one
 * chip covers it.
 *
 * @param {?Object<string, *>} own
 *   The element's OWN editing-compound values (order-preserving).
 * @param {?Object<string, *>} effective
 *   The RESOLVED editing-compound values (effectiveEntry(...).entry
 *   ?.[condition]).
 * @param {string} property
 *   The box shorthand family (a BOX_FAMILIES key).
 * @param {string[]} sides
 *   The sides the surface OFFERS (grantedSides()); uniformity, set-ness,
 *   and the custom listing are judged over exactly these, so a
 *   vertical-only grant lights its chip when top and bottom agree no
 *   matter what the ungoverned horizontal sides carry. Defaults to all
 *   four (the ungoverned surface).
 *
 * @return {{set: boolean, uniform: ?string, text: ?string}}
 *   set false: nothing styles any side (chips show nothing selected).
 *   uniform: the single value representing all four sides (feed
 *   segmentState), or null when the sides differ, are only partly set,
 *   or ride an unsplittable calc()/var() shorthand — except a shorthand
 *   that IS exactly one token reference (var(--name)): one reference
 *   addresses all four sides identically, so its spelling surfaces as
 *   the uniform value and a scale token pick lights its chip (the
 *   lit-stop rule is reference-spelling matching). text: display copy
 *   for the custom pill — the minimal shorthand when all four sides are
 *   set, a per-side listing when only some are, null for the opaque
 *   unsplittable case (the caller says calc/var).
 */
export const uniformState = (
  own,
  effective,
  property = 'padding',
  sides = SIDES,
) => {
  // No provenance tables here on purpose: the field's face is what the
  // element and its worn variants SET; a skin default is a ghost, never
  // a set value, so it must not light a chip or fill the custom pill.
  const provenance = sideProvenance(own, effective, property);
  const resolutions = sides.map((side) => provenance[side]);
  if (resolutions.every((resolution) => resolution.state === 'computed')) {
    return { set: false, uniform: null, text: null };
  }
  if (
    resolutions.some(
      (resolution) =>
        resolution.state !== 'computed' && resolution.value === null,
    )
  ) {
    // Set-but-unsplittable: a calc()/var() shorthand styles the sides but
    // no per-side value can honestly be shown. One exception surfaces
    // whole: when every side rides the shorthand declaration itself and
    // its value is exactly one token reference, that reference IS the
    // box's single value — the chips must light it by its spelling, or a
    // scale-constrained token pick would read back as custom. calc() and
    // multi-component var() shorthands stay opaque: no one spelling
    // honestly represents their sides.
    const whole = resolutions.every(
      (resolution) =>
        resolution.state !== 'computed' &&
        resolution.value === null &&
        resolution.property === property,
    );
    if (whole) {
      const raw =
        normalizeValue(own?.[property]) !== null
          ? own[property]
          : effective?.[property];
      const text = String(raw).trim();
      if (referenceVariable(text) !== null) {
        return { set: true, uniform: text, text };
      }
    }
    return { set: true, uniform: null, text: null };
  }
  if (resolutions.some((resolution) => resolution.state === 'computed')) {
    const text = sides
      .filter((side) => provenance[side].state !== 'computed')
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
 * declaration order never decides. A chip writes `padding: value` and
 * unsets whichever longhands the OWN layer carries in the same atomic
 * patch (leaving them would let map order pick a winner); with no own
 * longhands it stays the plain one-property write the row always made.
 * Variant-supplied longhands are untouched: the own shorthand lands
 * after them in the cascade and resets all four sides, exactly as CSS
 * would, and stays detachable. A null value (toggling the lit chip off)
 * unsets the shorthand AND the own longhands — the click said "no
 * padding choice", and leaving longhands would keep sides the chips
 * claimed to clear.
 *
 * @param {?Object<string, *>} own
 *   The element's OWN editing-compound values (order-preserving).
 * @param {?string} value
 *   The chip's canonical value; null unsets.
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
 * The properties object one CHIP pick commits under a SIDE-SUBSET grant.
 *
 * The chips' commit where the guide's allowlist withholds the shorthand
 * (a container's vertical-rhythm spacing): one value writes each offered
 * side's longhand — the shorthand cannot be spelled, so the subset dual
 * of uniformCommit writes longhands directly. An OWN shorthand (legacy
 * data from before the grant narrowed) is unset in the same atomic patch:
 * it is un-respellable here, and dropping it returns the withheld sides
 * to the variant/skin layer that owns them. Own longhands on withheld
 * sides are untouched — the click said nothing about them ("never reset
 * what a click didn't say"). A null value unsets the offered sides (and
 * the own shorthand), the chips' toggle-off.
 *
 * @param {?Object<string, *>} own
 *   The element's OWN editing-compound values (order-preserving).
 * @param {string[]} sides
 *   The offered sides (grantedSides()).
 * @param {?string} value
 *   The chip's canonical value; null unsets.
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
 * 0.1/100 grains are field-editor-only). Feed the result to
 * dimension-value's stepSize/scrubAdvance so the diagram and the field
 * editor share one snapping implementation.
 *
 * @param {{altKey?: boolean, shiftKey?: boolean}} modifiers
 *
 * @return {{shiftKey: boolean}}
 */
export const diagramModifiers = (modifiers = {}) => ({
  shiftKey: Boolean(modifiers.shiftKey) && !modifiers.altKey,
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
 *   unit's precision; grain modifiers stay out — magnets are the grain.
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
    const advanced = magnetAdvance(
      scrub.raw,
      movementX,
      detentsFor(space.stops, scrub.unit),
      {
        perPixel: SCRUB_PER_PIXEL * factor,
        magnet: MAGNET_RADIUS * factor,
        precision: unitPrecision(scrub.unit),
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
 * - nothing set: empty (the field's unset/ghost state);
 * - one value honestly covers the offered sides: that value in its
 *   committed spelling (a whole-reference shorthand keeps its var()
 *   spelling — the field maps ref→display and wears it blessed itself);
 * - differing or partly-set sides: uniformState's text (the minimal
 *   shorthand, or the per-side listing) — outside the dimension grammar,
 *   so the field degrades to honest text; typing over it collapses the
 *   sides back into one shorthand, the old chips' "make all sides equal";
 * - the opaque unsplittable case (calc(), multi-component var()): the
 *   raw shorthand spelling — a set value always stays visible.
 *
 * @param {{set: boolean, uniform: ?string, text: ?string}} state
 *   The uniformState result.
 * @param {*} raw
 *   The raw shorthand value behind the opaque case (own or effective).
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
