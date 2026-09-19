import { h, Fragment } from 'preact';
import { useEffect, useRef, useState } from 'preact/hooks';
import { normalizeValue, propertyStops } from '../../widget-values.js';
import {
  discreteScrubMove,
  discreteScrubStart,
  surfaceFor,
  valueOrient,
  valueSpaceFor,
  wearing,
} from '../../value-space.js';
import { rowMenu, tokenItems } from '../../provenance-model.js';
import { useMenu } from '../../menu.js';
import { Face } from '../../face.js';
import { PaletteGrid } from '../../palette-picker.js';
import { listboxHome, listboxTarget } from '../../palette-picker-model.js';
import { usePopover } from '../../popover.js';
import {
  choiceState,
  choiceStops,
  ghostFace,
  originFor,
  paintedSpace,
  rowHidden,
  scrubbable,
} from '../../choice-row-model.js';
import { useFieldGesture, useGesture } from './use-gesture.js';
import { DimensionField, useKachunk } from './dimension-field.js';
import { Segments } from './segments.js';
import { wellFace } from './swatch-well-model.js';
import { useRowHighlight } from './use-highlight.js';

/**
 * ChoiceRow: the one basic-mode row over one property, bound through the
 * widget contract, its surface derived on the value-space spine.
 *
 * Every basic row whose gesture is "pick from this property's
 * vocabulary" renders here: the alignment and shadow chips, the closed
 * type scale's well, the width, gap and radius fields. The row derives
 * the property's value space itself (guide + roster + the property's
 * constraints and its one fallback) and asks value-space.js surfaceFor
 * which surface that space earns; a widget never chooses (the
 * vocabulary is site data, so its size and readability are only known
 * at render, and a widget-side choice is wrong for the next skin):
 * - chips (Segments): the whole vocabulary in the row, at most five
 *   stops with short or icon faces. Clicking the lit chip unsets; a
 *   value no chip represents is the explicit custom pill.
 * - the choice well: one face wearing the current value (the lit stop's
 *   paint on a color or image space, its text face otherwise; "Custom"
 *   for an unrepresentable value; the ghost beneath an unset row), a
 *   popover with the whole roster behind it (the palette grid for
 *   painted stops, a listbox of "name · value" rows with the lit stop
 *   checked otherwise). Picking the lit stop unsets, as on the chips.
 * - the dimension field: a length or number property whose space is
 *   NOT closed, the scrubbable DimensionField the advanced rows render
 *   (free entry with sticky magnets on a detented space). Basic mode
 *   never renders a free text input for any other shape: a
 *   non-dimension property with nothing to offer hides (choice-row-
 *   model.js rowHidden), as does a closed space minting nothing, unless
 *   a committed value keeps the row visible.
 * Unset is one gesture everywhere: the lit chip, the lit stop in the
 * well, the emptied field, the menu's unset doorway.
 *
 * The leading slot is the row's provenance mark. On a chips or well
 * surface over a numeric ramp (a closed radius or type scale) it is the
 * GRIP, and dragging it scrubs the stops detent to detent with the
 * ka-chunk (value-space.js discreteScrubStart/Move), so nothing the
 * closed dimension field could do is lost when its faces outgrow the
 * chips; over a keyword or shadow vocabulary it is the resting ring;
 * on the field surface the field's own grip fills it. Either mark
 * paints in the origin's hue (`--daedalus-stylish-origin` on the row) and wears
 * the origin's hover story.
 *
 * Picks. Without `onPick` the row IS the gesture: a pick stages the
 * optimistic paint and commits `{[property]: stop.value}` through
 * `onCommit` (useGesture, one op per pick, the draft lighting the stop
 * before the round trip; a rejected commit marks the row invalid). A
 * widget whose gesture is not "one property, one value" supplies
 * `onPick(stop | null)` and does its own writing (a pair write, a batch
 * with companions), handing the row the draft-layered `value` it reads
 * state from; `state` is its own mapping over the widget stop table
 * where segmentState over that value would lie (corners_border's
 * strokeState over the width+style+color stroke, the Layout section's
 * alignmentState), else the row maps the value itself. `stop` is the
 * offer entry, whose `value` is the COMMIT spelling exactly as Segments'
 * consumers read it. The field surface always speaks the field contract
 * (useFieldGesture over onPreview / onRelease / onCommit): preview per
 * input, one commit on settle, the emptied field committing null; a pair
 * widget wraps those three so a typed value carries its partner too.
 *
 * `fallback` is propertyStops' one exception: an axis-relative built-in
 * table (the position and alignment rows face one value set per screen
 * axis, so the table cannot live under the property's name) standing in
 * for the property's own fallback; a guide constraint still displaces
 * it.
 *
 * `disabled` renders a chips or well surface inert (an axis reality has
 * taken off the table); `disabledTitle` is the reason, riding the
 * surface's hover title, since no prose lives inside a widget. The
 * state still renders honestly, so a committed value that currently
 * does nothing stays visible. `icons` is the chip icon map keyed by
 * stop id (Segments' contract; surfaceFor reads the same map so an icon
 * face is always within the chip budget).
 *
 * The ghost beneath an unset row is the row's own. It is the resting
 * value the element actually has, read from the same origin as the
 * mark (choice-row-model.js originFor's `ghost`, restingValue beneath
 * it: the map's delivering block at the pose, else the defaults tier,
 * a skin's `base:p` font-size or a declared default), never a committed
 * value, so a widget wires nothing to show it: the field shows it as
 * its placeholder, the well as its ghost face, and the chips wear it on
 * the stop that represents it (Segments' ghost posture: unlit, titled
 * with the value that applies). `placeholder` is a caller's OWN resting
 * value and beats the derived ghost when supplied, for the one reason
 * the derivation cannot know: a source outside the layer stack (the
 * position row's parent-attributed axis value, the Layout section's
 * arrangement gap and its floor). Handed RAW in the var() spelling and
 * faced at the surface, like the derived one. `highlight` ({source,
 * key, sub}) makes the row a canvas-highlight consumer
 * (useRowHighlight). `faceStyle(stop)`
 * is the one seam a text face renders THROUGH: an inline style for an
 * offer entry's text (a chip, a listbox option, the well's lit face), or
 * null for the panel's own type; the Font row hands each stop its own
 * literal stack (`stop.display`, never a var() the panel could not
 * resolve outside the token scope) so a face wears the family it names.
 *
 * Provenance (choice-row-model.js originFor over provenance-model.js
 * rowOrigin), the same story the
 * advanced rows tell: `provenance` is the bound target's origin inputs
 * ({own, suppliers, shadowed, skinEntry, declaredEntry, declaredScope,
 * labelOf, valueOf}, a control model's `provenance` or the Layout
 * section's own assembly) and `entry`
 * the RESOLVED condition-keyed entry the row's value was read from; the
 * row reads the property's values across it, finds the block delivering
 * at the pose, and asks the model who sets it there. The origin renders
 * as the mark's hue and hover title and as the row's right-click menu on
 * every surface: the origin in words, the doorways (drive the canvas to
 * the delivering condition, open the supplying variant there through
 * `onOpenDocument`, unset so the beaten layer returns, edit the skin),
 * every other condition carrying a value (driving the canvas through
 * `onPickCondition`), then on the field surface the field's token items
 * (follow a design-system stop or freeze the literal; a pick surface
 * commits references by construction, so it offers none). Without
 * `provenance` the row has no origin story: the field's menu is its
 * tokens alone and the native menu rides through elsewhere.
 */
