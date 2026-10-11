import { CAPABILITIES, isCapability } from '../selection/capable-model.js';

/**
 * The verb registry's applies gate, pure.
 *
 * Whether a verb applies to one selectable key, in order: the verb's
 * semantic kinds, the selection/edit scope partition (a selection verb
 * applies only while NO live edit lands on the key, an edit-scoped verb
 * only while the live edit IS the key), the capability gate (the verb's
 * declared capability, asked of the key or of its projection parent
 * through the one client gate, selection/capable.js: a verb never sees a
 * key its capability is refused on), then the verb's own applies()
 * refinement. verbs.js feeds it the store's facts; the node suite feeds
 * it directly.
 *
 * @param {object} verb
 *   A registered verb (verbs.js register normalizes `capability` and
 *   `of`).
 * @param {?string} key
 *   The selectable key.
 * @param {object} facts
 *   - kind: the key's semantic kind (selectable-key.js kindOf).
 *   - editingKey: the store's live edit key, or null.
 *   - capable: (key, capability) => boolean, the gate.
 *   - parent: the key's projection parent key, or null (asked when the
 *     verb's `of` is 'parent').
 *
 * @return {boolean}
 */
export const verbApplies = (
  verb,
  key,
  { kind = null, editingKey = null, capable = () => false, parent = null } = {},
) => {
  if (!key || !kind || !verb.kinds.includes(kind)) {
    return false;
  }
  if (verb.editScoped ? editingKey !== key : editingKey === key) {
    return false;
  }
  const asked = verbSubject(verb, kind, key) === 'parent' ? parent : key;
  if (!asked || !capable(asked, verbCapability(verb, kind))) {
    return false;
  }
  return !verb.applies || verb.applies(key);
};

/**
 * The capability a verb requires for a kind: its one declared capability,
 * or the kind's entry of its per-kind map.
 *
 * @param {object} verb
 * @param {string} kind
 *
 * @return {string}
 */
export const verbCapability = (verb, kind) =>
  typeof verb.capability === 'string' ? verb.capability : verb.capability[kind];

/**
 * Which key a verb's capability is asked of for a kind: 'element' (the
 * key itself) or 'parent' (its projection parent).
 *
 * A verb whose subject depends on the key itself (daedalus_layout's
 * Insert: after a block, so the block's parent; inside a slot door, a
 * block whose published insert verdict is its own) declares `of` as a
 * function of the key and its kind, answering one of the two subjects.
 *
 * @param {object} verb
 * @param {string} kind
 * @param {?string} key
 *   The selectable key, for a function-valued `of`.
 *
 * @return {string}
 */
export const verbSubject = (verb, kind, key = null) => {
  if (typeof verb.of === 'function') {
    return verb.of(key, kind);
  }
  return typeof verb.of === 'string' ? verb.of : verb.of[kind];
};

/**
 * The subjects a verb's `of` may name.
 */
export const VERB_SUBJECTS = ['element', 'parent'];

/**
 * Validates a verb spec's capability declaration, the registration
 * contract (daedalus_ui.api.php, "The verb contract").
 *
 * `capability` must be one of the five, or a map of them covering every
 * kind the verb registers; `of` (optional, 'element' by default) must be
 * 'element' or 'parent', such a map, or a function of (key, kind)
 * answering one of the two. Anything else throws, naming the
 * five: a default would keep a third-party verb running past the gate
 * and hide the contract.
 *
 * @param {object} spec
 *   The verb spec (kinds already known to be an array).
 *
 * @return {{capability: string|Object<string, string>, of: string|Object<string, string>|Function}}
 *   The normalized pair.
 */
export const declaredCapability = (spec) => {
  const named = `Verb ${spec.id ?? '(no id)'}`;
  const vocabulary = `one of ${CAPABILITIES.join(', ')}`;
  const { capability } = spec;
  const validMap = (value, valid) =>
    !!value &&
    typeof value === 'object' &&
    spec.kinds.every((kind) => valid(value[kind]));
  if (!isCapability(capability) && !validMap(capability, isCapability)) {
    throw new Error(
      `${named} must declare the capability it requires: \`capability\` is ${vocabulary}, or a map of those by kind covering ${spec.kinds.join(', ')}.`,
    );
  }
  const of = spec.of ?? 'element';
  const validSubject = (value) => VERB_SUBJECTS.includes(value);
  if (
    typeof of !== 'function' &&
    !validSubject(of) &&
    !validMap(of, validSubject)
  ) {
    throw new Error(
      `${named} names an invalid subject: \`of\` is 'element' (the key itself, the default) or 'parent' (its projection parent), a map of those by kind covering ${spec.kinds.join(', ')}, or a function of (key, kind) answering one of them.`,
    );
  }
  return { capability, of };
};
