/**
 * @file
 * The choice row's pure half: what a basic-mode row over one property
 * shows and hides, derived from the value-space spine. No DOM, no
 * preact, node-tested (tests/js/choice-row-model.test.mjs); the
 * rendering half is widgets/lib/choice-row.js.
 *
 * The row never picks its surface: value-space.js surfaceFor answers
 * chips / well / field from the space alone. What lives here is the
 * rest of the row's arithmetic: whether the row renders at all, the
 * offer the chips and the well enumerate (one entry per value-space
 * stop, in the widget stop-table shape a pick hands back), the lit
 * state normalized onto that offer, whether the leading grip may
 * scrub the stops detent to detent, the row's origin (the mark's
 * posture, hue and story), and the resting value beneath an unset row
 * (restingValue, faced by ghostFace): the one derivation every ghost
 * reads, in basic and advanced mode alike.
 */

import { normalizeValue, segmentState } from './widget-values.js';
import {
  displayAt,
  referenceVariable,
  stopFace,
  stopLabel,
} from './value-space.js';
import { propertyValues, valueAtPose } from './condition-cascade.js';
import { defaultsAtPose, rowOrigin } from './provenance-model.js';

/**
 * Whether a definition is dimension-shaped: a length or a bare number,
 * the two shapes the dimension field speaks (and the only shapes a basic
 * row may offer as free entry).
 *
 * @param {?object} definition
 *   The property definition.
 *
 * @return {boolean}
 */
export const dimensionShaped = (definition) =>
  definition?.type === 'dimension' || definition?.type === 'number';

/**
 * Whether a space's stops are PAINTED faces (a color or an image
 * shape): the well then wears the lit stop's paint and its popover is
 * the palette grid; every other space faces as text and lists as a
 * plain listbox.
 *
 * @param {?object} definition
 *   The property definition.
 *
 * @return {boolean}
 */
export const paintedSpace = (definition) =>
  definition?.type === 'color' || definition?.media === true;

/**
 * The row's hide rule. A closed space minting nothing (a scale empty on
 * the active skin while constraints are law) has nothing legal to offer,
 * and a free space over a non-dimension property has no vocabulary and
 * no field basic mode may render (free text is advanced mode's): both
 * hide. A committed value is the exception: a set value always stays
 * visible and unsettable, mirroring the advanced rows' hiddenFor rule.
 * A placeholder (the ghost beneath an unset row) never counts as a
 * committed value.
 *
 * @param {{kind: string, stops: Array<object>, definition: ?object}} space
 *   The property's value space (valueSpaceFor).
 * @param {*} value
 *   The committed value.
 * @param {?object} definition
 *   The property definition, defaulting to the space's own.
 *
 * @return {boolean}
 */
export const rowHidden = (space, value, definition = space.definition) =>
  normalizeValue(value) === null &&
  space.stops.length === 0 &&
  (space.kind === 'closed' ||
    (space.kind === 'free' && !dimensionShaped(definition)));

/**
 * The row's OFFER: one entry per value-space stop, in the widget
 * stop-table shape every pick consumer already reads (id, `value` the
 * COMMIT spelling, matches, `label` the chip face) with the paint
 * payload the grid needs, `text`, the plain listbox line (stopLabel:
 * "name · value"), and `display`, the stop's literal display value at
 * the editing condition (a token's minted value, never its var(): the
 * panel sits outside the token scope, so a face that must RENDER the
 * value, the Font row's stack on its own option, reads this and nothing
 * the browser would have to resolve).
 *
 * The widget stop table (propertyStops) and the value space
 * (valueSpaceFor) describe one vocabulary from two sides: the table
 * knows how a committed value lights a stop (matches), the space knows
 * how a stop reads (faces, display values, the collision set). The
 * offer joins them by stop id (the space stop carries the widget's id),
 * so a chip's pick hands back a stop whose `value` is the
 * reference spelling exactly as Segments' consumers expect, while its
 * face is the space's (a name for a shadow token, a value for a radius
 * token, a label twin for a curated literal).
 *
 * @param {{stops: Array<object>}} space
 *   The property's value space.
 * @param {Array<{id: string, value: string, matches: string[]}>} stops
 *   The property's widget stop table (propertyStops).
 *
 * @return {Array<{id: string, value: string, matches: string[],
 *   label: string, text: string, display: string, paint: ?string,
 *   paintDark: ?string, group: ?string, media: boolean,
 *   thumbnail: ?string, number: ?number, unit: ?string}>}
 */
