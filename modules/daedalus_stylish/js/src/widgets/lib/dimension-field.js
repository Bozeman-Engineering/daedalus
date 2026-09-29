import { cloneElement, h } from 'preact';
import { useEffect, useRef, useState } from 'preact/hooks';
import {
  applyStep,
  clamp,
  contextUnit,
  fieldState,
  formatDimension,
  lengthIn,
  numberFace,
  openChrome,
  scrubAdvance,
  scrubRate,
  seedAnchor,
  settleText,
  stepSize,
  unitGrain,
  unitMemory,
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
import { displayAt } from '../../value-space.js';
import { useStopMenu } from '../../stop-menu.js';

/**
 * A caller's placeholder as text: a value attributed to the field from
 * OUTSIDE its layer stack (the box-model diagram's computed side, a
 * parent's alignment), never a layer's value (a delivering layer is the
 * field's shown value). A reference faces through displayAt at the pose
 * (its raw spelling when the roster cannot resolve it), a literal as
 * itself; nothing (null, undefined, '') as null. The text then
 * converts into the unit the field works in through the canvas's
 * measured bases (dimension-value.js lengthIn: a computed `32px`
 * beneath a rem field at a 16px root reads `2rem`), and stays whole
 * when it cannot (`50%` beneath rem, or no bases off canvas): the
 * placeholder is a measurement, and the field's unit is the unit.
 */
const placeholderText = (
  guide,
  materials,
  placeholder,
  condition,
  unit,
  bases,
) => {
  if (placeholder === null || placeholder === undefined || placeholder === '') {
    return null;
  }
  const text =
    displayAt(guide, materials, placeholder, condition) ?? String(placeholder);
  return lengthIn(text, unit, bases) ?? text;
};

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
 *   stop). The scrub speaks the same modifier ladder as the free scrub,
 *   as a travel RATE (scrubRate: Shift x10, Cmd/Ctrl x100, Alt x0.1 of
 *   the unit-scaled base) rather than a step grain; the magnets keep
 *   capturing at every rate because their radius scales with it
 *   (magnetAdvance), so a held modifier changes how much value one
 *   pointer pixel is worth, never whether scrubbing onto a stop reaches
 *   the token.
 * - free (no vocabulary): the classic pro field verbatim: scrubbable
 *   label, math expressions (`16*2`, `4/3em`), unit select, steppers,
 *   the DevTools modifier scheme (the unit's grain, Shift 10, Alt a
 *   tenth, Cmd/Ctrl 100).
 *
 * The input shows the NUMBER; the unit beside it shows the unit
 * (numberFace: '8.5rem' faces '8.5' beside rem). Which chrome carries
 * it is the value space's call (dimension-value's openChrome): a space
 * that names the one unit its stops share shows it as a static LABEL
 * (the space's unit is the gesture's unit; a basic gesture never
 * generates one the space did not name), a space that names none (free,
 * or stops that disagree) offers the unit SELECT. Typed text is the
 * whole grammar under either: a bare number takes the shown unit, a
 * typed unit or a unit-tagged expression ('4/3em') commits as typed and
 * the label or select then reads the value's own unit (the exact-value
 * path; the server judges). Gesture-generated values carry the unit's
 * precision (whole pixels, tenths of a rem, hundredths under Alt;
 * dimension-value's unitPrecision); typed values stay exactly as
 * written.
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
 * The row's mark leads the scrub label: the row hands it in as `mark`
 * (a lib/mark.js Mark vnode over the row's origin; null renders none,
 * the box-model diagram's side editor, where the cell is the mark) and
 * the field stamps the mark's SHAPE with its own live-scrub fact (the
 * same boolean that sets the resize cursor: stops to step through or a
 * value in the dimension grammar, and no `disabled`), so a square means
 * "you can drag this now" and never lies on an inert row. The field
 * knows nothing of provenance beyond lending the slot. The field IS its
 * row, so it lays the row's two cells itself (inspector.css
 * __widget-label / __widget-value): the scrub label (mark + label, the
 * drag surface) is the label cell's content, and the controls cluster
 * fills the value cell, the panel's shared value column. A row that
 * carries its own tool (the padding row's ✎, the door to its per-side
 * diagram) hands it in as `tools`: rendered after the scrub label at
 * the label cell's end, where it neither displaces the mark nor moves
 * the value column's edge. A row whose value cell holds company beside
 * the number (the stroke row's color well and style well around the
 * width) hands that in as `leading` and `trailing`: rendered flush
 * before and after the controls cluster, INSIDE the value cell, so the
 * cluster gives up the width the company takes and the column's edges
 * stay the panel's shared ones.
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
 * multi-value shorthands, a per-side listing), the input IS the plain
 * text input it replaces: the scrub goes inert, and the unit and the
 * steppers leave the row (openChrome; a listing is not a number, and a
 * unit beside it said it was), so the input takes the cluster's width;
 * the text passes through untouched, never normalized. Mid-typed text
 * keeps the chrome, disabled, for the keystroke it is out of grammar.
 *
 * Clamping (definition `negative` + guide min/max) applies only to
 * values the widget itself generates (scrub, arrows, steppers). Typed
 * text, expressions, and unit switches are the exact-value path: they go
 * to the server as written, and its validation reports what a silent
 * client clamp would hide.
 *
 * `disabled` renders the field inert (the input, the unit well, the
 * steppers and the scrub): a reality outside the value has taken the
 * write off the table (the panel's write address withholds the
 * property); `disabledTitle` is the reason, riding the field's hover
 * title, since no prose lives inside a widget. The shown value still
 * renders honestly.
 *
 * `placeholder` is a value attributed to the field from OUTSIDE its
 * stack (the ring's computed side); `bases` are the canvas's measured
 * conversion bases (readCanvas), through which the placeholder
 * converts into the field's unit for its face and the seed a gesture
 * over the empty field starts from (placeholderText, seedAnchor).
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
  bases = null,
  mark = null,
  tools = null,
  leading = null,
  trailing = null,
  disabled = false,
  disabledTitle = null,
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
    bases,
    mark,
    tools,
    leading,
    trailing,
    disabled,
    disabledTitle,
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
 * with the choice row (widgets/lib/choice-row.js), whose mark scrubs a
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
  bases,
  mark,
  tools,
  leading,
  trailing,
  disabled,
  disabledTitle,
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
  // The unset option's hint, in the stops' unit (tokens carry their
  // units, so the select's unit is the vocabulary's).
  const hint = placeholderText(
    guide,
    materials,
    placeholder,
    condition,
    space.unit,
    bases,
  );

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
    if (!stops.length || disabled) {
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
    disabled ? 'daedalus-stylish-dimension--disabled' : '',
  ]
    .filter(Boolean)
    .join(' ');
  // The live-scrub fact: the cursor, the scrub's title and the mark's
  // shape all read it.
  const live = stops.length > 0 && !disabled;
  const scrubClasses = [
    'daedalus-stylish-dimension__scrub',
    scrubbing ? 'daedalus-stylish-dimension__scrub--active' : '',
    !live ? 'daedalus-stylish-dimension__scrub--inert' : '',
  ]
    .filter(Boolean)
    .join(' ');

  return (
    <span className={classes} title={disabled ? (disabledTitle ?? undefined) : undefined}>
      <span className="daedalus-stylish-inspector__widget-label">
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
          {mark && cloneElement(mark, { slide: live })}
          <span className="daedalus-stylish-dimension__label">{label}</span>
        </span>
        {tools}
      </span>
      <span className="daedalus-stylish-inspector__widget-value">
        {leading}
        <span className="daedalus-stylish-dimension__controls">
          <span className="daedalus-stylish-dimension__value">
            <select
              className="daedalus-stylish-inspector__input daedalus-stylish-dimension__select daedalus-ui-input daedalus-ui-input--select"
              value={selectValue}
              aria-label={label}
              disabled={disabled}
              title={
                disabled
                  ? (disabledTitle ?? undefined)
                  : token
                    ? Drupal.t(
                        'Tracks the design system: a skin change restyles it.',
                      )
                    : undefined
              }
              onChange={onChange}
            >
              <option value="">
                {hint
                  ? Drupal.t('- !value -', { '!value': hint })
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
              disabled={!stops.length || disabled}
              aria-label={Drupal.t('Increase')}
              onClick={onStep(1)}
            >
              ▲
            </button>
            <button
              type="button"
              className="daedalus-stylish-dimension__step daedalus-ui-icon-button daedalus-ui-icon-button--muted"
              disabled={!stops.length || disabled}
              aria-label={Drupal.t('Decrease')}
              onClick={onStep(-1)}
            >
              ▼
            </button>
          </span>
        </span>
        {trailing}
      </span>
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
  bases,
  mark,
  tools,
  leading,
  trailing,
  disabled,
  disabledTitle,
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
  const degraded = state.mode === 'text';
  // The value box the stop picker hangs under.
  const valueRef = useRef(null);
  // The raw text typed since the last settle (null = untouched). Typing
  // is "touching", so its settle goes through magnetic capture, and it
  // settles THIS text, never the input's face: the face is number-only
  // (a typed `2rem` shows `2` beside rem), so settling the face would
  // re-derive the unit from the fallback chain and commit `2px`. A blur
  // or Enter on an untouched field must never rewrite provenance (the
  // face equals the typed-nothing text by construction, so this is the
  // only honest tell).
  const typedRef = useRef(null);
  // The chrome beside the number (openChrome): the space's unit as a
  // label, the select where the space names none, nothing on degraded
  // text that arrived or settled (typing in flight keeps it, disabled).
  const chrome = openChrome({
    unitless,
    spaceUnit: space.unit,
    mode: state.mode,
    typing: typedRef.current !== null,
  });
  const options =
    chrome.unit === 'select' ? unitOptions(constraints, currentUnit || null) : [];

  // The select's unit memory: the context survives states that carry
  // none only while typing is in flight, so a field emptied by typing
  // and mid-typing text keep the last parsed unit (`4/3` typed over
  // `16em` stays em). pickedUnit is an explicit choice made while empty
  // (nothing to commit yet); a parsed value supersedes it. Both live by
  // unitMemory (dimension-value.js); the precedence beneath them is
  // contextUnit, where a space that names its unit wins outright, so a
  // labeled field never reads this memory: a typed `10px` in a rem
  // space is that value's unit, never the field's.
  const lastUnitRef = useRef(null);
  const [pickedUnit, setPickedUnit] = useState(null);
  // The latest text this component previewed but not yet echoed back
  // through `value`; gestures anchor on it so rapid ticks never re-read
  // a stale render.
  const pendingRef = useRef(null);
  // Whether arrow keydowns stepped since the last settle (keyup commits).
  const steppedKeysRef = useRef(false);
  // Whether a scrub currently sits on a magnet (blessed live, before the
  // release commits the reference).
  const [capturedLive, setCapturedLive] = useState(false);
  const [chunk, kachunk] = useKachunk();
  // The memory rule (unitMemory): a shown value carrying a unit context
  // is remembered and retires any pick; one carrying none (empty, or
  // text outside the grammar such as a per-side listing) keeps the
  // memory only while typing is in flight. Arriving from OUTSIDE (the
  // selection moved to an unset element or to one wearing "top 2rem",
  // an unset through the row's menu, an undo) it forgets both: the last
  // unit was another value's, and keeping it would open the field on
  // the px the previous element happened to wear. The first offered
  // unit takes over (contextUnit).
  useEffect(() => {
    pendingRef.current = null;
    const next = unitMemory(state, {
      last: lastUnitRef.current,
      picked: pickedUnit,
      bareContext,
      typing: typedRef.current !== null,
    });
    lastUnitRef.current = next.last;
    setPickedUnit((current) =>
      current === next.picked ? current : next.picked,
    );
  }, [value]);

  const fallbackUnit = unitless
    ? ''
    : contextUnit({
        spaceUnit: space.unit,
        picked: pickedUnit,
        last: lastUnitRef.current,
        options,
      });
  // Bare ('') is a real unit context only where the definition accepts
  // bare numbers; elsewhere it falls back so the label or select shows
  // the unit a settled bare number will actually get.
  const displayUnit =
    currentUnit === null || (currentUnit === '' && !bareContext)
      ? fallbackUnit
      : currentUnit;

  // The caller's placeholder (a value attributed from outside the
  // stack), converted into the unit beside it: the hint's number and
  // the unit well together read as the value the element actually
  // wears (`2` beside rem for a computed 32px at a 16px root). One
  // that cannot convert shows whole, unit and all.
  const hint = placeholderText(
    guide,
    materials,
    placeholder,
    condition,
    displayUnit,
    bases,
  );
  const hintState = fieldState(hint);
  const hintFace =
    hint === null
      ? undefined
      : hintState.mode === 'dimension' && hintState.unit === displayUnit
        ? numberFace(hint)
        : hint;

  // The value a gesture starts from: the pending or shown value, or,
  // while the field is empty, the placeholder converted into the
  // fallback unit through the canvas's bases (seedAnchor: a 16px
  // computed side beneath a rem scale at a 16px root steps 1rem to
  // 1.1rem, never 0 to 0.1rem), the same seed the diagram's cells take
  // (box-model.js sideAnchor), so ArrowUp here and on the ring agree.
  // The seed carries the gesture's precision (tenths of a rem,
  // hundredths under Alt), read from the event that starts it.
  const anchor = (modifiers = {}) => {
    const from = fieldState(pendingRef.current ?? face);
    return from.mode === 'empty'
      ? {
          ...from,
          number: seedAnchor(hint, fallbackUnit, bases, modifiers.altKey),
        }
      : from;
  };

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
    const from = anchor(event);
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
    const from = anchor(event);
    if (from.mode === 'text') {
      return;
    }
    const text = stepped(from, direction, event);
    pendingRef.current = null;
    onPreview(text);
    settleValue(text);
  };

  // --- unit select (a space naming no unit): switching keeps the number
  // (16px to 16em, no conversion). While empty it only picks the unit
  // later gestures use.
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
  // Detented: continuous travel with sticky magnets at the stops (feel
  // knobs rem-tuned, scaled for px-magnitude units); the same modifiers
  // change the travel RATE mid-drag (scrubRate, read from the event per
  // tick like the free scrub's grain), and magnetAdvance scales the
  // magnet radius with the rate so the stops keep capturing under Cmd.
  // Alt also sets the precision between magnets (hundredths of a rem
  // instead of tenths; unitPrecision), read per tick the same way.
  const scrubRef = useRef(null);
  const [scrubbing, setScrubbing] = useState(false);
  const onScrubDown = (event) => {
    if (disabled) {
      return;
    }
    const from = anchor(event);
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
          precision: unitPrecision(scrub.unit, event.altKey),
          rate: scrubRate(event),
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
  // The live-scrub fact: the cursor, the scrub's title and the mark's
  // shape all read it.
  const live = !degraded && !disabled;
  const classes = [
    'daedalus-stylish-dimension',
    degraded ? 'daedalus-stylish-dimension--text' : '',
    blessed ? 'daedalus-stylish-dimension--blessed' : '',
    landed ? 'daedalus-stylish-dimension--landed' : '',
    chunk ? 'daedalus-stylish-dimension--kachunk' : '',
    invalid ? 'daedalus-stylish-dimension--invalid' : '',
    disabled ? 'daedalus-stylish-dimension--disabled' : '',
  ]
    .filter(Boolean)
    .join(' ');
  const scrubClasses = [
    'daedalus-stylish-dimension__scrub',
    scrubbing ? 'daedalus-stylish-dimension__scrub--active' : '',
    !live ? 'daedalus-stylish-dimension__scrub--inert' : '',
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
        disabled
          ? (disabledTitle ?? undefined)
          : degraded
            ? Drupal.t('A value beyond these controls is set. Edit it as text.')
            : undefined
      }
    >
      <span className="daedalus-stylish-inspector__widget-label">
        <span
          className={scrubClasses}
          title={
            live
              ? Drupal.t(
                  'Drag sideways to change · Shift 10 · Alt @fine · Cmd 100',
                  { '@fine': grain / 10 },
                )
              : undefined
          }
          onPointerDown={onScrubDown}
          onPointerMove={onScrubMove}
          onPointerUp={onScrubUp}
          onPointerCancel={onScrubUp}
        >
          {mark && cloneElement(mark, { slide: live })}
          <span className="daedalus-stylish-dimension__label">{label}</span>
        </span>
        {tools}
      </span>
      <span className="daedalus-stylish-inspector__widget-value">
        {leading}
        {/* The value cell: input (or closed select), unit label or well,
            steppers in one cluster (.daedalus-stylish-dimension__controls)
            filling the value column, so every dimension row's controls
            start at the column's edge like the text rows' inputs,
            whatever the row's shape; degraded text drops the unit and
            the steppers and the input takes their room. */}
        <span className="daedalus-stylish-dimension__controls">
          <span className="daedalus-stylish-dimension__value" ref={valueRef}>
            <input
              type="text"
              className="daedalus-stylish-inspector__input daedalus-stylish-dimension__input daedalus-ui-input"
              value={numberFace(face)}
              placeholder={hintFace}
              aria-label={label}
              spellCheck={false}
              disabled={disabled}
              title={
                disabled
                  ? (disabledTitle ?? undefined)
                  : blessed
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
          {chrome.unit === 'label' && (
            /* The space's unit, static: the unit every gesture and bare
               settle speaks. A typed value in another unit shows its own
               (`10` beside px in a rem space), honestly. */
            <span
              className="daedalus-stylish-dimension__unit-label"
              title={
                displayUnit === space.unit
                  ? Drupal.t(
                      "The design system's unit · type a value with another unit to use it",
                    )
                  : Drupal.t("Outside the design system's @unit scale", {
                      '@unit': space.unit,
                    })
              }
            >
              {displayUnit === '' ? '–' : displayUnit}
            </span>
          )}
          {chrome.unit === 'select' && (
            <span className="daedalus-stylish-dimension__unit-well">
              <select
                className="daedalus-stylish-dimension__unit daedalus-ui-icon-button daedalus-ui-icon-button--muted"
                value={displayUnit}
                disabled={degraded || disabled}
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
          {chrome.steppers && (
            <span className="daedalus-stylish-dimension__steppers">
              <button
                type="button"
                className="daedalus-stylish-dimension__step daedalus-ui-icon-button daedalus-ui-icon-button--muted"
                disabled={degraded || disabled}
                aria-label={Drupal.t('Increase')}
                title={degraded ? undefined : stepTitle}
                onClick={onStep(1)}
              >
                ▲
              </button>
              <button
                type="button"
                className="daedalus-stylish-dimension__step daedalus-ui-icon-button daedalus-ui-icon-button--muted"
                disabled={degraded || disabled}
                aria-label={Drupal.t('Decrease')}
                title={degraded ? undefined : stepTitle}
                onClick={onStep(-1)}
              >
                ▼
              </button>
            </span>
          )}
        </span>
        {trailing}
      </span>
    </span>
  );
};
