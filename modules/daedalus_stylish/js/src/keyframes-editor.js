import { h } from 'preact';
import { useEffect, useRef, useState } from 'preact/hooks';
import { updateMaterial } from './material-gestures.js';
import { animationSpelling } from './materials.js';
import {
  insertStep,
  moveStep,
  removeStep,
  sameSteps,
  stepStops,
  stepsOf,
} from './skin-model.js';
import { positionPercent, trackPositions } from './track-model.js';
import { KeyframeIsland } from './code/keyframe-island.js';

/**
 * The session's stops carry a stable identity beside the track's shape:
 * an island keys on it, so a drag along the track (a position change)
 * re-renders the offset header and never remounts the editor.
 */
let nextStepKey = 1;
export const keyedStops = (steps) =>
  stepStops(steps).map((stop) => ({ ...stop, key: nextStepKey++ }));

/**
 * The keyframes editor: the offset track over one island per step.
 *
 * The gradient builder's track for offsets (track-model.js is the shared
 * math; skin-model.js adds the one-step-per-offset rule and the one-step
 * floor): drag a handle to move a step, click the track to add an empty
 * one, double-click a handle to remove it, or type the selected step's
 * offset. Beneath the track, every step in offset order wears an offset
 * header and its island (keyframe-island.js), keyed by the step's stable
 * identity. The editor is pure drafts-in/drafts-out: the caller (a
 * session or the mint card) owns the stops, hears every change through
 * onStops (a track gesture, a settled island; the next stops or an
 * updater over the caller's latest) and decides what to preview and when
 * to commit.
 */