export const ChoiceRow = ({
  guide,
  materials,
  condition = 'default',
  label,
  property,
  value,
  definition = {},
  constraints = {},
  entry = null,
  provenance = null,
  placeholder,
  fallback = null,
  icons = null,
  disabled = false,
  disabledTitle = null,
  state = null,
  faceStyle = null,
  onPick = null,
  onPreview,
  onRelease,
  onCommit,
  onPickCondition = null,
  onOpenDocument = null,
  highlight = null,
}) => {
  const space = valueSpaceFor(
    guide,
    materials,
    property,
    definition,
    constraints,
    condition,
    fallback,
  );
  const surface = surfaceFor(space, { icons, definition });
  const stops = propertyStops(
    guide,
    materials,
    property,
    definition,
    constraints,
    fallback,
  );
  const offer = choiceStops(space, stops);
  const painted = paintedSpace(definition);
  const closed = space.kind === 'closed';
  const scrubs = scrubbable(space, surface);
  const hidden = rowHidden(space, value, definition);

  // Every hook runs on every render, before the hide rule can return and
  // whatever the surface: the row's posture may change render to render
  // (a value set, a skin swapped) and the hook order must not.
  const field = useFieldGesture(
    value,
    property,
    onPreview,
    onRelease,
    onCommit,
  );
  const gesture = useGesture(
    { key: property, values: { [property]: value } },
    onPreview,
    onCommit,
  );
  const rowHighlight = useRowHighlight(
    highlight?.source ?? null,
    highlight?.key ?? null,
    highlight?.sub ?? null,
    [property],
  );
  const menu = useMenu();
  const [chunk, kachunk] = useKachunk();
  const scrubRef = useRef(null);
  const [scrubbing, setScrubbing] = useState(false);

  // The lit state twice: over the COMMITTED value (where a scrub starts
  // and what a release compares against) and over the draft-layered one
  // (what the faces show mid-gesture). A widget owning its own pick
  // mapping hands the row the committed value it reads state from, so
  // the two coincide there.
  const committed = choiceState(space, offer, stops, value, state);
  const lit = onPick
    ? committed
    : choiceState(space, offer, stops, gesture.values[property], state);
  const litIndex =
    lit.selected === null
      ? -1
      : offer.findIndex((stop) => stop.id === lit.selected);

  const choose = (stop) => {
    if (onPick) {
      onPick(stop);
      return;
    }
    gesture.pick({ [property]: stop ? stop.value : null });
  };
  const preview = (stop) => {
    if (onPick) {
      onPreview?.(property, stop.value);
      return;
    }
    gesture.stage({ [property]: stop.value });
  };

  const origin = provenance
    ? originFor({ guide, materials, condition, property, entry, provenance })
    : null;
  const style = origin?.color
    ? { '--daedalus-stylish-origin': origin.color }
    : undefined;
  const onDoorway = (doorway) => {
    switch (doorway.id) {
      case 'drive':
        onPickCondition?.(doorway.condition);
        break;
      case 'document':
        onOpenDocument?.(doorway.reference, doorway.condition);
        break;
      case 'unset':
        if (surface === 'field') {
          field.settle('');
        } else {
          choose(null);
        }
        break;
      case 'skin':
        window.open(doorway.url, '_blank', 'noopener');
        break;
      default:
        break;
    }
  };
  const openMenu = (event, tokens, face) => {
    const spec = rowMenu({
      origin,
      tokens,
      face,
      onDoorway,
      onCondition: onPickCondition,
    });
    if (spec && menu.open({ x: event.clientX, y: event.clientY, ...spec })) {
      event.preventDefault();
    }
  };

  const surfaceTitle = disabled ? (disabledTitle ?? undefined) : undefined;
  // The row's resting value, RAW (the var() spelling the chip match and
  // the face need): a caller's own placeholder when it has a source the
  // derivation lacks, else the origin's ghost, the same read the mark's
  // hue and sentence come from (choice-row-model.js restingValue), so
  // the two can never disagree.
  const resting = placeholder ?? origin?.ghost ?? null;
  const ghost = ghostFace(guide, materials, resting, condition);
  // The chips' ghost posture: the offer stop the resting value lights
  // beneath an unset row, matched the way a committed value lights a
  // chip (its commit spelling or one of the stop's equivalents).
  const ghostStop =
    ghost === null
      ? null
      : (offer.find(
          (stop) =>
            stop.value === resting ||
            stop.matches.includes(normalizeValue(resting)),
        ) ?? null);
  const well = useChoiceWell({
    offer,
    lit,
    painted,
    closed,
    label,
    ghost,
    disabled,
    title: surfaceTitle,
    chunk,
    faceStyle,
    onPick: choose,
  });

  if (hidden) {
    return null;
  }

  const invalid =
    surface === 'field' ? field.invalid : !onPick && gesture.invalid;
  const rowClasses = `daedalus-stylish-inspector__widget-row${invalid ? ' daedalus-stylish-inspector__widget-row--invalid' : ''}`;

  if (surface === 'field') {
    const onContextMenu = (event) =>
      openMenu(
        event,
        tokenItems(guide, materials, condition, space, field.shown, {
          onPreview: field.preview,
          onSettle: field.settle,
        }),
        wearing(guide, materials, space, field.shown, condition).face ||
          field.shown,
      );
    return (
      <div
        className={rowClasses}
        style={style}
        onContextMenu={onContextMenu}
        {...rowHighlight}
      >
        <DimensionField
          label={label}
          value={field.shown}
          property={property}
          definition={definition}
          constraints={constraints}
          guide={guide}
          materials={materials}
          condition={condition}
          placeholder={resting}
          invalid={field.invalid}
          gripTitle={origin?.title}
          onPreview={field.preview}
          onSettle={field.settle}
        />
      </div>
    );
  }

  // --- the detent scrub on the grip (a numeric ramp behind chips or the
  // well): half-band switching, pinned ends, preview per detent, the
  // pick on release. Value direction is the ramp's (valueOrient), so a
  // rightward drag always means "more". Entering the ramp from unset
  // starts at the value-low end, as the closed field does.
  const live = scrubs && !disabled;
  const committedIndex =
    committed.selected === null
      ? -1
      : offer.findIndex((stop) => stop.id === committed.selected);
  const orient = valueOrient(offer);
  const lowEnd = orient === 1 ? 0 : offer.length - 1;
  const onScrubDown = (event) => {
    if (!live) {
      return;
    }
    event.preventDefault();
    event.currentTarget.setPointerCapture(event.pointerId);
    const start = committedIndex === -1 ? lowEnd : committedIndex;
    scrubRef.current = {
      raw: discreteScrubStart(start),
      index: start,
      moved: false,
    };
    setScrubbing(true);
  };
  const onScrubMove = (event) => {
    const scrub = scrubRef.current;
    if (!scrub || !event.currentTarget.hasPointerCapture(event.pointerId)) {
      return;
    }
    const advanced = discreteScrubMove(
      scrub.raw,
      event.movementX * orient,
      offer.length,
    );
    scrub.raw = advanced.raw;
    if (
      advanced.index !== scrub.index ||
      (!scrub.moved && committedIndex === -1)
    ) {
      scrub.index = advanced.index;
      scrub.moved = true;
      kachunk();
      preview(offer[advanced.index]);
    }
  };
  const onScrubUp = (event) => {
    const scrub = scrubRef.current;
    if (!scrub) {
      return;
    }
    if (event.currentTarget.hasPointerCapture(event.pointerId)) {
      event.currentTarget.releasePointerCapture(event.pointerId);
    }
    scrubRef.current = null;
    setScrubbing(false);
    if (!scrub.moved) {
      return;
    }
    const landed = offer[scrub.index];
    if (landed.id === committed.selected) {
      // Back where it started: nothing to persist, release the staged
      // preview instead of re-committing the lit stop.
      onRelease?.({ [property]: landed.value });
      return;
    }
    choose(landed);
  };

  const face =
    lit.selected !== null ? (offer[litIndex]?.label ?? '') : (lit.value ?? '');
  const onContextMenu = (event) => openMenu(event, [], face);
  const scrubClasses = [
    'daedalus-stylish-dimension__scrub',
    scrubbing ? 'daedalus-stylish-dimension__scrub--active' : '',
    !live ? 'daedalus-stylish-dimension__scrub--inert' : '',
  ]
    .filter(Boolean)
    .join(' ');

  return (
    <Fragment>
      <div
        className={rowClasses}
        style={style}
        onContextMenu={onContextMenu}
        {...rowHighlight}
      >
        {scrubs ? (
          <span
            className={scrubClasses}
            title={
              live
                ? Drupal.t('Drag sideways to step through the allowed values')
                : undefined
            }
            onPointerDown={onScrubDown}
            onPointerMove={onScrubMove}
            onPointerUp={onScrubUp}
            onPointerCancel={onScrubUp}
          >
            <span
              className="daedalus-stylish-dimension__grip"
              title={origin?.title}
              aria-hidden="true"
            >
              ⣿
            </span>
            <span className="daedalus-stylish-inspector__widget-axis">{label}</span>
          </span>
        ) : (
          <Fragment>
            <span
              className="daedalus-stylish-inspector__mark daedalus-ui-dot"
              title={origin?.title}
              aria-hidden="true"
            />
            <span className="daedalus-stylish-inspector__widget-axis">{label}</span>
          </Fragment>
        )}
        {surface === 'chips' ? (
          <Segments
            stops={offer}
            state={lit}
            custom={lit.value}
            ghost={ghostStop ? { id: ghostStop.id, value: ghost } : null}
            icons={icons}
            disabled={disabled}
            closed={closed}
            title={surfaceTitle}
            faceStyle={faceStyle}
            onPick={choose}
          />
        ) : (
          well.well
        )}
      </div>
      {surface === 'well' && well.popover}
    </Fragment>
  );
};

