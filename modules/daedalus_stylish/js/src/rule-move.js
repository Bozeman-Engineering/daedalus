/**
 * @file
 * The rule move: the client twin of daedalus_stylish's RuleMove (PHP
 * authoritative, locked to this module by RuleMoveTest through the
 * shared battery tests/js/rule-move-cases.mjs).
 *
 * The one transformation behind "edit this rule's selector", over an
 * owner's RELATIVE keys (the selectors after the `::` of its style map
 * entries; the owner's root entry has none and is not one): given `from`
 * (the rule's selector) and `to` (what it becomes), the plan maps `from`
 * to `to` and every key continuing `from` (RelativeSelector::continues:
 * `> d-text .link`, `> d-text > *`, `> d-text::before` continue
 * `> d-text`; `> d-text:has(a)` and `> d-textual` do not) to `to`
 * continued by the same suffix (relative-selector.js under()). The
 * rule's own key heads the mapping whether or not the list carries it,
 * then the continuations in list order.
 *
 * Three refusals, each naming the first offending key in that walk:
 * `to` outside the grammar (the `to` text), a continuation the grammar
 * cannot rebase under `to` (the key that cannot follow: a `to` ending in
 * a pseudo-element, a tag `to` with a part class behind it), a target
 * key already present (the present key; an unchanged selector is its
 * own collision). `from` outside the grammar is a caller bug and throws.
 * The server class owns the rationale.
 *
 * continuationSuffix() is the client's one read of continues():
 * variant-scope.js relativeUnder() (the map-key form every
 * element-shaped read speaks: elementEntries, captureSources, the wear
 * bar's shadow walk, the styles clipboard) is an adapter over it, so
 * the family a move carries, the code view's rule island and the
 * clipboard's copy at a rule all agree with the server's print.
 */
import {
  ANCHOR,
  SUFFIX,
  canonicalSelector,
  parseSelector,
  under,
} from '../../../daedalus_ui/js/edit_mode/materials/relative-selector.js';

/**
 * The refusal reasons (RuleMove::REFUSED_*).
 */
export const REFUSED_GRAMMAR = 'grammar';
export const REFUSED_CONTINUATION = 'continuation';
export const REFUSED_TARGET_EXISTS = 'target_exists';

/**
 * A refused plan.
 */
const refuse = (reason, key) => ({ moves: null, refusal: { reason, key } });

/**
 * The suffix a key continues a canonical rule by, or null when it does
 * not (RelativeSelector::continues read back: `{rule} {suffix}` by
 * descent, or `{rule}::{pseudo}` as the suffix `&::{pseudo}`, and
 * under() must spell the key again).
 *
 * Exported for the readers that ask "is this key under that rule?"
 * without a plan: variant-scope.js relativeUnder() (the map-key
 * adapter) and pending.js (telling a document a move already left from
 * a real collision); every family walk of a MOVE takes planRuleMove()'s
 * moves map whole.
 *
 * @param {string} rule
 *   The rule's canonical selector.
 * @param {string} key
 *   The candidate relative key.
 *
 * @return {?string}
 */
export const continuationSuffix = (rule, key) => {
  const candidate = canonicalSelector(key);
  if (candidate === null || candidate === rule || parseSelector(rule).pseudo !== null) {
    return null;
  }
  let suffix;
  if (candidate.startsWith(`${rule} `)) {
    suffix = candidate.slice(rule.length + 1);
  } else if (candidate.startsWith(`${rule}${SUFFIX}`)) {
    suffix = `${ANCHOR}${candidate.slice(rule.length)}`;
  } else {
    return null;
  }
  if (canonicalSelector(suffix) === null) {
    return null;
  }
  return under(rule, suffix) === candidate ? suffix : null;
};

/**
 * Plans the move of a rule and its family over an owner's keys.
 *
 * @param {string[]} keys
 *   The owner's relative keys (canonical selectors). A key outside the
 *   grammar continues nothing.
 * @param {string} from
 *   The rule's selector.
 * @param {string} to
 *   The selector it becomes, in any spelling the grammar reads.
 *
 * @return {{moves: ?Object<string, string>, refusal: ?{reason: string, key: string}}}
 *   The plan: source key to target key, the rule's own first, with
 *   `refusal` null; or `moves` null and the refusal (one of the
 *   REFUSED_* reasons and the offending key).
 *
 * @throws {TypeError}
 *   When `from` is not a relative selector.
 */
export const planRuleMove = (keys, from, to) => {
  const rule = canonicalSelector(from);
  if (rule === null) {
    throw new TypeError(`"${from}" is not a relative selector.`);
  }
  const target = canonicalSelector(to);
  if (target === null) {
    return refuse(REFUSED_GRAMMAR, to);
  }
  const present = new Set(keys.map(String));
  // The walk refuses in order: the rule's own landing first, then each
  // continuation as the list reads, so the first offending key is the
  // one named.
  if (present.has(target)) {
    return refuse(REFUSED_TARGET_EXISTS, target);
  }
  const moves = { [rule]: target };
  for (const listed of keys) {
    const key = String(listed);
    const suffix = continuationSuffix(rule, key);
    if (suffix === null) {
      continue;
    }
    const landing = under(target, suffix);
    if (landing === null) {
      return refuse(REFUSED_CONTINUATION, key);
    }
    if (present.has(landing)) {
      return refuse(REFUSED_TARGET_EXISTS, landing);
    }
    moves[key] = landing;
  }
  return { moves, refusal: null };
};
