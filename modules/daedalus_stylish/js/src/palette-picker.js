import { h } from 'preact';
import { useEffect, useRef, useState } from 'preact/hooks';
import { Face } from './face.js';
import {
  groupedStops,
  listboxHome,
  listboxTarget,
} from './palette-picker-model.js';
import { usePopover } from './popover.js';

/**
 * The palette's visual offer surface: the grid and its popover posture.
 *
 * Extracted from inspector.js when the gradient builder became the second
 * consumer, then grown into the module every palette offer renders
 * through: PaletteGrid is the shared VISUAL surface (face chips over the
 * paint-carrying stop shape, the swatch well's popover content, the
 * advanced media row's `{}` popover, the gradient builder's stop offer).
 * The offer itself is never derived here: every consumer hands the grid
 * its property's stops (widget-values.js propertyStops / gradientStops),
 * so one value space per property reaches the grid as it reaches every
 * other face. The pure half (grid grouping, listbox navigation) lives in
 * palette-picker-model.js, node-tested.
 */

/**
 * A stop's hover label: its label, scheme pair spelled out when one rides.
 *
 * Shared by the grid's chips (where it rides the chrome tooltip spine,
 * daedalus_ui's data-daedalus-ui-tooltip, so swatches that paint alike still
 * tell apart instantly) and the swatch well's face (the well wears the
 * selected stop, so it tells the same story).
 */
export const stopTitle = (stop) =>
  stop.paintDark != null
    ? Drupal.t('@label: default @default · dark @dark', {
        '@label': Drupal.t(stop.label),
        '@default': stop.paint,
        '@dark': stop.paintDark,
      })
    : Drupal.t(stop.label);

/**
 * The shared visual offer surface: the palette rendered as face chips.
 *
 * Sections per palette group (groupedStops: scales in declaration order,
 * then swatch kinds; a curated `allowed:` list renders headerless), each
 * stop a face chip painting from payload data, never a var() resolution.
 * Pick semantics are the Segments contract unchanged: clicking the lit
 * stop calls onPick(null) (unset, never a normalize), any other stop
 * calls onPick(stop). A consumer with no lit-stop concept passes no
 * `selected` and never receives null. Placement chrome (the swatch
 * well's floating popover, a media row's panel) is the consumer's; the
 * grid is only the offer.
 *
 * A real listbox, not a button pile: the grid replaced a native select
 * (a DELIBERATE zero-popup-machinery a11y choice), so it owes the
 * select's keyboard story back. Roving tabindex (one chip tabbable: the
 * lit stop, else the first — listboxHome), arrows step the flat offer
 * order in both axes, Home/End jump, ends clamp (listboxTarget, the pure
 * half). The chips stay native buttons so Enter/Space activation is the
 * browser's. Focus return on dismissal is the popover consumer's job
 * (the grid does not own a popover); `autoFocus` is the entry half: a
 * popup consumer sets it so opening moves focus onto the roving chip.
 *
 * @param {object} props
 * @param {Array} props.stops
 *   Paint-carrying entries (the widget stop-table shape: id, value,
 *   label, paint, paintDark, group).
 * @param {?string} [props.selected]
 *   The lit stop's id, or null when none is.
 * @param {Function} props.onPick
 *   Called with the picked stop, or null when the lit stop was picked
 *   (toggle off).
 * @param {boolean} [props.autoFocus]
 *   Focus the roving chip on mount (a popup consumer's entry half).
 * @param {?string} [props.label]
 *   The listbox's accessible name; defaults to "Palette".
 */
export const PaletteGrid = ({
  stops,
  selected = null,
  onPick,
  autoFocus = false,
  label = null,
}) => {
  const ids = stops.map((stop) => stop.id);
  // The roving focus target: last chip focus landed on, falling back to
  // listboxHome when focus has not moved yet (or the stop vanished under
  // a palette rebuild).
  const [focusId, setFocusId] = useState(null);
  const chips = useRef(new Map());
  const roving =
    focusId !== null && ids.includes(focusId)
      ? focusId
      : listboxHome(ids, selected);

  useEffect(() => {
    if (autoFocus && roving !== null) {
      chips.current.get(roving)?.focus();
    }
    // Mount-only: entry focus, not a follow-the-roving-chip effect.
  }, []);

  const onKeyDown = (event) => {
    const target = listboxTarget(ids, roving, event.key);
    if (target === null) {
      return;
    }
    // The listbox owns the key: no sidebar scroll, no caret movement,
    // and no edit-mode hotkey (the document dispatcher) acting on the
    // same press.
    event.preventDefault();
    event.stopPropagation();
    setFocusId(target);
    chips.current.get(target)?.focus();
  };

  return (
    <div
      className="daedalus-stylish-palette-grid"
      role="listbox"
      aria-label={label ?? Drupal.t('Palette')}
      onKeyDown={onKeyDown}
    >
      {groupedStops(stops).map((group) => (
        <div
          key={group.label ?? ''}
          className="daedalus-stylish-palette-grid__group"
          role={group.label !== null ? 'group' : undefined}
          aria-label={group.label !== null ? Drupal.t(group.label) : undefined}
        >
          {group.label !== null && (
            <div
              className="daedalus-stylish-palette-grid__group-label"
              aria-hidden="true"
            >
              {Drupal.t(group.label)}
            </div>
          )}
          <div className="daedalus-stylish-palette-grid__stops">
            {group.stops.map((stop) => {
              const active = selected === stop.id;
              return (
                <button
                  key={stop.id}
                  ref={(element) => {
                    if (element) {
                      chips.current.set(stop.id, element);
                    } else {
                      chips.current.delete(stop.id);
                    }
                  }}
                  type="button"
                  role="option"
                  aria-selected={active}
                  tabIndex={stop.id === roving ? 0 : -1}
                  className={`daedalus-stylish-palette-grid__stop${active ? ' daedalus-stylish-palette-grid__stop--active' : ''}`}
                  aria-label={Drupal.t(stop.label)}
                  data-daedalus-ui-tooltip={active ? Drupal.t('Unset') : stopTitle(stop)}
                  data-daedalus-ui-tooltip-position="top"
                  onClick={() => onPick(active ? null : stop)}
                  onFocus={() => setFocusId(stop.id)}
                >
                  <Face
                    className="daedalus-stylish-palette-grid__face"
                    paint={stop.paint ?? stop.value}
                    paintDark={stop.paintDark}
                  />
                </button>
              );
            })}
          </div>
        </div>
      ))}
    </div>
  );
};

