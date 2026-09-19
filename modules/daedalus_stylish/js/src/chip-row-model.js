/**
 * @file
 * The chip-row model: the inspector's pose surface, derived pure.
 *
 * One chip row (State · Width · Scheme · Motion) under the inspector
 * header is the whole pose UI: each chip renders its axis's contribution
 * to the editing compound (lit = contributes a part, dim = resting), and
 * its menu poses that ONE axis of daedalus_ui's canvas state vector
 * (plus the selection-scoped force state on the state axis). This module
 * derives everything the component renders — faces, lit state, menu
 * items, checked marks, and the action each pick dispatches — from the
 * vector + force state + the guide payload's condition table, so the row
 * and the canvas can never disagree.
 *
 * Presentation stays out: faces and labels are typed descriptors (the
 * line-menu model's pattern) the component maps to translated strings;
 * only site vocabulary (a condition's guide label) arrives as text.
 * Actions are data too:
 * - {type: 'force-state', state}: selection-scoped, through the source.
 * - {type: 'pose', axis, value}: a partial canvas-vector write (width in
 *   px or null, scheme auto|light|dark, motion auto|full|reduce).
 * - {type: 'preview-window'}: open the responsive preview popout — the
 *   only true-viewport surface for @media conditions, kept at the width
 *   menu's tail.
 */

import { stopsFromConditions, stopPosePx } from './canvas-stops.js';
import { compoundLabel } from './condition-cascade.js';
import {
  DEFAULT_VECTOR,
  effectiveWidth,
  poseableAxisConditions,
  stateConditionNames,
  widthConditionFor,
} from './editing-at.js';

/**
 * The resolved system color scheme — the scheme chip's resting face.
 *
 * The face never reads "Auto" (Tim's resolved-face rule): at rest it
 * shows what the system actually is, dim; forcing lights it. Injected
 * matchMedia so the derivation stays node-testable.
 *
 * @param {?Function} matchMedia
 *   window.matchMedia (or a test double); missing degrades to light.
 *
 * @return {string}
 *   'dark' or 'light'.
 */
export const systemScheme = (matchMedia) =>
  typeof matchMedia === 'function' &&
  matchMedia('(prefers-color-scheme: dark)')?.matches
    ? 'dark'
    : 'light';

/**
 * A guide condition's display label (site vocabulary, not translation).
 */
const conditionLabel = compoundLabel;

/**
 * The force state, validated against the state-axis vocabulary.
 */
const validForceState = (forceState, conditions) =>
  forceState && conditions?.[forceState]?.axis === 'state' ? forceState : null;

/**
 * The width chip's face and lit state.
 *
 * The face is the WRITE TARGET, never canvas geometry: the effective
 * canvas width (posed ?? measured) inside a condition's band wears that
 * condition's label (lit), and a width above every band wears Base (dim:
 * base applies at every width, nothing non-default is posed). Px never
 * reaches the face; the ruler owns geometry. Because the derivation
 * reads the MEASURED width when nothing is posed, the face is honest at
 * every canvas size: a full-width canvas in the base band says Base and
 * writes base, and a window narrower than the base band's floor says
 * (and writes) the band it actually renders. An unmeasured canvas (no
 * facade, a satellite before its first snapshot) degrades to Base, the
 * unconditioned layer.
 */
const widthFace = (width, conditions) => {
  const active = widthConditionFor(width, conditions);
  if (active === null) {
    return { face: { kind: 'base' }, lit: false };
  }
  return {
    face: {
      kind: 'condition',
      name: active,
      label: conditionLabel(active, conditions),
    },
    lit: true,
  };
};

/**
 * The chip roster: one descriptor per rendered chip, in row order.
 *
 * Face kinds: {kind: 'axis-name'} (the resting State/Motion word),
 * {kind: 'base'}, {kind: 'condition', name, label}, {kind: 'scheme',
 * value}, {kind: 'motion', value}. The retired {kind: 'full-width'} is
 * gone entirely: the un-pose menu entry wears Base, like the face it
 * produces.
 * Lit = the chip contributes a part to the editing compound, so the row
 * doubles as the Editing-at readout. The state chip renders only when
 * the guide declares a state vocabulary, and disables without a
 * selection (force state is selection-scoped); the canvas axes work
 * selection-less.
 *
 * @param {?Object} vector
 *   The canvas state vector snapshot ({width, scheme, motion}, plus the
 *   measured stage width as `measured`), or null.
 * @param {?string} forceState
 *   The selection-scoped force state (a state-axis condition name).
 * @param {Object} conditions
 *   guide.conditions: name -> {label, wrapper, axis}.
 * @param {Object} options
 *   {scheme: 'light'|'dark' (systemScheme()), hasSelection: boolean}.
 *
 * @return {Array<{axis: string, face: Object, lit: boolean,
 *   disabled: boolean}>}
 */
