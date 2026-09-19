import { h } from 'preact';
import { useEffect, useRef, useState } from 'preact/hooks';
import {
  applyStep,
  clamp,
  contextUnit,
  fieldState,
  formatDimension,
  numberFace,
  scrubAdvance,
  settleText,
  stepSize,
  unitGrain,
  unitOptions,
  unitPrecision,
} from './dimension-value.js';
import {
  MAGNET_RADIUS,
  SCRUB_PER_PIXEL,
  detentsFor,
  discreteScrubMove,
  discreteScrubStart,
  feelFactor,
  magnetAdvance,
  magneticCapture,
  stepAcross,
  stepStop,
  valueOrient,
  valueSpaceFor,
  wearing,
} from '../../value-space.js';
import { useStopMenu } from '../../stop-menu.js';
import { ghostFace } from '../../choice-row-model.js';

/**
 * DimensionField: the one numeric field for CSS dimension values, over
 * the value-space spine (value-space.js).
 *
 * One shared component for advanced property rows and basic-mode field
 * rows (the Highway spine for numeric length input). The property's
 * VALUE SPACE, never the site's enforcement mode, picks the render:
 * - closed (a guide scale/allowed vocabulary while constraints are law):
 *   the input is a select of the stop VALUES, the label scrub jumps
 *   detent to detent (ka-chunk), steppers move one stop, no unit select
 *   (tokens carry their units); every commit is the stop's reference
 *   spelling.
 * - detented (the vocabulary shapes without closing): today's text
 *   input, continuous scrub with sticky magnets at the stops, steppers
 *   stepping the unit's grain but landing ON a crossed stop; a settled
 *   value that value-matches a stop captures its reference
 *   (magneticCapture: typing '.5rem' IS a valid way to reach the minted
 *   stop).
 * - free (no vocabulary): the classic pro field verbatim — scrubbable
 *   label, math expressions (`16*2`, `4/3em`), unit select, steppers,
 *   the DevTools modifier scheme (the unit's grain, Shift 10, Alt a
 *   tenth, Cmd/Ctrl 100).
 *
 * The input shows the NUMBER; the unit select beside it shows the unit
 * (numberFace: '8.5rem' faces '8.5' with the select on rem). Typed text
 * is the whole grammar still: a bare number takes the select's unit, a
 * typed unit or a unit-tagged expression ('4/3em') flips the select.
 * Gesture-generated values carry the unit's precision (whole pixels,
 * hundredths of a rem; dimension-value's unitPrecision); typed values
 * stay exactly as written.
 *
 * Faces show VALUES, never var() syntax: a committed reference renders
 * as its display value at the editing condition (wearing/displayAt) with
 * the blessed chrome (the value tracks the design system; a skin swap
 * restyles it): a full-green flash on every landing that fades back to
 * plain, so a row that has sat there a while wears nothing at all. A committed literal renders plain even when
 * it equals a token's value: a literal already in the map stays literal
 * until touched. Steering provenance explicitly (the token(s) behind the
 * shown value, the "Custom value" literal escape on detented spaces) is
 * the ROW's right-click menu (provenance-model.js tokenItems over this
 * field's value space), never the field's: the field renders no menu.
 * Detach is per-settle, deliberately not sticky: any later capture
 * re-attaches.
 *
 * The grip before the label is the row's provenance mark: it paints in
 * the row's `--daedalus-stylish-origin` hue when the row carries one (quiet
 * otherwise) and wears `gripTitle` as its hover story; the field knows
 * nothing of provenance beyond lending the slot. The grip LEADS the
 * row, so a row that carries its own tool (the padding row's ✎, the
 * door to its per-side diagram) hands it in as `tools`: rendered
 * between the scrub label and the value column, where it neither
 * displaces the mark nor moves the value column off the panel's shared
 * right edge. A row whose value column holds company beside the number
 * (the stroke row's color well and style well around the width) hands
 * that in as `leading` and `trailing`: rendered flush before and after
 * the controls cluster, INSIDE the value column, so the cluster gives up
 * the width the company takes and the column's right edge stays the
 * panel's shared one.
 *
 * The component is controlled (`value` is the shown text — the raw
 * committed value or the consumer's draft echo; ref→display mapping
 * lives HERE) and speaks the inspector's preview/settle contract
 * through two callbacks, so each consumer keeps its own
 * draft/commit policy:
 * - onPreview(text): per input event, scrub tick, arrow press. Paint
 *   only, nothing persisted. Always a plain display value.
 * - onSettle(text): gesture end (blur, Enter, arrow release, a stepper
 *   click, a select change, a unit switch, scrub release). The COMMIT
 *   spelling: the reference on a capture, the literal otherwise. The
 *   caller commits, or releases when nothing changed.
 *
 * The field derives the property's value space itself from `property`
 * (its one fallback, widget-values.js fallbackFor), `definition`,
 * `constraints`, the guide and the roster — no consumer hands it a
 * vocabulary, so Basic and Advanced rows over one property read one
 * space. Without a `guide` the space is free and the field is exactly
 * its old self, so consumers not yet on the spine keep their behavior.
 *
 * Honest degradation: when the shown text is outside the dimension
 * grammar (calc(), a reference the palette cannot resolve, keywords,
 * multi-value shorthands), the input behaves as the plain text input it
 * replaces (scrub goes inert, steppers and the unit select disable) and
 * the text passes through untouched, never normalized.
 *
 * Clamping (definition `negative` + guide min/max) applies only to
 * values the widget itself generates (scrub, arrows, steppers). Typed
 * text, expressions, and unit switches are the exact-value path: they go
 * to the server as written, and its validation reports what a silent
 * client clamp would hide.
 */
