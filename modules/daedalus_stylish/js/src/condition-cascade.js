/**
 * @file
 * Structural condition-cascade order and applicability — the one client
 * vocabulary for "which condition block wins where".
 *
 * Stored condition-key order is write order (update_style appends a block
 * the first time its condition is touched) and carries no cascade
 * meaning. Everything that needs an order derives it structurally from
 * here: 'default' first, then single conditions, then compounds (more
 * parts later), ties broken by the parts' positions in the guide's
 * condition table and then by given order. The printer emits in this
 * order (at-rule wrappers add no specificity, so document order IS the
 * cascade between blocks; CssPrinter::orderedConditions() is the server
 * mirror, locked byte for byte by the printer equivalence test), and the
 * inspector's inherited-value provenance answers "who shows through at
 * this pose" with the same order — the two can never disagree.
 */

/**
 * A condition key's compound parts (a single condition is the compound
 * of one; ConditionTypePluginManager::compoundParts() mirror).
 */
export const compoundParts = (condition) => condition.split('+');

/**
 * Whether a condition block applies whenever a posed compound does.
 *
 * 'default' applies everywhere; any other block applies when its parts
 * are a subset of the compound's ("narrow" applies at "narrow+dark").
 * At the default pose nothing but default applies.
 */
export const appliesWithin = (condition, compound) => {
  if (condition === 'default') {
    return true;
  }
  if (compound === 'default') {
    return false;
  }
  const parts = new Set(compoundParts(compound));
  return compoundParts(condition).every((part) => parts.has(part));
};

/**
 * A condition key as site vocabulary: each part's declared label (its
 * name when the table has none), compound parts joined with ' + '
 * ("Narrow + Dark"). 'default' has no site label and reads as its key;
 * a view that wants "Base" for it says so itself (translation is the
 * view's business, not this vocabulary's).
 */
export const compoundLabel = (condition, conditions) =>
  compoundParts(condition)
    .map((part) => conditions?.[part]?.label || part)
    .join(' + ');

/**
 * Sorts condition keys into structural cascade order, later wins.
 *
 * @param {string[]} keys
 *   Condition keys ('default', singles, 'a+b' compounds), any order.
 * @param {object} conditions
 *   The condition table (guide.conditions), in declaration order.
 *
 * @return {string[]}
 *   The keys in emission order: default, singles, compounds; unknown
 *   parts sort last, ties keep the given order.
 */
export const cascadeOrder = (keys, conditions) => {
  const position = new Map(
    Object.keys(conditions ?? {}).map((name, index) => [name, index]),
  );
  const vector = (key) => {
    if (key === 'default') {
      return [0, 0, []];
    }
    const parts = compoundParts(key).map((part) =>
      position.has(part) ? position.get(part) : Number.MAX_SAFE_INTEGER,
    );
    return [1, parts.length, parts];
  };
  const compare = (a, b) =>
    a[0] - b[0] ||
    a[1] - b[1] ||
    a[2].reduce((diff, value, i) => diff || value - b[2][i], 0);
  return keys
    .map((key, index) => ({ key, vector: vector(key), index }))
    .sort((a, b) => compare(a.vector, b.vector) || a.index - b.index)
    .map(({ key }) => key);
};

/**
 * The value a row inherits at a posed compound: the cascade-winning
 * applicable block OTHER than the compound's own.
 *
 * @param {Array<{condition: string, value: string}>} values
 *   A row's set values (buildSections shape).
 * @param {string} compound
 *   The editing compound ('default', a single, or 'a+b').
 * @param {object} conditions
 *   The condition table (guide.conditions).
 *
 * @return {?{condition: string, value: string}}
 *   The inheriting block, or null when nothing else applies.
 */
export const inheritedValue = (values, compound, conditions) => {
  const applicable = values.filter(
    ({ condition }) =>
      condition !== compound && appliesWithin(condition, compound),
  );
  if (!applicable.length) {
    return null;
  }
  const winner = cascadeOrder(
    applicable.map(({ condition }) => condition),
    conditions,
  ).pop();
  return applicable.find(({ condition }) => condition === winner) ?? null;
};

