/**
 * Pure value logic for a STROKE: a border or an outline read and written
 * as one idea spelled across three CSS properties (width, style, color).
 * Preact-free on purpose, like box-model.js and widget-values.js:
 * everything here is testable under plain node
 * (tests/js/stroke-model.test.mjs).
 *
 * The stroke row renders `[color well][width field][style well]` over
 * one stroke, the box-model ring renders one such row per border side,
 * and both read three questions from here:
 * - what the stroke IS (strokeState: unset, a preset the width row can
 *   name, or the explicit custom state, with a face per part);
 * - what one gesture on a part WRITES (strokeWrite: the completion
 *   rule, so a stroke that would not paint never lands half-formed);
 * - how the four border sides relate across the three families
 *   (strokeSides: per-side provenance and the uniform read; and the two
 *   patch shapes that keep the padding invariant three families wide:
 *   strokeSidesPatch expands to longhands and collapses back,
 *   strokeUniformPatch writes the shorthands over any own longhands).
 *
 * A stroke is named by its PARTS (strokeParts): the three property names
 * the width, style and color ride under. The shorthand strokes are
 * STROKES.border and STROKES.outline; a side's stroke spells its parts
 * through box-model.js familyLonghand, the one place a border side's
 * property is named (the border families ARE the shorthand parts, so
 * `familyLonghand(parts.width, side)` is the side's width). Outline has
 * no sides and no families.
 */

import { normalizeValue, segmentState } from '../../widget-values.js';
import {
  SIDES,
  familyLonghand,
  sideCommit,
  sideProvenance,
  sidesPatch,
  uniformCommit,
  uniformState,
} from './box-model.js';

/**
 * The three parts every stroke has, in the row's reading order for a
 * raw listing (width style color: the `border` shorthand's own order).
 */
export const STROKE_PARTS = ['width', 'style', 'color'];

/**
 * The shorthand strokes: each is the three property names its parts
 * ride under. The border parts double as BOX_FAMILIES keys.
 */
export const STROKES = {
  border: {
    width: 'border-width',
    style: 'border-style',
    color: 'border-color',
  },
  outline: {
    width: 'outline-width',
    style: 'outline-style',
    color: 'outline-color',
  },
};

/**
 * The parts of one stroke: the shorthand stroke, or one border side's
 * longhand stroke.
 *
 * @param {string} stroke
 *   A STROKES key ('border', 'outline').
 * @param {?string} side
 *   A physical side (box-model.js SIDES) for the border's per-side
 *   stroke, or null for the shorthand stroke.
 *
 * @return {{width: string, style: string, color: string}}
 *   part → property name ('border', 'top' → {width: 'border-top-width',
 *   style: 'border-top-style', color: 'border-top-color'}).
 */
export const strokeParts = (stroke, side = null) => {
  const parts = STROKES[stroke];
  if (!parts) {
    throw new Error(`Unknown stroke: ${stroke}`);
  }
  if (side === null) {
    return parts;
  }
  if (stroke !== 'border') {
    throw new Error(`A ${stroke} stroke has no sides`);
  }
  return Object.fromEntries(
    STROKE_PARTS.map((part) => [part, familyLonghand(parts[part], side)]),
  );
};

/**
 * Maps a stroke's committed parts onto its stop rows.
 *
 * The custom-state honesty rule three properties wide: a stroke is
 * unset when no part is set (honestly nothing), a PRESET when every set
 * part is representable in its own vocabulary (the width lights a
 * width stop, the style lights a style stop, the color is unset, which
 * paints currentColor, or lights a color stop), and otherwise the
 * EXPLICIT custom state: a width without a style paints nothing
 * (initial border-style is none), a bare style paints at the UA's
 * medium width, an off-scale width, an unlisted style or a literal
 * color are statements the presets do not make. `selected` names the
 * WIDTH stop (a preset is one idea over the width ramp; style and
 * color ride their wells), so the result also serves as a
 * choice-row-model choiceState mapper. `parts` carries each part's own
 * face for the row's three sub-controls: its trimmed value (null when
 * unset) and its segmentState over its own stops, so a custom stroke
 * still lights the parts that are representable.
 *
 * @param {?Object<string, *>} values
 *   The stroke's (draft-layered) values, keyed by property name.
 * @param {{width: string, style: string, color: string}} parts
 *   The stroke's property names (strokeParts).
 * @param {{width: Array, style: Array, color: Array}} stops
 *   One stop row per part (propertyStops for each; a missing row is
 *   the empty vocabulary, under which any set value is custom).
 *
 * @return {{set: boolean, selected: ?string, custom: boolean,
 *   value: ?string, parts: Object<string, {value: ?string,
 *   selected: ?string, custom: boolean}>}}
 *   value carries the raw set parts (width style color order) for the
 *   custom face, null otherwise.
 */
