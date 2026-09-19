/**
 * @file
 * The "Editing at" model: canvas state vector <-> compound condition.
 *
 * The container model's rule: the inspector no longer holds its own
 * editing condition — writes land at the compound the canvas is POSED at.
 * This module is the pure translation layer between daedalus_ui's
 * canvas state vector ({width, scheme, motion}, plus the inspector's own
 * selection-scoped force state on the state axis) and daedalus_stylish's condition
 * vocabulary:
 *
 * - vectorCondition() derives the compound condition writes land at from
 *   the vector + force state. The mapping dispatches on the guide payload's
 *   condition table (drupalSettings.daedalusStylish.guide.conditions), never on
 *   hardcoded names: the EFFECTIVE canvas width — the posed width, else
 *   the measured stage width riding the snapshot beside the axes —
 *   activates the width condition whose declared band contains it (the
 *   stop projection's ranges), a forced scheme/motion activates the
 *   condition whose declared preference value the emulation makes true,
 *   and the force state IS a state-axis condition name. Both facts ride
 *   the payload structured (`band`, `preference`) beside the wrapper
 *   string; nothing here reads the wrapper. Write target = rendered band, at every
 *   canvas width: an unposed canvas narrower than the base band's floor
 *   honestly edits the band it renders.
 * - conditionPose() is the reverse: the vector pose + force state that make
 *   a stored compound render — what "drive the canvas there" sets. @media
 *   width conditions (a site-provided viewport vocabulary; the shipped
 *   width conditions are container queries) answer to the viewport, which the popout preview owns, so they cannot be posed:
 *   the pose reports complete: false and leaves the width axis at default.
 *
 * Part order is canonical automatically: parts assemble in the condition
 * table's axis-declaration order (first appearance over the payload, the
 * same derivation ConditionTypePluginManager::conditionAxes() uses), so
 * the derived compound always matches the stored canonical spelling.
 *
 * The condition is the editing-at vector entire (four controls, one loud
 * mode): WHERE writes land is the posed compound; WHAT is being
 * edited is the inspector's place state (surface-state.js): the
 * places write the element's map, an editing session writes the edited
 * variant's own document. There is no write-target layer flip anymore.
 */

import { stopsFromConditions, stopPosePx } from './canvas-stops.js';

/**
 * The vector's resting pose: full width, follow the OS on both axes.
 */
export const DEFAULT_VECTOR = { width: null, scheme: 'auto', motion: 'auto' };

/**
 * The preference value each forced vector value makes true (emulate-media's
 * substitution semantics): forcing scheme 'dark' satisfies a condition
 * whose preference is (prefers-color-scheme, dark), forcing motion 'full'
 * satisfies (prefers-reduced-motion, no-preference). 'auto' follows the
 * OS — nothing is forced, so no condition part derives from it. The
 * vector's axis names the feature; the condition's `preference.value` is
 * what the forced value is matched against.
 */
const FORCED = {
  scheme: { light: 'light', dark: 'dark' },
  motion: { full: 'no-preference', reduce: 'reduce' },
};

/**
 * The declared preference value of a condition on a preference axis, or
 * null when the payload carries no structured preference fact for it.
 */
const preferenceValue = (definition) =>
  typeof definition?.preference?.value === 'string'
    ? definition.preference.value
    : null;

/**
 * The condition axes in axis-declaration order (first appearance over the
 * payload's condition table — the client mirror of conditionAxes()).
 *
 * @param {Object} conditions
 *   guide.conditions: name -> {label, wrapper, axis}.
 *
 * @return {string[]}
 *   The axis names, each once.
 */
export const conditionAxisOrder = (conditions) => {
  const axes = [];
  for (const definition of Object.values(conditions ?? {})) {
    const axis = definition?.axis;
    if (typeof axis === 'string' && axis !== '' && !axes.includes(axis)) {
      axes.push(axis);
    }
  }
  return axes;
};

/**
 * The state-axis condition names (the force-state vocabulary), in table
 * order.
 */
export const stateConditionNames = (conditions) =>
  Object.entries(conditions ?? {})
    .filter(([, definition]) => definition?.axis === 'state')
    .map(([name]) => name);

/**
 * The effective canvas width: the posed width, else the measured stage
 * width daedalus_ui publishes beside the axes on every vector
 * snapshot (the full width the unposed stage renders at). Null only when
 * neither exists — a page without the canvas facade, a satellite before
 * its first snapshot — where the width axis honestly contributes nothing.
 */
export const effectiveWidth = (vector) =>
  vector?.width ?? vector?.measured ?? null;

/**
 * The width-axis condition an effective canvas width activates, or null.
 *
 * Dispatches on the same @container band projection the ruler renders
 * (stopsFromConditions): the condition whose band contains the width. The
 * shipped bands tile the space below base (narrow, then medium), so a
 * width above every band's ceiling is base, like an unmeasured canvas
 * (null): writes land at the width-axis default either way.
 */
