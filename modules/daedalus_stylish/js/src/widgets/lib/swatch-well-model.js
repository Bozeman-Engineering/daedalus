/**
 * The swatch well's pure half: the face derivation.
 *
 * The well renders the CURRENT value; the popover's offer grouping lives
 * with the shared grid (palette-picker-model.js groupedStops). The face
 * derives from the same stop tables the old inline row consumed
 * (widget-values.js stopsFor, widget-gradients.js gradientStops), so
 * constraint honesty is inherited, not re-implemented.
 */

import { normalizeValue } from '../../widget-values.js';

/**
 * The stop a resting value lights: matched the way a committed value
 * lights a stop (its commit spelling, its id, or one of the stop's
 * normalized equivalents), so a skin's var() reference finds the palette
 * stop that commits it and the ghost wears that stop's paint.
 */
const ghostStop = (stops, ghost) => {
  const normalized = normalizeValue(ghost);
  return (
    stops.find(
      (stop) =>
        stop.value === ghost ||
        stop.id === ghost ||
        (stop.matches ?? []).includes(normalized),
    ) ?? null
  );
};

/**
 * What the well's face shows for the row's mapped state.
 *
 * Six faces, mutually exclusive: the selected stop's paint (scheme pair
 * included), the media thumbnail when the selected stop is the synthetic
 * media entry (widget-gradients.js mediaImageStop's `media` flag — a
 * committed url('media:…') background is represented, so it must never
 * wear the checker-"?" custom face), the painted LITERAL face when the
 * custom state is a lone inline color the well can paint (the state
 * read's `literal`: the value wears its own paint under a custom marker,
 * and the well's own color lane edits it), the honest "?" custom
 * face for an opaque compound or a reference no roster knows, the GHOST
 * face when nothing is set but a resting value reaches the row (the
 * matching stop's paint, else the raw value's own, rendered muted), or
 * the empty unset face. Custom outranks selected by construction: the
 * state reads (segmentState and its two-property twins) never set both.
 *
 * The ghost is answered only while the state is unset: the state reads
 * never learn about ghosts, so "set" and "resting" stay distinct for
 * every gesture and gate. It is handed RAW (choice-row-model.js
 * restingValue: the var() spelling kept) so the stop match works the
 * way a committed value's does; the well faces it for its title.
 *
 * @param {Array} stops
 *   The stop table (widget-values.js shape).
 * @param {{selected: ?string, custom: boolean, literal?: ?string}} state
 *   The row's mapped state.
 * @param {?string} [ghost]
 *   The raw resting value beneath an unset row, or null.
 *
 * @return {{kind: 'stop'|'media'|'literal'|'custom'|'ghost'|'unset',
 *   stop: ?object, literal?: string}}
 *   A ghost carries the matching stop, or `literal`, the raw value no
 *   stop represents.
 */
export const wellFace = (stops, state, ghost = null) => {
  if (state.custom) {
    return typeof state.literal === 'string' && state.literal !== ''
      ? { kind: 'literal', stop: null, literal: state.literal }
      : { kind: 'custom', stop: null };
  }
  const stop =
    state.selected === null || state.selected === undefined
      ? null
      : (stops.find((candidate) => candidate.id === state.selected) ?? null);
  if (stop === null) {
    if (typeof ghost !== 'string' || ghost.trim() === '') {
      return { kind: 'unset', stop: null };
    }
    const resting = ghostStop(stops, ghost);
    return resting === null
      ? { kind: 'ghost', stop: null, literal: ghost }
      : { kind: 'ghost', stop: resting };
  }
  return { kind: stop.media === true ? 'media' : 'stop', stop };
};