export const strokeState = (values, parts, stops) => {
  const faces = {};
  STROKE_PARTS.forEach((part) => {
    const raw = values?.[parts[part]];
    const value = normalizeValue(raw) === null ? null : String(raw).trim();
    faces[part] = { value, ...segmentState(raw, stops?.[part] ?? []) };
  });
  const set = STROKE_PARTS.some((part) => faces[part].value !== null);
  if (!set) {
    return {
      set: false,
      selected: null,
      custom: false,
      value: null,
      parts: faces,
    };
  }
  const representable =
    faces.width.selected !== null &&
    faces.style.selected !== null &&
    (faces.color.value === null || faces.color.selected !== null);
  if (representable) {
    return {
      set: true,
      selected: faces.width.selected,
      custom: false,
      value: null,
      parts: faces,
    };
  }
  const value = STROKE_PARTS.map((part) => faces[part].value)
    .filter((part) => part !== null)
    .join(' ');
  return { set: true, selected: null, custom: true, value, parts: faces };
};

/**
 * A stroke gesture's write: the completion rule.
 *
 * A stroke is one statement across three properties, so a part set on
 * its own may land a stroke that does not paint (a width with no style,
 * a color with neither). Every write that SETS a part completes what
 * the stroke would still lack after it: the style with `solid`, the
 * width with the first width stop (the ramp's first preset: thin under
 * the built-in table, the scale's first token under a guide binding;
 * left alone when the row offers no width, the UA's medium then
 * paints), the color never (unset color paints currentColor, the
 * honest resting state). A stroke already carrying a part keeps it: the
 * write is then the part alone, whichever surface made it (the well,
 * the scrubbed field, a typed value). A write that UNSETS a part (null,
 * an emptied field) clears the WHOLE stroke, never a part: the stroke
 * is one idea, so an emptied part says "no stroke", not a stroke with
 * a hole in it. The unset retires only what the stroke actually has
 * set ("never reset what a click didn't say"), so nothing set yields
 * the empty write. Properties outside the stroke pass through
 * untouched beside it.
 *
 * @param {Object<string, ?string>} properties
 *   The gesture's write ({property: value}, null for unset).
 * @param {?Object<string, *>} values
 *   The stroke's (draft-layered) values at the pose: the effective
 *   values (a worn variant's included) for the shorthand row, the
 *   side's for a ring editor.
 * @param {{width: string, style: string, color: string}} parts
 *   The stroke's property names (strokeParts).
 * @param {{width: Array<{value: string}>}} stops
 *   The stop rows (strokeState's); only `width` is read, for the
 *   completing width.
 *
 * @return {Object<string, ?string>}
 *   The properties to commit as one gesture; empty when an unset finds
 *   nothing set.
 */
export const strokeWrite = (properties, values, parts, stops) => {
  const names = STROKE_PARTS.map((part) => parts[part]);
  const entries = Object.entries(properties ?? {});
  const written = entries.filter(([name]) => names.includes(name));
  if (!written.length) {
    return properties;
  }
  const rest = Object.fromEntries(
    entries.filter(([name]) => !names.includes(name)),
  );
  if (written.some(([, value]) => normalizeValue(value) === null)) {
    const retired = names
      .filter((name) => normalizeValue(values?.[name]) !== null)
      .map((name) => [name, null]);
    return { ...rest, ...Object.fromEntries(retired) };
  }
  const write = { ...rest, ...Object.fromEntries(written) };
  const after = (part) =>
    normalizeValue(
      parts[part] in write ? write[parts[part]] : values?.[parts[part]],
    );
  if (after('style') === null) {
    write[parts.style] = 'solid';
  }
  if (after('width') === null && stops?.width?.length) {
    write[parts.width] = stops.width[0].value;
  }
  return write;
};

/**
 * The border stroke's parts as BOX_FAMILIES keys, in part order.
 */
const BORDER_FAMILIES = STROKE_PARTS.map((part) => STROKES.border[part]);