export const choiceStops = (space, stops) =>
  space.stops.map((stop) => {
    const widget = stops.find((candidate) => candidate.id === stop.id) ?? null;
    return {
      id: stop.id,
      value: widget?.value ?? stop.ref,
      matches: widget?.matches ?? [normalizeValue(stop.ref)],
      label: stopFace(stop),
      text: stopLabel(stop),
      display: stop.value,
      paint: stop.paint ?? null,
      paintDark: stop.paintDark ?? null,
      group: stop.group ?? null,
      media: stop.media === true,
      thumbnail: stop.thumbnail ?? null,
      number: stop.number ?? null,
      unit: stop.unit ?? null,
    };
  });

/**
 * The offer entry a widget stop id lights: the entry carrying that id,
 * or the one whose collision set holds it (two tokens sharing one
 * display value collapse into one space stop; a committed twin still
 * lights the shared face).
 */
const litEntry = (space, offer, id) => {
  const direct = offer.find((entry) => entry.id === id);
  if (direct) {
    return direct;
  }
  const collapsed = space.stops.find((stop) =>
    stop.tokens.some((token) => token.ref === id),
  );
  return collapsed
    ? (offer.find((entry) => entry.id === collapsed.id) ?? null)
    : null;
};

/**
 * The row's lit state over the offer: segmentState by default (the
 * committed value against the widget stop table), or a caller-supplied
 * mapping for a gesture that is not "one property, one value"
 * (corners_border's strokeState over the width+style+color stroke,
 * the Layout section's alignmentState). Either way `selected` is normalized onto
 * an OFFER id, so the chips, the well and the listbox agree on which
 * face is lit; a selection the offer cannot show reads as custom, never
 * as nothing. `value` is the raw text the custom pill or face explains
 * (the mapper's own when it carries one, the row's value otherwise),
 * null while nothing is custom.
 *
 * @param {{stops: Array<object>}} space
 *   The property's value space.
 * @param {Array<object>} offer
 *   The row's offer (choiceStops).
 * @param {Array<object>} stops
 *   The widget stop table (segmentState's input).
 * @param {*} value
 *   The committed (draft-layered) value.
 * @param {?{selected: ?string, custom: boolean, value?: ?string}} state
 *   A caller-supplied mapping, or null for segmentState.
 *
 * @return {{selected: ?string, custom: boolean, value: ?string}}
 */
export const choiceState = (space, offer, stops, value, state = null) => {
  const mapped = state ?? segmentState(value, stops);
  const lit =
    mapped.selected === null || mapped.selected === undefined
      ? null
      : litEntry(space, offer, mapped.selected);
  const custom =
    Boolean(mapped.custom) || (mapped.selected != null && lit === null);
  const raw =
    mapped.value ??
    (typeof value === 'string' && value.trim() !== '' ? value : null);
  return {
    selected: custom ? null : (lit?.id ?? null),
    custom,
    value: custom ? raw : null,
  };
};

/**
 * Whether the row's leading grip scrubs the offer detent to detent: a
 * chips or well surface over a numeric ramp (every stop parses, so the
 * stops order by value and a sideways drag means "more" or "less"; a
 * closed radius or type scale). A field surface scrubs inside the field
 * itself; a keyword or shadow vocabulary has no ramp to drag along, so
 * its slot holds the resting ring instead.
 *
 * @param {{stops: Array<{number: ?number}>}} space
 *   The property's value space.
 * @param {string} surface
 *   surfaceFor's answer.
 *
 * @return {boolean}
 */
export const scrubbable = (space, surface) =>
  surface !== 'field' &&
  space.stops.length > 1 &&
  space.stops.every((stop) => stop.number !== null);

/**
 * What reaches a row at the pose: the property's values across the
 * resolved entry, the delivering block at the pose (condition-cascade.js
 * valueAtPose), and the defaults tier's delivering block at the same
 * pose (provenance-model.js defaultsAtPose: the skin's, else the
 * element's declaration's own, through the cascade). Raw values, the
 * var() spelling kept: the one read behind the origin's sentence and
 * the ghost beneath an unset row.
 */
const deliveries = ({ guide, condition, property, entry, provenance }) => {
  const conditions = guide?.conditions ?? {};
  const values = propertyValues(entry, property, conditions);
  return {
    conditions,
    values,
    atPose: valueAtPose(values, condition, conditions),
    defaults: defaultsAtPose(
      provenance.skinEntry,
      provenance.declaredEntry,
      property,
      condition,
      conditions,
    ),
  };
};

/**
 * The resting rule, stated once: the map's delivering value at the
 * pose (the compound's own block, else the inheriting one), else the
 * defaults tier's, else nothing.
 */