/**
 * The grid behind a trigger: the shared popover posture around
 * PaletteGrid.
 *
 * Every popup consumer of the grid owes the same chrome: the floating
 * surface (usePopover, popover.js: toggle on the trigger, the panel
 * floats over the rows below on the shared surface, Escape or an
 * outside press closes, focus returns to the trigger on Escape), focus
 * onto the roving chip on open (the grid's autoFocus) and back onto the
 * trigger on a pick. The palette's own anatomy beyond the grid lives
 * here: the self-labelled lane row (`lanes`) and whatever flows under
 * it (`below`, the mint row). The swatch well is one consumer; an
 * insert-style chip (a media row, the color editor's link gesture) is
 * another: it passes no `selected` and treats its picks as inserts.
 *
 * A hook, not a component, for usePopover's two-slot reason: the
 * trigger stays the consumer's element (it spreads `triggerProps`) while
 * the popover portals into the surface under body.
 *
 * @param {object} options
 * @param {Array} options.stops
 *   Paint-carrying entries (PaletteGrid's shape).
 * @param {?string} [options.selected]
 *   The lit stop's id, or null when the consumer has no lit-stop concept
 *   (its onPick then never receives null).
 * @param {Function} options.onPick
 *   Called with the picked stop (or null on a lit-stop toggle-off, only
 *   when `selected` rides). The popover closes first.
 * @param {?string} [options.label]
 *   The grid's accessible name.
 * @param {Array<{id: string, icon: object, label: string, run: Function,
 *   disabled?: boolean, title?: string}>} [options.lanes]
 *   The self-labelled lane row after the groups: every other door to a
 *   value the grid cannot offer (the native color picker, the gradient
 *   builder, the media library), one pill each, no heading. A lane's
 *   `run` fires AFTER the popover closes, inside the same click, so the
 *   transient user activation the lane needs (showPicker(), a modal)
 *   still holds; a disabled lane renders with its `title` as the reason.
 * @param {?object} [options.below]
 *   Vnodes flowing under the grid and the lanes (the mint row).
 *
 * @return {{open: boolean, triggerProps: object, popover: ?object}}
 */
export const usePalettePopover = ({
  stops,
  selected = null,
  onPick,
  label = null,
  lanes = [],
  below = null,
}) => {
  const { open, setOpen, triggerRef, triggerProps, portal } = usePopover();

  const popover = !open
    ? null
    : portal(
        <div className="daedalus-stylish-swatch-popover">
          <PaletteGrid
            stops={stops}
            selected={selected}
            autoFocus
            label={label}
            onPick={(stop) => {
              setOpen(false);
              triggerRef.current?.focus();
              onPick(stop);
            }}
          />
          {lanes.length > 0 && (
            <div
              className="daedalus-stylish-palette-lanes"
              role="group"
              aria-label={Drupal.t('More ways to set this value')}
            >
              {lanes.map((lane) => (
                <button
                  key={lane.id}
                  type="button"
                  className={`daedalus-stylish-palette-lane daedalus-stylish-palette-lane--${lane.id}`}
                  disabled={Boolean(lane.disabled)}
                  title={lane.title ?? undefined}
                  onClick={() => {
                    // Close, return focus, THEN run: the lane's own surface
                    // (the picker, a builder popover, a modal) takes over
                    // from a closed popover, inside the click's activation.
                    setOpen(false);
                    triggerRef.current?.focus();
                    lane.run();
                  }}
                >
                  <span
                    className="daedalus-stylish-palette-lane__icon"
                    aria-hidden="true"
                  >
                    {lane.icon}
                  </span>
                  <span className="daedalus-stylish-palette-lane__label">
                    {lane.label}
                  </span>
                </button>
              ))}
            </div>
          )}
          {below}
        </div>,
      );

  return { open, triggerProps, popover };
};
