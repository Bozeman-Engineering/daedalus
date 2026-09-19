/**
 * The gradient builder's pure model half: everything node-testable that
 * gradient-builder.js (the preact component) decides with, kept free of
 * JSX so the test suite can import it directly.
 */

import { defaultGradient, serializeGradient } from './gradient-model.js';
import { paletteFallback, stopsFor } from './widget-values.js';
import { formatColor, parseColor } from './color-field-model.js';
import {
  insertStop,
  removeStop as removeTrackStop,
  trackPercent,
  trackPositions,
} from './track-model.js';

/**
 * The track math the builder reads through its own model: the generic
 * stops track (track-model.js) owns positions, handles and moves; the
 * gradient-specific shapes (a sampled color on insert, the two-stop
 * floor on remove) are below.
 */
export { positionPercent, trackPositions, moveStop } from './track-model.js';

/**
 * A whole var() reference, optional fallback: one var() and nothing else.
 */
export const VAR_REFERENCE = /^var\(\s*(--[A-Za-z0-9_-]+)\s*(?:,[\s\S]*)?\)$/;

/**
 * A fresh gradient seeded from the palette when the constraint-honest
 * stop vocabulary offers anything (the site's own colors make a better
 * starting draft than hardcoded neutrals), else the soft scrim default.
 * One choice seeds both stops (a fade of the same color beats inventing
 * a second one).
 *
 * @param {object} guide
 *   The style guide payload.
 * @param {object} materials
 *   The materials roster.
 * @param {?object} [constraints]
 *   The bound property's guide constraints (the stop vocabulary inherits
 *   them, exactly like a color row would).
 *
 * @return {object}
 *   A gradient-model gradient shape.
 */
export const seedGradient = (guide, materials, constraints = null) => {
  const choices = stopsFor(
    guide,
    materials,
    constraints ?? null,
    paletteFallback(guide, materials),
  );
  if (choices.length) {
    return defaultGradient(choices[0].value, (choices[1] ?? choices[0]).value);
  }
  return defaultGradient();
};

/**
 * The color a new stop wears: sampled between its neighbors.
 *
 * Identical neighbors keep their own spelling (a click between two
 * var(--accent) stops yields the reference, not a resolved hex); parseable
 * paints (color-field-model.js parseColor: hex and rgb()) interpolate
 * linearly; anything the sampler cannot mix falls back to the nearer
 * neighbor's own color. Paints are the caller's resolved
 * face colors (materials.js paintValue record data, never var()
 * resolution here).
 *
 * @param {Array<{color: string, position: ?string}>} stops
 * @param {string[]} paints
 *   Each stop's face paint, parallel to stops.
 * @param {number} percent
 *
 * @return {string}
 */
export const sampleColor = (stops, paints, percent) => {
  const resolved = trackPositions(stops);
  let before = 0;
  let after = stops.length - 1;
  for (let i = 0; i < stops.length; i++) {
    if (resolved[i] <= percent) {
      before = i;
    }
  }
  for (let i = stops.length - 1; i >= 0; i--) {
    if (resolved[i] >= percent) {
      after = i;
    }
  }
  if (stops[before].color === stops[after].color) {
    return stops[before].color;
  }
  const t =
    resolved[after] === resolved[before]
      ? 0
      : (percent - resolved[before]) / (resolved[after] - resolved[before]);
  const a = parseColor(paints[before]);
  const b = parseColor(paints[after]);
  if (a === null || b === null) {
    return t < 0.5 ? stops[before].color : stops[after].color;
  }
  return formatColor(a.map((v, i) => v + (b[i] - v) * t));
};

/**
 * A click on empty track: a new stop at the click, colored by its
 * neighbors, selected (the track model's insertion with a sampled
 * color as the stop's shape).
 *
 * @param {Array<{color: string, position: ?string}>} stops
 * @param {string[]} paints
 *   Each stop's face paint, parallel to stops (sampleColor's input).
 * @param {number} percent
 *
 * @return {{stops: Array, selected: number}}
 */
export const addStop = (stops, paints, percent) =>
  insertStop(
    stops,
    { color: sampleColor(stops, paints, trackPercent(percent)) },
    percent,
  );

/**
 * A removal with the gradient's floor: null below two stops (a gradient
 * needs two); otherwise the track model's selection survival.
 *
 * @param {Array} stops
 * @param {number} index
 *   The removed stop.
 * @param {number} selected
 *   The current selection.
 *
 * @return {?{stops: Array, selected: number}}
 */
export const removeStop = (stops, index, selected) =>
  removeTrackStop(stops, index, selected, 2);

/**
 * The angle knob's reading: a pointer offset from the knob's center as
 * CSS gradient degrees (0 = up = "to top", 90 = right — the compass CSS
 * angles speak).
 *
 * @param {number} dx
 * @param {number} dy
 *
 * @return {number}
 */
export const knobAngle = (dx, dy) =>
  Math.round(((Math.atan2(dy, dx) * 180) / Math.PI + 450) % 360) % 360;

/**
 * The seed gradient as a value string: a mint surface's starting draft.
 *
 * The palette's "New swatch" card needs a committable value before the
 * user touches a control (the builder itself emits only on gestures), and
 * the seed must honor the same stop vocabulary the builder renders with.
 *
 * @param {object} guide
 *   The style guide payload.
 * @param {?object} [constraints]
 *   The bound property's guide constraints.
 *
 * @return {string}
 *   The serialized seed gradient.
 */
export const defaultGradientValue = (guide, materials, constraints = null) =>
  serializeGradient(seedGradient(guide, materials, constraints));