const resting = (atPose, defaults) =>
  atPose?.value ?? defaults?.value ?? null;

/**
 * The value a row RESTS on at the pose, raw: what the property shows
 * when the row itself commits nothing at the exact compound. The map's
 * delivering block wins (an own or worn value at the pose is the row's
 * value and the resting value is simply itself; from another condition
 * it is the ghost), else the defaults tier's block reaching the pose
 * (the skin's over the declaration's), else null. Handed raw (the var()
 * spelling kept) so a chip can match it against a stop's commit and
 * ghostFace can face it at the pose; never pre-faced.
 *
 * The one derivation behind every ghost: the basic rows' `origin.ghost`
 * (originFor) and advanced mode's placeholder beneath an uncommitted
 * property row, which gates it behind its own committed value.
 *
 * @param {object} input
 * @param {?object} input.guide
 * @param {string} input.condition
 *   The editing compound (the pose).
 * @param {string} input.property
 * @param {?object} input.entry
 *   The RESOLVED condition-keyed entry the row's value was read from (a
 *   control model's `entry`).
 * @param {{skinEntry: ?object, declaredEntry: ?object}} input.provenance
 *   The bound target's origin inputs (provenance-model.js provenanceFor);
 *   only the two defaults entries are read.
 *
 * @return {?string}
 */
export const restingValue = (input) => {
  const { atPose, defaults } = deliveries(input);
  return resting(atPose, defaults);
};

/**
 * A ghost's display face: the resting value may itself be a reference
 * (a skin's `var(--text-body)`), and the user never sees var() outside
 * code mode, so a reference faces through displayAt at the pose (the
 * raw spelling when the roster cannot resolve it) and a literal faces
 * as itself. Nothing (null, undefined, '') faces as null.
 *
 * @param {?object} guide
 * @param {object} materials
 * @param {*} placeholder
 *   The raw resting value (restingValue, or a caller's own placeholder).
 * @param {string} condition
 *   The editing compound (the pose).
 *
 * @return {?string}
 */
export const ghostFace = (guide, materials, placeholder, condition) => {
  if (placeholder === null || placeholder === undefined || placeholder === '') {
    return null;
  }
  return referenceVariable(placeholder) !== null
    ? (displayAt(guide, materials, placeholder, condition) ??
        String(placeholder))
    : String(placeholder);
};

/**
 * The row's origin from its inputs (deliveries: the values across the
 * resolved entry, the delivering block at the pose, the defaults tier's
 * at the same pose), every value spoken as its display face for the
 * sentence (the field's own ref→display read at the pose, never a var()
 * spelling), plus `ghost`: the RAW resting value (restingValue) the row
 * shows beneath itself while it commits nothing at the pose. One read
 * behind the mark and the ghost, so the sentence's "@value from the
 * active skin" and the empty well beneath it can never disagree.
 *
 * The one derivation behind every basic row's leading mark: the choice
 * row's own postures, and the color widgets' well rows (text_color,
 * background), which are ordinary rows around a well the spine did not
 * pick, so they read their ring's hue and story from the same place.
 *
 * @param {object} input
 * @param {?object} input.guide
 * @param {?object} input.materials
 * @param {string} input.condition
 *   The editing compound (the pose).
 * @param {string} input.property
 * @param {?object} input.entry
 *   The RESOLVED condition-keyed entry the row's value was read from (a
 *   control model's `entry`).
 * @param {object} input.provenance
 *   The bound target's origin inputs (provenance-model.js provenanceFor).
 *
 * @return {object}
 *   rowOrigin() with `ghost` (?string, raw) beside it.
 */
export const originFor = (input) => {
  const { guide, materials, condition, property, provenance } = input;
  const { conditions, values, atPose, defaults } = deliveries(input);
  const faceOf = (raw) =>
    raw == null
      ? raw
      : (displayAt(guide, materials, raw, condition) ?? String(raw));
  return {
    ...rowOrigin({
      property,
      values: values.map((row) => ({ ...row, value: faceOf(row.value) })),
      atPose: atPose ? { ...atPose, value: faceOf(atPose.value) } : null,
      condition,
      conditions,
      own: provenance.own,
      suppliers: provenance.suppliers,
      shadowed: provenance.shadowed,
      defaults: defaults
        ? { ...defaults, value: faceOf(defaults.value) }
        : null,
      skinEditUrl: guide?.skinEditUrl ?? null,
      declaredScope: provenance.declaredScope ?? null,
      labelOf: provenance.labelOf,
      valueOf: (reference, at, name) =>
        faceOf(provenance.valueOf(reference, at, name)),
    }),
    ghost: resting(atPose, defaults),
  };
};