/**
 * The value a set of condition-keyed layers delivers AT a compound: the
 * compound's own block when it sets the property, else the inheriting
 * block (inheritedValue). The one read behind every "what shows at this
 * pose" face: the property row's committed-or-ghost value, the layout
 * widget's own claims, the Motion section's Replay gate.
 *
 * @param {Array<{condition: string, value: string}>} values
 *   The layers (buildSections row shape).
 * @param {string} compound
 *   The editing compound.
 * @param {object} conditions
 *   The condition table (guide.conditions).
 *
 * @return {?{condition: string, value: string}}
 *   The delivering block, or null when no layer reaches the pose.
 */
export const valueAtPose = (values, compound, conditions) =>
  values.find(({ condition }) => condition === compound) ??
  inheritedValue(values, compound, conditions);

const isBlock = (block) =>
  block !== null && typeof block === 'object' && !Array.isArray(block);

/**
 * One property's set values across a condition-keyed entry, in the
 * buildSections row shape ({condition, value}, values stringified, the
 * `variants` lane skipped, nulls dropped), ordered Base first then the
 * guide's condition order: the valueAtPose() and originOf() read for
 * any condition-keyed entry (a stack layer's styles, the stack's
 * delivered entry).
 *
 * @param {?object} entry
 *   A condition-keyed entry.
 * @param {string} property
 * @param {object} [conditions]
 *   The condition table (guide.conditions), for ordering.
 *
 * @return {Array<{condition: string, value: string}>}
 */
export const propertyValues = (entry, property, conditions = {}) => {
  const order = ['default', ...Object.keys(conditions ?? {})];
  const rank = (name) => {
    const index = order.indexOf(name);
    return index === -1 ? order.length : index;
  };
  return Object.entries(entry ?? {})
    .filter(
      ([condition, block]) =>
        condition !== 'variants' &&
        isBlock(block) &&
        block[property] !== undefined &&
        block[property] !== null,
    )
    .sort(([a], [b]) => rank(a) - rank(b))
    .map(([condition, block]) => ({
      condition,
      value: String(block[property]),
    }));
};

/**
 * The block a condition-keyed entry delivers for a property AT a pose:
 * valueAtPose over the entry's set values, so `default` delivers
 * everywhere, a subset compound overrides it, and the posed compound's
 * own block wins. The one read of the defaults tier (skin and declared
 * entries alike): the printer emits every condition block through
 * orderedConditions(), so a lookup at the exact compound would deny at
 * Medium what the canvas paints there.
 *
 * @param {?object} entry
 *   A condition-keyed entry.
 * @param {string} property
 * @param {string} compound
 *   The editing compound.
 * @param {object} [conditions]
 *   The condition table (guide.conditions).
 *
 * @return {?{condition: string, value: string}}
 *   The delivering block, or null when no block reaches the pose.
 */
export const entryAtPose = (entry, property, compound, conditions = {}) =>
  valueAtPose(
    propertyValues(entry, property, conditions),
    compound,
    conditions,
  );

/**
 * The flat declaration list a condition-keyed entry delivers at a pose:
 * every applicable block merged in cascade order, a later block's
 * declaration landing AFTER an earlier one for the same property (a
 * re-set property moves to its later position, as document order does),
 * nulls dropped as unsets. For a per-declaration read (a shorthand
 * against its longhands, box-model.js resolveSides) where entryAtPose
 * answers one property.
 *
 * @param {?object} entry
 *   A condition-keyed entry.
 * @param {string} compound
 *   The editing compound.
 * @param {object} [conditions]
 *   The condition table (guide.conditions).
 *
 * @return {?Object<string, *>}
 *   The merged block in delivery order, or null when nothing applies.
 */
export const posedBlock = (entry, compound, conditions = {}) => {
  const applicable = Object.keys(entry ?? {}).filter(
    (condition) =>
      condition !== 'variants' &&
      isBlock(entry[condition]) &&
      appliesWithin(condition, compound),
  );
  if (!applicable.length) {
    return null;
  }
  const merged = new Map();
  cascadeOrder(applicable, conditions).forEach((condition) => {
    Object.entries(entry[condition]).forEach(([property, value]) => {
      if (value !== undefined && value !== null) {
        merged.delete(property);
        merged.set(property, value);
      }
    });
  });
  return merged.size ? Object.fromEntries(merged) : null;
};