export const DimensionField = ({
  label,
  value,
  property,
  definition = {},
  constraints = {},
  guide = null,
  materials = null,
  condition = 'default',
  invalid = false,
  placeholder,
  gripTitle = undefined,
  tools = null,
  leading = null,
  trailing = null,
  onPreview,
  onSettle,
}) => {
  const space =
    guide && materials
      ? valueSpaceFor(
          guide,
          materials,
          property,
          definition,
          constraints,
          condition,
        )
      : { kind: 'free', stops: [], unit: null, definition, constraints };
  const shared = {
    label,
    value,
    space,
    guide,
    materials,
    condition,
    definition,
    constraints,
    invalid,
    placeholder,
    gripTitle,
    tools,
    leading,
    trailing,
    onPreview,
    onSettle,
  };
  return space.kind === 'closed' ? (
    <ClosedField {...shared} />
  ) : (
    <OpenField {...shared} />
  );
};

/**
 * The detent switch's haptic: a ~90ms scale pop on the value box. Shared
 * with the choice row (widgets/lib/choice-row.js), whose grip scrubs a
 * closed numeric vocabulary detent to detent the way ClosedField's does.
 */
export const useKachunk = () => {
  const [active, setActive] = useState(false);
  const timer = useRef(null);
  useEffect(() => () => clearTimeout(timer.current), []);
  const trigger = () => {
    setActive(true);
    clearTimeout(timer.current);
    timer.current = setTimeout(() => setActive(false), 90);
  };
  return [active, trigger];
};

/**
 * The landing flash: when a ka-chunk lands the value on a stop (a scrub
 * or stepper switching detents) the box lights full green for a beat,
 * then the CSS fades it back to plain. Gesture-driven only: a selection
 * change, a mount, or a typed value arriving on the system never flashes.
 * Attention at the event, not the state.
 */
const useLanding = (blessed, chunk) => {
  const [landed, setLanded] = useState(false);
  const timer = useRef(null);
  useEffect(() => () => clearTimeout(timer.current), []);
  useEffect(() => {
    if (blessed && chunk) {
      setLanded(true);
      clearTimeout(timer.current);
      timer.current = setTimeout(() => setLanded(false), 200);
    }
  }, [blessed, chunk]);
  return landed;
};

