/**
 * The Animations face's pure model: the palette model's twin for the
 * motion material.
 *
 * The catalog's third section is a flat, filterable list of the
 * animations the materials store carries (the roster's animation records,
 * delivery shape) with a lifecycle card per animation. This module owns
 * everything the view (animations.js) can compute without a DOM: the row
 * roster with its shipped marker, the card's gesture roster as data
 * (presence means permitted AND applicable), the offset grammar (the
 * client twin of AnimationStepsItem::canonicalOffset(), PHP
 * authoritative), the keyframes session's step track over the generic
 * stops track (track-model.js), the per-step analysis (the island
 * analysis under the universal vocabulary plus the keyframe-only rules
 * the validator applies to a step), and the live preview sheet the
 * leader re-prints per settle. The view only renders and dispatches.
 */

import { analyzeIsland, printIsland } from './code/island.js';
import { widePropertyNames } from './code/wide-tier.js';
import { isPlainObject } from './style-guide.js';
import { mentionsMedia } from './media-token.js';
import { animationOptions, animationSpelling } from './materials.js';
import { printKeyframes } from './printer.js';
import {
  insertStop,
  moveStop,
  positionPercent,
  removeStop,
  trackPercent,
} from './track-model.js';
import { isShipped, locked } from './variant-scope.js';

/**
 * The animation-* longhands a keyframe step may carry: the server's
 * validateAnimationSteps() admits the per-step easing only; every other
 * animation property (and the shorthand) describes the WEARER, not a
 * step, and is refused (INVALID_ANIMATION_STEP_PROPERTY).
 */
const STEP_ANIMATION_PROPERTIES = ['animation-timing-function'];

/**
 * Whether a property describes the element wearing the animation rather
 * than a step: the animation shorthand, every animation-* longhand but the
 * per-step easing, and timeline-trigger (the WHEN of the animation, the
 * wearer's business exactly as animation-trigger is). The client twin of
 * validateAnimationSteps()'s refused set.
 *
 * @param {string} property
 *
 * @return {boolean}
 */
const wearerProperty = (property) =>
  (property === 'animation' ||
    property === 'timeline-trigger' ||
    property.startsWith('animation-')) &&
  !STEP_ANIMATION_PROPERTIES.includes(property);

/**
 * The seed steps a fresh animation starts from: a fade, the canonical
 * demo, so the mint card's track and islands carry something honest
 * before the user touches them.
 */
export const SEED_STEPS = Object.freeze({
  '0%': { opacity: '0' },
  '100%': { opacity: '1' },
});

/**
 * A canonical offset: 'from' and 'to' fold to their percentages, a
 * percent renders trimmed (no trailing zeros, no dangling point), and
 * anything else (a keyword, a range, a comma list, over 100) is null.
 *
 * The client twin of AnimationStepsItem::canonicalOffset() (PHP is
 * authoritative): the session writes canonical offsets so the steps it
 * commits are byte-identical to what the setter stores, and the track's
 * "N%" read (positionPercent) maps every canonical offset.
 *
 * @param {string} offset
 *
 * @return {?string}
 */
export const canonicalOffset = (offset) => {
  const text = String(offset ?? '')
    .trim()
    .toLowerCase();
  if (text === 'from') {
    return '0%';
  }
  if (text === 'to') {
    return '100%';
  }
  const match = text.match(/^(\d+(?:\.\d+)?|\.\d+)%$/);
  if (!match) {
    return null;
  }
  const number = Number(match[1]);
  if (number > 100) {
    return null;
  }
  const rendered = number.toFixed(4).replace(/0+$/, '').replace(/\.$/, '');
  return `${rendered === '' ? '0' : rendered}%`;
};

/**
 * A steps map canonicalized: offsets folded and sorted ascending.
 *
 * AnimationStepsItem::canonicalizeSteps()'s twin: offsets that do not
 * canonicalize are kept verbatim and sort last (stably, by string), and
 * two spellings of one offset collapse with the later entry winning.
 *
 * @param {object} steps
 *   Offset -> declarations.
 *
 * @return {object}
 */