export const chipFaces = (vector, forceState, conditions, options = {}) => {
  const { scheme = 'light', hasSelection = true } = options;
  const pose = { ...DEFAULT_VECTOR, ...(vector ?? {}) };
  const chips = [];
  if (stateConditionNames(conditions).length > 0) {
    const forced = validForceState(forceState, conditions);
    chips.push({
      axis: 'state',
      face: forced
        ? {
            kind: 'condition',
            name: forced,
            label: conditionLabel(forced, conditions),
          }
        : { kind: 'axis-name' },
      lit: forced !== null,
      disabled: !hasSelection,
    });
  }
  chips.push({
    axis: 'width',
    ...widthFace(effectiveWidth(pose), conditions),
    disabled: false,
  });
  chips.push({
    axis: 'scheme',
    face: {
      kind: 'scheme',
      value: pose.scheme === 'auto' ? scheme : pose.scheme,
    },
    lit: pose.scheme !== 'auto',
    disabled: false,
  });
  chips.push({
    axis: 'motion',
    face:
      pose.motion === 'auto'
        ? { kind: 'axis-name' }
        : { kind: 'motion', value: pose.motion },
    lit: pose.motion !== 'auto',
    disabled: false,
  });
  return chips;
};

/**
 * A chip menu's items, derived per axis.
 *
 * Items are {key, label, hint, checked, action} (label reuses the face
 * descriptor kinds, plus {kind: 'no-state'} and
 * {kind: 'preview-window'}; `system` marks the follow-the-system entry
 * for its "(system)" suffix); {separator: true} draws a divider. Hints:
 * {kind: 'range', min?, max?} on width conditions (the band's inclusive
 * edges, both present on a true range like medium),
 * {kind: 'write-target', value} on scheme entries (where edits land —
 * the reason the menu needs three entries: two could never force dark
 * on a dark system, leaving dark-only styles unwritable there).
 *
 * - state: No state + the guide's state-axis vocabulary.
 * - width: Base (the un-pose entry) + the posable width conditions (no
 *   empty option; `@media` viewport conditions are the popout's business
 *   and excluded),
 *   then the preview-window tail when the host carries the payload.
 * - scheme: resolved-system entry + Light + Dark, checked against the
 *   VECTOR (following the system never checks a forced entry, even when
 *   they render the same word).
 * - motion: Auto (system) + Full motion + Reduced.
 *
 * @param {string} axis
 *   'state' | 'width' | 'scheme' | 'motion'.
 * @param {Object} options
 *   {vector, forceState, conditions, scheme, previewWindow: boolean}.
 *
 * @return {Array<Object>}
 */
export const chipMenuItems = (axis, options = {}) => {
  const {
    vector,
    forceState,
    conditions,
    scheme = 'light',
    previewWindow = false,
  } = options;
  const pose = { ...DEFAULT_VECTOR, ...(vector ?? {}) };
  if (axis === 'state') {
    const forced = validForceState(forceState, conditions);
    return [
      {
        key: '',
        label: { kind: 'no-state' },
        hint: null,
        checked: forced === null,
        action: { type: 'force-state', state: null },
      },
      ...stateConditionNames(conditions).map((name) => ({
        key: name,
        label: {
          kind: 'condition',
          name,
          label: conditionLabel(name, conditions),
        },
        hint: null,
        checked: forced === name,
        action: { type: 'force-state', state: name },
      })),
    ];
  }
  if (axis === 'width') {
    const stops = stopsFromConditions(conditions);
    const active = widthConditionFor(effectiveWidth(pose), conditions);
    const items = [
      // The un-pose entry wears Base and returns the canvas to its
      // natural width. Its checkmark tracks the WRITE TARGET like every
      // other entry, derived from the EFFECTIVE width: it checks only
      // when the natural (measured) canvas actually sits in the base
      // band. On a window narrower than the base band's floor, un-posing
      // honestly lands (and checks) the band the canvas renders — there
      // is no force-base escape hatch; base needs a canvas wide enough
      // to render it.
      {
        key: '',
        label: { kind: 'base' },
        hint: null,
        checked: active === null,
        action: { type: 'pose', axis: 'width', value: null },
      },
      // Every posable width condition has a band by construction (the
      // pose derives from the stop projection). The hint carries the
      // band's edges (two-sided for a true range like medium); the pick
      // poses the band's midpoint, the same px drive-there uses, so the
      // checkmark and the pose can never disagree.
      ...poseableAxisConditions('width', conditions).map((name) => {
        const stop = stops.find((entry) => entry.name === name);
        return {
          key: name,
          label: {
            kind: 'condition',
            name,
            label: conditionLabel(name, conditions),
          },
          hint: {
            kind: 'range',
            ...(stop.min !== undefined && { min: stop.min }),
            ...(stop.max !== undefined && { max: stop.max }),
          },
          checked: active === name,
          action: { type: 'pose', axis: 'width', value: stopPosePx(stop) },
        };
      }),
    ];
    if (previewWindow) {
      items.push(
        { separator: true },
        {
          key: 'preview-window',
          label: { kind: 'preview-window' },
          hint: null,
          checked: false,
          action: { type: 'preview-window' },
        },
      );
    }
    return items;
  }
  if (axis === 'scheme') {
    const entry = (value, shown, system) => ({
      key: value,
      label: { kind: 'scheme', value: shown, system },
      hint: { kind: 'write-target', value },
      checked: pose.scheme === value,
      action: { type: 'pose', axis: 'scheme', value },
    });
    return [
      entry('auto', scheme, true),
      entry('light', 'light', false),
      entry('dark', 'dark', false),
    ];
  }
  if (axis === 'motion') {
    return ['auto', 'full', 'reduce'].map((value) => ({
      key: value,
      label: { kind: 'motion', value, system: value === 'auto' },
      hint: null,
      checked: pose.motion === value,
      action: { type: 'pose', axis: 'motion', value },
    }));
  }
  return [];
};
