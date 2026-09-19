/**
 * Diffs a desired whole-entry state against the authoritative map into
 * update_style operations.
 *
 * The one consumer today is paste-styles (variants.js): a copied element's
 * effective entries diff against the target's effective map, so the pasted
 * values land as the override layer over whatever the target wears. The
 * result is ONE batch of PATCH ops — the server applies them atomically
 * with rollback-on-semantic-failure, so a paste is all-or-nothing like any
 * other batch. (The code view does NOT use this: islands commit
 * per-declaration via island.js islandOps.)
 *
 * Diff rules:
 * - Only REPRESENTABLE conditions diff: "default" plus the named conditions
 *   the guide's wrapper table carries. A condition outside that vocabulary
 *   (unknown name, plugin uninstalled) is inert data and stays untouched.
 * - Variant REFERENCES are invisible to the diff (the layered entry model):
 *   an entry's "variants" list is not diffable content and no op emitted
 *   here ever touches it (update_style keeps references; detaching is its
 *   own gesture). A pure-reference key with no diffable content therefore
 *   produces nothing (silence is not deletion), while its OWN condition
 *   layers ARE diffable: pasted values write override layers and the
 *   references stay.
 * - A key the desired state no longer mentions unsets everything it can
 *   represent. Against a RESOLVED map this also emits unsets for
 *   variant-supplied values; those land as no-ops (there is no override to
 *   remove) — removing a variant's value takes a detach, not a diff.
 */

const isEntryObject = (value) =>
  value !== null && typeof value === 'object' && !Array.isArray(value);

const conditionValues = (entry, condition) => {
  const values = isEntryObject(entry) ? entry[condition] : null;
  return isEntryObject(values) ? values : {};
};

/**
 * Computes the update_style batch that moves the map to the desired state.
 *
 * @param {string} key
 *   The element's selectable key (the diff's subject).
 * @param {object} map
 *   The authoritative style map.
 * @param {object} entries
 *   The desired state: what the element and its sub-element ('::'-suffixed)
 *   keys should hold.
 * @param {object} conditions
 *   guide.conditions (name → {label, wrapper}).
 *
 * @return {Array<object>}
 *   update_style operations ({op, key, condition?, properties?}),
 *   empty when the desired state matches the map.
 */
export const computeOps = (key, map, entries, conditions) => {
  const representable = ['default', ...Object.keys(conditions ?? {})];
  const keys = [key];
  Object.keys(map ?? {}).forEach((mapKey) => {
    if (mapKey.startsWith(`${key}::`) && !keys.includes(mapKey)) {
      keys.push(mapKey);
    }
  });
  Object.keys(entries ?? {}).forEach((entryKey) => {
    if (!keys.includes(entryKey)) {
      keys.push(entryKey);
    }
  });

  const ops = [];
  keys.forEach((targetKey) => {
    const oldEntry = map?.[targetKey];
    const newEntry = entries?.[targetKey];

    const keyOps = [];
    representable.forEach((condition) => {
      // The entry's own condition layers are the diffable content; a
      // "variants" list rides beside them, untouched (representable
      // condition names never collide with the reserved key).
      const oldValues = conditionValues(oldEntry, condition);
      const newValues = conditionValues(newEntry, condition);
      const patch = {};
      Object.entries(newValues).forEach(([property, value]) => {
        if (oldValues[property] !== value) {
          patch[property] = value;
        }
      });
      Object.keys(oldValues).forEach((property) => {
        if (!(property in newValues)) {
          patch[property] = null;
        }
      });
      if (Object.keys(patch).length) {
        const op = { op: 'update_style', key: targetKey, properties: patch };
        if (condition !== 'default') {
          op.condition = condition;
        }
        keyOps.push(op);
      }
    });

    ops.push(...keyOps);
  });

  return ops;
};
