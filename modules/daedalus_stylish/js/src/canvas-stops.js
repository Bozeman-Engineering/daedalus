/**
 * @file
 * Projects the width-axis container conditions into canvas width bands.
 *
 * daedalus_ui's canvas state vector renders a band ruler over the
 * resizable canvas; the bands it marks are daedalus_stylish's business (the
 * container-breakpoint conditions and their site-configured thresholds), so
 * daedalus_stylish derives them from the client guide payload's condition table and
 * registers them through the plain global queue
 * (window.DaedalusUiCanvasStops). A width-axis condition registers the
 * band the clause grammar derives from its wrapper (condition-clause.js
 * clauseBand, the one reader of wrapper text): the inclusive px edges of
 * a @container width query, which the page-level query container
 * measures and the canvas resize re-evaluates. A @media width condition
 * (a site-provided viewport vocabulary) derives none because it answers
 * to the viewport, which the popout preview owns; so does an edge in a
 * unit the canvas cannot pose in px.
 *
 * A stop is a BAND, not a threshold: {min?, max?, label, name}, missing
 * edges unbounded. The shipped base-at-top vocabulary projects narrow as
 * {max} and medium as {min, max}; the unconditioned base band above them
 * has no condition, so it never appears here.
 *
 * The published references are the ruler's permanent vocabulary (the
 * queue, drained once). A LITERAL width clause in use on the selected
 * element's rows (editing-at.js literalClauses) is marked on the ruler
 * while it is in use, through the facade's mark() lane (index.js wires
 * the selection and the map to it), projected by literalStops: the same
 * band shape, named by the clause's canonical text and labelled by its
 * edges, since the text is too long for a band's span and the edges are
 * what the ruler is about.
 */

import { WIDTH_AXIS, clauseBand } from '../../../daedalus_ui/js/edit_mode/materials/condition-clause.js';

/**
 * A band's edges as the short range text the chip hints and the ruler
 * labels print: `400-480px`, `≤ 767px`, `≥ 1920px`.
 *
 * @param {{min?: number, max?: number}} band
 */
export const bandRangeText = (band) => {
  if (band.min !== undefined && band.max !== undefined) {
    return `${band.min}-${band.max}px`;
  }
  return band.max !== undefined ? `≤ ${band.max}px` : `≥ ${band.min}px`;
};

/**
 * Projects the literal width clauses in use into ruler bands.
 *
 * @param {Array<Object>} clauses
 *   Literal clause records (editing-at.js literalClauses); those
 *   deriving no band (a @media width, a preference, a state) project
 *   nothing.
 *
 * @return {Array}
 *   Bands [{min?, max?, label, name}] in the given order (the ruler
 *   normalizes and sorts); the name is the clause's canonical text, the
 *   label its range text.
 */
export const literalStops = (clauses) =>
  (clauses ?? [])
    .filter((clause) => clause.band !== null)
    .map((clause) => ({
      ...clause.band,
      label: bandRangeText(clause.band),
      name: clause.text,
    }));

/**
 * Derives width bands from the guide payload's condition table.
 *
 * @param {Object} conditions
 *   drupalSettings.daedalusStylish.guide.conditions: name -> {label, wrapper,
 *   axis} ('default' is a plain string and skipped like any entry off the
 *   width axis).
 *
 * @return {Array}
 *   Bands [{min?, max?, label, name}], ascending. min/max are the band's
 *   inclusive px edges; a missing min extends to zero, a missing max
 *   upward without bound.
 */
export const stopsFromConditions = (conditions) => {
  const stops = [];
  for (const [name, definition] of Object.entries(conditions ?? {})) {
    if (definition?.axis !== WIDTH_AXIS || typeof definition.wrapper !== 'string') {
      continue;
    }
    const band = clauseBand(definition.wrapper);
    if (band === null) {
      continue;
    }
    const stop = { label: String(definition.label ?? name), name };
    if (band.min !== undefined) {
      stop.min = band.min;
    }
    if (band.max !== undefined) {
      stop.max = band.max;
    }
    stops.push(stop);
  }
  // MAX_SAFE_INTEGER, not Infinity: Infinity - Infinity is NaN in a
  // comparator, and two unbounded-max bands must still sort stably.
  return stops.sort(
    (a, b) =>
      (a.min ?? 0) - (b.min ?? 0) ||
      (a.max ?? Number.MAX_SAFE_INTEGER) - (b.max ?? Number.MAX_SAFE_INTEGER),
  );
};

/**
 * The canvas width a band pick poses: the band's midpoint px.
 *
 * The midpoint sits safely inside both edges, renders the band's look
 * unambiguously, and round-trips (widthConditionFor(stopPosePx(stop))
 * is the stop's own condition). A band with no ceiling has no midpoint;
 * its floor is the least surprising pose.
 */
export const stopPosePx = (stop) =>
  stop.max === undefined
    ? stop.min
    : Math.round(((stop.min ?? 0) + stop.max) / 2);