export const widthConditionFor = (width, conditions) => {
  if (width === null || width === undefined) {
    return null;
  }
  const stop = stopsFromConditions(conditions).find(
    (entry) =>
      width >= (entry.min ?? 0) &&
      (entry.max === undefined || width <= entry.max),
  );
  return stop?.name ?? null;
};

/**
 * The scheme/motion condition a forced vector value activates, or null.
 */
const preferenceConditionFor = (axis, value, conditions) => {
  const forced = FORCED[axis][value] ?? null;
  if (forced === null) {
    return null;
  }
  for (const [name, definition] of Object.entries(conditions ?? {})) {
    if (definition?.axis === axis && preferenceValue(definition) === forced) {
      return name;
    }
  }
  return null;
};

/**
 * The compound's parts for a vector pose + force state, in canonical
 * (axis-declaration) order. Empty when the pose activates nothing — the
 * default condition.
 */
export const vectorParts = (vector, forceState, conditions) => {
  const pose = { ...DEFAULT_VECTOR, ...(vector ?? {}) };
  const byAxis = {
    state:
      forceState && conditions?.[forceState]?.axis === 'state'
        ? forceState
        : null,
    width: widthConditionFor(effectiveWidth(pose), conditions),
    scheme: preferenceConditionFor('scheme', pose.scheme, conditions),
    motion: preferenceConditionFor('motion', pose.motion, conditions),
  };
  return conditionAxisOrder(conditions)
    .map((axis) => byAxis[axis] ?? null)
    .filter((part) => part !== null);
};

/**
 * The condition key writes land at for a vector pose + force state:
 * 'default', a single condition, or a canonical 'a+b' compound.
 */
export const vectorCondition = (vector, forceState, conditions) => {
  const parts = vectorParts(vector, forceState, conditions);
  return parts.length ? parts.join('+') : 'default';
};

/**
 * The canvas pose that renders a stored condition — "drive the canvas
 * there".
 *
 * Axes the condition does not name reset to their defaults (full width,
 * auto scheme/motion, no force state), so the derived write condition
 * lands exactly at the driven compound. Parts that cannot be posed, a
 * `@media` width condition (viewport-owned, the popout's business), an
 * unknown name, an axis the vector does not carry — leave their axis at
 * default and mark the pose incomplete.
 *
 * @return {{vector: Object, state: ?string, complete: boolean}}
 */
export const conditionPose = (condition, conditions) => {
  const pose = { vector: { ...DEFAULT_VECTOR }, state: null, complete: true };
  if (
    typeof condition !== 'string' ||
    condition === '' ||
    condition === 'default'
  ) {
    return pose;
  }
  const stops = stopsFromConditions(conditions);
  for (const part of condition.split('+')) {
    const definition = conditions?.[part];
    const axis = definition?.axis;
    if (axis === 'state') {
      pose.state = part;
      continue;
    }
    if (axis === 'width') {
      const stop = stops.find((entry) => entry.name === part);
      if (stop) {
        // The band's midpoint: safely inside both edges, so the derived
        // write condition round-trips to exactly this part.
        pose.vector.width = stopPosePx(stop);
      } else {
        pose.complete = false;
      }
      continue;
    }
    if (axis === 'scheme' || axis === 'motion') {
      const declared = preferenceValue(definition);
      const value =
        Object.entries(FORCED[axis]).find(
          ([, forced]) => forced === declared,
        )?.[0] ?? null;
      if (value) {
        pose.vector[axis] = value;
      } else {
        pose.complete = false;
      }
      continue;
    }
    pose.complete = false;
  }
  return pose;
};

/**
 * The posable condition names on a canvas axis, in table order — the
 * vocabulary the inspector's axis selects offer. A condition the canvas
 * cannot pose (a site-provided @media width condition, the popout
 * viewport's business) is excluded: offering it would promise a pose the
 * select cannot deliver.
 */
export const poseableAxisConditions = (axis, conditions) =>
  Object.entries(conditions ?? {})
    .filter(
      ([name, definition]) =>
        definition?.axis === axis && conditionPose(name, conditions).complete,
    )
    .map(([name]) => name);

/**
 * The conditions carrying styles on an element's (resolved) entry — what
 * the inspector's "Styled at" chips list. 'default' and the variants
 * reference list are not conditioned styles; empty blocks carry nothing.
 */
export const styledConditions = (entry) =>
  Object.entries(entry ?? {})
    .filter(
      ([key, value]) =>
        key !== 'variants' &&
        key !== 'default' &&
        value !== null &&
        typeof value === 'object' &&
        Object.keys(value).length > 0,
    )
    .map(([key]) => key);