/**
 * The four border sides across the three families: each side's
 * provenance per part, and whether one stroke honestly represents all
 * four sides.
 *
 * box-model.js sideProvenance and uniformState are read once per family
 * and merged, never by composing names: a side's width, style and
 * color each carry their own supplier (a skin strokes the left edge, a
 * variant colors the top, the element authors one width), so the ring
 * cell's hue follows its width and the per-side editor's wells show the
 * other two. `uniform` holds when every family is either unset or one
 * value on all four sides (however spelled: shorthand or longhands, own
 * or variant-supplied; skin defaults are ghosts and never count as set,
 * uniformState's rule), and then `values` is the stroke the shorthand
 * row faces and writes through (strokeState / strokeWrite over
 * STROKES.border), keyed by the three shorthands. A family the sides
 * disagree on reads null there; read `uniform` first, and `families`
 * for each family's own uniformState (its custom text lists the sides).
 *
 * @param {?Object<string, *>} own
 *   The element's OWN editing-compound values (order-preserving).
 * @param {?Object<string, *>} effective
 *   The RESOLVED editing-compound values (effectiveEntry(...).entry
 *   [condition]).
 * @param {object} [tables]
 *   The provenance tables (sideProvenance's: suppliers, skinEntry,
 *   declaredEntry, condition, conditions).
 *
 * @return {{sides: Object<string, {width: object, style: object,
 *   color: object}>, families: Object<string, {set: boolean,
 *   uniform: ?string, text: ?string}>, uniform: boolean,
 *   values: Object<string, ?string>}}
 */
export const strokeSides = (own, effective, tables = {}) => {
  const provenance = {};
  const families = {};
  STROKE_PARTS.forEach((part) => {
    provenance[part] = sideProvenance(
      own,
      effective,
      STROKES.border[part],
      tables,
    );
    families[part] = uniformState(own, effective, STROKES.border[part]);
  });
  const sides = {};
  SIDES.forEach((side) => {
    sides[side] = Object.fromEntries(
      STROKE_PARTS.map((part) => [part, provenance[part][side]]),
    );
  });
  const uniform = STROKE_PARTS.every(
    (part) => !families[part].set || families[part].uniform !== null,
  );
  const values = Object.fromEntries(
    STROKE_PARTS.map((part) => [
      STROKES.border[part],
      families[part].set ? families[part].uniform : null,
    ]),
  );
  return { sides, families, uniform, values };
};

/**
 * The properties object one PER-SIDE stroke gesture commits: the merged
 * three-family patch.
 *
 * Each side's write arrives keyed by the side's longhands (a strokeWrite
 * over strokeParts('border', side)); the patch folds it into
 * box-model.js sidesPatch once per family. The padding invariant holds
 * THREE FAMILIES WIDE: the border is spelled one way on the own layer,
 * the three shorthands or the twelve longhands, never a mix. So the
 * first side gesture expands EVERY own shorthand into its four
 * longhands in the same atomic op, the written family and the untouched
 * ones alike (a right-cell width drag over a shorthand border unsets
 * the three shorthands and writes all twelve longhands), and the
 * families collapse back together: only when every family the own
 * layer spells per side agrees on all four sides do they all fold into
 * their shorthands (uniformCommit per family: the shorthand written,
 * the longhands unset in the same patch), so a ring gesture that evens
 * the whole border out leaves the own layer as a shorthand write would.
 * A family with no own longhand at all (an unset color paints
 * currentColor) has nothing to spell and never holds the others open.
 * Entries that restate the own layer (a value it already carries, an
 * unset of what it never carried) are pruned, so an even-out that
 * changes nothing is the empty patch. Null when an unsplittable own
 * shorthand (calc(), a multi-component var(); a lone token reference
 * splits) blocks per-side editing on any family (sideCommit's
 * degradation signal), judged before the gesture is read so the ring's
 * empty probe answers it too.
 *
 * @param {?Object<string, *>} own
 *   The element's OWN editing-compound values (order-preserving).
 * @param {?Object<string, Object<string, ?string>>} patchesBySide
 *   side → the side's stroke write, keyed by its longhands; a longhand
 *   absent from a side's write leaves that family's side untouched.
 *
 * @return {?Object<string, ?string>}
 *   The update_style properties patch, or null when blocked.
 */
