import { h } from 'preact';

/**
 * The row mark: one glyph on every property row, three channels.
 *
 * - SHAPE says how the value changes: a circle on a pick row (chips, a
 *   well, a keyword), a square of the same footprint on a row that
 *   slides (the mark and the label are the drag surface). The square
 *   means "you can drag this now", so a dimension row whose value is
 *   inert (calc(), var(), a keyword, a withheld write) shows the circle:
 *   the field that owns the scrub stamps the shape on the mark it is
 *   handed (dimension-field.js), the choice row's chips surface reads
 *   its own `live`; never a second derivation.
 * - HUE says who supplies the shown value: the supplying layer's color
 *   (layers.js layerColor: the element, a variant, the skin), on
 *   `--daedalus-stylish-origin`; the faint resting ring when nothing
 *   authored exists for the property.
 * - FILL says whether the value is set at the pose: solid when the
 *   winner's block is the posed compound, hollow in the hue when it
 *   cascades in from another condition, or when a layer authors the
 *   property elsewhere and nothing delivers here.
 *
 * The sentence is the origin's title (provenance-model.js originOf:
 * every origin speaks, "Also set at …" appended). The mark shares
 * daedalus_ui's `.daedalus-ui-dot` footprint; the fill and the square
 * are stylish modifiers on `__mark` (css/inspector.css).
 */

/**
 * The mark's state from a row origin.
 *
 * @param {?object} origin
 *   originOf()'s shape ({color, atPose, delivers, title, …}), or null
 *   when the row has no stack to read (the resting ring, no sentence).
 * @param {boolean} [slide]
 *   Whether the row slides right now (a live scrub).
 *
 * @return {{here: boolean, slide: boolean, color: ?string, title: ?string}}
 *   here: the winner's block is the posed compound (the fill). slide:
 *   the square. color: the supplier's hue, null for the resting ring.
 *   title: the origin's sentence, null without an origin.
 */
export const markState = (origin, slide = false) => ({
  here: origin?.atPose === true,
  slide: slide === true,
  color: origin?.color ?? null,
  title: origin?.title ?? null,
});

/**
 * The mark element. A dimension field clones the vnode it is handed
 * with `slide` set to its live-scrub fact, so a row renders
 * `<Mark origin={origin} />` and never guesses the shape.
 */
export const Mark = ({ origin = null, slide = false }) => {
  const state = markState(origin, slide);
  const classes = [
    'daedalus-stylish-inspector__mark',
    'daedalus-ui-dot',
    state.here ? 'daedalus-stylish-inspector__mark--here' : '',
    state.slide ? 'daedalus-stylish-inspector__mark--slide' : '',
  ]
    .filter(Boolean)
    .join(' ');
  return (
    <span
      className={classes}
      // The hue rides the mark itself, so a mark reads the same wherever
      // a row places it; `initial` leaves the resting ring's fallback in
      // force when nothing is authored, whatever the row's own variable.
      style={{ '--daedalus-stylish-origin': state.color ?? 'initial' }}
      title={state.title ?? undefined}
      aria-hidden="true"
    />
  );
};
