import { h } from 'preact';
import { useEffect, useRef, useState } from 'preact/hooks';
import {
  buildValue,
  gradientTargetIndex,
  parseGradient,
  preludeAngle,
  serializeGradient,
  splitLayers,
} from './gradient-model.js';
import {
  addStop,
  knobAngle,
  moveStop,
  positionPercent,
  removeStop,
  seedGradient,
  trackPositions,
} from './gradient-builder-model.js';
import { paletteFallback, stopsFor } from './widget-values.js';
import { usePalettePopover } from './palette-picker.js';
import { customProperty, paintValue, styleReference } from './materials.js';
import { editableValue, mergedValue } from './palette-model.js';
import { fetchSwatchUsage } from './swatches-api.js';
import { saveMaterial, updateMaterial } from './material-gestures.js';
import { pickerHex } from './color-field-model.js';
import { browserHex } from './native-picker.js';

/**
 * A stop color as face paint: material references paint from roster data
 * (never getComputedStyle — the panel DOM may sit outside the token
 * scope); everything else paints as itself. The one face-paint rule
 * (materials.js paintValue), shared with the color kind's editor
 * (swatch-kinds.js) and the palette card faces.
 */
export const paintColor = (guide, materials, color) =>
  typeof color === 'string' ? paintValue(guide, materials, color) : color;

/**
 * The preview strip's layer: the gradient with every stop painted.
 */
const paintedLayer = (guide, materials, gradient) =>
  serializeGradient({
    ...gradient,
    stops: gradient.stops.map((stop) => ({
      ...stop,
      color: paintColor(guide, materials, stop.color),
    })),
  });

/**
 * The builder's working state from a property value (parse-or-degrade):
 * - 'gradient': the first gradient layer parsed (or, with no gradient
 *   layer anywhere, a seeded fresh one to PREPEND — the scrim-over-photo
 *   stacking order); every other layer preserved verbatim.
 * - 'raw': the target layer's grammar is beyond the model — that one
 *   layer edits as free text, the others still preserved verbatim.
 * - 'opaque': the whole value would not even split into layers
 *   (unbalanced parentheses); it edits whole, as free text.
 */
const derive = (value, guide, materials, constraints) => {
  const layers = splitLayers(value ?? '');
  if (layers === null) {
    return { kind: 'opaque' };
  }
  const index = gradientTargetIndex(layers);
  if (index === -1) {
    return {
      kind: 'gradient',
      layers,
      index: -1,
      gradient: seedGradient(guide, materials, constraints),
    };
  }
  const gradient = parseGradient(layers[index]);
  if (gradient === null) {
    return { kind: 'raw', layers, index };
  }
  return { kind: 'gradient', layers, index, gradient };
};