/**
 * The closed render: a select of stop values, detent-to-detent scrub,
 * one-stop steppers, reference commits only. A closed space can carry a
 * committed value its vocabulary no longer offers (or none at all when
 * only a committed value keeps an empty row visible): it renders as an
 * honest extra option, and unset stays available.
 */
const ClosedField = ({
  label,
  value,
  space,
  guide,
  materials,
  condition,
  invalid,
  placeholder,
  gripTitle,
  tools,
  leading,
  trailing,
  onPreview,
  onSettle,
}) => {
  const { stops } = space;
  const { face, token, stop } = wearing(
    guide,
    materials,
    space,
    value,
    condition,
  );
  const committed = typeof value === 'string' ? value.trim() : '';
  // The stop the shown value sits on: the worn reference's, or the
  // value-match (a scrub preview echoing back, a legacy literal).
  const current = stop ?? magneticCapture(stops, face)?.stop ?? null;
  const currentIndex = current ? stops.indexOf(current) : -1;
  // The list's VALUE direction: stops keep the scale's curated order in
  // the select, but gestures speak value (valueOrient — "increase" grows
  // the value, a rightward scrub too).
  const orient = valueOrient(stops);
  // Entering the ramp from unset: increase starts at the value-low end.
  const lowEnd = orient === 1 ? 0 : stops.length - 1;
  const [chunk, kachunk] = useKachunk();
  const [scrubbing, setScrubbing] = useState(false);
  const scrubRef = useRef(null);
  const ghost = ghostFace(guide, materials, placeholder, condition);

  const selectValue = stop?.ref ?? current?.ref ?? committed;
  const pick = (index) => {
    kachunk();
    onPreview(stops[index].value);
    onSettle(stops[index].ref);
  };

  const onChange = (event) => {
    const picked = event.currentTarget.value;
    if (picked === selectValue) {
      return;
    }
    const chosen = stops.find((candidate) => candidate.ref === picked);
    onPreview(chosen ? chosen.value : picked);
    onSettle(picked);
  };

  // --- scrub: detent to detent, half-band switching, pinned ends.
  const onScrubDown = (event) => {
    if (!stops.length) {
      return;
    }
    event.preventDefault();
    event.currentTarget.setPointerCapture(event.pointerId);
    const start = currentIndex === -1 ? lowEnd : currentIndex;
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
      stops.length,
    );
    scrub.raw = advanced.raw;
    if (
      advanced.index !== scrub.index ||
      (!scrub.moved && currentIndex === -1)
    ) {
      scrub.index = advanced.index;
      scrub.moved = true;
      kachunk();
      onPreview(stops[advanced.index].value);
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
    if (scrub.moved) {
      onSettle(stops[scrub.index].ref);
    }
  };

  // --- steppers: one stop per click, in VALUE direction (stepStop).
  const onStep = (direction) => () => {
    const next = stepStop(stops, currentIndex, direction);
    if (next !== null) {
      pick(next);
    }
  };

  // Blessed: a worn reference, or a scrub in flight (its release commits
  // one). A legacy literal sitting on a stop's value stays plain: lit by
  // the reference spelling only.
  const blessed = token !== null || (scrubbing && current !== null);
  const landed = useLanding(blessed, chunk);
  const classes = [
    'daedalus-stylish-dimension',
    'daedalus-stylish-dimension--closed',
    blessed ? 'daedalus-stylish-dimension--blessed' : '',
    landed ? 'daedalus-stylish-dimension--landed' : '',
    chunk ? 'daedalus-stylish-dimension--kachunk' : '',
    invalid ? 'daedalus-stylish-dimension--invalid' : '',
  ]
    .filter(Boolean)
    .join(' ');
  const scrubClasses = [
    'daedalus-stylish-dimension__scrub',
    scrubbing ? 'daedalus-stylish-dimension__scrub--active' : '',
    !stops.length ? 'daedalus-stylish-dimension__scrub--inert' : '',
  ]
    .filter(Boolean)
    .join(' ');

  return (
    <span className={classes}>
      <span
        className={scrubClasses}
        title={
          stops.length
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
          title={gripTitle}
          aria-hidden="true"
        >
          ⣿
        </span>
        <span className="daedalus-stylish-dimension__label">{label}</span>
      </span>
      {tools}
      {leading}
      <span className="daedalus-stylish-dimension__controls">
        <span className="daedalus-stylish-dimension__value">
          <select
            className="daedalus-stylish-inspector__input daedalus-stylish-dimension__select daedalus-ui-input daedalus-ui-input--select"
            value={selectValue}
            aria-label={label}
            title={
              token
                ? Drupal.t(
                    'Tracks the design system: a skin change restyles it.',
                  )
                : undefined
            }
            onChange={onChange}
          >
            <option value="">
              {ghost
                ? Drupal.t('- !value -', { '!value': ghost })
                : Drupal.t('- Unset -')}
            </option>
            {stops.map((candidate) => (
              <option key={candidate.ref} value={candidate.ref}>
                {candidate.value}
              </option>
            ))}
            {selectValue !== '' &&
              !stops.some((candidate) => candidate.ref === selectValue) && (
                /* A committed value the vocabulary does not offer: honest,
                 visible, replaceable. */
                <option value={selectValue}>{face || selectValue}</option>
              )}
          </select>
        </span>
        <span className="daedalus-stylish-dimension__steppers">
          <button
            type="button"
            className="daedalus-stylish-dimension__step daedalus-ui-icon-button daedalus-ui-icon-button--muted"
            disabled={!stops.length}
            aria-label={Drupal.t('Increase')}
            onClick={onStep(1)}
          >
            ▲
          </button>
          <button
            type="button"
            className="daedalus-stylish-dimension__step daedalus-ui-icon-button daedalus-ui-icon-button--muted"
            disabled={!stops.length}
            aria-label={Drupal.t('Decrease')}
            onClick={onStep(-1)}
          >
            ▼
          </button>
        </span>
      </span>
      {trailing}
    </span>
  );
};

/**
 * The detented and free renders: the classic text field, with the
 * detented space adding sticky magnets to the scrub, stop-landing
 * steppers, magnetic capture at every settle, the blessed chrome, the
 * stop picker (stop-menu.js: the chevron after the input, Alt+ArrowDown
 * inside it).
 */
const OpenField = ({
  label,
  value,
  space,
  guide,
  materials,
  condition,
  definition,
  constraints,
  invalid,
  placeholder,
  gripTitle,
  tools,
  leading,
  trailing,
  onPreview,
  onSettle,
}) => {
  const detented = space.kind === 'detented';
  const { face, token: wornToken } = wearing(
    guide,
    materials,
    space,
    value,
    condition,
  );
  const state = fieldState(face);
  const currentUnit = state.mode === 'dimension' ? state.unit : null;
  const unitless = definition.type === 'number';
  const allowBare = !unitless && Boolean(definition.number);
  // Whether bare ('') is a real unit context for this property. Only
  // where the definition accepts bare numbers: on a pure dimension
  // property mid-typed digits parse as a bare dimension, and adopting
  // that as context would settle, step, and scrub unitless values the
  // server rejects ('12' must settle '12px', not '12').
  const bareContext = unitless || allowBare;
  const options = unitless ? [] : unitOptions(constraints, currentUnit || null);
  const degraded = state.mode === 'text';
  // The value box the stop picker hangs under.
  const valueRef = useRef(null);

  // The unit context survives states that carry none: a field emptied
  // by typing and mid-typing text keep the last parsed unit, so `4/3`
  // typed over `16em` stays em. pickedUnit is an explicit choice made
  // while empty (nothing to commit yet); a parsed value supersedes it.
  // The precedence beneath both is contextUnit (dimension-value.js).
  const lastUnitRef = useRef(null);
  const [pickedUnit, setPickedUnit] = useState(null);
  // The latest text this component previewed but not yet echoed back
  // through `value`; gestures anchor on it so rapid ticks never re-read
  // a stale render.
  const pendingRef = useRef(null);
  // Whether arrow keydowns stepped since the last settle (keyup commits).
  const steppedKeysRef = useRef(false);
  // The raw text typed since the last settle (null = untouched). Typing
  // is "touching", so its settle goes through magnetic capture, and it
  // settles THIS text, never the input's face: the face is number-only
  // (a typed `2rem` shows `2` beside a rem select), so settling the face
  // would re-derive the unit from the fallback chain and commit `2px`.
  // A blur or Enter on an untouched field must never rewrite provenance
  // (the face equals the typed-nothing text by construction, so this is
  // the only honest tell).
  const typedRef = useRef(null);
  // Whether a scrub currently sits on a magnet (blessed live, before the
  // release commits the reference).
  const [capturedLive, setCapturedLive] = useState(false);
  const [chunk, kachunk] = useKachunk();
  useEffect(() => {
    pendingRef.current = null;
    if (state.mode === 'dimension' && (state.unit !== '' || bareContext)) {
      lastUnitRef.current = state.unit;
      setPickedUnit((current) => (current === null ? current : null));
    } else if (state.mode === 'empty' && typedRef.current === null) {
      // Emptied from outside (the selection moved to an unset element,
      // an unset through the row's menu, an undo): the last unit was
      // another value's, and keeping it would open a rem scale's field
      // on the px the previous element happened to wear. The
      // vocabulary's unit takes over (contextUnit); a clear typed in
      // the field keeps its unit, since typing is still in flight.
      lastUnitRef.current = null;
      setPickedUnit((current) => (current === null ? current : null));
    }
  }, [value]);

  // The ghost (the effective value beneath an unset field, the row's
  // placeholder): while the field is empty its unit is the select's
  // context, so the ghost's number and the select together read as the
  // value the element actually wears.
  const ghost = ghostFace(guide, materials, placeholder, condition);
  const ghostState = fieldState(ghost);
  const ghostUnit =
    ghostState.mode === 'dimension' && ghostState.unit !== ''
      ? ghostState.unit
      : null;
  const fallbackUnit = unitless
    ? ''
    : contextUnit({
        picked: pickedUnit,
        last: lastUnitRef.current,
        spaceUnit: space.unit,
        ghostUnit,
        options,
      });
  // Bare ('') is a real unit context only where the definition accepts
  // bare numbers; elsewhere it falls back so the select shows the unit a
  // settled bare number will actually get.
  const displayUnit =
    currentUnit === null || (currentUnit === '' && !bareContext)
      ? fallbackUnit
      : currentUnit;

  const anchor = () => fieldState(pendingRef.current ?? face);

  // The unit a gesture works in: the anchor's own unit, unless that is a
  // bare parse on a property where bare is no context (see bareContext).
  const gestureUnit = (from) =>
    from.unit === null || (from.unit === '' && !bareContext)
      ? fallbackUnit
      : from.unit;

  // Every settle funnels here: on a detented space a value that matches
  // a stop captures its reference (the magnetic-capture doctrine — the
  // settle commits the ref, the face keeps the value); everything else
  // commits the text as written.
  const settleValue = (text) => {
    // Every commit retires the typed text: a unit pick, a stepper click,
    // a scrub release or a stop pick after typing is the settle, and the
    // blur that follows must read the field as untouched.
    typedRef.current = null;
    if (detented && text !== '') {
      const capture = magneticCapture(space.stops, text);
      if (capture) {
        if (capture.stop.value !== text) {
          onPreview(capture.stop.value);
        }
        onSettle(capture.ref);
        return;
      }
    }
    onSettle(text);
  };

  const stepped = (from, direction, modifiers) => {
    const unit = gestureUnit(from);
    if (detented) {
      // The detented grain: the unit's own between stops (a pixel, a
      // tenth of a rem), landing ON a crossed stop so arrow-walking
      // still finds the system; a held modifier keeps its DevTools
      // meaning.
      const across = stepAcross(
        from.number,
        direction,
        detentsFor(space.stops, unit),
        stepSize(modifiers, unit),
      );
      return formatDimension(
        clamp(across.number, unit, definition, constraints),
        unit,
      );
    }
    const next = clamp(
      applyStep(from.number, direction, stepSize(modifiers, unit)),
      unit,
      definition,
      constraints,
    );
    return formatDimension(next, unit);
  };

  // Typed text's preview: a bare number where bare is no context paints
  // in the select's unit ('9' in a rem field previews 9rem); everything
  // else paints as typed (a typed unit, an expression, mid-typing text).
  const typedPreview = (text) => {
    const parsed = fieldState(text);
    return parsed.mode === 'dimension' && parsed.unit === '' && !bareContext
      ? `${text.trim()}${fallbackUnit}`
      : text;
  };

  const settleFromText = () => {
    steppedKeysRef.current = false;
    const pending = pendingRef.current;
    pendingRef.current = null;
    const typed = typedRef.current;
    typedRef.current = null;
    const text = pending ?? typed;
    if (text === null) {
      // Untouched (blur or Enter without an edit): pass the original
      // value through so provenance never rewrites itself — a committed
      // literal stays literal ('16.0px' is not re-spelled '16px'), a
      // worn twin stays the exact twin.
      onSettle(typeof value === 'string' ? value : '');
      return;
    }
    const resolved = settleText(text, fallbackUnit);
    if (resolved.text !== text) {
      // An evaluated expression paints its result before the commit
      // round-trip, so the field never shows `16*2` while 32px settles.
      onPreview(resolved.text);
    }
    settleValue(resolved.text);
  };

  // --- the stop picker (detented only: a free space has no stops to
  // offer): a pick is a magnetic capture, previewing the stop's display
  // value and settling through the same path a typed match takes.
  const stopMenu = useStopMenu({
    stops: detented ? space.stops : [],
    face,
    anchorRef: valueRef,
    onPick: (stop) => {
      pendingRef.current = null;
      onPreview(stop.value);
      settleValue(stop.value);
    },
  });

  // --- keyboard: DevTools arrows, preview per press, settle on release;
  // Alt+ArrowDown opens the stop picker instead of stepping.
  const onKeyDown = (event) => {
    if (stopMenu.onInputKeyDown(event)) {
      return;
    }
    if (event.key === 'Enter') {
      settleFromText();
      return;
    }
    if (event.key !== 'ArrowUp' && event.key !== 'ArrowDown') {
      return;
    }
    const from = anchor();
    if (from.mode === 'text') {
      // Degraded: arrows keep their native caret behavior.
      return;
    }
    event.preventDefault();
    const text = stepped(from, event.key === 'ArrowUp' ? 1 : -1, event);
    pendingRef.current = text;
    steppedKeysRef.current = true;
    onPreview(text);
  };
  const onKeyUp = (event) => {
    if (
      (event.key !== 'ArrowUp' && event.key !== 'ArrowDown') ||
      !steppedKeysRef.current
    ) {
      return;
    }
    steppedKeysRef.current = false;
    const text = pendingRef.current;
    pendingRef.current = null;
    if (text !== null) {
      settleValue(text);
    }
  };

  // --- steppers: a click is a discrete gesture, preview and settle in
  // one breath, like a select change.
  const onStep = (direction) => (event) => {
    const from = anchor();
    if (from.mode === 'text') {
      return;
    }
    const text = stepped(from, direction, event);
    pendingRef.current = null;
    onPreview(text);
    settleValue(text);
  };

  // --- unit select: switching keeps the number (16px to 16em, no
  // conversion). While empty it only picks the unit later gestures use.
  const onUnitChange = (event) => {
    const unit = event.currentTarget.value;
    const from = anchor();
    if (from.mode !== 'dimension') {
      setPickedUnit(unit);
      return;
    }
    const text = formatDimension(from.number, unit);
    pendingRef.current = null;
    onPreview(text);
    settleValue(text);
  };

  // --- scrub: pointer capture on the label, preview per tick, settle on
  // release. Free: the step grid, modifiers changing grain mid-drag.
  // Detented: continuous travel with sticky magnets at the stops
  // (feel knobs rem-tuned, scaled for px-magnitude units).
  const scrubRef = useRef(null);
  const [scrubbing, setScrubbing] = useState(false);
  const onScrubDown = (event) => {
    const from = anchor();
    if (from.mode === 'text') {
      return;
    }
    event.preventDefault();
    event.currentTarget.setPointerCapture(event.pointerId);
    scrubRef.current = {
      raw: from.number,
      unit: gestureUnit(from),
      last: null,
      lastStop: null,
    };
    setScrubbing(true);
  };
  const onScrubMove = (event) => {
    const scrub = scrubRef.current;
    if (!scrub || !event.currentTarget.hasPointerCapture(event.pointerId)) {
      return;
    }
    let advanced;
    let onStop = null;
    if (detented) {
      const factor = feelFactor(scrub.unit);
      advanced = magnetAdvance(
        scrub.raw,
        event.movementX,
        detentsFor(space.stops, scrub.unit),
        {
          perPixel: SCRUB_PER_PIXEL * factor,
          magnet: MAGNET_RADIUS * factor,
          precision: unitPrecision(scrub.unit),
        },
      );
      onStop = advanced.stop;
    } else {
      advanced = scrubAdvance(scrub.raw, event.movementX, event, scrub.unit);
    }
    const number = clamp(advanced.number, scrub.unit, definition, constraints);
    // Pin the accumulator at a clamp so recovery is immediate, no
    // rubber-band distance to scrub back through.
    scrub.raw = number === advanced.number ? advanced.raw : number;
    if (number !== advanced.number) {
      onStop = null;
    }
    if (detented) {
      if (onStop !== null && scrub.lastStop !== onStop) {
        kachunk();
      }
      scrub.lastStop = onStop;
      setCapturedLive(onStop !== null);
    }
    const text = formatDimension(number, scrub.unit);
    if (text !== scrub.last) {
      scrub.last = text;
      pendingRef.current = text;
      onPreview(text);
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
    setCapturedLive(false);
    pendingRef.current = null;
    if (scrub.last !== null) {
      settleValue(scrub.last);
    }
  };

  const blessed = wornToken !== null || capturedLive;
  const landed = useLanding(blessed, chunk);
  const classes = [
    'daedalus-stylish-dimension',
    degraded ? 'daedalus-stylish-dimension--text' : '',
    blessed ? 'daedalus-stylish-dimension--blessed' : '',
    landed ? 'daedalus-stylish-dimension--landed' : '',
    chunk ? 'daedalus-stylish-dimension--kachunk' : '',
    invalid ? 'daedalus-stylish-dimension--invalid' : '',
  ]
    .filter(Boolean)
    .join(' ');
  const scrubClasses = [
    'daedalus-stylish-dimension__scrub',
    scrubbing ? 'daedalus-stylish-dimension__scrub--active' : '',
    degraded ? 'daedalus-stylish-dimension__scrub--inert' : '',
  ]
    .filter(Boolean)
    .join(' ');
  const grain = unitGrain(displayUnit);
  const stepTitle = detented
    ? Drupal.t(
        "Step: @grain, landing on the design system's values · Shift 10 · Alt @fine · Cmd 100",
        { '@grain': grain, '@fine': grain / 10 },
      )
    : Drupal.t('Step: @grain · Shift 10 · Alt @fine · Cmd 100', {
        '@grain': grain,
        '@fine': grain / 10,
      });

  return (
    <span
      className={classes}
      title={
        degraded
          ? Drupal.t('A value beyond these controls is set. Edit it as text.')
          : undefined
      }
    >
      <span
        className={scrubClasses}
        title={
          degraded
            ? undefined
            : Drupal.t(
                'Drag sideways to change · Shift 10 · Alt @fine · Cmd 100',
                { '@fine': grain / 10 },
              )
        }
        onPointerDown={onScrubDown}
        onPointerMove={onScrubMove}
        onPointerUp={onScrubUp}
        onPointerCancel={onScrubUp}
      >
        <span
          className="daedalus-stylish-dimension__grip"
          title={gripTitle}
          aria-hidden="true"
        >
          ⣿
        </span>
        <span className="daedalus-stylish-dimension__label">{label}</span>
      </span>
      {tools}
      {leading}
      {/* The value column: input (or closed select), unit well, steppers
          in one fixed-width cluster (.daedalus-stylish-dimension__controls), so
          every dimension row's controls start at the property column's
          edge like the text rows' inputs, whatever the row's shape. */}
      <span className="daedalus-stylish-dimension__controls">
        <span className="daedalus-stylish-dimension__value" ref={valueRef}>
          <input
            type="text"
            className="daedalus-stylish-inspector__input daedalus-stylish-dimension__input daedalus-ui-input"
            value={numberFace(face)}
            placeholder={
              ghost === null
                ? undefined
                : ghostUnit === displayUnit
                  ? numberFace(ghost)
                  : ghost
            }
            aria-label={label}
            spellCheck={false}
            title={
              blessed
                ? Drupal.t(
                    'Tracks the design system: a skin change restyles it.',
                  )
                : degraded
                  ? undefined
                  : Drupal.t(
                      'Type a value or math (16*2, 4/3em) · arrows step: @grain, Shift 10, Alt @fine, Cmd 100',
                      { '@grain': grain, '@fine': grain / 10 },
                    )
            }
            onInput={(event) => {
              pendingRef.current = null;
              typedRef.current = event.currentTarget.value;
              onPreview(typedPreview(event.currentTarget.value));
            }}
            onKeyDown={onKeyDown}
            onKeyUp={onKeyUp}
            onBlur={settleFromText}
          />
          {/* The curated offer behind free entry: the stop picker's chevron
              (null on a free space). */}
          {stopMenu.chevron}
        </span>
        {!unitless && (
          <span className="daedalus-stylish-dimension__unit-well">
            <select
              className="daedalus-stylish-dimension__unit daedalus-ui-icon-button daedalus-ui-icon-button--muted"
              value={displayUnit}
              disabled={degraded}
              aria-label={Drupal.t('Unit')}
              onChange={onUnitChange}
            >
              {(displayUnit === '' || allowBare) && (
                /* The bare-number option: selectable when the definition
                   accepts bare numbers (line-height), otherwise only the
                   honest rendering of an existing bare value. */
                <option value="" disabled={!allowBare}>
                  –
                </option>
              )}
              {options.map((unit) => (
                <option key={unit} value={unit}>
                  {unit}
                </option>
              ))}
            </select>
          </span>
        )}
        <span className="daedalus-stylish-dimension__steppers">
          <button
            type="button"
            className="daedalus-stylish-dimension__step daedalus-ui-icon-button daedalus-ui-icon-button--muted"
            disabled={degraded}
            aria-label={Drupal.t('Increase')}
            title={degraded ? undefined : stepTitle}
            onClick={onStep(1)}
          >
            ▲
          </button>
          <button
            type="button"
            className="daedalus-stylish-dimension__step daedalus-ui-icon-button daedalus-ui-icon-button--muted"
            disabled={degraded}
            aria-label={Drupal.t('Decrease')}
            title={degraded ? undefined : stepTitle}
            onClick={onStep(-1)}
          >
            ▼
          </button>
        </span>
      </span>
      {trailing}
    </span>
  );
};