/**
 * The choice well + its popover: the well surface's two slots (the face
 * button inline on the row, the popover floating over the rows below on
 * the shared surface), the swatch well's posture over a text or painted
 * vocabulary. The
 * face is wellFace's answer over the offer (the lit stop, custom, or
 * unset; a literal color reads as custom here, the choice well has no
 * paint lane); the popover is the shared palette grid for painted
 * stops and the choice listbox otherwise, either closing on a pick and
 * returning focus to the well. Dismissal is usePopover's (Escape, an
 * outside press). `faceStyle` is the row's text-face seam, worn by the
 * lit stop's face and handed on to the listbox. A hook, not a
 * component, for the two-slot reason: preact renders one subtree per
 * component, and the row places the slots apart.
 */
const useChoiceWell = ({
  offer,
  lit,
  painted,
  closed,
  label,
  ghost,
  disabled,
  title,
  chunk,
  faceStyle,
  onPick,
}) => {
  const { open, setOpen, triggerRef, triggerProps, portal } = usePopover();
  const face = wellFace(offer, lit);
  const kind = face.kind === 'literal' ? 'custom' : face.kind;
  const pickAndClose = (stop) => {
    setOpen(false);
    triggerRef.current?.focus();
    onPick(stop);
  };
  const wellTitle = (() => {
    if (title) {
      return title;
    }
    switch (kind) {
      case 'custom':
        return closed
          ? Drupal.t(
              "A value these choices cannot represent is set: !value. This property's vocabulary is closed here, so pick a value to replace it.",
              { '!value': lit.value },
            )
          : Drupal.t(
              'A value these choices cannot represent is set: !value. Switch to Advanced to edit it, or pick a value to replace it.',
              { '!value': lit.value },
            );
      case 'stop':
      case 'media':
        return face.stop.text;
      default:
        return ghost === null
          ? Drupal.t('Pick a value')
          : Drupal.t('Unset; !value applies', { '!value': ghost });
    }
  })();
  const classes = [
    'daedalus-stylish-choice-well',
    `daedalus-stylish-choice-well--${kind}`,
    painted ? 'daedalus-stylish-choice-well--painted' : '',
    open ? 'daedalus-stylish-choice-well--open' : '',
    chunk ? 'daedalus-stylish-choice-well--kachunk' : '',
  ]
    .filter(Boolean)
    .join(' ');

  const well = (
    <button
      {...triggerProps}
      type="button"
      className={classes}
      disabled={disabled}
      aria-label={label}
      title={wellTitle}
    >
      {(kind === 'stop' || kind === 'media') &&
        (painted ? (
          face.stop.media && face.stop.thumbnail !== null ? (
            <img
              className="daedalus-stylish-choice-well__face daedalus-stylish-choice-well__thumbnail"
              src={face.stop.thumbnail}
              alt=""
            />
          ) : (
            <Face
              className="daedalus-stylish-choice-well__face"
              paint={face.stop.paint ?? face.stop.value}
              paintDark={face.stop.paintDark}
            />
          )
        ) : (
          <span
            className="daedalus-stylish-choice-well__text"
            style={faceStyle?.(face.stop) ?? undefined}
          >
            {Drupal.t(face.stop.label)}
          </span>
        ))}
      {kind === 'custom' && (
        <span className="daedalus-stylish-choice-well__text daedalus-stylish-choice-well__text--custom">
          {Drupal.t('Custom')}
        </span>
      )}
      {kind === 'unset' && (
        <span className="daedalus-stylish-choice-well__text daedalus-stylish-choice-well__text--ghost">
          {ghost ?? '–'}
        </span>
      )}
      <span className="daedalus-stylish-choice-well__chevron" aria-hidden="true">
        ▾
      </span>
    </button>
  );

  const popover = !open
    ? null
    : portal(
        <div className="daedalus-stylish-swatch-popover daedalus-stylish-choice-popover">
          {painted ? (
            <PaletteGrid
              stops={offer}
              selected={lit.selected}
              autoFocus
              label={label}
              onPick={pickAndClose}
            />
          ) : (
            <ChoiceList
              offer={offer}
              selected={lit.selected}
              label={label}
              faceStyle={faceStyle}
              onPick={pickAndClose}
            />
          )}
        </div>,
      );

  return { open, well, popover };
};