export const canonicalizeSteps = (steps) => {
  const folded = {};
  Object.entries(isPlainObject(steps) ? steps : {}).forEach(
    ([offset, declarations]) => {
      folded[canonicalOffset(offset) ?? offset] = declarations;
    },
  );
  const keys = Object.keys(folded).sort((a, b) => {
    const left = canonicalOffset(a);
    const right = canonicalOffset(b);
    if (left === null || right === null) {
      return (
        (left === null ? 1 : 0) - (right === null ? 1 : 0) ||
        (a < b ? -1 : a > b ? 1 : 0)
      );
    }
    return parseFloat(left) - parseFloat(right);
  });
  return Object.fromEntries(keys.map((key) => [key, folded[key]]));
};

/**
 * The animations list's row roster: filtered, flagged, in roster order.
 *
 * The paletteRows twin: the search needle matches label or machine name,
 * case-insensitive. `shipped` marks the record's origin (the lock,
 * `updatable`, is read at the card), `offsets` the canonical offsets in
 * order (the row's
 * anatomy line). Usage counts are management facts the card fetches per
 * open, so rows carry none.
 *
 * @param {?Array<object>} animations
 *   The animation records (roster order).
 * @param {{search?: string}} filters
 *
 * @return {Array<{reference: string, label: string, spelling: ?string,
 *   offsets: string[], shipped: boolean}>}
 */
export const animationRows = (animations, { search = '' } = {}) => {
  const needle = search.trim().toLowerCase();
  return (animations ?? [])
    .filter(
      (animation) =>
        needle === '' ||
        `${animation.label ?? ''}\n${animation.id ?? ''}`
          .toLowerCase()
          .includes(needle),
    )
    .map((animation) => ({
      reference: animation.id,
      label: animation.label !== '' ? animation.label : animation.id,
      spelling: animationSpelling(animation),
      offsets: Object.keys(canonicalizeSteps(animation.value)),
      shipped: isShipped(animation),
    }));
};

/**
 * The lifecycle card's gesture roster, as data.
 *
 * swatchCardGestures' twin: presence means the gesture is permitted AND
 * applicable, so nothing on the card is a dead control. Two record facts
 * split the roster (variant-scope.js isShipped, locked): the lock
 * (`updatable` false: a shipped animation on a consuming site, where the
 * importer owns its label and steps and rename, edit, restore and delete
 * all refuse server-side) withdraws the editing gestures; the shipped
 * origin offers "Revert to defaults" when the state is actionable. Under
 * the developer gate a shipped animation is updatable and carries both.
 * Delete carries the two permission
 * grades: a referenced animation needs the in-use grade (its delete runs
 * the rewrite-to-none batch), an unreferenced one either grade.
 *
 * @param {object} animation
 *   The animation record (delivery shape).
 * @param {object} config
 *   The gesture gates (drupalSettings.daedalusStylish.animations):
 *   {canCreate, canUpdate, canDelete, canDeleteInUse}.
 * @param {?{usages: number, shipped_state: ?string}} management
 *   The card's per-open management read, or null while in flight; then
 *   the count reads as zero and revert waits for the state.
 *
 * @return {{rename: boolean, editSteps: boolean, revisions: boolean,
 *   revert: boolean, delete: boolean}}
 */
export const animationCardGestures = (
  animation,
  config = {},
  management = null,
) => {
  const shipped = isShipped(animation);
  const editable = !locked(animation);
  const used = (management?.usages ?? 0) > 0;
  return {
    rename: editable && Boolean(config.canUpdate),
    editSteps: editable && Boolean(config.canUpdate),
    revisions: editable && Boolean(config.canUpdate),
    revert:
      shipped &&
      management?.shipped_state != null &&
      management.shipped_state !== 'unmodified' &&
      Boolean(config.canUpdate),
    delete:
      editable &&
      (used
        ? Boolean(config.canDeleteInUse)
        : Boolean(config.canDelete) || Boolean(config.canDeleteInUse)),
  };
};

/**
 * The session's step track: one stop per step, canonical offsets as
 * positions, ascending.
 *
 * The keyframes editor's stops are `{position, declarations}` over the
 * generic track (track-model.js reads `position` only): an offset IS a
 * position, so every canonical offset maps onto the track math.
 *
 * @param {object} steps
 *   Offset -> declarations (stored shape).
 *
 * @return {Array<{position: string, declarations: object}>}
 */
