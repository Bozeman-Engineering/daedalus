/**
 * @file
 * The chip-row model: the inspector's pose surface, derived pure.
 *
 * One chip row (State · Width · Scheme · Motion) under the inspector
 * header is the whole pose UI: each chip renders its axis's contribution
 * to the editing compound (lit = contributes a part, dim = resting), and
 * its menu poses that ONE axis of daedalus_ui's canvas state vector
 * (plus the selection-scoped force state on the state axis). Where an
 * element sits among its siblings is never a condition and so never a
 * chip: a position is selector text, a qualifier of the compound it
 * stands on, which no pose can change. This module
 * derives everything the component renders — faces, lit state, menu
 * items, checked marks, and the action each pick dispatches — from the
 * vector + force state + the guide payload's condition table + the
 * LITERAL clauses in use on the selected element's rows (editing-at.js
 * literalClauses), so the row and the canvas can never disagree. The
 * axis menus list the published references; a literal in use shows as a
 * chip entry on its axis (a `@container (min-width: 500px)` row's band
 * on the width menu, a `:checked` row's state on the state menu), faced
 * by its own canonical text, so it can be posed and lit like a
 * reference; a literal the canvas cannot pose (a @media width, an
 * `@supports` test, `:visited`) is never a chip (condition-clause.js
 * clausePosable), and the stamp word a literal state poses is
 * clauseStamp's, never the text.
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
import {
  PSEUDO,
  clausePosable,
  clauseStamp,
} from '../../../daedalus_ui/js/edit_mode/materials/condition-clause.js';

/**
 * The literal width bands in use: the literal clauses deriving a band,
 * map order (editing-at.js literalClauses).
 *
 * @param {?Array<{band: ?object}>} literals
 *   The literal clauses in use.
 *
 * @return {Array<object>}
 *   The clauses deriving a band.
 */
const literalBands = (literals) =>
  (literals ?? []).filter((clause) => clause.band !== null);

/**
 * The literal states in use the stamp can pose: the posable pseudo-class
 * literals, map order.
 *
 * @param {?Array<object>} literals
 *   The literal clauses in use.
 *
 * @return {Array<object>}
 *   The posable pseudo-class clauses.
 */
const literalStates = (literals) =>
  (literals ?? []).filter(
    (clause) => clause.form === PSEUDO && clausePosable(clause),
  );

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
 * The force state as a chip face: {name, label} for a state-axis
 * condition name (its guide label) or a posable literal state in use
 * whose stamp word the force state carries (faced by the literal's own
 * text, `:checked`); null for anything else.
 *
 * @param {?string} forceState
 *   The selection-scoped force state.
 * @param {?Object} conditions
 *   guide.conditions.
 * @param {?Array<object>} literals
 *   The literal clauses in use (editing-at.js literalClauses).
 *
 * @return {?{name: string, label: string}}
 *   The face, or null.
 */
const forcedState = (forceState, conditions, literals) => {
  if (!forceState) {
    return null;
  }
  if (conditions?.[forceState]?.axis === 'state') {
    return { name: forceState, label: conditionLabel(forceState, conditions) };
  }
  const literal = literalStates(literals).find(
    (clause) => clauseStamp(clause) === forceState,
  );
  return literal ? { name: forceState, label: literal.text } : null;
};

/**
 * The width chip's face and lit state.
 *
 * The face is the WRITE TARGET, never canvas geometry: the effective
 * canvas width (posed ?? measured) inside a condition's band wears that
 * condition's label (lit; a literal band in use its own text), and a
 * width above every band wears Base (dim: base applies at every width,
 * nothing non-default is posed). Px never
 * reaches the face; the ruler owns geometry. Because the derivation
 * reads the MEASURED width when nothing is posed, the face is honest at
 * every canvas size: a full-width canvas in the base band says Base and
 * writes base, and a window narrower than the base band's floor says
 * (and writes) the band it actually renders. An unmeasured canvas (no
 * facade, a satellite before its first snapshot) degrades to Base, the
 * unconditioned layer.
 *
 * @param {?number} width
 *   The effective canvas width, px (editing-at.js effectiveWidth).
 * @param {?Object} conditions
 *   guide.conditions.
 * @param {Array<object>} literals
 *   The literal clauses in use (editing-at.js literalClauses).
 *
 * @return {{face: object, lit: boolean}}
 *   The chip's face and whether it is lit.
 */
