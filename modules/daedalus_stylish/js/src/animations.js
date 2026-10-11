import { h, Fragment } from 'preact';
import { useEffect, useRef, useState } from 'preact/hooks';
import {
  deleteAnimation,
  fetchAnimationRevisions,
  fetchAnimationUsage,
  renameAnimation,
  restoreAnimationRevision,
  revertAnimation,
} from './animations-api.js';
import {
  respondedMaterial,
  saveMaterial,
  updateMaterial,
} from './material-gestures.js';
import { driveBatch, gesture } from './gesture-transport.js';
import { animationSpelling } from './materials.js';
import { AiOfferToggle, isOffered } from './ai-offer.js';
import {
  SEED_STEPS,
  animationCardGestures,
  animationRows,
  insertStep,
  moveStep,
  removeStep,
  sameSteps,
  stepStops,
  stepsOf,
} from './animations-model.js';
import { positionPercent, trackPositions } from './track-model.js';
import { KeyframeIsland } from './code/keyframe-island.js';
import { RevisionList, revisionDate } from './revision-list.js';

/**
 * The session's stops carry a stable identity beside the track's shape:
 * an island keys on it, so a drag along the track (a position change)
 * re-renders the offset header and never remounts the editor.
 */
let nextStepKey = 1;
const keyedStops = (steps) =>
  stepStops(steps).map((stop) => ({ ...stop, key: nextStepKey++ }));

/**
 * The keyframes editor: the offset track over one island per step.
 *
 * The gradient builder's track for offsets (track-model.js is the shared
 * math; animations-model.js adds the one-step-per-offset rule and the
 * one-step floor): drag a handle to move a step, click the track to add
 * an empty one, double-click a handle to remove it, or type the selected
 * step's offset. Beneath the track, every step in offset order wears an
 * offset header and its island (keyframe-island.js), keyed by the step's
 * stable identity. The editor is pure drafts-in/drafts-out: the caller
 * (a session or the mint card) owns the stops, hears every change through
 * onStops (a track gesture, a settled island; the next stops or an
 * updater over the caller's latest) and decides what to preview and when
 * to commit.
 */