export const KeyframesEditor = ({
  guide,
  materials,
  stops,
  selected,
  onStops,
  onSelect,
  onErrors,
}) => {
  const [offsetDraft, setOffsetDraft] = useState(null);
  // The live drag, pointer-captured on the handle: refs, not state, and
  // the drag carries its own authoritative stops (pointer events outpace
  // the render cycle).
  const dragging = useRef(null);
  const dragStops = useRef(null);
  const sel = Math.min(selected, stops.length - 1);
  const display = trackPositions(stops);

  const percentAt = (event) => {
    const rect = event.currentTarget.getBoundingClientRect();
    return ((event.clientX - rect.left) / rect.width) * 100;
  };
  const apply = (result, settle = true) => {
    if (!result) {
      return;
    }
    onSelect(result.selected);
    setOffsetDraft(null);
    onStops(result.stops, settle);
  };
  const trackDown = (event) => {
    const handle = event.target.closest('.daedalus-stylish-keyframes__handle');
    if (!handle) {
      return;
    }
    dragging.current = Number(handle.dataset.index);
    dragStops.current = stops;
    onSelect(dragging.current);
    handle.setPointerCapture(event.pointerId);
  };
  const trackMove = (event) => {
    if (dragging.current === null) {
      return;
    }
    const result = moveStep(
      dragStops.current,
      dragging.current,
      percentAt(event),
    );
    if (!result) {
      return;
    }
    dragStops.current = result.stops;
    dragging.current = result.selected;
    onSelect(result.selected);
    // The handle follows per move; the preview waits for the settle (a
    // sheet re-print per pointer move is a style reconstruct per move).
    onStops(result.stops, false);
  };
  const trackUp = () => {
    if (dragging.current === null) {
      return;
    }
    dragging.current = null;
    const next = dragStops.current;
    dragStops.current = null;
    onStops(next, true);
  };
  const trackClick = (event) => {
    if (event.target.closest('.daedalus-stylish-keyframes__handle')) {
      return;
    }
    apply(insertStep(stops, percentAt(event)));
  };
  const trackDoubleClick = (event) => {
    const handle = event.target.closest('.daedalus-stylish-keyframes__handle');
    if (handle) {
      apply(removeStep(stops, Number(handle.dataset.index), sel));
    }
  };
  const commitOffset = () => {
    if (offsetDraft === null) {
      return;
    }
    const parsed = Number(offsetDraft);
    setOffsetDraft(null);
    if (Number.isFinite(parsed)) {
      apply(moveStep(stops, sel, parsed));
    }
  };

  return (
    <div className="daedalus-stylish-keyframes">
      <div className="daedalus-stylish-keyframes__track-wrap">
        <div
          className="daedalus-stylish-keyframes__track"
          onPointerDown={trackDown}
          onPointerMove={trackMove}
          onPointerUp={trackUp}
          onPointerCancel={trackUp}
          onClick={trackClick}
          onDblClick={trackDoubleClick}
        >
          {stops.map((stop, i) => (
            <div
              key={stop.key}
              className={`daedalus-stylish-keyframes__handle${i === sel ? ' daedalus-stylish-keyframes__handle--selected' : ''}`}
              style={{ left: `${display[i]}%` }}
              data-index={i}
              title={stop.position}
            >
              <span className="daedalus-stylish-keyframes__handle-tag">
                {stop.position}
              </span>
            </div>
          ))}
        </div>
        <div className="daedalus-stylish-keyframes__track-hint">
          {Drupal.t(
            'Drag steps to move them · click the track to add one · double-click a step to remove it',
          )}
        </div>
      </div>
      <div className="daedalus-stylish-keyframes__selected">
        <label className="daedalus-stylish-keyframes__offset">
          <span>{Drupal.t('Offset')}</span>
          <input
            type="number"
            className="daedalus-ui-input daedalus-stylish-keyframes__offset-input"
            min="0"
            max="100"
            step="0.1"
            value={offsetDraft ?? positionPercent(stops[sel]?.position) ?? ''}
            onInput={(event) => setOffsetDraft(event.target.value)}
            onBlur={commitOffset}
            onKeyDown={(event) => {
              if (event.key === 'Enter') {
                commitOffset();
              }
            }}
          />
          <span className="daedalus-stylish-keyframes__offset-unit">%</span>
        </label>
        <button
          type="button"
          className="daedalus-ui-chip daedalus-stylish-keyframes__remove"
          disabled={stops.length <= 1}
          title={
            stops.length <= 1
              ? Drupal.t('An animation needs at least one step.')
              : Drupal.t('Remove the selected step.')
          }
          onClick={() => apply(removeStep(stops, sel, sel))}
        >
          {Drupal.t('remove step')}
        </button>
      </div>
      <div className="daedalus-stylish-keyframes__steps">
        {stops.map((stop, i) => (
          <div
            key={stop.key}
            className={`daedalus-stylish-keyframes__step${i === sel ? ' daedalus-stylish-keyframes__step--selected' : ''}`}
          >
            <button
              type="button"
              className="daedalus-stylish-keyframes__step-head"
              title={Drupal.t('The declarations at this offset.')}
              onClick={() => onSelect(i)}
            >
              <span className="daedalus-ui-chip daedalus-stylish-keyframes__step-offset">
                {stop.position}
              </span>
            </button>
            <KeyframeIsland
              guide={guide}
              materials={materials}
              declarations={stop.declarations}
              autoFocus={
                Object.keys(stop.declarations).length === 0 && i === sel
              }
              onSettle={(declarations, hasErrors) => {
                // An updater, not this render's stops: a settle can land
                // while a track gesture's stops are still on their way to
                // a render.
                onErrors(stop.key, hasErrors);
                onStops(
                  (current) =>
                    current.map((candidate) =>
                      candidate.key === stop.key
                        ? { ...candidate, declarations }
                        : candidate,
                    ),
                  true,
                );
              }}
            />
          </div>
        ))}
      </div>
    </div>
  );
};

/**
 * The keyframes session: the animation's steps edited in place, the
 * gradient token session's posture for the motion material.
 *
 * Mount snapshots the record (the session's one baseline: the prop
 * follows the store, and an Update lands a new record). Every settle (a
 * track gesture landing, an island settling) previews the draft on the
 * canvas through the source (leader-source previewAnimation; the
 * satellite relays it as an animation-preview intent): the leader
 * re-prints the preview sheet, so every wearer plays the draft. Update
 * commits the whole steps map as update_material (kind animation), the
 * op the AI submits, a skin revision like a token edit; Discard (and
 * unmount) releases the preview, and the canvas returns to the stored
 * steps. Update waits on a clean editor: an island holding an invalid
 * line cannot commit (the server would refuse the whole save) and an
 * unchanged draft has nothing to commit.
 */
