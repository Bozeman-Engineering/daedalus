import { h } from 'preact';

/**
 * One payload-painted face chip, scheme pair included.
 *
 * The single split-face renderer, everywhere a face paints from payload
 * data (Segments swatch stops, the Materials Palette's swatch and
 * skin-token
 * faces): `paint` fills the chip; a scheme-paired value (`paintDark`
 * non-null) renders a split face, default top-left / dark bottom-right on
 * the 135deg diagonal, the diagonal itself signaling that the value
 * answers light and dark differently. The dark half is a clip-path'd
 * child, NOT a linear-gradient composition, because a gradient paint
 * (gradient-kind swatch stops) cannot ride inside another gradient's
 * color stop list. Paints are display values from payload data, never
 * var() the browser would resolve, because the panel DOM may sit outside
 * the token scope.
 *
 * @param {object} props
 * @param {string} props.className
 *   The call site's face class(es); the paired modifier joins here.
 * @param {string} props.paint
 *   The default-scheme display value.
 * @param {?string} [props.paintDark]
 *   The scheme-paired display value; null/undefined paints a whole chip.
 */
export const Face = ({ className, paint, paintDark = null }) => (
  <span
    className={`${className}${paintDark != null ? ' daedalus-stylish-face--paired' : ''}`}
    style={{ background: paint }}
  >
    {paintDark != null && (
      <span className="daedalus-stylish-face__dark" style={{ background: paintDark }} />
    )}
  </span>
);
