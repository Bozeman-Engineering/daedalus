import { h } from 'preact';
import { useRef, useState } from 'preact/hooks';
import { magneticCapture, stopLabel } from '../../value-space.js';
import { useStopMenu } from '../../stop-menu.js';

/**
 * TextField: the one text-shape input over the value-space spine, the
 * DimensionField's twin for every property that is not a length or a
 * number (keywords, families, shorthands, the trigger pair, the wide
 * tier).
 *
 * The advanced property rows' input (inspector.js); basic mode never
 * renders free text for a non-dimension property (its choice row,
 * widgets/lib/choice-row.js, offers the space's stops as chips or a
 * well instead), so this is advanced mode's alone. The
 * property's VALUE SPACE, never the enforcement mode, picks the render:
 * - closed (a guide scale/allowed vocabulary while constraints are law):
 *   a select of the space's stops (option value the commit spelling,
 *   text the display value with the material's name when one stands
 *   behind it), the unset option, and the honest extra option for a
 *   shown value the vocabulary does not offer: visible, replaceable,
 *   never editable as free text. The shown value is whatever the
 *   consumer hands in (the advanced row's lit winner, whichever layer
 *   delivers it); no option describes an inherited value, because the
 *   row shows it.
 * - detented / free: the text input in its value box, and on a detented
 *   space the stop picker's chevron after it (stop-menu.js: the curated
 *   offer behind free entry, Alt+ArrowDown inside the input); a stop
 *   pick previews the stop's display value and settles it through the
 *   same path a typed match takes.
 *
 * Every settle is a magnetic capture on a detented space (the
 * DimensionField's doctrine): a settled value that value-matches a stop
 * emits the stop's reference spelling, so typing a swatch's hex IS
 * reaching the swatch and no second write path exists. The component is
 * controlled (`value` is the shown text: the committed value or the
 * consumer's draft echo) and speaks the inspector's preview/settle
 * contract through two callbacks, each consumer keeping its own
 * draft/commit policy:
 * - onPreview(text): per input event and stop pick. Paint only.
 * - onSettle(text): gesture end (blur, Enter, a select change, a stop
 *   pick). The COMMIT spelling: the reference on a capture, the literal
 *   otherwise, '' for an emptied input. The caller commits, or releases
 *   when nothing changed.
 *
 * An optional `face` is text the input wears INSTEAD of the value while
 * it is not focused (the media row's gradient-swatch label over the
 * truncated var() spelling); focus swaps the real value back in, so
 * editing as text stays exactly what it was, and a blur that still
 * reads the face (landing before the focus swap re-rendered) settles
 * nothing. `invalid` is the component-level rejected-commit mark
 * (basic-mode rows have no property wrapper to carry it). `disabled`
 * renders the input inert with `title` as the reason (the panel's
 * write address withholds the property); the shown value still
 * renders honestly.
 */
export const TextField = ({
  label,
  value,
  space,
  face = null,
  title,
  invalid = false,
  disabled = false,
  onPreview,
  onSettle,
}) => {
  const shown = typeof value === 'string' ? value : '';
  const closed = space.kind === 'closed';
  const detented = space.kind === 'detented';
  const [focused, setFocused] = useState(false);
  // The value box the stop picker hangs under.
  const valueRef = useRef(null);

  const settle = (text) => {
    const trimmed = text.trim();
    const captured =
      detented && trimmed !== '' ? magneticCapture(space.stops, trimmed) : null;
    onSettle(captured ? captured.ref : text);
  };

  if (closed) {
    return (
      <select
        className={`daedalus-stylish-inspector__input daedalus-ui-input daedalus-ui-input--select${invalid ? ' daedalus-stylish-text-field--invalid' : ''}`}
        value={shown}
        aria-label={label}
        disabled={disabled}
        title={title}
        onChange={(event) => {
          onPreview(event.target.value);
          settle(event.target.value);
        }}
      >
        <option value="">{Drupal.t('- Unset -')}</option>
        {space.stops.map((stop) => (
          <option key={stop.ref} value={stop.ref}>
            {stopLabel(stop)}
          </option>
        ))}
        {shown !== '' && !space.stops.some((stop) => stop.ref === shown) && (
          <option value={shown}>{shown}</option>
        )}
      </select>
    );
  }

  const wearsFace = face !== null && !focused;
  const stopMenu = useStopMenu({
    stops: detented ? space.stops : [],
    face: wearsFace ? '' : shown,
    anchorRef: valueRef,
    onPick: (stop) => {
      onPreview(stop.value);
      settle(stop.value);
    },
  });
  // A settle that never mistakes the worn face for typed input.
  const settleText = (text) => {
    if (face !== null && text === face) {
      return;
    }
    settle(text);
  };
  return (
    <span
      className={`daedalus-stylish-inspector__value-box${invalid ? ' daedalus-stylish-text-field--invalid' : ''}`}
      ref={valueRef}
    >
      <input
        type="text"
        className={`daedalus-stylish-inspector__input daedalus-ui-input${wearsFace ? ' daedalus-stylish-inspector__input--swatch-face' : ''}`}
        value={wearsFace ? face : shown}
        aria-label={label}
        title={title}
        disabled={disabled}
        onFocus={() => setFocused(true)}
        onInput={(event) => onPreview(event.target.value)}
        onBlur={(event) => {
          setFocused(false);
          settleText(event.target.value);
        }}
        onKeyDown={(event) => {
          if (stopMenu.onInputKeyDown(event)) {
            return;
          }
          if (event.key === 'Enter') {
            settleText(event.target.value);
          }
        }}
      />
      {stopMenu.chevron}
    </span>
  );
};
