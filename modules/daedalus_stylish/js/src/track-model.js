/**
 * The stops track's pure model: positions on a 0-100 track, generic over
 * the stop shape.
 *
 * Two editors place handles on one horizontal track and read the same
 * math: the gradient builder (stops are `{color, position}`) and the
 * keyframes editor (steps are `{position, declarations}`, the offset
 * being the position). Everything here reads exactly one field,
 * `position` (a raw CSS string, "N%" or anything else), and hands the
 * rest of each stop through untouched, so a stop's own shape (its paint,
 * its declarations) is the consumer's business: insertion takes the new
 * stop's shape, removal takes the consumer's floor (a gradient needs two
 * stops, an animation one step).
 */

/**
 * The percent a raw stop position spells, or null.
 *
 * Only the "N%" spelling maps onto the track's math; every other spelling
 * ("10px", "1em", a two-part position) stays a raw string the numeric
 * controls must not claim, since editing it through a percent would
 * silently rewrite its unit.
 *
 * @param {?string} position
 *   A stop position (raw, possibly null).
 *
 * @return {?number}
 */
export const positionPercent = (position) => {
  const text = typeof position === 'string' ? position.trim() : '';
  const match = text.match(/^(-?(?:\d+\.?\d*|\.\d+))%$/);
  return match ? Number(match[1]) : null;
};

/**
 * Where each stop's handle sits on the track, 0-100.
 *
 * The CSS default-position rules, mirrored for display: a positionless
 * (or non-percent) first stop sits at 0, a positionless last at 100, and
 * interior runs space evenly between their resolved neighbors. Display
 * also mirrors CSS render clamping (each position is at least the one
 * before it, inside [0, 100]) so the handles always match the painted
 * track. Pure display math: the stops' raw positions are never touched.
 *
 * @param {Array<{position: ?string}>} stops
 *
 * @return {number[]}
 */
export const trackPositions = (stops) => {
  const resolved = stops.map((stop) => positionPercent(stop.position));
  if (resolved.length === 0) {
    return [];
  }
  if (resolved[0] === null) {
    resolved[0] = 0;
  }
  if (resolved[resolved.length - 1] === null) {
    resolved[resolved.length - 1] = 100;
  }
  let i = 1;
  while (i < resolved.length - 1) {
    if (resolved[i] !== null) {
      i++;
      continue;
    }
    let j = i;
    while (resolved[j] === null) {
      j++;
    }
    const from = resolved[i - 1];
    const span = j - i + 1;
    for (let k = i; k < j; k++) {
      resolved[k] = from + ((resolved[j] - from) * (k - i + 1)) / span;
    }
    i = j;
  }
  let floor = 0;
  return resolved.map((position) => {
    floor = Math.min(100, Math.max(floor, position));
    return floor;
  });
};

/**
 * A percent clamped to the track and spelled with at most one decimal.
 *
 * @param {number} percent
 *
 * @return {number}
 */
export const trackPercent = (percent) =>
  Number(Math.min(100, Math.max(0, percent)).toFixed(1));

/**
 * The array index where a stop at this percent belongs (after every stop
 * whose display position is at or before it): the sorted-serialization
 * invariant, array order is visual order, so a serialized value never
 * relies on CSS's silent render clamping.
 *
 * @param {Array<{position: ?string}>} stops
 * @param {number} percent
 *
 * @return {number}
 */
export const insertionIndex = (stops, percent) => {
  const resolved = trackPositions(stops);
  for (let i = 0; i < resolved.length; i++) {
    if (resolved[i] > percent) {
      return i;
    }
  }
  return stops.length;
};

/**
 * A drag (or numeric position edit): one stop's position becomes an
 * explicit percent, the gesture's meaning, so a positionless or
 * otherwise-spelled position rewriting to "N%" is honest here and only
 * here. The array reorders to keep visual order, and the selection
 * follows the moved stop. Every other field of every stop rides through
 * verbatim.
 *
 * @param {Array<{position: ?string}>} stops
 * @param {number} index
 *   The moved stop.
 * @param {number} percent
 *   The pointer's track position.
 *
 * @return {{stops: Array, selected: number}}
 */
export const moveStop = (stops, index, percent) => {
  const clamped = trackPercent(percent);
  const moved = { ...stops[index], position: `${clamped}%` };
  const rest = stops.filter((_, n) => n !== index);
  const at = insertionIndex(rest, clamped);
  return {
    stops: [...rest.slice(0, at), moved, ...rest.slice(at)],
    selected: at,
  };
};

/**
 * An insertion: a new stop of the consumer's shape at the percent,
 * sorted into visual order, selected.
 *
 * The shape is whatever the consumer's stop carries besides its position
 * (a sampled color, an empty declarations map); the position is written
 * here as the clamped percent.
 *
 * @param {Array<{position: ?string}>} stops
 * @param {object} shape
 *   The new stop's fields other than `position`.
 * @param {number} percent
 *
 * @return {{stops: Array, selected: number}}
 */
export const insertStop = (stops, shape, percent) => {
  const clamped = trackPercent(percent);
  const stop = { ...shape, position: `${clamped}%` };
  const at = insertionIndex(stops, clamped);
  return {
    stops: [...stops.slice(0, at), stop, ...stops.slice(at)],
    selected: at,
  };
};

/**
 * A removal, with the consumer's floor and selection survival: null at
 * or below the floor; otherwise the selection keeps pointing at the same
 * stop, or the nearest survivor when it was the one removed.
 *
 * @param {Array} stops
 * @param {number} index
 *   The removed stop.
 * @param {number} selected
 *   The current selection.
 * @param {number} minStops
 *   The fewest stops the consumer's value can hold.
 *
 * @return {?{stops: Array, selected: number}}
 */
export const removeStop = (stops, index, selected, minStops) => {
  if (stops.length <= minStops) {
    return null;
  }
  const next = stops.filter((_, n) => n !== index);
  const kept =
    selected === index
      ? Math.min(index, next.length - 1)
      : selected > index
        ? selected - 1
        : selected;
  return { stops: next, selected: kept };
};
