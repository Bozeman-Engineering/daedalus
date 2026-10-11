/**
 * @file
 * The materials roster's client twin: records indexed by kind and spelling.
 *
 * The JS mirror of the PHP MaterialsRoster (daedalus_blueprint
 * src/Materials/MaterialsRoster.php) over records in the delivery shape
 * (MaterialRecord::toArray(): snake_case keys, every field present). It
 * is the lookup the value grammar resolves through and the ONE
 * applicability predicate ("may this element wear this variant", "may
 * this blueprint land here") the pickers, the wear bar and the palette
 * share with the server, locked together by
 * ValueGrammarTest::testClientTwinEquivalence through
 * tests/js/materials-cases.mjs. Change MaterialsRoster's predicate and
 * this module in the same commit, or the lock fails. The predicate is a
 * roster method on both sides: a blueprint's band fact resolves its root's
 * wearings against the variant records of the same roster.
 *
 * No entity is read here: the records arrive on the page-load payload
 * ({records: [...]}) and the mutation response's materials delta, and the
 * materials store rebuilds the index from its current record set.
 */

import { SLOT, slotScope } from './grammar.js';

/**
 * The material kinds, in roster order (MaterialKind::cases(): a new kind
 * lands last).
 */
export const MATERIAL_KINDS = [
  'token',
  'swatch',
  'variant',
  'blueprint',
  'animation',
  'font',
];

/**
 * The applies key naming a depth restriction, and its one value.
 */
export const APPLIES_DEPTH = 'depth';
export const DEPTH_ROOT = 'root';

/**
 * The applies key carrying a blueprint root's captured wearings.
 */
export const APPLIES_WEARS = 'wears';

/**
 * Whether a record applies at top level only (MaterialRecord::rootOnly()).
 *
 * @param {object} record
 *   A record in the delivery shape.
 * @return {boolean}
 */
export const rootOnly = (record) =>
  record.applies?.[APPLIES_DEPTH] === DEPTH_ROOT;

/**
 * The variant ids a blueprint record's root wears (MaterialRecord::wears()).
 *
 * @param {object} record
 *   A record in the delivery shape.
 * @return {string[]}
 */
export const wears = (record) => record.applies?.[APPLIES_WEARS] ?? [];

/**
 * Whether a variant scope pairs with an element scope (the family rule).
 *
 * A scope is a styling ADDRESS printed; the grammar and the rule live in
 * daedalus_blueprint's PHP Address value object (Address::pairsWith()),
 * and this is the verbatim twin of its string door
 * MaterialsRoster::scopesPair() over printed addresses: scopes pair only
 * within their family, and within a family the kind is the wildcard, so
 * refinements must match exactly; base:{tag} variants pair with every
 * element scope (whether an element renders as that tag is a live-DOM
 * fact the address families cannot express).
 *
 * @param {string} variantScope
 * @param {string} elementScope
 * @return {boolean}
 */
export const scopesPair = (variantScope, elementScope) => {
  if (elementScope === variantScope) {
    return true;
  }
  if (variantScope.startsWith('base:')) {
    return true;
  }
  const families = [
    ['kind:container', 'layout:'],
    ['kind:block', 'block_type:'],
    ['kind:block', 'block_plugin:'],
  ];
  for (const [kind, refinement] of families) {
    const inFamily = (scope) => scope === kind || scope.startsWith(refinement);
    if (inFamily(elementScope) && inFamily(variantScope)) {
      return elementScope === kind || variantScope === kind;
    }
  }
  return false;
};

/**
 * Indexes records by kind, id and spelling: the roster's read surface.
 *
 * Refuses duplicate ids within a kind, duplicate spellings within a slot
 * and an attribute slot naming another scope than the record's exactly
 * as the PHP roster and record do: a payload built from a roster that
 * refused them can never carry them, so a refusal here is a real drift
 * (two deltas disagreeing), not something to paper over. An attribute
 * slot carries its scope (attrSlot in ./grammar.js), so the flat index
 * partitions attribute values per scope by construction.
 *
 * @param {object[]} records
 *   Records in the delivery shape.
 * @return {object}
 *   The roster: all(), ofKind(kind), get(kind, id), has(kind, id),
 *   isEmpty(), bySpelling(slot, spelling), spellings(slot),
 *   applicable(record, elementScope, topLevel, withinBlueprint),
 *   isBand(blueprint), rootOnlyWears(blueprint).
 */