/**
 * The gradient builder: the gradient swatch kind's editor, and the value
 * editor behind the media rows' gradient chip.
 *
 * A pure client widget over one string value (so it renders identically in
 * the satellite window): type, repeating flag, angle knob + number (or the
 * raw prelude), and the stops TRACK — the edited layer painted left to
 * right (always a 90deg linear for editing, whatever the type: stops are
 * one-dimensional; only the preview strip differs). Handles drag with
 * pointer capture and wear position tags; a click on empty track adds a
 * stop colored by its neighbors; a double-click removes one (two-stop
 * floor). The selected handle drives the selected-stop panel: the face
 * doubles as the palette trigger (the shared grid popover,
 * usePalettePopover, over the same constraint-honest vocabulary the
 * color widgets speak, stopsFor), the native picker and free text
 * (stop colors have no server-side curation constraint, so the UI offers
 * the whole space; palette picks still commit var() references), a
 * numeric position, and remove. Every discrete gesture
 * commits like a select control (preview + settle); continuous input
 * (typing, wheel drags) previews per event and settles on blur/Enter —
 * always through the caller's existing preview path, so the canvas answers
 * instantly.
 *
 * Layer honesty: exactly one gradient layer is edited; other layers of a
 * comma-layered value (media tokens, further gradients) reassemble
 * verbatim (buildValue). What the model cannot parse degrades to free
 * text at the narrowest honest scope — the target layer, or the whole
 * value when it will not even split — never a silent normalization.
 *
 * The save-as-swatch gesture (create-swatches permission, `canMint`)
 * mints the edited gradient as a site-wide swatch through the source's
 * batch commit (save_material, kind swatch: the op the AI submits) and
 * commits the var() reference in the layer's place; the op queue lands
 * the minted record on the store (the leader relays it to the satellite)
 * before the promise resolves, so the live vocabulary and the canvas
 * custom property already know it.
 *
 * @param {object} props
 * @param {string} props.value
 *   The row's current (draft-layered) value.
 * @param {object} props.guide
 *   The style guide payload.
 * @param {?object} [props.constraints]
 *   The bound property's guide constraints — the stop vocabulary inherits
 *   them (stopsFor), exactly like a color row would.
 * @param {boolean} [props.canMint]
 *   Whether the save-as-swatch gesture renders (honest chrome; the op
 *   enforces).
 * @param {?object} [props.source]
 *   The state source the mint commits through (required with canMint).
 * @param {Function} props.onPreview
 *   Called with the whole rebuilt value per input event.
 * @param {Function} props.onCommit
 *   Called with the whole rebuilt value on settle.
 * @param {?Function} [props.onClose]
 *   Closes the builder (the chip's toggle in chip form).
 * @param {?object} [props.session]
 *   The swatch update session's chrome (GradientSwatchSession wires it):
 *   {label, ref, conditions, usages, busy, onUpdate, onDiscard}. Present, the builder wears the session frame: head with
 *   identity + blast line, Update swatch / Discard commit bar in the mint
 *   gesture's place.
 */