/**
 * The plain listbox behind a text-faced well: one row per offer entry
 * (stopLabel's "name · value"), the lit stop checked, the palette grid's
 * keyboard story (roving tabindex homing on the lit stop, arrows step,
 * Home/End jump, ends clamp; palette-picker-model.js) and its pick
 * contract (the lit row unsets). Two face seams: `glyph(stop)` is a
 * vnode drawn before the row's text (the stroke row's style well draws
 * each line style as the line itself), null for a text-only row;
 * `faceStyle(stop)` is an inline style the option itself wears (the
 * Font row's option set in the stack it names), null for the panel's
 * own type.
 */
export const ChoiceList = ({
  offer,
  selected,
  label,
  glyph = null,
  faceStyle = null,
  onPick,
}) => {
  const ids = offer.map((stop) => stop.id);
  const [focusId, setFocusId] = useState(null);
  const options = useRef(new Map());
  const roving =
    focusId !== null && ids.includes(focusId)
      ? focusId
      : listboxHome(ids, selected);

  useEffect(() => {
    if (roving !== null) {
      options.current.get(roving)?.focus();
    }
    // Mount-only: entry focus, not a follow-the-roving-row effect.
  }, []);

  const onKeyDown = (event) => {
    const target = listboxTarget(ids, roving, event.key);
    if (target === null) {
      return;
    }
    event.preventDefault();
    event.stopPropagation();
    setFocusId(target);
    options.current.get(target)?.focus();
  };

  return (
    <div
      className="daedalus-stylish-choice-list"
      role="listbox"
      aria-label={label}
      onKeyDown={onKeyDown}
    >
      {offer.map((stop) => {
        const active = selected === stop.id;
        return (
          <button
            key={stop.id}
            ref={(element) => {
              if (element) {
                options.current.set(stop.id, element);
              } else {
                options.current.delete(stop.id);
              }
            }}
            type="button"
            role="option"
            aria-selected={active}
            tabIndex={stop.id === roving ? 0 : -1}
            className={`daedalus-stylish-choice-list__option${active ? ' daedalus-stylish-choice-list__option--active' : ''}`}
            style={faceStyle?.(stop) ?? undefined}
            title={active ? Drupal.t('Unset') : undefined}
            onClick={() => onPick(active ? null : stop)}
            onFocus={() => setFocusId(stop.id)}
          >
            <span className="daedalus-stylish-choice-list__check" aria-hidden="true">
              {active ? '✓' : ''}
            </span>
            {glyph !== null && glyph(stop)}
            <span className="daedalus-stylish-choice-list__label">{stop.text}</span>
          </button>
        );
      })}
    </div>
  );
};
