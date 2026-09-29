/**
 * The swatch well's pure half: the face derivation.
 *
 * The well renders the SHOWN value; the popover's offer grouping lives
 * with the shared grid (palette-picker-model.js groupedStops). The face
 * derives from the same stop tables the old inline row consumed
 * (widget-values.js stopsFor, widget-gradients.js gradientStops), so
 * constraint honesty is inherited, not re-implemented.
 */

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
 * and the well's own color lane edits it), the painted GRADIENT face
 * when the selected stop is the synthetic gradient entry
 * (widget-gradients.js gradientImageStop's `gradient` flag: a lone
 * gradient literal no offered stop claims is represented, lit in the
 * grid so its toggle is the unpick, and paints as itself under the same
 * custom marker as the literal face, since it is not from the palette),
 * the honest "?" custom face for an opaque compound or a reference no
 * roster knows, or the empty unset face when no layer delivers the value
 * at all. Custom outranks selected by construction: the state reads
 * (segmentState and its two-property twins) never set both.
 *
 * The state is read over the row's LIT value, the winner's at the pose
 * whichever layer delivers it (a skin's var() lights the palette stop
 * that commits it exactly as an own value does), so the well never
 * needs a resting face beneath an unset one: unset means nothing
 * delivers.
 *
 * @param {Array} stops
 *   The stop table (widget-values.js shape).
 * @param {{selected: ?string, custom: boolean, literal?: ?string}} state
 *   The row's mapped state.
 *
 * @return {{kind: 'stop'|'media'|'literal'|'gradient'|'custom'|'unset',
 *   stop: ?object, literal?: string, gradient?: string}}
 */
export const wellFace = (stops, state) => {
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
    return { kind: 'unset', stop: null };
  }
  if (stop.media === true) {
    return { kind: 'media', stop };
  }
  if (stop.gradient === true) {
    return { kind: 'gradient', stop, gradient: stop.value };
  }
  return { kind: 'stop', stop };
};
