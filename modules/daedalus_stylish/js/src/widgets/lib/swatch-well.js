import { h } from 'preact';
import { Face } from '../../face.js';
import { facePair } from '../../materials.js';
import { MediaGlyph } from '../../media-button.js';
import { stopTitle, usePalettePopover } from '../../palette-picker.js';
import { wellFace } from './swatch-well-model.js';

/**
 * The swatch well + palette popover: an unbounded vocabulary behind its
 * current value, and every other door to a value beside it.
 *
 * The color widgets' replacement for the inline Segments track. Segments
 * assumes a small closed vocabulary that fits one line; the palette is
 * open (user-mintable, gradient kinds appended), shared by every
 * color-bearing widget, and clipped at narrow sidebar widths: so the
 * enumeration moves into a popover and the row keeps only the WELL, a
 * face button wearing the row's shown value (the winner's at the pose,
 * whichever layer delivers it: the skin's default lights its stop the
 * way an own value does): the selected stop's paint (split face for
 * scheme pairs, the shared Face renderer), the media thumbnail when the
 * selected stop is the synthetic media entry (wellFace's media kind —
 * the Background widget's url('media:…') posture), the painted LITERAL
 * face for a lone inline color no stop represents (the value wears its
 * own paint from the one face-paint rule, under a custom marker), the
 * painted gradient face when the selected stop is the synthetic gradient
 * entry (wellFace's gradient kind, the Background widget's lone-literal
 * posture: the same paint rule and marker, lit in the grid so its toggle
 * is the unpick, the gradient lane edits it in place), the honest "?"
 * custom face for an opaque compound (the old custom pill's
 * message rides its tooltip), or the slashed empty face when no layer
 * delivers. The well stays a pure OFFER: it never becomes a select of
 * its own value.
 *
 * The well IS the control: its popover carries the palette grid, then
 * the consumer's self-labelled lane row (the color picker, the gradient
 * builder, the media library: every door to a value the grid cannot
 * offer), then whatever the consumer flows beneath (the save-as-swatch
 * mint row). The popover is the shared grid-behind-a-trigger chrome
 * (palette-picker.js usePalettePopover: the floating surface, open/close,
 * dismissal, focus choreography, closing on pick, a lane's run after the
 * close); the well owns only its FACE. Pick semantics are the Segments
 * contract unchanged, carried by the grid: clicking the lit stop calls
 * onPick(null) (unset, never a normalize), any other stop calls
 * onPick(stop), and the consumer's own pick mapping (textPick,
 * backgroundPick) does the rest.
 *
 * A hook rather than one component for the two-slot reason: the well
 * sits inline on the row while the popover floats over the rows below
 * it, and preact renders one subtree per component. Pure client over the
 * stop tables, so it renders in the satellite unchanged.
 *
 * @param {object} options
 * @param {Array} options.stops
 *   The concatenated stop table (stopsFor then gradientStops).
 * @param {{selected: ?string, custom: boolean, value: string,
 *   literal?: ?string}} options.state
 *   The row's mapped state (segmentState or a two-property twin).
 * @param {?string} options.custom
 *   The raw unrepresentable value for the custom face's tooltip, or null.
 * @param {?object} [options.guide]
 *   The style guide payload (the literal face paints through the
 *   materials' face-paint rule).
 * @param {?object} [options.materials]
 *   The materials roster.
 * @param {boolean} [options.closed]
 *   Whether the row's COLOR value space is closed (value-space.js
 *   spaceClosed): the custom and literal tooltips then say a pick is the
 *   only way out, instead of pointing at the well's lanes.
 * @param {Function} options.onPick
 *   Called with the picked stop, or null when the lit stop was picked
 *   (toggle off).
 * @param {Array} [options.lanes]
 *   The popover's lane row (usePalettePopover's `lanes`).
 * @param {?object} [options.below]
 *   What flows under the grid and the lanes (usePalettePopover's
 *   `below`: the mint row).
 * @param {boolean} [options.disabled]
 *   Renders the well inert (the panel's write address withholds the
 *   property); `disabledTitle` is the reason, riding the well's hover
 *   title. The face still renders honestly.
 * @param {?string} [options.disabledTitle]
 *
 * @return {{open: boolean, face: object, well: object, popover: ?object}}
 *   The well button and the (open ? mounted : null) popover, as vnodes
 *   the consumer places; face is wellFace's answer.
 */