export const createRoster = (records) => {
  const byKind = new Map(MATERIAL_KINDS.map((kind) => [kind, new Map()]));
  const bySpelling = new Map();
  for (const record of records ?? []) {
    const ofKind = byKind.get(record?.kind);
    if (!ofKind) {
      throw new Error(
        `A materials roster holds records of a material kind only; got "${record?.kind}".`,
      );
    }
    if (ofKind.has(record.id)) {
      throw new Error(
        `Two ${record.kind} records share the id "${record.id}".`,
      );
    }
    ofKind.set(record.id, record);
    for (const [slot, spelling] of Object.entries(record.spellings ?? {})) {
      if (
        slot.startsWith(SLOT.ATTR_PREFIX) &&
        slotScope(slot) !== (record.scope ?? null)
      ) {
        throw new Error(
          `Attribute slot "${slot}" on ${record.kind} "${record.id}" names scope "${slotScope(slot)}", but the record's scope is ${record.scope == null ? 'unset' : `"${record.scope}"`}; an axis attribute spells in its own scope only.`,
        );
      }
      if (!bySpelling.has(slot)) {
        bySpelling.set(slot, new Map());
      }
      const slotIndex = bySpelling.get(slot);
      if (slotIndex.has(spelling)) {
        const other = slotIndex.get(spelling);
        throw new Error(
          `${other.kind} "${other.id}" and ${record.kind} "${record.id}" both spell "${spelling}" in the ${slot} slot.`,
        );
      }
      slotIndex.set(spelling, record);
    }
  }
  const get = (kind, id) => byKind.get(kind)?.get(id) ?? null;

  /**
   * The root-only variant records a blueprint's root wears, keyed by id.
   *
   * The twin of MaterialsRoster::rootOnlyWears(): what makes a blueprint a
   * band, named, so a corrective can spell the variant and its axis. The
   * record's captured wearings resolve against the variant records of
   * THIS roster; a wearing that names no record here (a variant
   * unpublished since the capture, or one outside the active design
   * system) has no opinion. Empty for every other kind.
   *
   * @param {object} blueprint
   *   A record in the delivery shape.
   * @return {Map<string, object>}
   *   The root-only records among the blueprint's wearings, in wearing order.
   */
  const rootOnlyWears = (blueprint) => {
    const records = new Map();
    for (const id of wears(blueprint)) {
      const variant = get('variant', id);
      if (variant !== null && rootOnly(variant)) {
        records.set(id, variant);
      }
    }
    return records;
  };

  /**
   * Whether a blueprint is a page band: its root wears a root-only variant.
   *
   * The twin of MaterialsRoster::isBand(), the one derivation behind every
   * "band" word the client speaks (the palette's absent caret, the
   * click-to-place refusal).
   *
   * @param {object} blueprint
   *   A record in the delivery shape.
   * @return {boolean}
   */
  const isBand = (blueprint) => rootOnlyWears(blueprint).size > 0;

  /**
   * Whether a material may be worn by an element in a given situation.
   *
   * The twin of MaterialsRoster::applicable(): variants gate on scope
   * pairing, root-only depth and blueprint ownership; a blueprint gates on
   * one derived fact (a band places at top level only, every other
   * blueprint anywhere the placement grammar allows); every other kind
   * applies everywhere.
   *
   * @param {object} record
   *   A record in the delivery shape.
   * @param {string} elementScope
   *   The element's own scope ("block_type:text", "kind:container"); for
   *   a blueprint, the scope its root lands as.
   * @param {boolean} [topLevel=true]
   *   Whether the element sits at top level (a page band); for a blueprint,
   *   where it would land.
   * @param {string|null} [withinBlueprint=null]
   *   The id of the blueprint whose placement the element sits inside.
   * @return {boolean}
   */
  const applicable = (
    record,
    elementScope,
    topLevel = true,
    withinBlueprint = null,
  ) => {
    if (record.kind === 'blueprint') {
      return topLevel || !isBand(record);
    }
    if (record.kind !== 'variant') {
      return true;
    }
    if (record.scope == null || !scopesPair(record.scope, elementScope)) {
      return false;
    }
    if (rootOnly(record) && !topLevel) {
      return false;
    }
    if (record.owned_by != null && record.owned_by !== withinBlueprint) {
      return false;
    }
    return true;
  };

  return {
    all: () => MATERIAL_KINDS.flatMap((kind) => [...byKind.get(kind).values()]),
    ofKind: (kind) => [...(byKind.get(kind)?.values() ?? [])],
    get,
    has: (kind, id) => byKind.get(kind)?.has(id) ?? false,
    isEmpty: () => MATERIAL_KINDS.every((kind) => byKind.get(kind).size === 0),
    bySpelling: (slot, spelling) => bySpelling.get(slot)?.get(spelling) ?? null,
    spellings: (slot) => Object.fromEntries(bySpelling.get(slot) ?? []),
    applicable,
    isBand,
    rootOnlyWears,
  };
};