export const GradientBuilder = ({
  value,
  guide = {},
  materials,
  constraints = null,
  canMint = false,
  source = null,
  onPreview,
  onCommit,
  onClose = null,
  session = null,
}) => {
  const [state, setState] = useState(() =>
    derive(value, guide, materials, constraints),
  );
  // The echo guard: values this builder emitted come straight back as the
  // row's draft — re-deriving from them would collapse the structured
  // editor mid-keystroke (a half-typed "rgb(" parses as nothing). Only a
  // FOREIGN value change (an op settle to something else, a failed
  // commit's revert, an AI edit) re-derives.
  const emitted = useRef(null);
  useEffect(() => {
    if ((value ?? '') !== emitted.current) {
      setState(derive(value, guide, materials, constraints));
    }
  }, [value]);
  const [angleDraft, setAngleDraft] = useState(null);
  // The track's selection: which stop the selected-stop panel edits. An
  // index into the gradient's stops, clamped at render (a foreign
  // re-derive may shrink the array under it).
  const [selected, setSelected] = useState(0);
  const [posDraft, setPosDraft] = useState(null);
  // The live drag (a stop handle or the angle knob), pointer-captured.
  // Refs, not state — and the drag carries its own authoritative stops:
  // pointer events outpace the render cycle, so a move must never pair a
  // fresh drag index with a stale render's stops (a crossing would then
  // drag the wrong stop).
  const dragging = useRef(null);
  const dragStops = useRef(null);
  const knobTurning = useRef(false);
  const [mintLabel, setMintLabel] = useState('');
  const [minting, setMinting] = useState(false);

  const wholeOf = (next) =>
    next.kind === 'gradient'
      ? buildValue(next.layers, next.index, serializeGradient(next.gradient))
      : next.layers.join(', ');
  const emitWhole = (whole, settle) => {
    emitted.current = whole;
    (settle ? onCommit : onPreview)(whole);
  };
  const setGradient = (gradient, settle = true) => {
    const next = { ...state, gradient };
    setState(next);
    emitWhole(wholeOf(next), settle);
  };
  const setStop = (i, patch, settle = true) => {
    setGradient(
      {
        ...state.gradient,
        stops: state.gradient.stops.map((stop, n) =>
          n === i ? { ...stop, ...patch } : stop,
        ),
      },
      settle,
    );
  };
  const settleNow = () => {
    setAngleDraft(null);
    setPosDraft(null);
    emitWhole(wholeOf(state), true);
  };
  const select = (i) => {
    setSelected(i);
    setPosDraft(null);
  };
  const settleOnEnter = (event) => {
    if (event.key === 'Enter') {
      settleNow();
    }
  };

  const mint = () => {
    setMinting(true);
    saveMaterial(
      source,
      {
        kind: 'swatch',
        label: mintLabel.trim(),
        group: 'gradient',
        value: serializeGradient(state.gradient),
      },
      Drupal.t('Added the swatch @label', { '@label': mintLabel.trim() }),
    )
      .then((minted) => {
        // The minted record is already on the store; its style spelling is
        // what this value references from now on. The value becomes the
        // reference: the edited layer's slot now holds the swatch's style
        // spelling (a swatch edit restyles this use) and every other layer
        // still reassembles verbatim.
        emitWhole(
          buildValue(state.layers, state.index, styleReference(minted)),
          true,
        );
        onClose?.();
      })
      .catch(() => {
        // The op queue's source reported it (reportFailure on the leader, the
        // satellite's settle); the mint row stays for a correction.
      })
      .finally(() => setMinting(false));
  };

  const gradient = state.kind === 'gradient' ? state.gradient : null;
  const choices = gradient
    ? stopsFor(
        guide,
        materials,
        constraints ?? null,
        paletteFallback(guide, materials),
      )
    : [];
  const angle =
    gradient && gradient.type === 'linear'
      ? preludeAngle(gradient.prelude)
      : null;

  // The track's display geometry and the selection, clamped to the
  // current stops (the pure module owns the math; this is just wiring).
  const display = gradient ? trackPositions(gradient.stops) : [];
  const sel = gradient ? Math.min(selected, gradient.stops.length - 1) : 0;
  // The selected stop's palette offer: the shared grid behind the stop's
  // face (the face is the trigger; the popover floats under it over the
  // stop panel). An insert-style consumer: no lit stop, a pick just writes
  // the reference into the stop's color, exactly what the old select's
  // option pick committed.
  const stopPalette = usePalettePopover({
    stops: choices,
    label: Drupal.t('Palette color'),
    onPick: (stop) => {
      if (stop) {
        setStop(sel, { color: stop.value });
      }
    },
  });
  const stopPaints = () =>
    gradient.stops.map((stop) => paintColor(guide, materials, stop.color));
  const percentAt = (event) => {
    const rect = event.currentTarget.getBoundingClientRect();
    return ((event.clientX - rect.left) / rect.width) * 100;
  };
  const applyStops = (result, settle = true) => {
    select(result.selected);
    setGradient({ ...gradient, stops: result.stops }, settle);
  };

  const trackDown = (event) => {
    const handle = event.target.closest('.daedalus-stylish-gradient__handle');
    if (!handle) {
      return;
    }
    // Capture on the handle (not the track): the compat click/dblclick
    // events then target the handle too, so the empty-track click-to-add
    // below cannot fire from a drag's release or a removal's clicks.
    dragging.current = Number(handle.dataset.index);
    dragStops.current = gradient.stops;
    select(dragging.current);
    handle.setPointerCapture(event.pointerId);
  };
  const trackMove = (event) => {
    if (dragging.current === null) {
      return;
    }
    const result = moveStop(
      dragStops.current,
      dragging.current,
      percentAt(event),
    );
    dragStops.current = result.stops;
    dragging.current = result.selected;
    select(result.selected);
    setGradient({ ...gradient, stops: result.stops }, false);
  };
  const trackUp = () => {
    if (dragging.current === null) {
      return;
    }
    dragging.current = null;
    const stops = dragStops.current;
    dragStops.current = null;
    // Settle from the drag's own stops, not the render's — the last move
    // may not have rendered yet when the pointer lifts.
    setGradient({ ...gradient, stops }, true);
  };
  const trackClick = (event) => {
    if (event.target.closest('.daedalus-stylish-gradient__handle')) {
      return;
    }
    applyStops(addStop(gradient.stops, stopPaints(), percentAt(event)));
  };
  const trackDoubleClick = (event) => {
    const handle = event.target.closest('.daedalus-stylish-gradient__handle');
    if (!handle) {
      return;
    }
    const removed = removeStop(
      gradient.stops,
      Number(handle.dataset.index),
      sel,
    );
    if (removed) {
      applyStops(removed);
    }
  };

  return (
    <div
      className={`daedalus-stylish-gradient${session ? ' daedalus-stylish-gradient--session' : ''}`}
    >
      <div className="daedalus-stylish-gradient__header">
        <span className="daedalus-stylish-gradient__title">
          {session ? Drupal.t('Editing swatch') : Drupal.t('Gradient')}
        </span>
        {onClose && (
          <button
            type="button"
            className="daedalus-stylish-gradient__close"
            title={Drupal.t('Close the gradient builder')}
            onClick={onClose}
          >
            ×
          </button>
        )}
      </div>
      {session && (
        /* The session head (the mockup's who + blast lines): the edited
           swatch's identity and the site-wide consequence, on screen the
           moment the session mounts. The conditions line is the string
           editors' honesty rule: the builder edits the default entry;
           the other condition entries commit back verbatim. */
        <div className="daedalus-stylish-gradient__session-head">
          <span className="daedalus-stylish-gradient__session-who">
            <strong className="daedalus-stylish-gradient__session-name">
              {session.label}
            </strong>
            <code className="daedalus-stylish-gradient__session-variable">
              {session.ref}
            </code>
          </span>
          <span className="daedalus-stylish-gradient__session-blast">
            {session.usages === null
              ? Drupal.t('Changes affect every use')
              : Drupal.formatPlural(
                  session.usages,
                  'Worn in 1 place · changes affect every use',
                  'Worn in @count places · changes affect every use',
                )}
          </span>
          {session.conditions.length > 0 && (
            <span className="daedalus-stylish-gradient__session-conditions">
              {Drupal.t(
                'Editing the default value; the @conditions entries are kept as they are.',
                { '@conditions': session.conditions.join(', ') },
              )}
            </span>
          )}
        </div>
      )}
      {state.kind === 'opaque' && (
        /* Degrade-whole honesty: the value would not even split into
           layers, so the builder offers exactly what the row already
           does — the free text, no structure invented. */
        <div className="daedalus-stylish-gradient__degraded">
          {Drupal.t(
            'This value is beyond the builder; edit it as text in the row above.',
          )}
        </div>
      )}
      {state.kind === 'raw' && (
        /* Degrade-layer honesty: the gradient layer's grammar is beyond
           the model, so THAT layer edits as text — the other layers stay
           preserved exactly as they are. */
        <div className="daedalus-stylish-gradient__raw">
          <div className="daedalus-stylish-gradient__degraded">
            {Drupal.t(
              'This gradient uses grammar the builder cannot represent; edit this layer as text. Other layers are untouched.',
            )}
          </div>
          <input
            type="text"
            className="daedalus-stylish-gradient__raw-input daedalus-ui-input"
            value={state.layers[state.index]}
            onInput={(event) => {
              const layers = state.layers.map((layer, i) =>
                i === state.index ? event.target.value : layer,
              );
              const next = { ...state, layers };
              setState(next);
              emitWhole(layers.join(', '), false);
            }}
            onBlur={settleNow}
            onKeyDown={settleOnEnter}
          />
        </div>
      )}
      {gradient && (
        <div className="daedalus-stylish-gradient__body">
          <div
            className="daedalus-stylish-gradient__strip"
            style={{ background: paintedLayer(guide, materials, gradient) }}
          />
          <div className="daedalus-stylish-gradient__row">
            <select
              className="daedalus-stylish-gradient__type daedalus-ui-input daedalus-ui-input--select"
              value={gradient.type}
              title={Drupal.t('Gradient type')}
              onChange={(event) => {
                // A type flip resets the prelude to the new type's default
                // spelling ('' — an angle means nothing to a radial): an
                // explicit user gesture, not a silent normalization.
                setGradient({
                  ...gradient,
                  type: event.target.value,
                  prelude: '',
                });
              }}
            >
              <option value="linear">{Drupal.t('Linear')}</option>
              <option value="radial">{Drupal.t('Radial')}</option>
              <option value="conic">{Drupal.t('Conic')}</option>
            </select>
            <label className="daedalus-stylish-gradient__repeating">
              <input
                type="checkbox"
                checked={gradient.repeating}
                onChange={(event) =>
                  setGradient({ ...gradient, repeating: event.target.checked })
                }
              />
              {Drupal.t('Repeat')}
            </label>
          </div>
          <div className="daedalus-stylish-gradient__row">
            {angle !== null ? (
              /* The angle control owns only the "Ndeg" spelling (plus the
                 default direction); any other prelude renders as raw text
                 below — editing through the number would silently rewrite
                 "to bottom right" into degrees. */
              <label className="daedalus-stylish-gradient__angle">
                {Drupal.t('Angle')}
                <span
                  className="daedalus-stylish-gradient__knob"
                  title={Drupal.t('Drag to rotate')}
                  style={`--daedalus-stylish-knob-angle: ${angle}deg`}
                  onPointerDown={(event) => {
                    knobTurning.current = true;
                    event.currentTarget.setPointerCapture(event.pointerId);
                  }}
                  onPointerMove={(event) => {
                    if (!knobTurning.current) {
                      return;
                    }
                    const rect = event.currentTarget.getBoundingClientRect();
                    const degrees = knobAngle(
                      event.clientX - (rect.left + rect.width / 2),
                      event.clientY - (rect.top + rect.height / 2),
                    );
                    setAngleDraft(null);
                    setGradient(
                      { ...gradient, prelude: `${degrees}deg` },
                      false,
                    );
                  }}
                  onPointerUp={() => {
                    if (knobTurning.current) {
                      knobTurning.current = false;
                      settleNow();
                    }
                  }}
                  onPointerCancel={() => {
                    knobTurning.current = false;
                  }}
                />
                <input
                  type="number"
                  step="1"
                  className="daedalus-ui-input"
                  value={angleDraft ?? String(angle)}
                  onInput={(event) => {
                    setAngleDraft(event.target.value);
                    const parsed = Number(event.target.value);
                    if (
                      event.target.value.trim() !== '' &&
                      Number.isFinite(parsed)
                    ) {
                      setGradient(
                        { ...gradient, prelude: `${parsed}deg` },
                        false,
                      );
                    }
                  }}
                  onBlur={settleNow}
                  onKeyDown={settleOnEnter}
                />
                {Drupal.t('deg')}
              </label>
            ) : (
              <label className="daedalus-stylish-gradient__prelude">
                {gradient.type === 'linear'
                  ? Drupal.t('Direction')
                  : Drupal.t('Shape')}
                <input
                  type="text"
                  className="daedalus-ui-input"
                  value={gradient.prelude}
                  placeholder={
                    gradient.type === 'radial'
                      ? Drupal.t('circle at center')
                      : gradient.type === 'conic'
                        ? Drupal.t('from 0deg')
                        : Drupal.t('to bottom')
                  }
                  onInput={(event) =>
                    setGradient(
                      { ...gradient, prelude: event.target.value },
                      false,
                    )
                  }
                  onBlur={settleNow}
                  onKeyDown={settleOnEnter}
                />
              </label>
            )}
          </div>
          <div className="daedalus-stylish-gradient__track-wrap">
            <div
              className="daedalus-stylish-gradient__track"
              /* The track paints the stops as a 90deg linear whatever the
                 gradient type (stops are one-dimensional), at their
                 DISPLAY positions — the same numbers the handles sit at,
                 so paint and handles cannot disagree. Colors come from
                 payload data (paintColor), never var() resolution. */
              style={{
                background: `linear-gradient(90deg, ${gradient.stops
                  .map(
                    (stop, i) =>
                      `${paintColor(guide, materials, stop.color)} ${display[i]}%`,
                  )
                  .join(', ')})`,
              }}
              onPointerDown={trackDown}
              onPointerMove={trackMove}
              onPointerUp={trackUp}
              onPointerCancel={trackUp}
              onClick={trackClick}
              onDblClick={trackDoubleClick}
            >
              {gradient.stops.map((stop, i) => (
                <div
                  key={i}
                  className={`daedalus-stylish-gradient__handle${
                    i === sel ? ' daedalus-stylish-gradient__handle--selected' : ''
                  }`}
                  style={{
                    left: `${display[i]}%`,
                    background: paintColor(guide, materials, stop.color),
                  }}
                  data-index={i}
                  title={stop.color}
                >
                  <span className="daedalus-stylish-gradient__handle-tag">
                    {`${Math.round(display[i])}%`}
                  </span>
                </div>
              ))}
            </div>
            <div className="daedalus-stylish-gradient__track-hint">
              {Drupal.t(
                'Drag stops to move them · click the track to add one · double-click a stop to remove it',
              )}
            </div>
          </div>
          {(() => {
            const stop = gradient.stops[sel];
            const paint = paintColor(guide, materials, stop.color);
            // The native picker's face: the stop's paint brought to
            // #rrggbb (color-field-model.js pickerHex, the color field's
            // rule: any spelling the parser or the browser reads; a
            // spelling beyond both disables the picker instead of
            // nudging black over a legal color).
            const hex = pickerHex(paint, browserHex);
            const percent = positionPercent(stop.position);
            return (
              <div className="daedalus-stylish-gradient__stop-panel">
                <div className="daedalus-stylish-gradient__row">
                  {choices.length > 0 ? (
                    /* The face IS the palette trigger: the current color
                       on the row, the shared grid in the popover beneath
                       (usePalettePopover, wired above the render). */
                    <button
                      {...stopPalette.triggerProps}
                      type="button"
                      className="daedalus-stylish-gradient__stop-face"
                      style={{ background: paint }}
                      title={Drupal.t('@color · pick a palette color', {
                        '@color': stop.color,
                      })}
                    />
                  ) : (
                    <span
                      className="daedalus-stylish-gradient__stop-face"
                      style={{ background: paint }}
                      title={stop.color}
                    />
                  )}
                  <input
                    type="color"
                    className="daedalus-stylish-inspector__swatch"
                    title={
                      hex === null
                        ? Drupal.t(
                            'This spelling is beyond the picker; edit the text instead.',
                          )
                        : Drupal.t('Free picker')
                    }
                    disabled={hex === null}
                    value={hex ?? '#000000'}
                    onInput={(event) =>
                      setStop(sel, { color: event.target.value }, false)
                    }
                    onChange={(event) =>
                      setStop(sel, { color: event.target.value })
                    }
                  />
                  <button
                    type="button"
                    className="daedalus-stylish-gradient__stop-remove daedalus-ui-pill-button"
                    title={Drupal.t('Remove this stop')}
                    disabled={gradient.stops.length <= 2}
                    onClick={() => {
                      const removed = removeStop(gradient.stops, sel, sel);
                      if (removed) {
                        applyStops(removed);
                      }
                    }}
                  >
                    {Drupal.t('Remove')}
                  </button>
                </div>
                {stopPalette.popover}
                <div className="daedalus-stylish-gradient__row">
                  <input
                    type="text"
                    className="daedalus-stylish-gradient__stop-color daedalus-ui-input"
                    value={stop.color}
                    title={Drupal.t('Stop color (any CSS spelling)')}
                    onInput={(event) =>
                      setStop(sel, { color: event.target.value }, false)
                    }
                    onBlur={settleNow}
                    onKeyDown={settleOnEnter}
                  />
                  <input
                    type="number"
                    min="0"
                    max="100"
                    step="any"
                    className="daedalus-stylish-gradient__stop-position daedalus-ui-input"
                    /* Only the "N%" spelling maps onto this number; any
                       other position spelling shows as its raw placeholder
                       and is only rewritten by an explicit gesture here or
                       a drag — never silently. */
                    value={
                      posDraft ?? (percent === null ? '' : String(percent))
                    }
                    placeholder={stop.position ?? Drupal.t('auto')}
                    title={
                      stop.position === null
                        ? Drupal.t('Stop position (%)')
                        : Drupal.t('Stop position (@position)', {
                            '@position': stop.position,
                          })
                    }
                    onInput={(event) => {
                      setPosDraft(event.target.value);
                      const parsed = Number(event.target.value);
                      if (
                        event.target.value.trim() !== '' &&
                        Number.isFinite(parsed)
                      ) {
                        // Straight through (not applyStops): the draft
                        // just set must survive this keystroke.
                        const moved = moveStop(gradient.stops, sel, parsed);
                        setSelected(moved.selected);
                        setGradient({ ...gradient, stops: moved.stops }, false);
                      }
                    }}
                    onBlur={settleNow}
                    onKeyDown={settleOnEnter}
                  />
                  <span className="daedalus-stylish-gradient__stop-position-unit">
                    %
                  </span>
                </div>
              </div>
            );
          })()}
          {canMint && !session && (
            <div className="daedalus-stylish-gradient__mint">
              <input
                type="text"
                className="daedalus-stylish-gradient__mint-name daedalus-ui-input"
                placeholder={Drupal.t('Swatch name…')}
                value={mintLabel}
                onInput={(event) => setMintLabel(event.target.value)}
              />
              <button
                type="button"
                className="daedalus-stylish-gradient__mint-save daedalus-ui-pill-button"
                title={Drupal.t(
                  'Save this gradient as a palette swatch the whole site can reuse; this value becomes a reference to it.',
                )}
                disabled={minting || mintLabel.trim() === ''}
                onClick={mint}
              >
                {Drupal.t('Save as swatch')}
              </button>
            </div>
          )}
        </div>
      )}
      {session && (
        /* The session's exits, in the mint gesture's place, and OUTSIDE
           the gradient-only body, so a swatch whose value degrades (raw
           layer grammar, an unsplittable whole) still commits or discards
           honestly. */
        <div className="daedalus-stylish-gradient__session-bar">
          <button
            type="button"
            className="daedalus-ui-pill-button"
            disabled={session.busy}
            title={Drupal.t(
              'Close without saving; the swatch and every use keep their stored value.',
            )}
            onClick={session.onDiscard}
          >
            {Drupal.t('Discard')}
          </button>
          <button
            type="button"
            className="daedalus-ui-pill-button daedalus-stylish-gradient__session-update"
            disabled={session.busy}
            title={Drupal.t(
              'Save the value; every place referencing this swatch restyles instantly.',
            )}
            onClick={session.onUpdate}
          >
            {Drupal.t('Update swatch')}
          </button>
        </div>
      )}
    </div>
  );
};

