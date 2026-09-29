/**
 * Daedalus Stylish's client observability seam: the logger handle plus the pure
 * diffs the log points report.
 *
 * The logger itself lives at the daedalus_ui spine
 * (`Drupal.Daedalus.logger`, library `daedalus_ui/logging`);
 * this module is the one place daedalus_stylish grabs it, so every bundle module
 * logs through the same instance and the same module name. Nothing prints
 * until the console switches daedalus_stylish on:
 *
 *   Drupal.Daedalus.logging.enable('daedalus_stylish');
 *
 * The instrumented seams (leader-source.js, variants-api.js) are the ones
 * that were blind during browser testing: what op went out, what the
 * mirror came back holding, which keys gained or lost fragment references,
 * and what the variant library actually answered. Deliberately a handful of
 * points, not blanket tracing.
 *
 * The logger is grabbed at module load, per the spine's contract: the
 * instance is memoized there, `log` is closure-bound (destructuring is
 * safe) and `enabled` is read live inside log(), so a handle taken before
 * the console enables daedalus_stylish starts printing the moment it does. The
 * globalThis guard covers the two hosts that have no daedalus_ui
 * logging library: node (the tests/js suites import these modules directly)
 * and an older daedalus_ui without the seam. Both degrade to silence,
 * like the other guarded seams in this client.
 */
import { entryRefs } from './variant-scope.js';

const logger = globalThis.Drupal?.Daedalus?.logger?.('daedalus_stylish') ?? {
  log: () => {},
  enabled: false,
};

/**
 * Logs one line: `[daedalus_stylish:tag] message` plus optional data.
 */
export const { log } = logger;

/**
 * Whether daedalus_stylish logging is on right now.
 *
 * Call it before building anything a log point does not otherwise need:
 * the map diff below walks the whole style map, which is not work to do on
 * every mutation of every editing session.
 *
 * @return {boolean}
 */
export const logEnabled = () => logger.enabled;

const isEntryObject = (value) =>
  value !== null && typeof value === 'object' && !Array.isArray(value);

const refId = (ref) => `${ref.variant}#${ref.fragment}`;

/**
 * The condition blocks whose content differs.
 *
 * Everything in an entry except the `variants` list is a condition-keyed
 * block of overrides, so the names ARE the condition axis,
 * exactly what a reader wants to see changed.
 */
const changedConditions = (before, after) => {
  const previous = isEntryObject(before) ? before : {};
  const next = isEntryObject(after) ? after : {};
  const names = new Set([...Object.keys(previous), ...Object.keys(next)]);
  names.delete('variants');
  return [...names].filter(
    (name) => JSON.stringify(previous[name]) !== JSON.stringify(next[name]),
  );
};

/**
 * What changed between two style maps, keyed by selectable key.
 *
 * Every mutation response replaces the blueprint mirror wholesale, so the
 * mirror swap IS the server's own ledger of what a gesture did, including
 * the parts no gesture asked for by name (the children a composition apply
 * dressed, the overrides a shadow-clearing batch retired).
 *
 * @param {object} previous
 *   The style map before (selectable key → entry).
 * @param {object} next
 *   The style map after.
 *
 * @return {Object<string, {change: string, refsGained?: Array,
 *   refsLost?: Array, conditions?: string[]}>}
 *   One record per changed key; {} when the maps are equivalent. `change`
 *   is 'added' / 'removed' / 'changed'; the optional members are omitted
 *   when empty so the console shows only what moved.
 */
export const styleMapDelta = (previous, next) => {
  const before = isEntryObject(previous) ? previous : {};
  const after = isEntryObject(next) ? next : {};
  const delta = {};
  new Set([...Object.keys(before), ...Object.keys(after)]).forEach((key) => {
    if (JSON.stringify(before[key]) === JSON.stringify(after[key])) {
      return;
    }
    const wasRefs = entryRefs(before[key]);
    const nowRefs = entryRefs(after[key]);
    const wasIds = wasRefs.map(refId);
    const nowIds = nowRefs.map(refId);
    const gained = nowRefs.filter((ref) => !wasIds.includes(refId(ref)));
    const lost = wasRefs.filter((ref) => !nowIds.includes(refId(ref)));
    const conditions = changedConditions(before[key], after[key]);
    let change = 'changed';
    if (before[key] === undefined) {
      change = 'added';
    } else if (after[key] === undefined) {
      change = 'removed';
    }
    delta[key] = { change };
    if (gained.length) {
      delta[key].refsGained = gained;
    }
    if (lost.length) {
      delta[key].refsLost = lost;
    }
    if (conditions.length) {
      delta[key].conditions = conditions;
    }
  });
  return delta;
};

/**
 * Every key of a style map that references one variant, with its fragments.
 *
 * The "who is wearing this" read: a composition apply distributes
 * {variant, fragment} references over the resolved subtree, so a variant's
 * carriers are the honest picture of its reach on this page: the root
 * wearer (fragment '') plus each dressed descendant.
 *
 * @return {Object<string, string[]>}
 *   Selectable key → the fragments it holds of that variant.
 */
export const variantCarriers = (map, variant) => {
  const carriers = {};
  Object.entries(isEntryObject(map) ? map : {}).forEach(([key, entry]) => {
    const fragments = entryRefs(entry)
      .filter((ref) => ref.variant === variant)
      .map((ref) => ref.fragment);
    if (fragments.length) {
      carriers[key] = fragments;
    }
  });
  return carriers;
};

/**
 * The carrier diff an apply or detach produced.
 *
 * Which keys gained references to the variant (a composition apply's
 * distribution), which lost them (a root detach's group gesture over the
 * current subtree), and how many carry it now.
 *
 * @return {{gained: object, lost: object, carriers: number}}
 */
export const carrierDelta = (before, after) => {
  const gained = {};
  const lost = {};
  Object.entries(after).forEach(([key, fragments]) => {
    if (!(key in before)) {
      gained[key] = fragments;
    }
  });
  Object.entries(before).forEach(([key, fragments]) => {
    if (!(key in after)) {
      lost[key] = fragments;
    }
  });
  return { gained, lost, carriers: Object.keys(after).length };
};
