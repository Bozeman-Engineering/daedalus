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
 * state normalized onto that offer, whether the leading mark may
 * scrub the stops detent to detent, and the row's origin (the mark's
 * supplier, at-pose fact and story), a projection of the row's stack read
 * (style-stack.js stackAt) through provenance-model.js originOf: the
 * shown value is always the winner's, lit, so no row rests on a ghost.
 */

import { normalizeValue, segmentState } from './widget-values.js';
import { displayAt, stopFace, stopLabel } from './value-space.js';
import { originOf } from './provenance-model.js';
import { stackAt } from './style-stack.js';

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
 * hide. A shown value is the exception: a value the row lights (its
 * winner's, whichever layer delivers it) always stays visible,
 * mirroring the advanced rows' hiddenFor rule. A caller's placeholder
 * (a value attributed from outside the stack) never counts.
 *
 * @param {{kind: string, stops: Array<object>, definition: ?object}} space
 *   The property's value space (valueSpaceFor).
 * @param {*} value
 *   The shown value.
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
 * Whether the row's leading mark scrubs the offer detent to detent: a
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
 * The row's origin: the mark's supplier and hue, whether the value is
 * set at the pose, its story, the menu's doorways, its "Also set at"
 * entries and its "Also set on" box, from the row's stack read
 * (provenance-model.js originOf over stackAt), every value spoken as
 * its display face for the sentence (the field's own ref→display read
 * at the pose, never a var() spelling).
 *
 * The one derivation behind every basic row's leading mark: the choice
 * row's own surfaces, the color widgets' well rows (text_color,
 * background), the stroke row's three parts, which are ordinary rows
 * around a well the spine did not pick, so they read their ring's hue
 * and story from the same place. `read` is the row's own read when the
 * caller already holds it (a control model's `reads[property]`); a
 * caller without one (a stroke row over a ring side's longhand, the
 * Layout section's rows) hands the stack alone and the read is taken
 * here.
 *
 * @param {object} input
 * @param {?object} input.guide
 * @param {?object} input.materials
 * @param {string} input.condition
 *   The editing compound (the pose).
 * @param {string} input.property
 * @param {object} input.stack
 *   The bound target's stack (buildStack).
 * @param {?{winner: ?object, beneath: Array<object>, own: ?object}} [input.read]
 *   The property's read at the pose (stackAt), or null to take it here.
 * @param {object} [input.blockTypes]
 *   component uuid → bundle, for the words of a rule a worn value
 *   arrives through (originOf); a caller without the table passes none.
 * @param {object} [input.sectionLayouts]
 *   section uuid → layout id, likewise.
 *
 * @return {object}
 *   originOf()'s shape: {layer, tier, reference, color, atPose,
 *   delivers, title, header, doorways, alsoAt, alsoOn}.
 */
export const originFor = ({
  guide,
  materials,
  condition,
  property,
  stack,
  read = null,
  blockTypes = {},
  sectionLayouts = {},
}) => {
  const conditions = guide?.conditions ?? {};
  const faceOf = (raw) =>
    raw == null
      ? raw
      : (displayAt(guide, materials, raw, condition) ?? String(raw));
  return originOf(read ?? stackAt(stack, property, condition, conditions), {
    stack,
    property,
    pose: condition,
    conditions,
    guide,
    blockTypes,
    sectionLayouts,
    skinEditUrl: guide?.skinEditUrl ?? null,
    faceOf,
  });
};
