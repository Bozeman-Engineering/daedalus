/**
 * @file
 * Projects the width-axis container conditions into canvas width bands.
 *
 * daedalus_ui's canvas state vector renders a band ruler over the
 * resizable canvas; the bands it marks are daedalus_stylish's business (the
 * container-breakpoint conditions and their site-configured thresholds), so
 * daedalus_stylish derives them from the client guide payload's condition table and
 * registers them through the plain global queue
 * (window.DaedalusUiCanvasStops). Only conditions carrying a `band`
 * fact register: the condition type declares one exactly for the bands
 * the page-level query container measures (the ones the canvas resize
 * re-evaluates; ConditionTypeInterface::getConditions()), and a @media
 * width condition (a site-provided viewport vocabulary) declares none
 * because it answers to the viewport, which the popout preview owns. The
 * wrapper text is the printer's business; nothing here reads it.
 *
 * A stop is a BAND, not a threshold: {min?, max?, label, name}, missing
 * edges unbounded. The shipped base-at-top vocabulary projects narrow as
 * {max} and medium as {min, max}; the unconditioned base band above them
 * has no condition, so it never appears here.
 */

/**
 * A finite px edge of a band fact, or undefined when the fact omits it.
 */
const edge = (value) =>
  typeof value === 'number' && Number.isFinite(value) ? value : undefined;

/**
 * Derives width bands from the guide payload's condition table.
 *
 * @param {Object} conditions
 *   drupalSettings.daedalusStylish.guide.conditions: name -> {label, wrapper,
 *   axis, band?} ('default' is a plain string and skipped like any entry
 *   without a band fact).
 *
 * @return {Array}
 *   Bands [{min?, max?, label, name}], ascending. min/max are the band's
 *   inclusive px edges; a missing min extends to zero, a missing max
 *   upward without bound.
 */
export const stopsFromConditions = (conditions) => {
  const stops = [];
  for (const [name, definition] of Object.entries(conditions ?? {})) {
    const band = definition?.axis === 'width' ? definition.band : null;
    if (band === null || typeof band !== 'object') {
      continue;
    }
    const min = edge(band.min);
    const max = edge(band.max);
    if (min === undefined && max === undefined) {
      continue;
    }
    const stop = { label: String(definition.label ?? name), name };
    if (min !== undefined) {
      stop.min = min;
    }
    if (max !== undefined) {
      stop.max = max;
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