const widthFace = (width, conditions, literals) => {
  const active = widthConditionFor(width, conditions, literals);
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
 *   {scheme: 'light'|'dark' (systemScheme()), hasSelection: boolean,
 *   literals: the literal clauses in use on the selected element's rows
 *   (editing-at.js literalClauses)}.
 *
 * @return {Array<{axis: string, face: Object, lit: boolean,
 *   disabled: boolean}>}
 *   The chips, in row order.
 */
export const chipFaces = (vector, forceState, conditions, options = {}) => {
  const { scheme = 'light', hasSelection = true, literals = [] } = options;
  const pose = { ...DEFAULT_VECTOR, ...(vector ?? {}) };
  const chips = [];
  if (
    stateConditionNames(conditions).length > 0 ||
    literalStates(literals).length > 0
  ) {
    const forced = forcedState(forceState, conditions, literals);
    chips.push({
      axis: 'state',
      face: forced
        ? { kind: 'condition', name: forced.name, label: forced.label }
        : { kind: 'axis-name' },
      lit: forced !== null,
      disabled: !hasSelection,
    });
  }
  chips.push({
    axis: 'width',
    ...widthFace(effectiveWidth(pose), conditions, literals),
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
 * - state: No state + the guide's state-axis vocabulary + the posable
 *   literal states in use (keyed and posed by their stamp word, faced by
 *   their text).
 * - width: Base (the un-pose entry) + the posable width conditions (no
 *   empty option; `@media` viewport conditions are the popout's business
 *   and excluded) + the literal width bands in use (keyed by their text),
 *   then the preview-window tail when the host carries the payload.
 * - scheme: resolved-system entry + Light + Dark, checked against the
 *   VECTOR (following the system never checks a forced entry, even when
 *   they render the same word).
 * - motion: Auto (system) + Full motion + Reduced.
 *
 * @param {string} axis
 *   'state' | 'width' | 'scheme' | 'motion'.
 * @param {Object} options
 *   {vector, forceState, conditions, literals, scheme, previewWindow:
 *   boolean}.
 *
 * @return {Array<Object>}
 *   The axis menu's items (chip-row.js maps them to text).
 */
export const chipMenuItems = (axis, options = {}) => {
  const {
    vector,
    forceState,
    conditions,
    literals = [],
    scheme = 'light',
    previewWindow = false,
  } = options;
  const pose = { ...DEFAULT_VECTOR, ...(vector ?? {}) };
  if (axis === 'state') {
    const forced = forcedState(forceState, conditions, literals)?.name ?? null;
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
      // A literal state in use: posed by its stamp word (what the
      // printed dual matches), faced by its own text.
      ...literalStates(literals).map((clause) => {
        const stamp = clauseStamp(clause);
        return {
          key: stamp,
          label: { kind: 'condition', name: stamp, label: clause.text },
          hint: null,
          checked: forced === stamp,
          action: { type: 'force-state', state: stamp },
        };
      }),
    ];
  }
  if (axis === 'width') {
    const stops = stopsFromConditions(conditions);
    const active = widthConditionFor(
      effectiveWidth(pose),
      conditions,
      literals,
    );
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
      // A literal width band in use: its own text as the face, its
      // derived band as the hint and the pose, keyed by the text the
      // write target spells.
      ...literalBands(literals).map((clause) => ({
        key: clause.text,
        label: { kind: 'condition', name: clause.text, label: clause.text },
        hint: {
          kind: 'range',
          ...(clause.band.min !== undefined && { min: clause.band.min }),
          ...(clause.band.max !== undefined && { max: clause.band.max }),
        },
        checked: active === clause.text,
        action: { type: 'pose', axis: 'width', value: stopPosePx(clause.band) },
      })),
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
