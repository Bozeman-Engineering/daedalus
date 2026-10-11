/**
 * @file
 * Search: one box over all of it, answering one flat list.
 *
 * From two characters the panel leaves the sets and answers with one
 * flat list, each face once, in label order, each hit naming the kind it
 * lives under in All (Basics, Blueprints, a category), since the sets
 * that would have said where it is are not drawn. A hit is a face whose
 * label holds the query or that is filed in a group whose label holds
 * it, read case-insensitively, so "market" finds every Marketing block.
 *
 * Under two characters the panel is not searching: the sets stand and
 * the box is only focused (activeQuery answers '' and search answers
 * null). From two characters with no hit the answer is an empty list,
 * which the panel draws as its "nothing matches" sentence; the two
 * states are told apart by null against [].
 *
 * Pure: no DOM, no Preact.
 */

import { byLabel } from './order.js';
import { DEFAULT_WORDS, kindOf } from './sets.js';

/**
 * The characters a query needs before the panel searches.
 */
export const MIN_QUERY = 2;

/**
 * The set id a search's rows are keyed under (faces.js rowKey).
 */
export const SEARCH_ID = 'search';

/**
 * The query the panel searches with, or '' when it is not searching.
 *
 * @param {*} raw
 *   The box's value.
 *
 * @return {string}
 *   The trimmed, lower-cased query from MIN_QUERY characters; '' under.
 */
export const activeQuery = (raw) => {
  const query = String(raw ?? '').trim();
  return query.length >= MIN_QUERY ? query.toLowerCase() : '';
};

/**
 * The search's answer.
 *
 * @param {Array<object>} faces
 *   Every face (faces.js faceList).
 * @param {Array<{id: string, label: string}>} groups
 *   The site's vocabulary.
 * @param {*} raw
 *   The box's value.
 * @param {object} words
 *   The fixed headings' words (sets.js).
 *
 * @return {?Array<{face: object, kind: {id: string, label: string}}>}
 *   null when not searching; else the hits in label order, each once,
 *   with the kind it lives under.
 */
export const search = (faces, groups, raw, words = DEFAULT_WORDS) => {
  const query = activeQuery(raw);
  if (query === '') {
    return null;
  }
  const groupLabels = new Map(
    (groups ?? []).map((group) => [
      String(group.id),
      String(group.label ?? group.id).toLowerCase(),
    ]),
  );
  const hits = new Map();
  faces.forEach((face) => {
    if (hits.has(face.id)) {
      return;
    }
    const inLabel = face.label.toLowerCase().includes(query);
    const inGroup = face.groups.some((id) =>
      (groupLabels.get(id) ?? '').includes(query),
    );
    if (inLabel || inGroup) {
      hits.set(face.id, face);
    }
  });
  return byLabel([...hits.values()]).map((face) => ({
    face,
    kind: kindOf(face, words),
  }));
};