export const strokeSidesPatch = (own, patchesBySide) => {
  if (BORDER_FAMILIES.some((family) => sidesPatch(own, {}, family) === null)) {
    return null;
  }
  const writes = {};
  BORDER_FAMILIES.forEach((family) => {
    writes[family] = {};
    Object.entries(patchesBySide ?? {}).forEach(([side, write]) => {
      const longhand = familyLonghand(family, side);
      if (write && longhand in write) {
        writes[family][side] = write[longhand];
      }
    });
  });
  if (!BORDER_FAMILIES.some((family) => Object.keys(writes[family]).length)) {
    return {};
  }
  let layer = { ...(own ?? {}) };
  const patch = {};
  BORDER_FAMILIES.forEach((family) => {
    // An untouched family still expands its own shorthand (sideCommit
    // over no sides is the bare expansion): it follows the written one
    // into longhands.
    const step = Object.keys(writes[family]).length
      ? sidesPatch(layer, writes[family], family)
      : sideCommit(layer, [], null, family);
    Object.assign(patch, step);
    layer = { ...layer, ...step };
  });
  const spelled = BORDER_FAMILIES.filter((family) =>
    SIDES.some(
      (side) => normalizeValue(layer[familyLonghand(family, side)]) !== null,
    ),
  );
  const agrees = (family) => {
    const spellings = SIDES.map((side) =>
      normalizeValue(layer[familyLonghand(family, side)]),
    );
    return (
      spellings.every((spelling) => spelling !== null) &&
      new Set(spellings).size === 1
    );
  };
  if (spelled.every(agrees)) {
    spelled.forEach((family) => {
      const collapse = uniformCommit(
        layer,
        layer[familyLonghand(family, SIDES[0])],
        family,
      );
      Object.assign(patch, collapse);
      layer = { ...layer, ...collapse };
    });
  }
  Object.keys(patch).forEach((name) => {
    const before =
      normalizeValue(own?.[name]) === null ? null : String(own[name]).trim();
    const after =
      normalizeValue(patch[name]) === null ? null : String(patch[name]).trim();
    if (before === after) {
      delete patch[name];
    }
  });
  return patch;
};

/**
 * One border side's stroke as the ring reads it: the side's three
 * longhands and their values, from a strokeSides read.
 *
 * Each part's value is what its provenance resolved for the side, or
 * the family's uniform read where the per-side resolution is opaque (a
 * lone token reference as the shorthand: `border-color:
 * var(--color-accent)` resolves no side by itself, sideProvenance's
 * rule, but uniformState reads the one reference as every side's
 * value, so the cell and the editor still know the color). By default
 * only the set tiers answer (authored on the element, supplied by a
 * worn variant): the ring's side editor seeds its fields from these,
 * and a defaults-tier value is a ghost it must never settle. `ghosts`
 * adds the skin's and the declaration's values for the cell face, which
 * shows what the side wears whoever supplies it.
 *
 * @param {{sides: object, families: object}} read
 *   A strokeSides result.
 * @param {string} side
 *   A physical side.
 * @param {{ghosts?: boolean}} [options]
 *
 * @return {Object<string, ?string>}
 *   longhand → value (null when unset or unread).
 */
export const strokeSideValues = (read, side, { ghosts = false } = {}) =>
  Object.fromEntries(
    STROKE_PARTS.map((part) => {
      const provenance = read.sides[side][part];
      const tiers = ghosts
        ? ['authored', 'variant', 'skin', 'declared']
        : ['authored', 'variant'];
      const family = read.families[part];
      const value = tiers.includes(provenance.state)
        ? (provenance.value ?? (family.set ? family.uniform : null))
        : null;
      return [familyLonghand(STROKES.border[part], side), value];
    }),
  );

/**
 * The longhands a set of border sides spans across the three families:
 * the ring's highlight claim (a side's cell lights its width, style and
 * color together, since the canvas paints one border band for any of
 * them).
 *
 * @param {string[]} sides
 *
 * @return {string[]}
 */
export const strokeSideLonghands = (sides) =>
  BORDER_FAMILIES.flatMap((family) =>
    sides.map((side) => familyLonghand(family, side)),
  );

/**
 * The properties object the SHORTHAND stroke row commits: each border
 * family's shorthand written with any own longhands collapsed into it
 * (uniformCommit, the inverse of the ring's expansion), so the row's
 * write resets all four sides exactly as CSS would and the own layer
 * never carries both spellings. Properties outside the border families
 * (an outline part, border-radius) pass through as plain writes.
 *
 * @param {?Object<string, *>} own
 *   The element's OWN editing-compound values (order-preserving).
 * @param {Object<string, ?string>} write
 *   The row's write (a strokeWrite over STROKES.border).
 *
 * @return {Object<string, ?string>}
 *   The update_style properties patch.
 */
export const strokeUniformPatch = (own, write) => {
  const patch = {};
  Object.entries(write ?? {}).forEach(([name, value]) => {
    if (BORDER_FAMILIES.includes(name)) {
      Object.assign(patch, uniformCommit(own, value, name));
    } else {
      patch[name] = value;
    }
  });
  return patch;
};