/**
 * The gradient chip's edit-in-place posture: the builder over the SWATCH's
 * own value (right after minting, the editor's next gesture is "keep
 * tweaking"; a trip to Materials → Palette is a mode error).
 *
 * Mounted by the media rows' PropertyWidget when the row's committed value
 * is exactly one var() naming a gradient-kind swatch
 * (materials.js gradientSwatchOf). The row's value never changes here; the
 * live link IS the point: the builder edits the swatch's default condition
 * entry (editableValue; other condition entries remerge verbatim on
 * commit, the honesty rule) and Update swatch is the update_material op
 * (kind swatch) through the source's batch commit, whose materials delta
 * the op queue lands on the store (the leader relays it to the
 * satellite), so every use on the canvas and every face in both windows
 * restyles without a reload.
 *
 * Live preview rides the source's previewSwatch seam per input event: the
 * draft lands on the token scope element's inline custom property (the
 * canvas answers while the user drags; the store keeps the committed
 * value, so panel faces stay on it until Update). Discard, close, or
 * unmount releases the preview (previewSwatch with null restores the
 * store's value). Pure client over the source seam, so the satellite
 * renders it unchanged: the relay carries the seam.
 *
 * @param {object} props
 * @param {object} props.swatch
 *   The swatch as a picker entry (materials.js gradientSwatchOf's answer:
 *   {name, label, kind, ref, value, record}).
 * @param {object} props.guide
 *   The style guide payload.
 * @param {object} props.materials
 *   The materials roster.
 * @param {?object} [props.constraints]
 *   The bound property's guide constraints (the stop vocabulary).
 * @param {object} props.source
 *   The state source (commitBatch and the previewSwatch seam).
 * @param {?Function} [props.onClose]
 *   Closes the builder (the chip's toggle).
 */
