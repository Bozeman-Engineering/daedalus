import { MATERIAL_KINDS } from './roster.js';

/**
 * @file
 * The materials delta applied to a per-kind record set: the one seating rule.
 *
 * Two record sets follow the delivery contract's mid-session half: the
 * edit-mode store's slice (store/slices/materials-slice.js) and a paired
 * window without the app bundle (daedalus_stylish's popped-out inspector), which
 * holds its own set over the same boot envelope and the relayed deltas.
 * Both seat records through here, so the two windows agree record for
 * record after every delta.
 *
 * Records are kept per kind in roster order (the delivery order: the
 * provider's own, weight-then-label for the mintable kinds, declared
 * scale order for tokens). An upsert of a record whose weight and label
 * did not change keeps its seat (a value edit never reorders anything);
 * every other upsert is seated by (weight, label) among its kind, which
 * is where the next page load's roster lists it. Removes run after
 * upserts, as the server resolves them: a record both upserted and
 * removed in one delta is a drift no delta can carry (each touched id
 * resolves to exactly one side at flush). Removing an unknown id is a
 * no-op (an unpublished record the set never held).
 */

/**
 * A fresh per-kind record set: {token: [], swatch: [], variant: [],
 * stencil: [], animation: []}.
 */
export const emptyRecords = () =>
  Object.fromEntries(MATERIAL_KINDS.map((kind) => [kind, []]));

/**
 * A per-kind record set from a page-load payload ({records: [...]}).
 *
 * Loud on an unknown kind: a payload built from the roster can never
 * carry one, so a miss here is a real drift between the delivery contract
 * and this twin.
 */
export const recordsFromPayload = (payload) => {
  const records = emptyRecords();
  (payload?.records ?? []).forEach((record) => {
    if (!records[record?.kind]) {
      throw new Error(
        `A materials payload holds records of a material kind only; got "${record?.kind}".`,
      );
    }
    records[record.kind].push(record);
  });
  return records;
};

/**
 * The roster's order key within a kind: weight, then label.
 */
const compare = (a, b) =>
  (a.weight ?? 0) - (b.weight ?? 0) ||
  String(a.label ?? '').localeCompare(String(b.label ?? ''), undefined, {
    sensitivity: 'base',
  });

const upsert = (records, record) => {
  const index = records.findIndex((existing) => existing.id === record.id);
  if (index !== -1 && compare(records[index], record) === 0) {
    records[index] = record;
    return;
  }
  if (index !== -1) {
    records.splice(index, 1);
  }
  const seat = records.findIndex((existing) => compare(existing, record) > 0);
  records.splice(seat === -1 ? records.length : seat, 0, record);
};

const remove = (records, id) => {
  const index = records.findIndex((existing) => existing.id === id);
  if (index !== -1) {
    records.splice(index, 1);
  }
};

/**
 * Applies a {upsert, remove} delta to a per-kind record set, in place.
 *
 * In place on purpose: the store slice applies it under immer's draft,
 * and a plain holder applies it to arrays it owns.
 *
 * @param {Object<string, object[]>} records
 *   The per-kind record set (emptyRecords() shape).
 * @param {{upsert: object[], remove: Array<{kind: string, id: string}>}} delta
 *   The delta, already asserted (assertDelta).
 */
export const applyDelta = (records, delta) => {
  const { upsert: upserts = [], remove: removes = [] } = delta;
  upserts.forEach((record) => upsert(records[record.kind], record));
  removes.forEach(({ kind, id }) => remove(records[kind], id));
};

/**
 * Every record of a per-kind set as one flat list, roster order (kind
 * order, then the seat order within the kind).
 */
export const flattenRecords = (records) =>
  MATERIAL_KINDS.flatMap((kind) => records[kind]);

/**
 * Whether a delta carries nothing: applied nowhere, relayed nowhere, so
 * the (empty) delta every mutation response carries never re-renders a
 * widget.
 */
export const isEmptyDelta = (delta) =>
  !delta ||
  ((delta.upsert?.length ?? 0) === 0 && (delta.remove?.length ?? 0) === 0);

/**
 * Refuses a delta of the wrong shape or an unknown kind, loudly: a payload
 * built from the roster can never carry either, so a miss here is a real
 * drift between the server's delivery contract and this twin.
 */
export const assertDelta = (delta) => {
  if (
    !delta ||
    typeof delta !== 'object' ||
    !Array.isArray(delta.upsert) ||
    !Array.isArray(delta.remove)
  ) {
    throw new Error(
      'A materials delta is {upsert: [record...], remove: [{kind, id}...]}.',
    );
  }
  [...delta.upsert, ...delta.remove].forEach((entry) => {
    if (
      !MATERIAL_KINDS.includes(entry?.kind) ||
      typeof entry?.id !== 'string' ||
      entry.id === ''
    ) {
      throw new Error(
        `A materials delta entry names a material kind and id; got ${JSON.stringify(entry)}.`,
      );
    }
  });
};