export const stepStops = (steps) =>
  Object.entries(canonicalizeSteps(steps)).map(([position, declarations]) => ({
    position,
    declarations: isPlainObject(declarations) ? declarations : {},
  }));

/**
 * The track's stops back as the stored steps map (stepStops' inverse):
 * position -> declarations, in track order.
 *
 * @param {Array<{position: string, declarations: object}>} stops
 *
 * @return {object}
 */
export const stepsOf = (stops) =>
  Object.fromEntries(stops.map((stop) => [stop.position, stop.declarations]));

/**
 * Whether two steps maps commit the same thing (the session's dirty
 * read): canonical offsets, declarations compared by property and value.
 *
 * @param {object} a
 * @param {object} b
 *
 * @return {boolean}
 */
export const sameSteps = (a, b) => {
  const left = canonicalizeSteps(a);
  const right = canonicalizeSteps(b);
  const offsets = Object.keys(left);
  if (offsets.length !== Object.keys(right).length) {
    return false;
  }
  return offsets.every((offset) => {
    const mine = isPlainObject(left[offset]) ? left[offset] : {};
    const theirs = isPlainObject(right[offset]) ? right[offset] : {};
    const properties = Object.keys(mine);
    return (
      Object.prototype.hasOwnProperty.call(right, offset) &&
      properties.length === Object.keys(theirs).length &&
      properties.every((property) => theirs[property] === mine[property])
    );
  });
};

/**
 * Whether a percent already has a step (two steps can never share an
 * offset: the validator's DUPLICATE_ANIMATION_OFFSET).
 */
const occupied = (stops, percent, except = -1) =>
  stops.some(
    (stop, index) =>
      index !== except && positionPercent(stop.position) === percent,
  );

/**
 * A drag (or numeric offset edit): the moved step lands at the clamped
 * percent, sorted, selected; null when another step already sits there
 * (an offset is one step; the gesture cannot merge two).
 *
 * @param {Array<{position: string, declarations: object}>} stops
 * @param {number} index
 * @param {number} percent
 *
 * @return {?{stops: Array, selected: number}}
 */
export const moveStep = (stops, index, percent) =>
  occupied(stops, trackPercent(percent), index)
    ? null
    : moveStop(stops, index, percent);

/**
 * A click on empty track: a new EMPTY step at the click (an empty step is
 * legal; the user types its declarations), sorted, selected; null when
 * the percent already has a step.
 *
 * @param {Array<{position: string, declarations: object}>} stops
 * @param {number} percent
 *
 * @return {?{stops: Array, selected: number}}
 */
export const insertStep = (stops, percent) =>
  occupied(stops, trackPercent(percent))
    ? null
    : insertStop(stops, { declarations: {} }, percent);

/**
 * A removal with the animation's floor: null below one step (an
 * animation needs at least one); otherwise the track model's selection
 * survival.
 *
 * @param {Array} stops
 * @param {number} index
 * @param {number} selected
 *
 * @return {?{stops: Array, selected: number}}
 */
export const removeStep = (stops, index, selected) =>
  removeStop(stops, index, selected, 1);

/**
 * A step's island text: its declarations, one per line.
 *
 * @param {object} declarations
 *
 * @return {string}
 */
export const printStep = (declarations) =>
  printIsland(
    Object.entries(isPlainObject(declarations) ? declarations : {}).map(
      ([property, value]) => ({ property, value }),
    ),
  );

/**
 * The group id the whole generated wide tier grants under on a step's
 * node: the tier is one tier, so one group carries every wide name.
 */
export const WIDE_TIER_SET = 'wide:*';

/**
 * The node a keyframe step validates against: the universal vocabulary.
 *
 * The server validates a step's declarations with EVERY group declared,
 * curated and generated alike (validateAnimationSteps(): a step is not
 * an element and grants nothing per kind), so the island analysis runs
 * over a node granting every property of every curated group the guide
 * serves plus, under WIDE_TIER_SET, every wide-tier name. No value
 * spaces: a step is not an address, so no roster binds it here; an
 * authored site default the server does bind inside a step surfaces
 * through the commit's corrective.
 *
 * @param {object} guide
 *
 * @return {{grants: Object<string, string[]>}}
 */
