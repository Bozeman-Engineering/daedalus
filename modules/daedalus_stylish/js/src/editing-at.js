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
import { createMatcher } from './selector-matcher.js';

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
 * Whether a condition is a structural FACT (the payload's `fact` flag).
 *
 * A fact (':first-child', ':empty') holds by the element's place in the
 * markup, so no gesture can pose it: it never derives from the vector,
 * never lights a chip, and the pose skips it. It is written only where a
 * row addresses its own condition (the code view's islands, the AI).
 */
export const isFactCondition = (name, conditions) =>
  conditions?.[name]?.fact === true;

/**
 * The structural fact names, in table order (the `&` gesture's Position
 * group).
 */
export const factConditionNames = (conditions) =>
  Object.keys(conditions ?? {}).filter((name) =>
    isFactCondition(name, conditions),
  );

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
 * default condition. Only the four canvas axes derive a part; a
 * structural fact's axis is not a canvas axis, so a fact never lands in
 * the editing compound (it is written where a row addresses it).
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
 * default and mark the pose incomplete. A structural FACT is skipped
 * outright: it is the selection's place in the markup, not a canvas
 * pose, so it neither drives an axis nor makes the pose incomplete
 * (driving to 'hover+first-child' poses hover, complete).
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
    if (isFactCondition(part, conditions)) {
      continue;
    }
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
 * select cannot deliver. A structural fact is never posable: the pose
 * skips it rather than failing, so it is excluded by its flag.
 */
export const poseableAxisConditions = (axis, conditions) =>
  Object.entries(conditions ?? {})
    .filter(
      ([name, definition]) =>
        definition?.axis === axis &&
        !isFactCondition(name, conditions) &&
        conditionPose(name, conditions).complete,
    )
    .map(([name]) => name);

/**
 * The conditions carrying styles on a condition-keyed entry — what the
 * inspector's "Styled at" chips list, read over the target's floorless
 * stack (style-stack.js deliveredEntry: the map's layers, every worn
 * reference's conditions beside the own entry's). 'default' and the
 * variants reference list are not conditioned styles; empty blocks carry
 * nothing.
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

/**
 * The structural facts an element satisfies, from the blueprint tree.
 *
 * The tree is the delivered lean node tree (the blueprint's `_tree`,
 * source.getTree(): the leader reads the mirror, the satellite the
 * broadcast), so the read works with or without a canvas. The element's
 * place is its index among its tree siblings (a section's children, a
 * region wrapper's nodes, or the root section list), evaluated by the
 * selector matcher against each fact's printed WRAPPER
 * (selector-matcher.js structuralHolds), so a site's own fact vocabulary
 * needs no client code as long as it prints a structural pseudo-class
 * the twin speaks. A key the tree does not carry (the entity, a field)
 * satisfies nothing; a selector key reads its owner's place.
 *
 * @param {?Array} tree
 *   The lean node tree.
 * @param {?string} key
 *   A selectable key ('block:{uuid}', 'container:{uuid}').
 * @param {Object} conditions
 *   guide.conditions.
 *
 * @return {Set<string>}
 *   The satisfied fact names.
 */
export const satisfiedFacts = (tree, key, conditions) => {
  const satisfied = new Set();
  const facts = factConditionNames(conditions);
  if (!facts.length || typeof key !== 'string') {
    return satisfied;
  }
  const matcher = createMatcher(tree);
  facts.forEach((name) => {
    if (matcher.satisfies(key, conditions[name]?.wrapper ?? '')) {
      satisfied.add(name);
    }
  });
  return satisfied;
};

/**
 * Whether a stored condition renders on the selection right now: its
 * posable parts are exactly the posed compound and every fact part is
 * one the selection satisfies.
 *
 * The code view's lit marker for a condition row. A pure pose ('hover')
 * renders when the canvas stands there; a pure fact ('first-child')
 * renders when the element is one, at any pose; a mixed compound needs
 * both. 'default' never lights (it is the absence of conditions).
 *
 * @param {string} condition
 *   The row's condition key.
 * @param {string} posed
 *   The editing compound the canvas derives (vectorCondition()).
 * @param {Set<string>} satisfied
 *   satisfiedFacts() for the row's element.
 * @param {Object} conditions
 *   guide.conditions.
 */
export const conditionRenders = (condition, posed, satisfied, conditions) => {
  if (typeof condition !== 'string' || condition === 'default') {
    return false;
  }
  const parts = condition.split('+');
  const facts = parts.filter((part) => isFactCondition(part, conditions));
  const posable = parts.filter((part) => !isFactCondition(part, conditions));
  const posedParts = posed && posed !== 'default' ? posed.split('+') : [];
  return (
    posable.join('+') === posedParts.join('+') &&
    facts.every((fact) => satisfied.has(fact))
  );
};
