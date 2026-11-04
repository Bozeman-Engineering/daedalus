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
 *   condition table (drupalSettings.daedalusStylish.guide.conditions) and
 *   on the LITERAL clauses in use on the selected element's rows
 *   (literalClauses), never on hardcoded names: the EFFECTIVE canvas
 *   width — the posed width, else the measured stage width riding the
 *   snapshot beside the axes — activates the width clause whose band
 *   contains it, a forced scheme/motion activates the clause whose
 *   preference value the emulation makes true, and the force state is a
 *   state-axis condition name or a literal state's stamp word. Bands and
 *   preferences are DERIVED from each clause's CSS by the clause grammar
 *   (condition-clause.js, the one reader of wrapper text: a @container
 *   width query's px edges, a "prefers-*" media query's feature and
 *   value), for a published reference and a typed literal alike; nothing
 *   here reads the wrapper. Write target = rendered band, at every canvas
 *   width: an unposed canvas narrower than the base band's floor honestly
 *   edits the band it renders. Where a literal band in use and a
 *   published band both hold at the width (a `@container (min-width:
 *   400px) and (max-width: 480px)` row on an element whose site publishes
 *   `narrow` up to 767px), the write target is the band the cascade
 *   delivers LAST at that width: the printers emit a literal clause after
 *   every published condition (condition-cascade.js cascadeOrder, the
 *   server's orderedConditions), so a property both set is the literal's
 *   on the canvas, and editing what the canvas shows means editing the
 *   literal; among literals the later in map order wins the same way. A
 *   pose outside the literal's band (Narrow's own midpoint) edits the
 *   reference again.
 * - conditionPose() is the reverse: the vector pose + force state that make
 *   a stored compound render — what "drive the canvas there" sets — read
 *   off the clause records (a band poses its midpoint, a preference the
 *   forced value that makes it true, a posable pseudo-class its stamp
 *   word). A clause the canvas cannot pose (a @media width, the
 *   viewport's and the popout preview's business; a @supports test; a
 *   preference no vector axis carries; `:visited`) leaves its axis at
 *   default and the pose reports complete: false.
 *
 * Part order is canonical automatically: the derived parts are spelled
 * through the clause grammar's canonicalCompound (published axes by
 * first appearance, then literal axes), so the derived compound always
 * matches the stored canonical spelling.
 *
 * The condition is the editing-at vector entire (four controls, one loud
 * mode): WHERE writes land is the posed compound; WHAT is being
 * edited is the inspector's place state (surface-state.js): the
 * places write the element's map, an editing session writes the edited
 * variant's own document. There is no write-target layer flip anymore.
 */