export const stepDeclaration = (guide) => {
  const grants = {};
  Object.entries(isPlainObject(guide?.groups) ? guide.groups : {}).forEach(
    ([id, group]) => {
      grants[id] = Object.keys(
        isPlainObject(group?.properties) ? group.properties : {},
      );
    },
  );
  grants[WIDE_TIER_SET] = widePropertyNames();
  return { grants };
};

/**
 * Analyzes one step's island text: the island analysis under the
 * universal vocabulary, plus the keyframe-only rules.
 *
 * On top of analyzeIsland (recognition, the closed world, typed values,
 * token references): an animation-* property other than the per-step
 * easing, and timeline-trigger, are refused (INVALID_ANIMATION_STEP_PROPERTY:
 * the wearer's business, never a step's), and a media token is refused
 * (INVALID_STYLE_VALUE: the compiled keyframes file records no media and
 * the validator refuses it), each marking its declaration invalid so a
 * settle drops it. Diagnostics stay CM6-lint-shaped.
 *
 * @param {string} text
 * @param {{guide: object, materials: object}} options
 *
 * @return {{declarations: Array<object>, diagnostics: Array<object>}}
 */
export const analyzeStep = (text, { guide, materials } = {}) => {
  const analysis = analyzeIsland(text, {
    guide,
    materials,
    declaration: stepDeclaration(guide),
  });
  const diagnostics = [...analysis.diagnostics];
  analysis.declarations.forEach((record) => {
    if (!record.valid) {
      return;
    }
    if (wearerProperty(record.property)) {
      record.valid = false;
      diagnostics.push({
        from: record.from,
        to: record.from + record.property.length,
        severity: 'error',
        message: `"${record.property}" describes the element wearing the animation, not a step; a step may carry animation-timing-function only.`,
        code: 'INVALID_ANIMATION_STEP_PROPERTY',
      });
    } else if (mentionsMedia(record.value)) {
      record.valid = false;
      diagnostics.push({
        from: record.valueFrom,
        to: record.valueTo,
        severity: 'error',
        message:
          'A keyframe step cannot reference media; the compiled keyframes carry literal and token values only.',
        code: 'INVALID_STYLE_VALUE',
      });
    }
  });
  return {
    declarations: analysis.declarations,
    diagnostics: diagnostics.sort((a, b) => a.from - b.from),
  };
};

/**
 * A step's settled declarations: the buffer's valid declarations as a
 * map, later duplicates winning (how the printed step reads), invalid
 * lines held out (the server would refuse the whole save).
 *
 * @param {Array<object>} declarations
 *   analyzeStep()'s declarations.
 *
 * @return {{declarations: object, hasErrors: boolean}}
 */
export const settledStep = (declarations) => {
  const settled = {};
  let hasErrors = false;
  declarations.forEach((record) => {
    if (record.valid) {
      settled[record.property] = record.value;
    } else {
      hasErrors = true;
    }
  });
  return { declarations: settled, hasErrors };
};

/**
 * The live preview sheet: every rostered animation's @keyframes, a
 * session's draft steps substituted for the animation it edits.
 *
 * The compiled animations file this page loaded predates any animation
 * minted or edited this session, so the leader prints the whole roster
 * into a <style> tag later in the document than the file's <link> (the
 * last @keyframes of a name in document order wins): a mint plays before
 * any reload, a settled session step plays on every wearer, and the
 * committed record supersedes the draft on the next store change.
 * Records without a spelling (none in the animation slot) print nothing;
 * printKeyframes drops steps with nothing printable.
 *
 * @param {object} materials
 *   The materials roster.
 * @param {Map<string, object>} drafts
 *   Animation id -> draft steps map (the sessions' live drafts).
 *
 * @return {string}
 *   The sheet text, '' when nothing prints.
 */
export const animationPreviewCss = (materials, drafts = new Map()) =>
  animationOptions(materials)
    .map((animation) =>
      printKeyframes(
        animation.ref,
        drafts.get(animation.name) ?? animation.steps,
      ),
    )
    .filter((css) => css !== '')
    .join('\n');