export const KeyframesSession = ({ animation, guide, materials, source, onClose }) => {
  const [original] = useState(animation);
  const [stops, setStops] = useState(() => keyedStops(original.value));
  const [selected, setSelected] = useState(0);
  const [busy, setBusy] = useState(false);
  const [lint, setLint] = useState(() => new Map());
  // Whether the delivery seam wears a previewed draft that must restore.
  const previewed = useRef(false);
  // The latest stops, for the settle that follows a render-lagging drag.
  const latest = useRef(stops);
  latest.current = stops;

  const restore = () => {
    if (previewed.current) {
      previewed.current = false;
      source.previewAnimation(original, null);
    }
  };
  // Unmount IS close: whatever the session previewed restores.
  useEffect(() => restore, []);

  const change = (next, settle) => {
    const resolved = typeof next === 'function' ? next(latest.current) : next;
    latest.current = resolved;
    setStops(resolved);
    if (settle) {
      previewed.current = true;
      source.previewAnimation(original, stepsOf(resolved));
    }
  };
  const noteErrors = (key, hasErrors) =>
    setLint((current) => {
      const next = new Map(current);
      next.set(key, hasErrors);
      return next;
    });
  const invalid = [...lint.values()].some(Boolean);
  const dirty = !sameSteps(original.value, stepsOf(stops));

  const update = () => {
    setBusy(true);
    updateMaterial(
      source,
      { kind: 'animation', id: original.id, steps: stepsOf(latest.current) },
      Drupal.t('Updated the animation !label', { '!label': original.label }),
    )
      .then(() => {
        // The committed record supersedes the preview: the store holds the
        // committed truth in both windows and the sheet re-prints from it.
        previewed.current = false;
        source.notify?.(
          'status',
          Drupal.t('Updated "!label"; everything wearing it follows.', {
            '!label': original.label,
          }),
        );
        onClose();
      })
      .catch(() => {
        // The op queue's source reported it; the editor stays open for a
        // correction.
      })
      .finally(() => setBusy(false));
  };
  const discard = () => {
    restore();
    onClose();
  };

  return (
    <div className="daedalus-stylish-keyframes-session">
      <div className="daedalus-stylish-keyframes-session__head">
        <span className="daedalus-stylish-keyframes-session__who">
          <strong className="daedalus-stylish-keyframes-session__name">
            {original.label}
          </strong>
          <code className="daedalus-stylish-keyframes-session__spelling">
            {animationSpelling(original)}
          </code>
        </span>
        <span className="daedalus-stylish-keyframes-session__blast">
          {Drupal.t('Changes affect every use')}
        </span>
      </div>
      <KeyframesEditor
        guide={guide}
        materials={materials}
        stops={stops}
        selected={selected}
        onStops={change}
        onSelect={setSelected}
        onErrors={noteErrors}
      />
      <div className="daedalus-stylish-keyframes-session__bar">
        <button
          type="button"
          className="daedalus-ui-pill-button"
          disabled={busy}
          title={Drupal.t(
            'Close without saving; the animation and every use keep their stored steps.',
          )}
          onClick={discard}
        >
          {Drupal.t('Discard')}
        </button>
        <button
          type="button"
          className="daedalus-ui-pill-button daedalus-stylish-keyframes-session__update daedalus-stylish-material-card__save"
          disabled={busy || invalid || !dirty}
          title={
            invalid
              ? Drupal.t(
                  'A step holds a line that cannot save; fix or remove it first.',
                )
              : Drupal.t(
                  'Save the steps; every place wearing this animation plays the new ones.',
                )
          }
          onClick={update}
        >
          {Drupal.t('Save keyframes')}
        </button>
      </div>
    </div>
  );
};