export const GradientSwatchSession = ({
  swatch,
  guide = {},
  materials,
  constraints = null,
  source,
  onClose = null,
}) => {
  // The mount-time snapshot: the session's one baseline. The prop follows
  // the store (an Update lands a new record), so deriving the stored value
  // from the prop mid-session would move the baseline under the draft.
  const [original] = useState(swatch);
  const { draft: stored, conditions } = editableValue(original.record.value);
  const [draft, setDraft] = useState(stored);
  const [usages, setUsages] = useState(null);
  const [busy, setBusy] = useState(false);
  // Whether the delivery seam wears a previewed value that must restore.
  const previewed = useRef(false);

  // The blast line's number: the existing swatch usage read, once per
  // session entry (the SessionFrame precedent at value scope).
  useEffect(() => {
    let alive = true;
    fetchSwatchUsage(original.name).then(
      (body) =>
        alive &&
        setUsages(typeof body.usages === 'number' ? body.usages : null),
      () => alive && setUsages(null),
    );
    return () => {
      alive = false;
    };
  }, [original.name]);

  const restore = () => {
    if (previewed.current) {
      previewed.current = false;
      source.previewSwatch(original.record, null);
    }
  };
  // Unmount IS close (the chip toggled, the row's value changed from
  // under the session): whatever the session previewed restores.
  useEffect(() => restore, []);

  const preview = (value) => {
    setDraft(value);
    previewed.current = true;
    source.previewSwatch(original.record, value);
  };

  const update = () => {
    setBusy(true);
    updateMaterial(
      source,
      {
        kind: 'swatch',
        id: original.name,
        value: mergedValue(original.record.value, draft),
      },
      Drupal.t('Updated the swatch @label', { '@label': original.label }),
    )
      .then(() => {
        // The committed record supersedes the preview: nothing to restore,
        // the store holds the committed truth in both windows (the palette
        // card's rule).
        previewed.current = false;
        onClose?.();
      })
      .catch(() => {
        // The op queue's source reported it (reportFailure on the leader, the
        // satellite's settle); the session stays open for a correction.
      })
      .finally(() => setBusy(false));
  };

  const discard = () => {
    restore();
    onClose?.();
  };

  return (
    <GradientBuilder
      value={draft}
      guide={guide}
      materials={materials}
      constraints={constraints}
      onPreview={preview}
      onCommit={preview}
      onClose={onClose}
      session={{
        label: original.label,
        ref: original.ref,
        conditions,
        usages,
        busy,
        onUpdate: update,
        onDiscard: discard,
      }}
    />
  );
};
