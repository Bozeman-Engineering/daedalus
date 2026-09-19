import { h } from 'preact';

/**
 * The segmented-button primitive shared by the stop-table widgets.
 *
 * Renders ONLY the input surface — the label line and hasMore hint are
 * pipeline chrome (basic-controls.js). For a small CLOSED vocabulary
 * only (alignment, a spacing scale): an open vocabulary like the palette
 * belongs behind the swatch well (swatch-well.js), where the enumeration
 * lives in a popover instead of the row. Segment semantics:
 * - Clicking the lit stop toggles it OFF (onPick(null) = unset). It never
 *   rewrites an alias to the canonical value — the honesty rule's "never
 *   normalized on next click".
 * - Values no stop represents render as a disabled "Custom" pill carrying
 *   the raw value; picking a stop over it is an explicit replacement.
 *   Editing the custom value itself belongs to the row's pencil reveal
 *   where one exists (spacing_scale's DimensionField beside this row) —
 *   custom values stay editable without leaving basic mode — and to
 *   advanced mode otherwise; `customTitle` lets a consumer with a reveal
 *   tell that story on the pill.
 */
const SegmentIcon = ({ rects }) => (
  <svg
    className="daedalus-stylish-inspector__segment-icon"
    viewBox="0 0 16 16"
    width="14"
    height="14"
    aria-hidden="true"
    focusable="false"
  >
    {rects.map(([x, y, width, height], index) => (
      <rect
        key={index}
        x={x}
        y={y}
        width={width}
        height={height}
        rx="0.75"
        fill="currentColor"
      />
    ))}
  </svg>
);

/**
 * One segmented button row: the stops, the explicit custom pill.
 *
 * @param {object} props
 * @param {Array} props.stops
 *   The stop table (widget-values.js shape).
 * @param {{selected: ?string, custom: boolean}} props.state
 *   The row's mapped state (segmentState).
 * @param {?string} props.custom
 *   The raw unrepresentable value(s) for the custom pill, or null.
 * @param {Function} props.onPick
 *   Called with the clicked stop, or null when the lit stop was clicked
 *   (toggle off).
 * @param {?Object<string, Array>} [props.icons]
 *   Stop id → SegmentIcon rects; stops with an icon render it instead of
 *   the text label (which stays as the accessible name).
 * @param {?{id: string, value: string}} [props.ghost]
 *   The ghost posture beneath an UNSET row: the stop representing the
 *   resting value the element actually has (a parent's align-items
 *   placing this child, the layer stack's delivery) wears it, unlit and
 *   titled with the value that applies, the chips' twin of the field's
 *   placeholder and the well's ghost face. Ignored while a stop is lit
 *   or a custom value is set.
 * @param {boolean} [props.disabled]
 *   Renders the whole row inert (an axis reality has taken off the table,
 *   e.g. cross-axis alignment under a plain block parent). The reason
 *   rides `title`; the state still renders honestly, so a committed
 *   value that currently does nothing stays visible.
 * @param {boolean} [props.closed]
 *   Whether the property's value space is CLOSED (value-space.js
 *   spaceClosed): the custom pill's default advice then says the
 *   vocabulary is closed here (a pick replaces the value) instead of
 *   sending the user to Advanced to edit a value no mode may author.
 * @param {?string} [props.customTitle]
 *   Overrides the custom pill's tooltip. A row with its own edit path
 *   (the spacing pencil reveal) points at it instead of the default's
 *   "Switch to Advanced" advice.
 * @param {?string} [props.title]
 *   The group's hover title: a disabled row's reason rides here (no
 *   prose inside widgets), so the row explains itself on hover.
 * @param {?Function} [props.faceStyle]
 *   Stop → an inline style the chip wears, or null: the choice row's
 *   text-face seam (a font stop's chip set in the stack it names).
 */
export const Segments = ({
  stops,
  state,
  custom,
  onPick,
  icons = null,
  disabled = false,
  closed = false,
  customTitle = null,
  title = null,
  ghost = null,
  faceStyle = null,
}) => (
  <span
    className="daedalus-stylish-inspector__segments daedalus-ui-segments"
    role="group"
    title={title ?? undefined}
  >
    {stops.map((stop) => {
      const active = state.selected === stop.id;
      const resting =
        !active &&
        !state.custom &&
        state.selected === null &&
        ghost?.id === stop.id;
      const iconRects = icons?.[stop.id] ?? null;
      const stopTitle = (() => {
        if (active && !disabled) {
          return Drupal.t('Unset');
        }
        return resting
          ? Drupal.t('Unset; !value applies', { '!value': ghost.value })
          : Drupal.t(stop.label);
      })();
      return (
        <button
          key={stop.id}
          type="button"
          className={`daedalus-stylish-inspector__segment daedalus-ui-segment${active ? ' daedalus-ui-segment--active' : ''}${resting ? ' daedalus-stylish-inspector__segment--ghost' : ''}`}
          aria-pressed={active}
          aria-label={Drupal.t(stop.label)}
          title={stopTitle}
          style={faceStyle?.(stop) ?? undefined}
          disabled={disabled}
          onClick={() => onPick(active ? null : stop)}
        >
          {iconRects ? <SegmentIcon rects={iconRects} /> : Drupal.t(stop.label)}
        </button>
      );
    })}
    {state.custom && (
      <span
        className="daedalus-stylish-inspector__segment daedalus-ui-segment daedalus-ui-segment--static daedalus-stylish-inspector__segment--custom"
        title={
          customTitle ??
          (closed
            ? Drupal.t(
                "A value these buttons cannot represent is set: !value. This property's vocabulary is closed here, so pick a button to replace it.",
                { '!value': custom },
              )
            : Drupal.t(
                'A value these buttons cannot represent is set: !value. Switch to Advanced to edit it, or pick a button to replace it.',
                { '!value': custom },
              ))
        }
      >
        {Drupal.t('Custom')}
      </span>
    )}
  </span>
);