export const useSwatchWell = ({
  stops,
  state,
  custom,
  guide = null,
  materials = null,
  closed = false,
  onPick,
  lanes = [],
  below = null,
  disabled = false,
  disabledTitle = null,
}) => {
  const { open, triggerProps, popover } = usePalettePopover({
    stops,
    selected: state.selected ?? null,
    onPick,
    lanes,
    below,
  });
  const face = wellFace(stops, state);
  // A value's own paint (nested references substituted from roster data)
  // for the literal face.
  const paintOf = (value) =>
    materials !== null
      ? facePair(guide, materials, value)
      : { paint: value, paintDark: null };
  const literalPair =
    face.kind === 'literal'
      ? paintOf(face.literal)
      : face.kind === 'gradient'
        ? paintOf(face.gradient)
        : { paint: null, paintDark: null };

  const wellTitle = (() => {
    switch (face.kind) {
      case 'literal':
        return closed
          ? Drupal.t(
              "A custom color is set: !value. This property's palette is closed here, so pick a swatch to replace it.",
              { '!value': face.literal },
            )
          : Drupal.t(
              'A custom color is set: !value. Open the well to edit it, or pick a swatch to replace it.',
              { '!value': face.literal },
            );
      case 'gradient':
        return closed
          ? Drupal.t(
              "A custom gradient is set: !value. This property's palette is closed here, so pick a swatch to replace it.",
              { '!value': face.gradient },
            )
          : Drupal.t(
              'A custom gradient is set: !value. Its lit chip in the palette unsets it; open the well to edit it, or pick a swatch to replace it.',
              { '!value': face.gradient },
            );
      case 'custom':
        return closed
          ? Drupal.t(
              "A value the palette cannot represent is set: !value. This property's palette is closed here, so pick a swatch to replace it.",
              { '!value': custom },
            )
          : Drupal.t(
              'A value the palette cannot represent is set: !value. Open the well to replace it.',
              { '!value': custom },
            );
      case 'media':
        return Drupal.t(
          'An image from the media library. Its lit chip in the palette unsets it; any other pick replaces it.',
        );
      case 'stop':
        return stopTitle(face.stop);
      default:
        return Drupal.t('Pick a palette value');
    }
  })();

  const well = (
    <button
      {...triggerProps}
      type="button"
      className={`daedalus-stylish-swatch-well daedalus-stylish-swatch-well--${face.kind}${open ? ' daedalus-stylish-swatch-well--open' : ''}`}
      title={disabled ? (disabledTitle ?? undefined) : wellTitle}
      disabled={disabled}
    >
      {face.kind === 'stop' && (
        <Face
          className="daedalus-stylish-swatch-well__face"
          paint={face.stop.paint ?? face.stop.value}
          paintDark={face.stop.paintDark}
        />
      )}
      {/* The literal and gradient faces: the value's own paint (nested
          references substituted from roster data) under the custom
          marker the modifier class draws, so a custom color or gradient
          reads as itself, not a question mark. */}
      {(face.kind === 'literal' || face.kind === 'gradient') && (
        <Face
          className="daedalus-stylish-swatch-well__face"
          paint={literalPair.paint}
          paintDark={literalPair.paintDark}
        />
      )}
      {/* The media face: the resolved thumbnail, or the photo glyph when
          the client URL table cannot answer the uuid (a lost file, a
          satellite before its first broadcast) — an honest "an image is
          set", never the checker-"?" custom face. */}
      {face.kind === 'media' &&
        (face.stop.thumbnail !== null ? (
          <img
            className="daedalus-stylish-swatch-well__thumbnail"
            src={face.stop.thumbnail}
            alt=""
          />
        ) : (
          <MediaGlyph className="daedalus-stylish-swatch-well__glyph" />
        ))}
    </button>
  );

  return { open, face, well, popover };
};