import {
  IS,
  parseSelector,
} from '../../../daedalus_ui/js/edit_mode/materials/relative-selector.js';
import { stopsFromConditions, stopPosePx } from './canvas-stops.js';
import {
  DELIMITER,
  LITERAL,
  PSEUDO,
  REFERENCE,
  canonicalCompound,
  clausePosable,
  clausePreference,
  clauseStamp,
  compoundParts,
  parseCompound,
} from '../../../daedalus_ui/js/edit_mode/materials/condition-clause.js';
import { conditionForPseudoClass } from './style-guide.js';

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
 * The media feature each vector axis emulates: what a literal preference
 * clause in use is matched against (a published condition is matched by
 * its declared axis, a literal by the feature it tests, since its axis is
 * the feature's name where nothing published tests it).
 */
const FEATURES = {
  scheme: 'prefers-color-scheme',
  motion: 'prefers-reduced-motion',
};

/**
 * The preference a condition's wrapper tests ({feature, value}), derived
 * by the clause grammar, or null when its wrapper tests no single user
 * preference.
 *
 * @param {?{wrapper?: string}} definition
 *   A condition definition (guide.conditions[name]).
 *
 * @return {?{feature: string, value: string}}
 *   The preference, or null.
 */
const preferenceOf = (definition) =>
  typeof definition?.wrapper === 'string'
    ? clausePreference(definition.wrapper)
    : null;

/**
 * Whether a band {min?, max?} holds at a width (inclusive edges).
 *
 * @param {{min?: number, max?: number}} band
 *   The band.
 * @param {number} width
 *   A canvas width, px.
 *
 * @return {boolean}
 *   Whether the width is inside the band.
 */
const bandHolds = (band, width) =>
  width >= (band.min ?? 0) && (band.max === undefined || width <= band.max);

/**
 * The literal clauses in use on an element's rows.
 *
 * The LITERAL clause records (condition-clause.js parseCompound) of
 * every condition carrying a value on the element's own entry and on
 * its rule entries (`{key}::…`), each once by canonical text, in map
 * order: the WHEN a site admitted beside its published references (a
 * typed `@container (min-width: 500px)`, a `:checked`, a `@media
 * (prefers-contrast: more)`). The pose derivation reads them beside the
 * table so a literal in use poses like a published band or state, the
 * chip row shows each as a chip on its axis, and the ruler marks a
 * literal width band; a reference's clause is the table's and is never
 * here. Conditions holding no value are not in use (the code view lists
 * no island for them either).
 *
 * @param {Object} styleMap
 *   The host's style map.
 * @param {?string} key
 *   The element's selectable key (null: nothing selected, no rows).
 * @param {Object} conditions
 *   guide.conditions: name -> {label, wrapper, axis}.
 *
 * @return {Array<Object>}
 *   The literal clause records, in map order.
 */
export const literalClauses = (styleMap, key, conditions) => {
  if (!key) {
    return [];
  }
  const found = new Map();
  Object.entries(styleMap ?? {}).forEach(([mapKey, entry]) => {
    if (
      (mapKey !== key && !mapKey.startsWith(`${key}::`)) ||
      entry === null ||
      typeof entry !== 'object'
    ) {
      return;
    }
    Object.entries(entry).forEach(([condition, values]) => {
      if (
        condition === 'default' ||
        condition === 'variants' ||
        values === null ||
        typeof values !== 'object' ||
        Array.isArray(values) ||
        !Object.values(values).some(
          (value) => value !== null && value !== undefined,
        )
      ) {
        return;
      }
      parseCompound(condition, conditions).forEach((clause) => {
        if (clause.kind === LITERAL && !found.has(clause.text)) {
          found.set(clause.text, clause);
        }
      });
    });
  });
  return [...found.values()];
};

/**
 * The state-axis condition names (the force-state vocabulary), in table
 * order.
 *
 * @param {?Object} conditions
 *   guide.conditions.
 *
 * @return {string[]}
 *   The names.
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
 *
 * @param {?{width?: ?number, measured?: ?number}} vector
 *   The canvas state vector snapshot.
 *
 * @return {?number}
 *   The width, px, or null.
 */
export const effectiveWidth = (vector) =>
  vector?.width ?? vector?.measured ?? null;

/**
 * The width-axis clause an effective canvas width activates, or null: a
 * published condition's name, or the canonical text of a literal width
 * clause in use.
 *
 * Dispatches on the same @container band projection the ruler renders
 * (stopsFromConditions) plus the literal bands in use, and answers the
 * band the cascade delivers LAST at the width (the docblock's rule): a
 * literal in use that holds beats every published band, the later
 * literal in map order beats the earlier; with no literal holding, the
 * published band containing the width (the shipped bands tile the space
 * below base, narrow then medium). A width above every band's ceiling is
 * base, like an unmeasured canvas (null): writes land at the width-axis
 * default either way.
 *
 * @param {?number} width
 *   The effective canvas width.
 * @param {Object} conditions
 *   guide.conditions.
 * @param {Array<Object>} literals
 *   The literal clauses in use (literalClauses), map order.
 */
export const widthConditionFor = (width, conditions, literals = []) => {
  if (width === null || width === undefined) {
    return null;
  }
  const literal = [...literals]
    .reverse()
    .find((clause) => clause.band !== null && bandHolds(clause.band, width));
  if (literal) {
    return literal.text;
  }
  const stop = stopsFromConditions(conditions).find((entry) =>
    bandHolds(entry, width),
  );
  return stop?.name ?? null;
};

/**
 * The scheme/motion clause a forced vector value activates, or null: a
 * published condition whose wrapper tests the axis's feature for the
 * value the forced value makes true, else a literal in use testing the
 * same (the later in map order, as for widths). The FEATURE decides, not
 * a declared axis name: the derivation and the pose read one fact.
 *
 * @param {string} axis
 *   'scheme' | 'motion'.
 * @param {string} value
 *   The axis's posed vector value.
 * @param {?Object} conditions
 *   guide.conditions.
 * @param {Array<{preference: ?object, text: string}>} literals
 *   The literal clauses in use (literalClauses).
 *
 * @return {?string}
 *   The condition name or literal text, or null.
 */
const preferenceConditionFor = (axis, value, conditions, literals) => {
  const forced = FORCED[axis][value] ?? null;
  if (forced === null) {
    return null;
  }
  const tests = (preference) =>
    preference?.feature === FEATURES[axis] && preference.value === forced;
  for (const [name, definition] of Object.entries(conditions ?? {})) {
    if (tests(preferenceOf(definition))) {
      return name;
    }
  }
  const literal = [...literals]
    .reverse()
    .find((clause) => tests(clause.preference));
  return literal?.text ?? null;
};

/**
 * The state clause a force state activates, or null: a state-axis
 * condition name as the force state spells it, else a posable literal
 * pseudo-class in use whose stamp word the force state carries
 * (`checked` for `:checked`, the word the printed dual matches).
 *
 * @param {?string} forceState
 *   The selection-scoped force state.
 * @param {?Object} conditions
 *   guide.conditions.
 * @param {Array<object>} literals
 *   The literal clauses in use (literalClauses).
 *
 * @return {?string}
 *   The condition name or literal text, or null.
 */
const stateConditionFor = (forceState, conditions, literals) => {
  if (!forceState) {
    return null;
  }
  if (conditions?.[forceState]?.axis === 'state') {
    return forceState;
  }
  const literal = literals.find(
    (clause) =>
      clause.form === PSEUDO &&
      clausePosable(clause) &&
      clauseStamp(clause) === forceState,
  );
  return literal?.text ?? null;
};

/**
 * The compound's parts for a vector pose + force state, in canonical
 * order. Empty when the pose activates nothing — the default condition.
 * The four canvas axes derive the parts, over the table and the literal
 * clauses in use on the selected element's rows.
 *
 * @param {?Object} vector
 *   The canvas state vector snapshot.
 * @param {?string} forceState
 *   The selection-scoped force state.
 * @param {?Object} conditions
 *   guide.conditions.
 * @param {Array<object>} [literals]
 *   The literal clauses in use (literalClauses).
 *
 * @return {string[]}
 *   The parts.
 */
export const vectorParts = (vector, forceState, conditions, literals = []) => {
  const pose = { ...DEFAULT_VECTOR, ...(vector ?? {}) };
  const parts = [
    stateConditionFor(forceState, conditions, literals),
    widthConditionFor(effectiveWidth(pose), conditions, literals),
    preferenceConditionFor('scheme', pose.scheme, conditions, literals),
    preferenceConditionFor('motion', pose.motion, conditions, literals),
  ].filter((part) => part !== null);
  return parts.length
    ? compoundParts(canonicalCompound(parts.join(DELIMITER), conditions))
    : [];
};

/**
 * The condition key writes land at for a vector pose + force state:
 * 'default', a single condition, or a canonical 'a+b' compound.
 *
 * @param {?Object} vector
 *   The canvas state vector snapshot.
 * @param {?string} forceState
 *   The selection-scoped force state.
 * @param {Object} conditions
 *   guide.conditions.
 * @param {Array<Object>} literals
 *   The literal clauses in use on the selected element's rows
 *   (literalClauses); none when nothing is selected.
 */
export const vectorCondition = (
  vector,
  forceState,
  conditions,
  literals = [],
) => {
  const parts = vectorParts(vector, forceState, conditions, literals);
  return parts.length ? parts.join(DELIMITER) : 'default';
};

/**
 * The canvas pose that renders a stored condition — "drive the canvas
 * there".
 *
 * Each clause is read off its record (condition-clause.js parseCompound,
 * a published reference and a literal alike): a state-axis reference or
 * a posable pseudo-class literal poses its stamp word as the force
 * state, a clause deriving a band poses the band's midpoint (safely
 * inside both edges, so the derived write condition round-trips to
 * exactly this clause), a clause deriving a preference poses the vector
 * value that makes it true. Axes the condition does not name reset to
 * their defaults (full width, auto scheme/motion, no force state), so
 * the derived write condition lands exactly at the driven compound.
 * Clauses that cannot be posed — a `@media` width (viewport-owned, the
 * popout's business), a `@supports` test, a preference no vector axis
 * emulates, `:visited`, an unknown word — leave their axis at default
 * and mark the pose incomplete.
 *
 * @param {string} condition
 *   A stored condition (a compound key).
 * @param {?Object} conditions
 *   guide.conditions.
 *
 * @return {{vector: Object, state: ?string, complete: boolean}}
 *   The vector to pose, the force state, and whether every clause posed.
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
  for (const clause of parseCompound(condition, conditions)) {
    if (clause.form === PSEUDO) {
      const posable =
        clause.kind === REFERENCE
          ? clause.axis === 'state'
          : clause.kind === LITERAL && clausePosable(clause);
      if (posable) {
        pose.state = clauseStamp(clause);
      } else {
        pose.complete = false;
      }
      continue;
    }
    if (clause.band !== null) {
      pose.vector.width = stopPosePx(clause.band);
      continue;
    }
    if (clause.preference !== null) {
      const axis =
        Object.keys(FEATURES).find(
          (key) => FEATURES[key] === clause.preference.feature,
        ) ?? null;
      const value = axis
        ? (Object.entries(FORCED[axis]).find(
            ([, forced]) => forced === clause.preference.value,
          )?.[0] ?? null)
        : null;
      if (value) {
        pose.vector[axis] = value;
        continue;
      }
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
 *
 * @param {string} axis
 *   'state' | 'width' | 'scheme' | 'motion'.
 * @param {?Object} conditions
 *   guide.conditions.
 *
 * @return {string[]}
 *   The names.
 */
export const poseableAxisConditions = (axis, conditions) =>
  Object.entries(conditions ?? {})
    .filter(
      ([name, definition]) =>
        definition?.axis === axis && conditionPose(name, conditions).complete,
    )
    .map(([name]) => name);

/**
 * The conditions carrying styles on a condition-keyed entry — what the
 * inspector's "Styled at" chips list, read over the target's floorless
 * stack (style-stack.js deliveredEntry: the map's layers, every worn
 * reference's conditions beside the own entry's). 'default' and the
 * variants reference list are not conditioned styles; empty blocks carry
 * nothing.
 *
 * @param {?object} entry
 *   A condition-keyed entry (deliveredEntry).
 *
 * @return {string[]}
 *   The condition keys carrying styles.
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
 * The state qualifiers a rule's selector carries, by compound.
 *
 * A pseudo-class qualifier on any top-level compound (the anchor
 * included) whose name spells a published state-axis condition
 * (conditionForPseudoClass over the guide's wrapper table, never the
 * name itself): the WHERE side of a state, `li:hover > ul`'s hover on
 * its `li`. The pose stamps such a state where it stands (force-state.js
 * resolves the selector's prefix at the compound), and the Styled-at
 * line lists it as a condition the row lives at. A state inside an
 * `:is()` member stands on the member's host compound and is found with
 * that compound's index (`.menu-item:is(:focus-within, :hover) > ul`'s
 * hover on its `.menu-item`): the prefix cut there is what the pose
 * stamps. A qualifier inside `:has()` or `:not()` is not here: its
 * compound has no prefix the stamp could land on. Positions, classes
 * and attributes are never states; an unpublished pseudo-class
 * (`a:visited` where the roster publishes no `visited`) prints bare and
 * poses nothing.
 *
 * @param {?string} selector
 *   A canonical relative selector (null or '' for the owner itself).
 * @param {Object} conditions
 *   guide.conditions: name -> {label, wrapper, axis}.
 *
 * @return {Array<{index: number, state: string}>}
 *   The compound's 0-based index and the condition name, in chain
 *   order; one entry per qualifier.
 */
export const stateQualifiers = (selector, conditions) => {
  const parsed =
    typeof selector === 'string' && selector !== ''
      ? parseSelector(selector)
      : null;
  if (parsed === null) {
    return [];
  }
  const found = [];
  // A bare pseudo-class has no argument; `:has`, `:not`, `:is`, the nth
  // family, a class and an attribute all carry one. An `:is()` member's
  // bare pseudo-classes stand on the host compound.
  const bareStates = (qualifiers) =>
    qualifiers.flatMap((qualifier) =>
      qualifier.name === IS
        ? qualifier.argument.flatMap((member) => bareStates(member.qualifiers))
        : qualifier.argument === null
          ? [qualifier.name]
          : [],
    );
  parsed.compounds.forEach((compound, index) => {
    bareStates(compound.qualifiers).forEach((name) => {
      const state = conditionForPseudoClass(conditions, name);
      if (state !== null && conditions?.[state]?.axis === 'state') {
        found.push({ index, state });
      }
    });
  });
  return found;
};

/**
 * The state conditions a rule's selector is qualified by, each once, in
 * the condition table's order: what the Styled-at line lists beside the
 * entry's own conditions, so a reveal rule (`li:hover > ul`) shows the
 * state it lives at and a click poses it.
 *
 * @param {?string} selector
 *   A canonical relative selector (null or '' for the owner itself).
 * @param {Object} conditions
 *   guide.conditions: name -> {label, wrapper, axis}.
 *
 * @return {string[]}
 *   The names, in table order.
 */
export const qualifierStates = (selector, conditions) => {
  const names = stateQualifiers(selector, conditions).map(({ state }) => state);
  return Object.keys(conditions ?? {}).filter((name) => names.includes(name));
};

/**
 * Whether a stored condition renders on the selection right now: it is
 * exactly the posed compound.
 *
 * The code view's lit marker for a condition row: a row renders when the
 * canvas stands at its compound. 'default' never lights (it is the
 * absence of conditions). Where the element sits among its siblings is
 * never a condition (position is selector text, judged by the matcher as
 * reach), so nothing but the pose decides.
 *
 * @param {string} condition
 *   The row's condition key.
 * @param {string} posed
 *   The editing compound the canvas derives (vectorCondition()).
 */
export const conditionRenders = (condition, posed) =>
  typeof condition === 'string' &&
  condition !== 'default' &&
  condition === posed;