const KeyframesEditor = ({
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
 * gradient swatch session's posture for the motion material.
 *
 * Mount snapshots the record (the session's one baseline: the prop
 * follows the store, and an Update lands a new record). Every settle (a
 * track gesture landing, an island settling) previews the draft on the
 * canvas through the source (leader-source previewAnimation; the
 * satellite relays it as an animation-preview intent): the leader
 * re-prints the preview sheet, so every wearer plays the draft. Update
 * commits the whole steps map as update_material (kind animation), the
 * op the AI submits, revisioned like a swatch edit; Discard (and unmount)
 * releases the preview, and the canvas returns to the stored steps.
 * Update waits on a clean editor: an island holding an invalid line
 * cannot commit (the server would refuse the whole save) and an unchanged
 * draft has nothing to commit.
 */
const KeyframesSession = ({ animation, guide, materials, source, onClose }) => {
  const [original] = useState(animation);
  const [stops, setStops] = useState(() => keyedStops(original.value));
  const [selected, setSelected] = useState(0);
  const [usages, setUsages] = useState(null);
  const [busy, setBusy] = useState(false);
  const [lint, setLint] = useState(() => new Map());
  // Whether the delivery seam wears a previewed draft that must restore.
  const previewed = useRef(false);
  // The latest stops, for the settle that follows a render-lagging drag.
  const latest = useRef(stops);
  latest.current = stops;

  useEffect(() => {
    let alive = true;
    fetchAnimationUsage(original.id).then(
      (body) =>
        alive &&
        setUsages(typeof body.usages === 'number' ? body.usages : null),
      () => alive && setUsages(null),
    );
    return () => {
      alive = false;
    };
  }, [original.id]);

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
        // The op queue's source reported it (reportFailure on the leader, the
        // satellite's settle); the editor stays open for a correction.
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
          {usages === null
            ? Drupal.t('Changes affect every use')
            : Drupal.formatPlural(
                usages,
                'Worn in 1 place · changes affect every use',
                'Worn in @count places · changes affect every use',
              )}
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
          className="daedalus-ui-pill-button daedalus-stylish-keyframes-session__update"
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
          {Drupal.t('Update animation')}
        </button>
      </div>
    </div>
  );
};

/**
 * The lifecycle card, inline under its open list row.
 *
 * The swatch card's twin for the motion material: rename, the keyframes
 * session in the value editor's place (steps are a structured map, so
 * editing is a session with Update/Discard rather than an instant
 * field), the revision history with restore, and delete via the shared
 * dialog. Locked animations (the distro lock, the record's `updatable`
 * flag) render read-only; shipped ones offer "Revert to defaults" when
 * the state is actionable. The gesture roster
 * is data (animations-model.js animationCardGestures): presence means
 * permitted AND applicable. The record is the store's (the prop follows
 * every delta); the management facts beyond it (the live reference
 * count, the shipped state) are one per-open read of the usage route,
 * refreshed after each gesture and again at every prompt. The lifecycle
 * gestures stay routes whose response carries the materials delta,
 * handed to source.applyResponse so the store learns it in both windows.
 */
const AnimationCard = ({
  animation,
  guide,
  materials,
  config,
  source,
  onCard,
}) => {
  const [busy, setBusy] = useState(false);
  const [draft, setDraft] = useState(null);
  const [revisions, setRevisions] = useState(null);
  const [editing, setEditing] = useState(false);
  const [progress, setProgress] = useState(null);
  const [management, setManagement] = useState(null);

  const reference = animation.id;
  const gestures = animationCardGestures(animation, config, management);

  useEffect(() => {
    let alive = true;
    fetchAnimationUsage(reference).then(
      (body) => alive && setManagement(body),
      () => alive && setManagement(null),
    );
    return () => {
      alive = false;
    };
  }, [reference, animation]);

  // Busy state only: gesture() is the route lane's one reporter, so a
  // failure has toasted by the time it settles here.
  const run = (work) => {
    setBusy(true);
    return gesture(source, work)
      .catch(() => null)
      .finally(() => setBusy(false));
  };

  useEffect(() => {
    if (!gestures.revisions) {
      return undefined;
    }
    let alive = true;
    fetchAnimationRevisions(reference).then(
      (body) => alive && setRevisions(body.revisions ?? []),
      () => alive && setRevisions([]),
    );
    return () => {
      alive = false;
    };
  }, [reference, animation]);

  const rename = (value) => {
    const label = value.trim();
    setDraft(null);
    if (label === '' || label === animation.label) {
      return;
    }
    run(() =>
      renameAnimation(reference, label).then((response) => {
        source.applyResponse(response);
        source.notify?.(
          'status',
          Drupal.t('Renamed to "!label".', {
            '!label':
              respondedMaterial(response, 'animation', reference)?.label ??
              label,
          }),
        );
        return null;
      }),
    );
  };

  const restore = (revision) => {
    run(() =>
      fetchAnimationUsage(reference).then(async ({ usages }) => {
        const confirmed = await Drupal.Daedalus.dialog.confirm({
          title: Drupal.t('Restore revision'),
          message: Drupal.t('Restore "!label" to its revision of !date?', {
            '!label': animation.label,
            '!date': revisionDate(revision.created),
          }),
          detail:
            usages === 0
              ? Drupal.t(
                  'A new revision carrying those steps; nothing wears the animation right now.',
                )
              : Drupal.formatPlural(
                  usages,
                  'A new revision carrying those steps; this changes the motion in 1 place site-wide.',
                  'A new revision carrying those steps; this changes the motion in @count places site-wide.',
                ),
          confirmLabel: Drupal.t('Restore'),
        });
        if (!confirmed) {
          return null;
        }
        return restoreAnimationRevision(reference, revision.revision_id).then(
          (response) => {
            source.applyResponse(response);
            source.notify?.(
              'status',
              Drupal.t('Restored "!label" from its revision of !date.', {
                '!label':
                  respondedMaterial(response, 'animation', reference)?.label ??
                  animation.label,
                '!date': revisionDate(revision.created),
              }),
            );
            return null;
          },
        );
      }),
    );
  };

  const revert = () => {
    run(() =>
      fetchAnimationUsage(reference).then(async ({ usages }) => {
        const confirmed = await Drupal.Daedalus.dialog.confirm({
          title: Drupal.t('Revert to defaults'),
          message: Drupal.t('Revert "!label" to the steps its module ships?', {
            '!label': animation.label,
          }),
          detail:
            usages === 0
              ? Drupal.t(
                  'A new revision carrying the shipped steps; nothing wears the animation right now.',
                )
              : Drupal.formatPlural(
                  usages,
                  'A new revision carrying the shipped steps; this changes the motion in 1 place site-wide.',
                  'A new revision carrying the shipped steps; this changes the motion in @count places site-wide.',
                ),
          confirmLabel: Drupal.t('Revert'),
        });
        if (!confirmed) {
          return null;
        }
        return revertAnimation(reference).then((response) => {
          source.applyResponse(response);
          source.notify?.(
            'status',
            Drupal.t('Reverted "!label" to its shipped defaults.', {
              '!label':
                respondedMaterial(response, 'animation', reference)?.label ??
                animation.label,
            }),
          );
          return null;
        });
      }),
    );
  };

  // Delete, always safely: for a worn animation the dialog is ONE action,
  // "Rewrite N references to none, then delete" (the batch: every wearer
  // stops animating, then the entity goes; the client drives the returned
  // core batch URL). There is no "delete anyway".
  const destroy = () => {
    run(() =>
      fetchAnimationUsage(reference).then(async ({ usages }) => {
        const confirmed = await Drupal.Daedalus.dialog.confirm({
          danger: true,
          title: Drupal.t('Delete animation'),
          message: Drupal.t('Delete "!label"?', { '!label': animation.label }),
          detail:
            usages === 0
              ? Drupal.t('Nothing wears it; it is deleted immediately.')
              : Drupal.formatPlural(
                  usages,
                  'Rewrite 1 reference to none, then delete: that place stops animating, then the animation is deleted.',
                  'Rewrite @count references to none, then delete: those places stop animating, then the animation is deleted.',
                ),
          confirmLabel:
            usages === 0 ? Drupal.t('Delete') : Drupal.t('Rewrite and delete'),
        });
        if (!confirmed) {
          return null;
        }
        return deleteAnimation(reference).then(async (result) => {
          if (!result.deleted && result.batch_url) {
            setProgress({ percentage: '0' });
            try {
              await driveBatch(result.batch_url, setProgress);
            } finally {
              setProgress(null);
            }
          }
          source.applyResponse(result);
          onCard(null);
          source.notify?.(
            'status',
            Drupal.t('Deleted "!label".', { '!label': animation.label }),
          );
          return null;
        });
      }),
    );
  };

  return (
    <div className="daedalus-stylish-library-card daedalus-stylish-animations-card">
      <div className="daedalus-stylish-library-card__name">
        {gestures.rename ? (
          <input
            type="text"
            className="daedalus-stylish-library-card__label daedalus-ui-input"
            value={draft ?? animation.label}
            disabled={busy}
            title={Drupal.t(
              'Rename the animation. Its identity never changes, so every reference follows.',
            )}
            onInput={(event) => setDraft(event.target.value)}
            onBlur={(event) => rename(event.target.value)}
            onKeyDown={(event) => {
              if (event.key === 'Enter') {
                rename(event.target.value);
              }
            }}
          />
        ) : (
          <span className="daedalus-stylish-library-card__label">{animation.label}</span>
        )}
        <code
          className="daedalus-stylish-palette-card__variable"
          title={Drupal.t('The keyframes name a motion value is written as.')}
        >
          {animationSpelling(animation)}
        </code>
      </div>
      {/* The AI opt-out (ai-offer.js): the toggle when the card can
          update (update_material, kind animation), a chip when read-only
          and withheld; the description shows only while offered. */}
      {gestures.rename ? (
        <AiOfferToggle
          className="daedalus-stylish-library-card__offer"
          offered={isOffered(animation)}
          disabled={busy}
          onChange={(next) =>
            run(() =>
              updateMaterial(
                source,
                { kind: 'animation', id: reference, offered: next },
                next
                  ? Drupal.t('Offered the animation !label to the AI', {
                      '!label': animation.label,
                    })
                  : Drupal.t('Kept the animation !label from the AI', {
                      '!label': animation.label,
                    }),
              ).then(() => null),
            )
          }
        />
      ) : (
        !isOffered(animation) && (
          <span
            className="daedalus-ui-chip daedalus-stylish-library-card__offer-off"
            title={Drupal.t(
              'Kept from the AI: it leaves the brief and the schema but still compiles and applies.',
            )}
          >
            {Drupal.t('not offered to the AI')}
          </span>
        )
      )}
      {isOffered(animation) && animation.description && (
        <div className="daedalus-stylish-animations-card__description">
          {animation.description}
        </div>
      )}
      <div className="daedalus-stylish-library-card__meta">
        <span>
          {management === null
            ? Drupal.t('counting uses…')
            : management.usages === 0
              ? Drupal.t('worn by nothing')
              : Drupal.formatPlural(
                  management.usages,
                  'worn in 1 place',
                  'worn in @count places',
                )}
        </span>
      </div>
      {editing ? (
        <KeyframesSession
          animation={animation}
          guide={guide}
          materials={materials}
          source={source}
          onClose={() => setEditing(false)}
        />
      ) : (
        <Fragment>
          {gestures.revisions && (
            <RevisionList
              revisions={revisions}
              busy={busy}
              onRestore={restore}
            />
          )}
          {progress && (
            <div className="daedalus-stylish-library-card__progress">
              {Drupal.t('Rewriting… !percent%', {
                '!percent': progress.percentage ?? '0',
              })}
            </div>
          )}
          <div className="daedalus-stylish-library-card__actions">
            {gestures.editSteps && (
              <button
                type="button"
                className="daedalus-ui-pill-button daedalus-stylish-library-card__action"
                disabled={busy}
                title={Drupal.t(
                  'Edit the keyframes; the canvas previews every change, and Update saves them for every place wearing this animation.',
                )}
                onClick={() => setEditing(true)}
              >
                {Drupal.t('Edit keyframes')}
              </button>
            )}
            {gestures.revert && (
              <button
                type="button"
                className="daedalus-ui-pill-button daedalus-stylish-library-card__action"
                disabled={busy}
                onClick={revert}
              >
                {Drupal.t('Revert to defaults')}
              </button>
            )}
            {gestures.delete && (
              <button
                type="button"
                className="daedalus-ui-pill-button daedalus-stylish-library-card__action daedalus-stylish-library-card__action--delete"
                disabled={busy}
                onClick={destroy}
              >
                {Drupal.t('Delete…')}
              </button>
            )}
          </div>
        </Fragment>
      )}
    </div>
  );
};

/**
 * The mint card: the Animations face's "New animation" gesture.
 *
 * Label, description, and the keyframes editor over the seed steps (a
 * fade): the same save_material op (kind animation) the AI submits. No
 * canvas preview here: nothing wears an animation before it exists. The
 * op queue lands the response's materials delta on the store before the
 * promise resolves (the leader relays it to the satellite), so the
 * animation completes on every motion row of both windows and the
 * leader's preview sheet carries its @keyframes before any reload.
 */
const MintCard = ({ guide, materials, source, onClose }) => {
  const [label, setLabel] = useState('');
  const [description, setDescription] = useState('');
  // The AI opt-out (ai-offer.js): born offered, the default; the mint
  // sends the key only when off.
  const [offered, setOffered] = useState(true);
  const [stops, setStops] = useState(() => keyedStops(SEED_STEPS));
  const [selected, setSelected] = useState(0);
  const [lint, setLint] = useState(() => new Map());
  const [minting, setMinting] = useState(false);
  const latest = useRef(stops);
  latest.current = stops;

  const invalid = [...lint.values()].some(Boolean);
  const noteErrors = (key, hasErrors) =>
    setLint((current) => {
      const next = new Map(current);
      next.set(key, hasErrors);
      return next;
    });

  const mint = () => {
    setMinting(true);
    saveMaterial(
      source,
      {
        kind: 'animation',
        label: label.trim(),
        description: description.trim() === '' ? undefined : description.trim(),
        offered: offered ? undefined : false,
        steps: stepsOf(latest.current),
      },
      Drupal.t('Added the animation !label', { '!label': label.trim() }),
    )
      .then((record) => {
        source.notify?.(
          'status',
          Drupal.t('Added the !label animation; wear it as !spelling.', {
            '!label': record.label,
            '!spelling': animationSpelling(record) ?? record.id,
          }),
        );
        onClose();
      })
      .catch(() => {
        // The op queue's source reported it (reportFailure on the leader, the
        // satellite's settle); the editor stays open for a correction.
      })
      .finally(() => setMinting(false));
  };

  return (
    <div className="daedalus-stylish-library-card daedalus-stylish-animations-mint">
      <input
        type="text"
        className="daedalus-stylish-library-card__label daedalus-ui-input"
        placeholder={Drupal.t('Animation name…')}
        value={label}
        onInput={(event) => setLabel(event.target.value)}
      />
      {offered && (
        <input
          type="text"
          className="daedalus-ui-input daedalus-stylish-animations-mint__description"
          placeholder={Drupal.t('What it is for (optional)…')}
          value={description}
          onInput={(event) => setDescription(event.target.value)}
        />
      )}
      <AiOfferToggle offered={offered} onChange={setOffered} />
      <KeyframesEditor
        guide={guide}
        materials={materials}
        stops={stops}
        selected={selected}
        onStops={(next) => {
          const resolved =
            typeof next === 'function' ? next(latest.current) : next;
          latest.current = resolved;
          setStops(resolved);
        }}
        onSelect={setSelected}
        onErrors={noteErrors}
      />
      <div className="daedalus-stylish-library-card__actions">
        <button
          type="button"
          className="daedalus-ui-pill-button daedalus-stylish-library-card__action"
          disabled={minting || invalid || label.trim() === ''}
          title={Drupal.t(
            "Mint the animation: named keyframes that join the site's materials; any element can wear it.",
          )}
          onClick={mint}
        >
          {Drupal.t('Save animation')}
        </button>
        <button
          type="button"
          className="daedalus-ui-pill-button daedalus-stylish-library-card__action"
          disabled={minting}
          onClick={onClose}
        >
          {Drupal.t('Cancel')}
        </button>
      </div>
    </div>
  );
};

/**
 * The Animations face: the catalog's motion section.
 *
 * The palette face's sibling for the motion material: site-wide content,
 * no selection needed. Search, the flat animation list with cards inline,
 * and the mint card. Every row is the materials store's record (the
 * roster this window was handed; a mint, edit or delete in either window
 * re-renders it through the store's delta): nothing fetches a list, and
 * the facts a card needs beyond its record are its own per-open read.
 */
export const AnimationsView = ({ guide, materials, config, source }) => {
  const [search, setSearch] = useState('');
  const [card, setCard] = useState(null);
  const [minting, setMinting] = useState(false);

  const animations = materials.ofKind('animation');
  const rows = animationRows(animations, { search });

  return (
    <div className="daedalus-stylish-library daedalus-stylish-animations">
      <div className="daedalus-stylish-library__controls">
        <input
          type="search"
          className="daedalus-stylish-library__search daedalus-ui-input"
          placeholder={Drupal.t('Search animations…')}
          value={search}
          onInput={(event) => setSearch(event.target.value)}
        />
        {Boolean(config?.canCreate) && !minting && (
          <button
            type="button"
            className="daedalus-ui-pill-button daedalus-stylish-palette__mint-open"
            title={Drupal.t(
              "Mint a new animation: named keyframes that join the site's materials; any element can wear it.",
            )}
            onClick={() => setMinting(true)}
          >
            {Drupal.t('+ New animation')}
          </button>
        )}
      </div>
      {minting && (
        <MintCard
          guide={guide}
          materials={materials}
          source={source}
          onClose={() => setMinting(false)}
        />
      )}
      {rows.length === 0 ? (
        <div className="daedalus-stylish-inspector__empty">
          {animations.length === 0
            ? Drupal.t(
                "No animations exist yet. Mint one here; minted animations join the site's materials and any element can wear them.",
              )
            : Drupal.t('No animations match.')}
        </div>
      ) : (
        <div className="daedalus-stylish-library__list">
          {rows.map((row) => {
            const record = animations.find(
              (candidate) => candidate.id === row.reference,
            );
            return (
              <Fragment key={row.reference}>
                <button
                  type="button"
                  className={`daedalus-stylish-library__row${card === row.reference ? ' daedalus-stylish-library__row--open' : ''}`}
                  aria-expanded={card === row.reference}
                  onClick={() =>
                    setCard(card === row.reference ? null : row.reference)
                  }
                >
                  <span className="daedalus-stylish-library__name">{row.label}</span>
                  <span
                    className="daedalus-ui-chip daedalus-stylish-library__axis"
                    title={Drupal.t(
                      'The offsets this animation steps through.',
                    )}
                  >
                    {row.offsets.join(' · ')}
                  </span>
                  {row.shipped && (
                    <span
                      className="daedalus-ui-chip daedalus-stylish-library__shipped"
                      title={Drupal.t(
                        'Shipped by a module; read-only. Mint your own animation to get an editable one.',
                      )}
                    >
                      {Drupal.t('shipped')}
                    </span>
                  )}
                </button>
                {card === row.reference && record && (
                  <AnimationCard
                    animation={record}
                    guide={guide}
                    materials={materials}
                    config={config}
                    source={source}
                    onCard={setCard}
                  />
                )}
              </Fragment>
            );
          })}
        </div>
      )}
    </div>
  );
};
